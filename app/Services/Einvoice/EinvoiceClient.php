<?php

namespace App\Services\Einvoice;

use App\Models\EinvoiceCredential;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin HTTP wrapper for the einvoiceapp.my (O2O E-Invoice) middleware.
 * Auth is HTTP Basic; one credential pair = one legal entity/TIN.
 * Status codes: 0 created, 1 validated, 2 submitted, 3 invalid, 4 cancelled.
 */
class EinvoiceClient
{
    public const STATUS_MAP = [
        0 => 'created',
        1 => 'validated',
        2 => 'submitted',
        3 => 'rejected',
        4 => 'cancelled',
    ];

    public function __construct(private EinvoiceCredential $credential)
    {
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->credential->baseUrl())
            ->withBasicAuth($this->credential->keyid, $this->credential->keysecret)
            ->acceptJson()
            ->timeout(30);
    }

    public function clientDetails(): Response
    {
        return $this->http()->get('/api/get_client_details');
    }

    /** Submit an invoice. direct_submit=true sends it straight to LHDN. */
    public function createInvoice(array $payload): Response
    {
        return $this->http()->post('/api/create_invoice', $payload);
    }

    /** @param array $filters e.g. ['invoice_code' => ..., 'from' => ..., 'to' => ...] */
    public function getInvoices(array $filters = []): Response
    {
        return $this->http()->get('/api/get_invoices', $filters);
    }

    public function cancelInvoice(string $invoiceCode): Response
    {
        return $this->http()->post('/api/cancel_invoice', ['invoice_code' => $invoiceCode]);
    }

    public function createCreditNote(array $payload): Response
    {
        return $this->http()->post('/api/create_credit_note', $payload);
    }

    public function createDebitNote(array $payload): Response
    {
        return $this->http()->post('/api/create_debit_note', $payload);
    }

    public function createRefundNote(array $payload): Response
    {
        return $this->http()->post('/api/create_refund_note', $payload);
    }

    /** QR JPEG bytes for a validated document. */
    public function qrCode(string $invoiceCode): Response
    {
        return $this->http()->get("/qr/{$invoiceCode}");
    }
}
