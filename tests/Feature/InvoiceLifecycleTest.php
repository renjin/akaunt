<?php

use App\Models\Company;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Services\ChartOfAccountsTemplate;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Services\ReportService;

beforeEach(function () {
    $this->company = Company::create([
        'name' => 'Agency Sdn Bhd', 'slug' => 'agency-'.uniqid(), 'legal_form' => 'sdn_bhd',
        'base_currency' => 'MYR', 'sst_registration_no' => 'W10-1234-56789012',
    ]);
    ChartOfAccountsTemplate::seed($this->company);

    $this->customer = $this->company->parties()->create([
        'role' => 'customer', 'name' => 'Client Sdn Bhd',
        'registration_scheme' => 'BRN', 'registration_number' => '202301012345',
        'tin' => 'C1234567890', 'email' => 'client@example.my',
    ]);

    $this->svc = app(InvoiceService::class);
});

function makeInvoice($test, string $taxName = 'Service Tax 8%'): Invoice
{
    $tax = $test->company->taxCodes()->where('name', $taxName)->first();
    $income = $test->company->accounts()->where('code', '4100')->first();

    $invoice = $test->company->invoices()->create([
        'party_id' => $test->customer->id,
        'invoice_number' => $test->svc->nextNumber($test->company),
        'issue_date' => '2026-07-01',
        'due_date' => '2026-07-31',
    ]);
    $invoice->lines()->create([
        'description' => 'Web development retainer — July',
        'quantity' => 1, 'unit_price' => 5000,
        'tax_code_id' => $tax->id, 'income_account_id' => $income->id,
        'classification_code' => '008',
    ]);

    return $invoice->refresh();
}

it('numbers invoices sequentially', function () {
    expect($this->svc->nextNumber($this->company))->toBe('INV-00001');
    makeInvoice($this);
    expect($this->svc->nextNumber($this->company))->toBe('INV-00002');
});

it('calculates SST on approval and posts Dr AR / Cr Income / Cr SST Payable', function () {
    $invoice = makeInvoice($this);
    $this->svc->approve($invoice);
    $invoice->refresh();

    expect($invoice->status)->toBe('approved')
        ->and($invoice->subtotal)->toBe('5000.00')
        ->and($invoice->tax_total)->toBe('400.00')    // 8% service tax
        ->and($invoice->total)->toBe('5400.00');

    $ar = $this->company->accounts()->where('code', '1100')->first();
    $income = $this->company->accounts()->where('code', '4100')->first();
    $sst = $this->company->accounts()->where('code', '2200')->first();

    expect($ar->balance())->toBe('5400.00')
        ->and($income->balance())->toBe('5000.00')
        ->and($sst->balance())->toBe('400.00');
});

it('rolls status through partial to paid and clears AR', function () {
    $invoice = makeInvoice($this);
    $this->svc->approve($invoice);
    $bank = $this->company->accounts()->where('code', '1010')->first();
    $pay = app(PaymentService::class);

    $pay->receiveAgainstInvoice($invoice->refresh(), '2000.00', '2026-07-10', $bank, 'fpx', 'FPX123');
    expect($invoice->refresh()->status)->toBe('partial');

    $pay->receiveAgainstInvoice($invoice->refresh(), '3400.00', '2026-07-20', $bank, 'duitnow');
    $invoice->refresh();

    expect($invoice->status)->toBe('paid')
        ->and($invoice->amount_paid)->toBe('5400.00')
        ->and($this->company->accounts()->where('code', '1100')->first()->balance())->toBe('0.00')
        ->and($bank->balance())->toBe('5400.00');
});

it('rejects overpayment', function () {
    $invoice = makeInvoice($this);
    $this->svc->approve($invoice);
    $bank = $this->company->accounts()->where('code', '1010')->first();

    app(PaymentService::class)->receiveAgainstInvoice($invoice->refresh(), '9999.00', '2026-07-10', $bank);
})->throws(InvalidArgumentException::class, 'exceeds balance');

it('rejects approving a non-draft and voiding a paid invoice', function () {
    $invoice = makeInvoice($this);
    $this->svc->approve($invoice);

    expect(fn () => $this->svc->approve($invoice->refresh()))
        ->toThrow(InvalidArgumentException::class);

    $bank = $this->company->accounts()->where('code', '1010')->first();
    app(PaymentService::class)->receiveAgainstInvoice($invoice->refresh(), '5400.00', '2026-07-10', $bank);

    expect(fn () => $this->svc->void($invoice->refresh()))
        ->toThrow(InvalidArgumentException::class);
});

it('voiding an unpaid invoice reverses the ledger', function () {
    $invoice = makeInvoice($this);
    $this->svc->approve($invoice);
    $this->svc->void($invoice->refresh());

    expect($invoice->refresh()->status)->toBe('void')
        ->and($this->company->accounts()->where('code', '1100')->first()->balance())->toBe('0.00')
        ->and($this->company->accounts()->where('code', '2200')->first()->balance())->toBe('0.00');
});

it('exempt tax code produces zero tax', function () {
    $invoice = makeInvoice($this, 'Exempt');
    $this->svc->approve($invoice);

    expect($invoice->refresh()->tax_total)->toBe('0.00')
        ->and($invoice->total)->toBe('5000.00');
});

it('reposts an edited posted invoice with a balanced, updated ledger entry', function () {
    $invoice = makeInvoice($this);
    $this->svc->approve($invoice);

    // Edit the posted invoice: bump the line price 5000 -> 8000
    $invoice->refresh()->lines->first()->forceFill(['unit_price' => 8000])->save();
    $this->svc->repost($invoice->refresh());

    $invoice->refresh();
    expect($invoice->status)->toBe('approved')
        ->and($invoice->total)->toBe('8640.00') // 8000 + 8% SST
        ->and($this->company->accounts()->where('code', '1100')->first()->balance())->toBe('8640.00')
        ->and($this->company->accounts()->where('code', '2200')->first()->balance())->toBe('640.00')
        ->and(JournalEntry::query()
            ->where('source_type', Invoice::class)
            ->where('source_id', $invoice->id)
            ->count())->toBe(1);

    $tb = app(ReportService::class)->trialBalance($this->company);
    expect(bccomp($tb['total_debit'], $tb['total_credit'], 2))->toBe(0);
});

it('repost re-derives partial status from existing payments', function () {
    $invoice = makeInvoice($this);
    $this->svc->approve($invoice);
    $bank = $this->company->accounts()->where('code', '1010')->first();
    app(PaymentService::class)->receiveAgainstInvoice($invoice->refresh(), '1000.00', '2026-07-05', $bank, 'fpx');

    $invoice->refresh()->lines->first()->forceFill(['unit_price' => 8000])->save();
    $this->svc->repost($invoice->refresh());

    expect($invoice->refresh()->status)->toBe('partial')
        ->and((float) $invoice->amount_paid)->toBe(1000.0);
});
