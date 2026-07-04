<?php

namespace App\Services\HitPay;

use App\Models\Company;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper over the HitPay v1 API.
 * Amounts are MAJOR units (100 = RM100.00) — not cents.
 */
class HitPayClient
{
    public function __construct(private Company $company)
    {
    }

    public function baseUrl(): string
    {
        return $this->company->hitpay_environment === 'production'
            ? 'https://api.hit-pay.com/v1'
            : 'https://api.sandbox.hit-pay.com/v1';
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())
            ->withHeaders([
                'X-BUSINESS-API-KEY' => $this->company->hitpay_api_key,
                'X-Requested-With' => 'XMLHttpRequest', // required per HitPay troubleshooting docs
            ])
            ->asJson()
            ->acceptJson()
            ->timeout(30);
    }

    public function createPaymentRequest(array $payload): Response
    {
        return $this->http()->post('/payment-requests', $payload);
    }

    public function getPaymentRequest(string $id): Response
    {
        return $this->http()->get("/payment-requests/{$id}");
    }

    /**
     * Verify a v1 webhook: HMAC-SHA256 over ksort'ed key.value concatenation
     * (no separators, `hmac` excluded), keyed with the API-key salt.
     */
    public function verifyWebhook(array $payload): bool
    {
        $hmac = $payload['hmac'] ?? '';
        unset($payload['hmac']);
        ksort($payload);

        $signable = '';
        foreach ($payload as $key => $value) {
            $signable .= $key . $value;
        }

        return hash_equals(
            hash_hmac('sha256', $signable, $this->company->hitpay_salt),
            (string) $hmac,
        );
    }
}
