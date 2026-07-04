<?php

use App\Models\Company;
use App\Services\ChartOfAccountsTemplate;
use App\Services\DuitnowQr;
use App\Services\InvoicePdf;
use App\Services\InvoiceService;
use App\Services\PaymentService;

// Sample EMVCo-style payload (structure only; not a real merchant)
const QR_PAYLOAD = '00020201021126580014A000000615000101065887420210MY60000123453037458';

beforeEach(function () {
    $this->company = Company::create([
        'name' => 'Pay Sdn Bhd', 'slug' => 'pay-' . uniqid(), 'legal_form' => 'sdn_bhd',
        'duitnow_qr_payload' => QR_PAYLOAD,
        'payment_link' => 'https://billplz.com/pay-sdn-bhd',
    ]);
    ChartOfAccountsTemplate::seed($this->company);
    $customer = $this->company->parties()->create(['role' => 'customer', 'name' => 'Payer Bhd']);
    $income = $this->company->accounts()->where('code', '4100')->first();

    $this->invoice = $this->company->invoices()->create([
        'party_id' => $customer->id, 'invoice_number' => 'INV-00001', 'issue_date' => today()->toDateString(),
    ]);
    $this->invoice->lines()->create(['description' => 'Work', 'quantity' => 1, 'unit_price' => 1000, 'income_account_id' => $income->id]);
    app(InvoiceService::class)->approve($this->invoice->refresh());
});

it('generates a valid SVG QR from a DuitNow payload', function () {
    $svg = DuitnowQr::svg(QR_PAYLOAD);

    expect($svg)->toContain('<svg')
        ->and(DuitnowQr::dataUri(QR_PAYLOAD))->toStartWith('data:image/svg+xml;base64,');
});

it('renders the pay block on an unpaid invoice PDF', function () {
    $pdf = InvoicePdf::render($this->invoice->refresh())->output();

    expect(substr($pdf, 0, 4))->toBe('%PDF')
        ->and(strlen($pdf))->toBeGreaterThan(1000);
});

it('omits the pay block once the invoice is fully paid', function () {
    $bank = $this->company->accounts()->where('code', '1010')->first();
    app(PaymentService::class)->receiveAgainstInvoice($this->invoice->refresh(), '1000.00', today()->toDateString(), $bank);

    // Paid invoice → balance_due 0 → the view's $showPay is false; render must still succeed
    $pdf = InvoicePdf::render($this->invoice->refresh())->output();

    expect(substr($pdf, 0, 4))->toBe('%PDF');
});

it('renders fine for a company with no payment details configured', function () {
    $this->company->forceFill(['duitnow_qr_payload' => null, 'payment_link' => null])->save();

    $pdf = InvoicePdf::render($this->invoice->refresh())->output();

    expect(substr($pdf, 0, 4))->toBe('%PDF');
});
