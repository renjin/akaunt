<?php

namespace App\Services\HitPay;

use App\Models\Account;
use App\Models\Company;
use App\Models\Invoice;
use App\Services\PaymentService;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

class HitPayService
{
    public function __construct(private PaymentService $payments)
    {
    }

    /** Create a hosted checkout for an invoice's outstanding balance and store the URL on it. */
    public function createCheckout(Invoice $invoice): Invoice
    {
        $company = $invoice->company;
        if (! $company->hitpayConfigured()) {
            throw new InvalidArgumentException('HitPay is not configured for this company.');
        }
        if (! $invoice->isPosted()) {
            throw new InvalidArgumentException('Only approved invoices can have a checkout link.');
        }
        if ((float) $invoice->balance_due <= 0) {
            throw new InvalidArgumentException('Invoice has no outstanding balance.');
        }

        $webhook = route('webhooks.hitpay', $company);

        $client = new HitPayClient($company);
        $response = $client->createPaymentRequest(array_filter([
            'amount' => $invoice->balance_due,          // major units per HitPay docs
            'currency' => $invoice->currency,
            'purpose' => "Invoice {$invoice->invoice_number} — {$company->name}",
            'reference_number' => $invoice->invoice_number,
            'email' => $invoice->party->email,
            'name' => $invoice->party->name,
            // HitPay rejects localhost webhook URLs — omit in local dev; the hourly poll fallback settles instead
            'webhook' => str_contains($webhook, 'localhost') || str_contains($webhook, '127.0.0.1') ? null : $webhook,
        ]));

        if (! $response->successful() || ! $response->json('url')) {
            throw new RuntimeException('HitPay error: ' . ($response->json('message') ?? "HTTP {$response->status()}"));
        }

        $invoice->forceFill([
            'payment_url' => $response->json('url'),
            'hitpay_payment_request_id' => $response->json('id'),
        ])->save();

        return $invoice;
    }

    /**
     * Handle a v1 webhook callback. Idempotent: a second delivery for an
     * already-settled invoice is acknowledged without a duplicate payment.
     */
    public function handleWebhook(Company $company, array $payload): void
    {
        $client = new HitPayClient($company);
        if (! $client->verifyWebhook($payload)) {
            throw new InvalidArgumentException('Invalid HitPay webhook signature.');
        }
        if (($payload['status'] ?? '') !== 'completed') {
            return; // only completed payments post to the books
        }

        $invoice = $company->invoices()
            ->where('hitpay_payment_request_id', $payload['payment_request_id'] ?? '')
            ->first();
        if (! $invoice) {
            Log::warning('HitPay webhook for unknown payment request', ['payload' => $payload]);

            return;
        }
        if ((float) $invoice->balance_due <= 0) {
            return; // duplicate delivery — already recorded
        }
        if (($payload['currency'] ?? '') !== $invoice->currency) {
            throw new InvalidArgumentException('HitPay webhook currency mismatch.');
        }

        $this->payments->receiveAgainstInvoice(
            $invoice,
            (string) $payload['amount'],
            now()->toDateString(),
            $this->depositAccount($company),
            'hitpay',
            $payload['payment_id'] ?? null,
        );
    }

    /** Fallback for missed webhooks: poll in-flight checkouts and settle completed ones. */
    public function pollPending(Company $company): int
    {
        $client = new HitPayClient($company);
        $updated = 0;

        $pending = $company->invoices()
            ->whereNotNull('hitpay_payment_request_id')
            ->whereIn('status', ['approved', 'sent', 'partial'])
            ->get();

        foreach ($pending as $invoice) {
            $response = $client->getPaymentRequest($invoice->hitpay_payment_request_id);
            if (! $response->successful() || $response->json('status') !== 'completed') {
                continue;
            }
            if ((float) $invoice->balance_due <= 0) {
                continue;
            }
            $this->payments->receiveAgainstInvoice(
                $invoice,
                $invoice->balance_due, // completed request = full outstanding amount settled
                now()->toDateString(),
                $this->depositAccount($company),
                'hitpay',
                $invoice->hitpay_payment_request_id,
            );
            $updated++;
        }

        return $updated;
    }

    private function depositAccount(Company $company): Account
    {
        return Account::find($company->hitpay_deposit_account_id)
            ?? $company->accounts()->where('subtype', 'cash_bank')->where('active', true)->orderBy('code')->firstOrFail();
    }
}
