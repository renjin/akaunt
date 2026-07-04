<?php

use App\Models\Company;
use App\Services\ChartOfAccountsTemplate;
use App\Services\HitPay\HitPayClient;
use App\Services\HitPay\HitPayService;
use App\Services\InvoiceService;
use Illuminate\Support\Facades\Http;

const HP_SALT = 'test-salt-for-hmac';

function signHitPayPayload(array $payload): array
{
    ksort($payload);
    $signable = '';
    foreach ($payload as $key => $value) {
        $signable .= $key . $value;
    }
    $payload['hmac'] = hash_hmac('sha256', $signable, HP_SALT);

    return $payload;
}

beforeEach(function () {
    $this->company = Company::create([
        'name' => 'HitPay Sdn Bhd', 'slug' => 'hp-' . uniqid(), 'legal_form' => 'sdn_bhd',
        'hitpay_api_key' => 'test_key', 'hitpay_salt' => HP_SALT, 'hitpay_environment' => 'sandbox',
    ]);
    ChartOfAccountsTemplate::seed($this->company);
    $this->company->forceFill([
        'hitpay_deposit_account_id' => $this->company->accounts()->where('code', '1010')->value('id'),
    ])->save();

    $customer = $this->company->parties()->create(['role' => 'customer', 'name' => 'Online Payer', 'email' => 'payer@example.my']);
    $income = $this->company->accounts()->where('code', '4100')->first();

    $this->invoice = $this->company->invoices()->create([
        'party_id' => $customer->id, 'invoice_number' => 'INV-00001', 'issue_date' => today()->toDateString(),
    ]);
    $this->invoice->lines()->create(['description' => 'Work', 'quantity' => 1, 'unit_price' => 1000, 'income_account_id' => $income->id]);
    app(InvoiceService::class)->approve($this->invoice->refresh());
    $this->invoice->refresh();

    $this->svc = app(HitPayService::class);
});

it('creates a checkout and stores the hosted URL on the invoice', function () {
    Http::fake([
        'api.sandbox.hit-pay.com/v1/payment-requests' => Http::response([
            'id' => 'req-uuid-1', 'url' => 'https://securecheckout.sandbox.hit-pay.com/payment-request/@x/checkout',
            'status' => 'pending',
        ]),
    ]);

    $this->svc->createCheckout($this->invoice);

    expect($this->invoice->refresh()->payment_url)->toContain('securecheckout')
        ->and($this->invoice->hitpay_payment_request_id)->toBe('req-uuid-1');

    Http::assertSent(fn ($request) => $request->hasHeader('X-BUSINESS-API-KEY', 'test_key')
        && $request->hasHeader('X-Requested-With', 'XMLHttpRequest')
        && $request['amount'] === '1000.00' // major units, the outstanding balance
        && $request['currency'] === 'MYR'
        && $request['reference_number'] === 'INV-00001');
});

it('verifies a correctly signed webhook and records the payment', function () {
    $this->invoice->forceFill(['hitpay_payment_request_id' => 'req-uuid-1'])->save();

    $payload = signHitPayPayload([
        'payment_id' => 'pay-1', 'payment_request_id' => 'req-uuid-1',
        'amount' => '1000.00', 'currency' => 'MYR', 'status' => 'completed',
        'reference_number' => 'INV-00001',
    ]);

    $this->post(route('webhooks.hitpay', $this->company), $payload)->assertOk();

    $this->invoice->refresh();
    expect($this->invoice->status)->toBe('paid')
        ->and($this->invoice->amount_paid)->toBe('1000.00')
        ->and($this->company->accounts()->where('code', '1010')->first()->balance())->toBe('1000.00')
        ->and($this->company->payments()->where('method', 'hitpay')->count())->toBe(1);
});

it('rejects a webhook with a bad signature', function () {
    $this->invoice->forceFill(['hitpay_payment_request_id' => 'req-uuid-1'])->save();

    $payload = signHitPayPayload([
        'payment_id' => 'pay-1', 'payment_request_id' => 'req-uuid-1',
        'amount' => '1000.00', 'currency' => 'MYR', 'status' => 'completed',
    ]);
    $payload['amount'] = '1.00'; // tampered after signing

    $this->post(route('webhooks.hitpay', $this->company), $payload)->assertStatus(400);

    expect($this->invoice->refresh()->status)->toBe('approved');
});

it('is idempotent on duplicate webhook deliveries', function () {
    $this->invoice->forceFill(['hitpay_payment_request_id' => 'req-uuid-1'])->save();
    $payload = signHitPayPayload([
        'payment_id' => 'pay-1', 'payment_request_id' => 'req-uuid-1',
        'amount' => '1000.00', 'currency' => 'MYR', 'status' => 'completed',
        'reference_number' => 'INV-00001',
    ]);

    $this->post(route('webhooks.hitpay', $this->company), $payload)->assertOk();
    $this->post(route('webhooks.hitpay', $this->company), $payload)->assertOk(); // second delivery

    expect($this->company->payments()->count())->toBe(1)
        ->and($this->invoice->refresh()->amount_paid)->toBe('1000.00');
});

it('ignores non-completed webhook statuses', function () {
    $this->invoice->forceFill(['hitpay_payment_request_id' => 'req-uuid-1'])->save();
    $payload = signHitPayPayload([
        'payment_id' => 'pay-1', 'payment_request_id' => 'req-uuid-1',
        'amount' => '1000.00', 'currency' => 'MYR', 'status' => 'failed',
    ]);

    $this->post(route('webhooks.hitpay', $this->company), $payload)->assertOk();

    expect($this->invoice->refresh()->status)->toBe('approved')
        ->and($this->company->payments()->count())->toBe(0);
});

it('poll fallback settles a completed checkout that missed its webhook', function () {
    $this->invoice->forceFill(['hitpay_payment_request_id' => 'req-uuid-1'])->save();
    Http::fake([
        'api.sandbox.hit-pay.com/v1/payment-requests/req-uuid-1' => Http::response([
            'id' => 'req-uuid-1', 'status' => 'completed', 'amount' => '1000.00', 'currency' => 'MYR',
        ]),
    ]);

    $updated = $this->svc->pollPending($this->company);

    expect($updated)->toBe(1)
        ->and($this->invoice->refresh()->status)->toBe('paid');
});

it('refuses a checkout for an unconfigured company or a settled invoice', function () {
    $bare = Company::create(['name' => 'NoHP', 'slug' => 'nohp-' . uniqid(), 'legal_form' => 'sdn_bhd']);

    expect(fn () => $this->svc->createCheckout($this->invoice->replicate()->setRelation('company', $bare)))
        ->toThrow(InvalidArgumentException::class);

    Http::fake();
    $this->invoice->forceFill(['amount_paid' => $this->invoice->total, 'status' => 'paid'])->save();
    expect(fn () => $this->svc->createCheckout($this->invoice->refresh()))
        ->toThrow(InvalidArgumentException::class, 'no outstanding balance');
});
