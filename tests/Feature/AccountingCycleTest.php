<?php

use App\Models\Company;
use App\Services\BankTransactionService;
use App\Services\BillService;
use App\Services\ChartOfAccountsTemplate;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Services\ReportService;

/**
 * The plan's end-to-end verification: invoice → payment → bill → payment →
 * bank categorize, then assert TB = 0 and the statements hold together.
 */
beforeEach(function () {
    $this->company = Company::create([
        'name' => 'Cycle Sdn Bhd', 'slug' => 'cycle-'.uniqid(), 'legal_form' => 'sdn_bhd',
        'sst_registration_no' => 'W10-1234-56789012',
    ]);
    ChartOfAccountsTemplate::seed($this->company);
    $this->reports = app(ReportService::class);
    $this->bank = $this->company->accounts()->where('code', '1010')->first();
});

function runFullCycle($test): void
{
    $customer = $test->company->parties()->create(['role' => 'customer', 'name' => 'Customer A']);
    $vendor = $test->company->parties()->create(['role' => 'vendor', 'name' => 'Vendor B']);
    $svcTax = $test->company->taxCodes()->where('name', 'Service Tax 8%')->first();
    $income = $test->company->accounts()->where('code', '4100')->first();
    $rent = $test->company->accounts()->where('code', '6100')->first();

    // Invoice RM5000 + 8% SST = RM5400, fully paid
    $invoice = $test->company->invoices()->create([
        'party_id' => $customer->id, 'invoice_number' => 'INV-00001', 'issue_date' => '2026-07-01',
    ]);
    $invoice->lines()->create([
        'description' => 'Consulting', 'quantity' => 1, 'unit_price' => 5000,
        'tax_code_id' => $svcTax->id, 'income_account_id' => $income->id,
    ]);
    app(InvoiceService::class)->approve($invoice->refresh());
    app(PaymentService::class)->receiveAgainstInvoice($invoice->refresh(), '5400.00', '2026-07-05', $test->bank, 'fpx');

    // Bill: rent RM2000 + 8% SST RM160 → RM2160 expense (SST folds in), fully paid
    $bill = $test->company->bills()->create([
        'party_id' => $vendor->id, 'bill_number' => 'VB-77', 'bill_date' => '2026-07-02',
    ]);
    $bill->lines()->create([
        'description' => 'Office rent', 'quantity' => 1, 'unit_price' => 2000,
        'tax_code_id' => $svcTax->id, 'expense_account_id' => $rent->id,
    ]);
    app(BillService::class)->approve($bill->refresh());
    app(PaymentService::class)->payBill($bill->refresh(), '2160.00', '2026-07-10', $test->bank, 'duitnow');

    // Stray bank fee categorized straight from the feed
    $txn = $test->company->bankTransactions()->create([
        'account_id' => $test->bank->id, 'txn_date' => '2026-07-15',
        'description' => 'Bank service fee', 'amount' => '25.00', 'direction' => 'out',
    ]);
    $fees = $test->company->accounts()->where('code', '6600')->first();
    app(BankTransactionService::class)->categorize($txn, $fees);
}

it('bill approval folds SST into the expense — no recoverable tax asset', function () {
    runFullCycle($this);

    $rent = $this->company->accounts()->where('code', '6100')->first();
    $sstPayable = $this->company->accounts()->where('code', '2200')->first();

    expect($rent->balance())->toBe('2160.00')       // 2000 + 160 SST folded in
        ->and($sstPayable->balance())->toBe('400.00'); // ONLY output tax from our invoice
});

it('trial balance nets to zero after the full cycle', function () {
    runFullCycle($this);

    $tb = $this->reports->trialBalance($this->company, '2026-07-31');

    expect($tb['total_debit'])->toBe($tb['total_credit'])
        ->and((float) $tb['total_debit'])->toBeGreaterThan(0);
});

it('P&L shows income 5000, expenses 2185, net 2815', function () {
    runFullCycle($this);

    $pl = $this->reports->profitAndLoss($this->company, '2026-07-01', '2026-07-31');

    expect($pl['totals']['income'])->toBe('5000.00')
        ->and($pl['totals']['expense'])->toBe('2185.00') // 2160 rent + 25 fee
        ->and($pl['net_profit'])->toBe('2815.00');
});

it('balance sheet balances: assets = liabilities + equity', function () {
    runFullCycle($this);

    $bs = $this->reports->balanceSheet($this->company, '2026-07-31');

    expect($bs['totals']['asset'])->toBe($bs['liabilities_plus_equity'])
        // bank: +5400 − 2160 − 25 = 3215
        ->and($bs['totals']['asset'])->toBe('3215.00')
        // SST payable 400
        ->and($bs['totals']['liability'])->toBe('400.00')
        // current year earnings
        ->and($bs['totals']['equity'])->toBe('2815.00');
});

