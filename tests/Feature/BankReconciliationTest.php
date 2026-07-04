<?php

use App\Models\Company;
use App\Services\BankReconciliationService;
use App\Services\BillService;
use App\Services\ChartOfAccountsTemplate;
use App\Services\InvoiceService;
use App\Services\PaymentService;

beforeEach(function () {
    $this->company = Company::create([
        'name' => 'Recon Sdn Bhd', 'slug' => 'recon-' . uniqid(), 'legal_form' => 'sdn_bhd',
    ]);
    ChartOfAccountsTemplate::seed($this->company);
    $this->bank = $this->company->accounts()->where('code', '1010')->first();
    $this->svc = app(BankReconciliationService::class);

    $customer = $this->company->parties()->create(['role' => 'customer', 'name' => 'Client Z']);
    $income = $this->company->accounts()->where('code', '4100')->first();
    $invoice = $this->company->invoices()->create([
        'party_id' => $customer->id, 'invoice_number' => 'INV-00001', 'issue_date' => '2026-06-01',
    ]);
    $invoice->lines()->create(['description' => 'Work', 'quantity' => 1, 'unit_price' => 1000, 'income_account_id' => $income->id]);
    app(InvoiceService::class)->approve($invoice->refresh());
    app(PaymentService::class)->receiveAgainstInvoice($invoice->refresh(), '1000.00', '2026-06-05', $this->bank);

    $vendor = $this->company->parties()->create(['role' => 'vendor', 'name' => 'Vendor Z']);
    $rent = $this->company->accounts()->where('code', '6100')->first();
    $bill = $this->company->bills()->create(['party_id' => $vendor->id, 'bill_number' => 'B-1', 'bill_date' => '2026-06-02']);
    $bill->lines()->create(['description' => 'Rent', 'quantity' => 1, 'unit_price' => 300, 'expense_account_id' => $rent->id]);
    app(BillService::class)->approve($bill->refresh());
    app(PaymentService::class)->payBill($bill->refresh(), '300.00', '2026-06-06', $this->bank);
});

it('finds all unreconciled ledger lines for the bank account, including payments that never touched bank_transactions', function () {
    $lines = $this->svc->unreconciledLines($this->bank, '2026-06-30');

    expect($lines)->toHaveCount(2); // the receipt and the bill payment — neither created a bank_transactions row
});

it('rejects finishing when the cleared balance does not match the statement', function () {
    $lines = $this->svc->unreconciledLines($this->bank, '2026-06-30');

    expect(fn () => $this->svc->finish($this->bank, '2026-06-30', '999.00', $lines->pluck('id')->all()))
        ->toThrow(InvalidArgumentException::class, 'does not match');
});

it('reconciles when the cleared balance matches the statement, and excludes those lines next time', function () {
    $lines = $this->svc->unreconciledLines($this->bank, '2026-06-30');
    // net = +1000 (receipt) - 300 (bill payment) = 700.00
    $count = $this->svc->finish($this->bank, '2026-06-30', '700.00', $lines->pluck('id')->all());

    expect($count)->toBe(2)
        ->and($this->svc->unreconciledLines($this->bank, '2026-06-30'))->toHaveCount(0)
        ->and($this->svc->previouslyReconciledBalance($this->bank, '2026-06-30'))->toBe('700.00');
});

it('supports partial reconciliation, leaving the rest for next time', function () {
    $lines = $this->svc->unreconciledLines($this->bank, '2026-06-30');
    $receiptLine = $lines->firstWhere('debit_base', '1000.00');

    $this->svc->finish($this->bank, '2026-06-05', '1000.00', [$receiptLine->id]);

    expect($this->svc->unreconciledLines($this->bank, '2026-06-30'))->toHaveCount(1)
        ->and($this->svc->previouslyReconciledBalance($this->bank, '2026-06-30'))->toBe('1000.00');
});
