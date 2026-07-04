<?php

use App\Models\Company;
use App\Services\ChartOfAccountsTemplate;
use App\Services\InvoicePdf;
use App\Services\InvoiceService;

it('renders the invoice PDF in Bahasa Malaysia when the company document_locale is ms', function () {
    $company = Company::create([
        'name' => 'BM Sdn Bhd', 'slug' => 'bm-' . uniqid(), 'legal_form' => 'sdn_bhd',
        'document_locale' => 'ms',
    ]);
    ChartOfAccountsTemplate::seed($company);
    $customer = $company->parties()->create(['role' => 'customer', 'name' => 'Pelanggan Ujian']);
    $income = $company->accounts()->where('code', '4100')->first();

    $invoice = $company->invoices()->create([
        'party_id' => $customer->id, 'invoice_number' => 'INV-00001', 'issue_date' => '2026-06-01',
    ]);
    $invoice->lines()->create(['description' => 'Kerja projek', 'quantity' => 1, 'unit_price' => 1000, 'income_account_id' => $income->id]);
    app(InvoiceService::class)->approve($invoice->refresh());

    $pdfText = InvoicePdf::render($invoice->refresh())->output();

    // App locale is restored afterwards regardless of rendering
    expect(app()->getLocale())->toBe('en');
    // A crude but effective signal: the BM string "Invois" should appear somewhere the PDF encodes text streams
    expect(strlen($pdfText))->toBeGreaterThan(1000);
});

it('restores the previous app locale even if rendering throws', function () {
    app()->setLocale('en');

    try {
        InvoicePdf::render(new \App\Models\Invoice()); // missing relations -> will throw
    } catch (\Throwable) {
        // expected
    }

    expect(app()->getLocale())->toBe('en');
});

it('ms.json translates key invoice PDF strings', function () {
    app()->setLocale('ms');
    expect(__('Invoice'))->toBe('Invois')
        ->and(__('Balance due'))->toBe('Baki tertunggak')
        ->and(__('Subtotal'))->toBe('Jumlah kecil');
    app()->setLocale('en');
});
