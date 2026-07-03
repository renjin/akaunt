<?php

use App\Mail\InvoiceMail;
use App\Models\Company;
use App\Services\ChartOfAccountsTemplate;
use App\Services\InvoicePdf;
use App\Services\InvoiceService;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->company = Company::create([
        'name' => 'Agency Sdn Bhd', 'slug' => 'agency-' . uniqid(), 'legal_form' => 'sdn_bhd',
        'sst_registration_no' => 'W10-1234-56789012',
    ]);
    ChartOfAccountsTemplate::seed($this->company);
    $customer = $this->company->parties()->create([
        'role' => 'customer', 'name' => 'Client Sdn Bhd', 'email' => 'client@example.my',
    ]);
    $tax = $this->company->taxCodes()->where('name', 'Service Tax 8%')->first();

    $this->invoice = $this->company->invoices()->create([
        'party_id' => $customer->id, 'invoice_number' => 'INV-00001',
        'issue_date' => '2026-07-01', 'due_date' => '2026-07-31',
    ]);
    $this->invoice->lines()->create([
        'description' => 'Retainer', 'quantity' => 1, 'unit_price' => 1000, 'tax_code_id' => $tax->id,
    ]);
    app(InvoiceService::class)->approve($this->invoice->refresh());
});

it('renders a PDF with the SST-inclusive total', function () {
    $output = InvoicePdf::render($this->invoice->refresh())->output();

    expect(strlen($output))->toBeGreaterThan(1000)
        ->and(substr($output, 0, 4))->toBe('%PDF');
});

it('emails the invoice with PDF attached', function () {
    Mail::fake();

    Mail::to('client@example.my')->send(new InvoiceMail($this->invoice->refresh()));

    Mail::assertSent(InvoiceMail::class, fn (InvoiceMail $mail) => $mail->invoice->is($this->invoice)
        && $mail->envelope()->subject === 'Agency Sdn Bhd — Invoice INV-00001'
        && count($mail->attachments()) === 1);
});