it('SST-02 output summary matches the invoice tax', function () {
    runFullCycle($this);

    $sst = $this->reports->sstOutputSummary($this->company, '2026-07-01', '2026-07-31');

    expect($sst['total_taxable'])->toBe('5000.00')
        ->and($sst['total_tax'])->toBe('400.00');
});

it('aged payables buckets an unpaid bill', function () {
    $vendor = $this->company->parties()->create(['role' => 'vendor', 'name' => 'Vendor C']);
    $bill = $this->company->bills()->create([
        'party_id' => $vendor->id, 'bill_number' => 'VB-88',
        'bill_date' => today()->subDays(45)->toDateString(),
        'due_date' => today()->subDays(45)->toDateString(),
    ]);
    $bill->lines()->create(['description' => 'Supplies', 'quantity' => 1, 'unit_price' => 300]);
    app(BillService::class)->approve($bill->refresh());

    $aged = app(ReportService::class)->agedPayables($this->company);

    expect($aged['Vendor C']['b60'])->toBe('300.00')
        ->and($aged['Vendor C']['total'])->toBe('300.00');
});

it('imports a bank CSV with dedupe', function () {
    $csv = "Date,Description,Amount\n2026-07-01,MAYBANK TRANSFER CLIENT A,5400.00\n2026-07-03,TNB BILL,-350.50\n";
    $svc = app(BankTransactionService::class);

    [$imported, $skipped] = $svc->importCsv($this->company, $this->bank, $csv);
    expect($imported)->toBe(2);

    // re-import: everything deduped
    [$imported2, $skipped2] = $svc->importCsv($this->company, $this->bank, $csv);
    expect($imported2)->toBe(0)->and($skipped2)->toBe(2);

    $txn = $this->company->bankTransactions()->where('description', 'TNB BILL')->first();
    expect($txn->direction)->toBe('out')->and($txn->amount)->toBe('350.50');
});

it('general ledger running balance reaches the account balance', function () {
    runFullCycle($this);

    $gl = $this->reports->generalLedger($this->company, $this->bank->refresh(), '2026-07-01', '2026-07-31');

    expect($gl['closing'])->toBe('3215.00')
        ->and($gl['opening'])->toBe('0.00')
        ->and(count($gl['rows']))->toBe(3); // receipt, bill payment, bank fee
});

it('products and services report summarizes sales and purchase line activity', function () {
    $customer = $this->company->parties()->create(['role' => 'customer', 'name' => 'Customer A']);
    $vendor = $this->company->parties()->create(['role' => 'vendor', 'name' => 'Vendor B']);
    $income = $this->company->accounts()->where('code', '4100')->first();
    $expense = $this->company->accounts()->where('code', '6200')->first();
    $item = $this->company->items()->create([
        'name' => 'Implementation package',
        'kind' => 'sales',
        'unit_price' => '750.00',
        'income_account_id' => $income->id,
    ]);

    $invoice = $this->company->invoices()->create([
        'party_id' => $customer->id,
        'invoice_number' => 'INV-00002',
        'issue_date' => '2026-07-03',
    ]);
    $invoice->lines()->create([
        'item_id' => $item->id,
        'description' => 'Implementation package',
        'quantity' => 2,
        'unit_price' => 750,
        'income_account_id' => $income->id,
    ]);
    app(InvoiceService::class)->approve($invoice->refresh());

    $bill = $this->company->bills()->create([
        'party_id' => $vendor->id,
        'bill_number' => 'B-100',
        'bill_date' => '2026-07-04',
    ]);
    $bill->lines()->create([
        'item_id' => $item->id,
        'description' => 'Implementation package',
        'quantity' => 1,
        'unit_price' => 250,
        'expense_account_id' => $expense->id,
    ]);
    app(BillService::class)->approve($bill->refresh());

    $report = $this->reports->productsAndServices($this->company, '2026-07-01', '2026-07-31');
    $row = $report['rows']->first();

    expect($row['label'])->toBe('Implementation package')
        ->and($row['sales_quantity'])->toBe('2.00')
        ->and($row['sales_amount'])->toBe('1500.00')
        ->and($row['purchase_quantity'])->toBe('1.00')
        ->and($row['purchase_amount'])->toBe('250.00')
        ->and($row['net_amount'])->toBe('1250.00')
        ->and($report['totals']['net_amount'])->toBe('1250.00')
        ->and(count($row['details']))->toBe(2);
});

it('expenses report includes bills and categorized outgoing bank transactions', function () {
    runFullCycle($this);

    $report = $this->reports->expenses($this->company, '2026-07-01', '2026-07-31');
    $vendorRow = $report['rows']->firstWhere('label', 'Vendor B');
    $bankRow = $report['rows']->firstWhere('label', 'Unassigned vendor');

    expect($report['totals']['amount'])->toBe('2185.00')
        ->and($report['totals']['sources'])->toBe(2)
        ->and($vendorRow['amount'])->toBe('2160.00')
        ->and($bankRow['amount'])->toBe('25.00');
});
