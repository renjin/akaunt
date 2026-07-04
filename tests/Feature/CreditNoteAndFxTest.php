<?php

use App\Models\Company;
use App\Services\ChartOfAccountsTemplate;
use App\Services\CreditNoteService;
use App\Services\InvoiceService;
use App\Services\PaymentService;

beforeEach(function () {
    $this->company = Company::create([
        'name' => 'CN Sdn Bhd', 'slug' => 'cn-' . uniqid(), 'legal_form' => 'sdn_bhd',
    ]);
    ChartOfAccountsTemplate::seed($this->company);
    $this->customer = $this->company->parties()->create(['role' => 'customer', 'name' => 'Client Y']);
    $this->tax = $this->company->taxCodes()->where('name', 'Service Tax 8%')->first();
    $this->income = $this->company->accounts()->where('code', '4100')->first();
    $this->bank = $this->company->accounts()->where('code', '1010')->first();
    $this->ar = $this->company->accounts()->where('code', '1100')->first();

    $this->invoice = $this->company->invoices()->create([
        'party_id' => $this->customer->id, 'invoice_number' => 'INV-00001', 'issue_date' => '2026-06-01',
    ]);
    $this->invoice->lines()->create([
        'description' => 'Project work', 'quantity' => 1, 'unit_price' => 5000,
        'tax_code_id' => $this->tax->id, 'income_account_id' => $this->income->id,
    ]);
    app(InvoiceService::class)->approve($this->invoice->refresh());
});

it('reverses income/tax/AR and reduces the original invoice balance', function () {
    $svc = app(CreditNoteService::class);
    $creditNote = $svc->create($this->invoice, '2026-06-10', [
        ['description' => 'Partial refund of scope', 'quantity' => 1, 'unit_price' => 1000, 'tax_code_id' => $this->tax->id, 'income_account_id' => $this->income->id],
    ]);
    $svc->approve($creditNote->refresh());

    expect($creditNote->refresh()->status)->toBe('approved')
        ->and($creditNote->total)->toBe('1080.00')
        ->and($creditNote->isCreditNote())->toBeTrue()
        ->and($this->invoice->refresh()->total)->toBe('4320.00') // 5400 - 1080
        ->and($this->ar->refresh()->balance())->toBe('4320.00')
        ->and($this->income->refresh()->balance())->toBe('4000.00'); // 5000 - 1000
});

it('rejects a credit note larger than the outstanding balance', function () {
    $svc = app(CreditNoteService::class);
    $creditNote = $svc->create($this->invoice, '2026-06-10', [
        ['description' => 'Too much', 'quantity' => 1, 'unit_price' => 9999, 'income_account_id' => $this->income->id],
    ]);

    expect(fn () => $svc->approve($creditNote->refresh()))->toThrow(InvalidArgumentException::class, 'exceeds');
});

it('marks the original invoice paid when a credit note covers the remaining balance', function () {
    app(PaymentService::class)->receiveAgainstInvoice($this->invoice->refresh(), '3400.00', '2026-06-05', $this->bank);
    expect($this->invoice->refresh()->status)->toBe('partial');

    $svc = app(CreditNoteService::class);
    $creditNote = $svc->create($this->invoice, '2026-06-15', [
        ['description' => 'Write-off remainder', 'quantity' => 1, 'unit_price' => 2000, 'income_account_id' => $this->income->id], // exactly the remaining balance (5400-3400), no tax
    ]);
    $svc->approve($creditNote->refresh());

    expect($this->invoice->refresh()->status)->toBe('paid');
});

it('posts realized FX gain when settlement rate is more favorable than the booked rate', function () {
    $usdInvoice = $this->company->invoices()->create([
        'party_id' => $this->customer->id, 'invoice_number' => 'INV-00002', 'issue_date' => '2026-06-01',
        'currency' => 'USD', 'fx_rate' => '4.70',
    ]);
    $usdInvoice->lines()->create(['description' => 'Consulting', 'quantity' => 1, 'unit_price' => 1000, 'income_account_id' => $this->income->id]);
    app(InvoiceService::class)->approve($usdInvoice->refresh());

    // Booked at 4.70 => AR base = 4700.00. Settled at 4.80 => Bank base = 4800.00. Gain = 100.00.
    app(PaymentService::class)->receiveAgainstInvoice($usdInvoice->refresh(), '1000.00', '2026-06-20', $this->bank, 'bank_transfer', null, '4.80');

    $fx = $this->company->accounts()->where('code', '4910')->first();
    expect($this->bank->refresh()->balance())->toBe('4800.00')
        ->and($fx->refresh()->balance())->toBe('100.00')
        ->and($usdInvoice->refresh()->status)->toBe('paid');
});

it('posts realized FX loss when settlement rate is worse than the booked rate', function () {
    $usdInvoice = $this->company->invoices()->create([
        'party_id' => $this->customer->id, 'invoice_number' => 'INV-00003', 'issue_date' => '2026-06-01',
        'currency' => 'USD', 'fx_rate' => '4.70',
    ]);
    $usdInvoice->lines()->create(['description' => 'Consulting', 'quantity' => 1, 'unit_price' => 1000, 'income_account_id' => $this->income->id]);
    app(InvoiceService::class)->approve($usdInvoice->refresh());

    app(PaymentService::class)->receiveAgainstInvoice($usdInvoice->refresh(), '1000.00', '2026-06-20', $this->bank, 'bank_transfer', null, '4.60');

    $fx = $this->company->accounts()->where('code', '4910')->first();
    expect($this->bank->refresh()->balance())->toBe('4600.00')
        ->and($fx->refresh()->balance())->toBe('-100.00');
});

it('same-currency MYR payments never touch the FX account', function () {
    app(PaymentService::class)->receiveAgainstInvoice($this->invoice->refresh(), '5400.00', '2026-06-05', $this->bank);

    $fx = $this->company->accounts()->where('code', '4910')->first();
    expect($fx->refresh()->balance())->toBe('0.00');
});
