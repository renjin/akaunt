<?php

use App\Models\Company;
use App\Models\User;
use App\Services\ChartOfAccountsTemplate;
use App\Services\Einvoice\EinvoiceService;
use App\Services\InvoiceService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->company = Company::create([
        'name' => 'EInv Sdn Bhd', 'slug' => 'einv-' . uniqid(), 'legal_form' => 'sdn_bhd',
        'tin' => 'C9876543210', 'msic_code' => '62010',
        'einvoice_enabled' => true,
    ]);
    ChartOfAccountsTemplate::seed($this->company);
    $this->company->einvoiceCredential()->create([
        'keyid' => 'keyid', 'keysecret' => 'keysecret', 'environment' => 'staging',
    ]);

    $customer = $this->company->parties()->create([
        'role' => 'customer', 'name' => 'Buyer Sdn Bhd',
        'registration_scheme' => 'BRN', 'registration_number' => '202301099999',
        'tin' => 'C1111111111', 'email' => 'buyer@example.my',
        'address_line1' => '1 Jalan Test', 'city' => 'Petaling Jaya',
        'postcode' => '46000', 'state' => 'Selangor', 'country_code' => 'MY',
    ]);

    $tax = $this->company->taxCodes()->where('name', 'Service Tax 8%')->first();
    $this->invoice = $this->company->invoices()->create([
        'party_id' => $customer->id, 'invoice_number' => 'INV-00001', 'issue_date' => today()->toDateString(),
    ]);
    $this->invoice->lines()->create([
        'description' => 'Consulting', 'quantity' => 1, 'unit_price' => 1000,
        'tax_code_id' => $tax->id, 'classification_code' => '008',
    ]);
    app(InvoiceService::class)->approve($this->invoice->refresh());
    $this->invoice->refresh();

    $this->svc = app(EinvoiceService::class);
    $this->reviewer = User::factory()->create();
});

it('builds the middleware payload with buyer identity and classification codes', function () {
    $submission = $this->svc->queueForApproval($this->invoice);
    $p = $submission->payload_snapshot;

    expect($p['order_id'])->toBe('INV-00001')
        ->and($p['tin_no'])->toBe('C1111111111')
        ->and($p['identification_type'])->toBe('BRN')
        ->and($p['currency_code'])->toBe('MYR')
        ->and($p['order_tax_total'])->toBe('80.00')
        ->and($p['order_total'])->toBe('1080.00')
        ->and($p['items'][0]['classification_code'])->toBe('008')
        ->and($p)->not->toHaveKey('direct_submit') // nothing auto-submits at queue time
        ->and($this->invoice->refresh()->einvoice_status)->toBe('pending_review');
});

it('does NOT transmit until a human approves', function () {
    Http::fake();

    $this->svc->queueForApproval($this->invoice);

    Http::assertNothingSent();
});

it('transmits after approval and stores the middleware response', function () {
    Http::fake([
        '*/api/create_invoice' => Http::response([
            'success' => true, 'message' => 'e-Invoice created successfully',
            'eInvoiceCode' => 'abc123', 'eInvoiceUrl' => 'https://dev-api.einvoiceapp.my/invoice/abc123',
        ]),
    ]);

    $submission = $this->svc->queueForApproval($this->invoice);
    $this->svc->approveAndSubmit($submission, $this->reviewer);
    $submission->refresh();

    expect($submission->status)->toBe('submitted')
        ->and($submission->middleware_invoice_code)->toBe('abc123')
        ->and($submission->reviewed_by)->toBe($this->reviewer->id)
        ->and($this->invoice->refresh()->einvoice_status)->toBe('submitted');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/api/create_invoice')
            && $request['direct_submit'] === true
            && $request['order_id'] === 'INV-00001'
            && $request->hasHeader('Authorization'); // Basic auth
    });
});

it('marks failed when the middleware rejects the create', function () {
    Http::fake([
        '*/api/create_invoice' => Http::response(['success' => false, 'message' => 'Invoice already exists'], 400),
    ]);

    $submission = $this->svc->queueForApproval($this->invoice);

    expect(fn () => $this->svc->approveAndSubmit($submission, $this->reviewer))
        ->toThrow(RuntimeException::class);

    expect($submission->refresh()->status)->toBe('failed')
        ->and($submission->rejected_reason)->toBe('Invoice already exists');
});

it('polls and rolls to validated', function () {
    Http::fake([
        '*/api/create_invoice' => Http::response([
            'success' => true, 'eInvoiceCode' => 'abc123', 'eInvoiceUrl' => 'https://x/abc123',
        ]),
        '*/api/get_invoices*' => Http::response([
            'data' => [
                ['invoice_code' => 'abc123', 'status' => 1, 'uuid' => 'LHDN-UUID-1'],
            ],
        ]),
    ]);

    $submission = $this->svc->queueForApproval($this->invoice);
    $this->svc->approveAndSubmit($submission, $this->reviewer);

    $updated = $this->svc->pollStatuses($this->company);

    expect($updated)->toBe(1)
        ->and($submission->refresh()->status)->toBe('validated')
        ->and($submission->lhdn_uuid)->toBe('LHDN-UUID-1')
        ->and($this->invoice->refresh()->einvoice_status)->toBe('validated');
});

it('cancels a submitted e-invoice through the middleware', function () {
    Http::fake([
        '*/api/create_invoice' => Http::response(['success' => true, 'eInvoiceCode' => 'abc123', 'eInvoiceUrl' => 'https://x']),
        '*/api/cancel_invoice' => Http::response(['success' => true, 'message' => 'cancelled']),
    ]);

    $submission = $this->svc->queueForApproval($this->invoice);
    $this->svc->approveAndSubmit($submission, $this->reviewer);
    $this->svc->cancel($submission->refresh());

    expect($submission->refresh()->status)->toBe('cancelled')
        ->and($this->invoice->refresh()->einvoice_status)->toBe('cancelled');
});

it('refuses to queue when e-invoicing is disabled (RM1m exemption default)', function () {
    $this->company->forceFill(['einvoice_enabled' => false])->save();
    $this->invoice->company->refresh();

    $this->svc->queueForApproval($this->invoice->refresh());
})->throws(InvalidArgumentException::class, 'not enabled');

it('refuses non-MYR invoices (middleware has no FX field)', function () {
    $this->invoice->forceFill(['currency' => 'USD'])->save();

    $this->svc->queueForApproval($this->invoice->refresh());
})->throws(InvalidArgumentException::class, 'MYR');
