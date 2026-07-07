<?php

use App\Models\Company;
use App\Services\BillService;
use App\Services\ChartOfAccountsTemplate;
use App\Services\EstimateService;
use App\Services\InvoiceService;
use App\Services\PurchaseOrderService;
use App\Services\RecurringInvoiceService;

beforeEach(function () {
    $this->company = Company::create([
        'name' => 'Phase4 Sdn Bhd', 'slug' => 'phase4-'.uniqid(), 'legal_form' => 'sdn_bhd',
    ]);
    ChartOfAccountsTemplate::seed($this->company);
    $this->customer = $this->company->parties()->create(['role' => 'customer', 'name' => 'Client X']);
    $this->tax = $this->company->taxCodes()->where('name', 'Service Tax 8%')->first();
    $this->income = $this->company->accounts()->where('code', '4100')->first();
    $this->expense = $this->company->accounts()->where('code', '6400')->first();
});

it('estimate lifecycle carries no ledger impact until converted', function () {
    $svc = app(EstimateService::class);
    $estimate = $this->company->estimates()->create([
        'party_id' => $this->customer->id, 'estimate_number' => $svc->nextNumber($this->company),
        'issue_date' => today()->toDateString(),
    ]);
    $estimate->lines()->create([
        'description' => 'Website design', 'quantity' => 1, 'unit_price' => 3000,
        'tax_code_id' => $this->tax->id, 'income_account_id' => $this->income->id,
    ]);
    $svc->calculateTotals($estimate);

    expect($estimate->refresh()->total)->toBe('3240.00')
        ->and($this->company->journalEntries()->count())->toBe(0);

    $svc->send($estimate);
    expect($estimate->refresh()->status)->toBe('sent');

    $svc->accept($estimate);
    expect($estimate->refresh()->status)->toBe('accepted');

    $invoice = $svc->convertToInvoice($estimate, app(InvoiceService::class));

    expect($estimate->refresh()->status)->toBe('converted')
        ->and($estimate->converted_invoice_id)->toBe($invoice->id)
        ->and($invoice->status)->toBe('draft')
        ->and($invoice->total)->toBe('3240.00')
        ->and($this->company->journalEntries()->count())->toBe(0); // still no ledger impact until the invoice itself is approved
});

it('rejects converting a non-accepted estimate', function () {
    $svc = app(EstimateService::class);
    $estimate = $this->company->estimates()->create([
        'party_id' => $this->customer->id, 'estimate_number' => $svc->nextNumber($this->company),
        'issue_date' => today()->toDateString(),
    ]);
    $estimate->lines()->create(['description' => 'X', 'quantity' => 1, 'unit_price' => 100]);

    expect(fn () => $svc->convertToInvoice($estimate, app(InvoiceService::class)))
        ->toThrow(InvalidArgumentException::class);
});

it('purchase order lifecycle carries no ledger impact until converted bill is approved', function () {
    $vendor = $this->company->parties()->create(['role' => 'vendor', 'name' => 'Supplier X']);
    $svc = app(PurchaseOrderService::class);

    $po = $this->company->purchaseOrders()->create([
        'party_id' => $vendor->id,
        'purchase_order_number' => $svc->nextNumber($this->company),
        'order_date' => today()->toDateString(),
    ]);
    $po->lines()->create([
        'description' => 'Office chairs',
        'quantity' => 2,
        'unit_price' => 300,
        'tax_code_id' => $this->tax->id,
        'expense_account_id' => $this->expense->id,
    ]);

    $svc->calculateTotals($po);

    expect($po->refresh()->total)->toBe('648.00')
        ->and($this->company->journalEntries()->count())->toBe(0);

    $svc->send($po);
    expect($po->refresh()->status)->toBe('sent');

    $svc->approve($po);
    expect($po->refresh()->status)->toBe('approved')
        ->and($this->company->journalEntries()->count())->toBe(0);

    $bill = $svc->convertToBill($po, app(BillService::class));

    expect($po->refresh()->status)->toBe('converted')
        ->and($po->converted_bill_id)->toBe($bill->id)
        ->and($bill->status)->toBe('draft')
        ->and($bill->po_number)->toBe($po->purchase_order_number)
        ->and($bill->total)->toBe('648.00')
        ->and($this->company->journalEntries()->count())->toBe(0);

    app(BillService::class)->approve($bill->refresh());

    expect($this->company->journalEntries()->count())->toBe(1)
        ->and($this->expense->refresh()->balance())->toBe('648.00');
});

it('generates and approves a due recurring invoice, then advances the schedule', function () {
    $recurring = $this->company->recurringInvoices()->create([
        'party_id' => $this->customer->id, 'frequency' => 'monthly',
        'next_run_date' => today()->subDay()->toDateString(), // already due
    ]);
    $recurring->lines()->create([
        'description' => 'Monthly retainer', 'quantity' => 1, 'unit_price' => 5000,
        'tax_code_id' => $this->tax->id, 'income_account_id' => $this->income->id,
    ]);

    expect($recurring->isDue())->toBeTrue();

    $invoice = app(RecurringInvoiceService::class)->generate($recurring);

    expect($invoice->status)->toBe('approved')
        ->and($invoice->total)->toBe('5400.00')
        ->and($this->company->accounts()->where('code', '4100')->first()->balance())->toBe('5000.00')
        ->and($recurring->refresh()->next_run_date->toDateString())->toBe(today()->subDay()->addMonthNoOverflow()->toDateString())
        ->and($recurring->last_run_date->toDateString())->not->toBe(null);
});

it('generateAllDue processes only due, active templates with lines', function () {
    $due = $this->company->recurringInvoices()->create([
        'party_id' => $this->customer->id, 'frequency' => 'monthly', 'next_run_date' => today()->toDateString(),
    ]);
    $due->lines()->create(['description' => 'Retainer', 'quantity' => 1, 'unit_price' => 1000]);

    $notYetDue = $this->company->recurringInvoices()->create([
        'party_id' => $this->customer->id, 'frequency' => 'monthly', 'next_run_date' => today()->addMonth()->toDateString(),
    ]);
    $notYetDue->lines()->create(['description' => 'Retainer', 'quantity' => 1, 'unit_price' => 1000]);

    $inactive = $this->company->recurringInvoices()->create([
        'party_id' => $this->customer->id, 'frequency' => 'monthly', 'next_run_date' => today()->toDateString(), 'active' => false,
    ]);
    $inactive->lines()->create(['description' => 'Retainer', 'quantity' => 1, 'unit_price' => 1000]);

    $count = app(RecurringInvoiceService::class)->generateAllDue();

    expect($count)->toBe(1)
        ->and($this->company->invoices()->count())->toBe(1);
});

it('deactivates a recurring invoice once it passes its end date', function () {
    $recurring = $this->company->recurringInvoices()->create([
        'party_id' => $this->customer->id, 'frequency' => 'monthly',
        'next_run_date' => today()->toDateString(), 'end_date' => today()->toDateString(),
    ]);
    $recurring->lines()->create(['description' => 'Last one', 'quantity' => 1, 'unit_price' => 500]);

    app(RecurringInvoiceService::class)->generate($recurring);

    expect($recurring->refresh()->active)->toBeFalse();
});
