<?php

namespace App\Services\Einvoice;

use App\Models\EinvoiceSubmission;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * The human-gated e-Invoice pipeline:
 *   queue (pending_approval) → approve (a user reviews) → submit → poll → validated.
 * Nothing is transmitted to LHDN until a user has explicitly approved.
 */
class EinvoiceService
{
    public function __construct(private EinvoicePayloadMapper $mapper)
    {
    }

    private function clientFor(Invoice $invoice): EinvoiceClient
    {
        $credential = $invoice->company->einvoiceCredential;
        if (! $credential) {
            throw new InvalidArgumentException('No e-Invoice API credentials configured for this company.');
        }

        return new EinvoiceClient($credential);
    }

    /** Stage an approved invoice for review. Builds and snapshots the payload. */
    public function queueForApproval(Invoice $invoice): EinvoiceSubmission
    {
        if (! $invoice->company->einvoice_enabled) {
            throw new InvalidArgumentException('e-Invoicing is not enabled for this company.');
        }
        if ($invoice->submissions()->whereNotIn('status', ['rejected', 'failed', 'cancelled'])->exists()) {
            throw new InvalidArgumentException('This invoice already has an active e-Invoice submission.');
        }

        $payload = $this->mapper->map($invoice); // validates MYR + posted

        $submission = $invoice->submissions()->create([
            'company_id' => $invoice->company_id,
            'payload_snapshot' => $payload,
        ]);

        $invoice->forceFill(['einvoice_status' => 'pending_review'])->save();

        return $submission;
    }

    /** THE GATE: a user approves, and only then do we transmit. */
    public function approveAndSubmit(EinvoiceSubmission $submission, User $reviewer): EinvoiceSubmission
    {
        if ($submission->status !== 'pending_approval') {
            throw new InvalidArgumentException("Submission is not awaiting approval (is: {$submission->status}).");
        }

        $submission->forceFill([
            'status' => 'approved',
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ])->save();

        $invoice = $submission->invoice;
        $response = $this->clientFor($invoice)->createInvoice(
            $submission->payload_snapshot + ['direct_submit' => true]
        );

        if (! $response->successful() || ! ($response->json('success') ?? false)) {
            $submission->forceFill([
                'status' => 'failed',
                'rejected_reason' => $response->json('message') ?? "HTTP {$response->status()}",
                'response_snapshot' => $response->json() ?? ['body' => $response->body()],
            ])->save();
            $invoice->forceFill(['einvoice_status' => 'rejected'])->save();

            throw new RuntimeException('e-Invoice submission failed: ' . $submission->rejected_reason);
        }

        $submission->forceFill([
            'status' => 'submitted',
            'middleware_invoice_code' => $response->json('eInvoiceCode'),
            'einvoice_url' => $response->json('eInvoiceUrl'),
            'submitted_at' => now(),
            'response_snapshot' => $response->json(),
        ])->save();
        $invoice->forceFill(['einvoice_status' => 'submitted'])->save();

        return $submission;
    }

    /** Poll middleware status for all in-flight submissions of a company. */
    public function pollStatuses(\App\Models\Company $company): int
    {
        $inFlight = EinvoiceSubmission::query()
            ->where('company_id', $company->id)
            ->where('status', 'submitted')
            ->whereNotNull('middleware_invoice_code')
            ->get();

        if ($inFlight->isEmpty()) {
            return 0;
        }

        $client = new EinvoiceClient($company->einvoiceCredential);
        $response = $client->getInvoices();
        if (! $response->successful()) {
            return 0;
        }

        $byCode = collect($response->json('data') ?? $response->json() ?? [])
            ->keyBy(fn ($row) => $row['invoice_code'] ?? $row['eInvoiceCode'] ?? '');

        $updated = 0;
        foreach ($inFlight as $submission) {
            $row = $byCode->get($submission->middleware_invoice_code);
            if (! $row || ! isset($row['status'])) {
                continue;
            }
            $status = EinvoiceClient::STATUS_MAP[(int) $row['status']] ?? null;

            match ($status) {
                'validated' => $this->markValidated($submission, $row),
                'rejected' => $this->markRejected($submission, $row),
                'cancelled' => $this->markCancelled($submission),
                default => null,
            };
            $updated++;
        }

        return $updated;
    }

    public function cancel(EinvoiceSubmission $submission): EinvoiceSubmission
    {
        if (! in_array($submission->status, ['submitted', 'validated'])) {
            throw new InvalidArgumentException('Only submitted or validated e-Invoices can be cancelled.');
        }

        $response = $this->clientFor($submission->invoice)
            ->cancelInvoice($submission->middleware_invoice_code);

        if (! $response->successful() || ! ($response->json('success') ?? false)) {
            throw new RuntimeException('Cancellation failed: ' . ($response->json('message') ?? "HTTP {$response->status()}"));
        }

        $this->markCancelled($submission);

        return $submission;
    }

    private function markValidated(EinvoiceSubmission $submission, array $row): void
    {
        DB::transaction(function () use ($submission, $row) {
            $submission->forceFill([
                'status' => 'validated',
                'validated_at' => now(),
                'lhdn_uuid' => $row['uuid'] ?? $submission->lhdn_uuid,
            ])->save();
            $submission->invoice->forceFill(['einvoice_status' => 'validated'])->save();
        });

        $this->storeQr($submission);
    }

    /** Fetch and store the validated QR JPEG so the invoice PDF can embed it. */
    private function storeQr(EinvoiceSubmission $submission): void
    {
        try {
            $response = $this->clientFor($submission->invoice)->qrCode($submission->middleware_invoice_code);
            if ($response->successful()) {
                $path = "einvoice-qr/{$submission->id}.jpg";
                \Illuminate\Support\Facades\Storage::put($path, $response->body());
                $submission->forceFill(['qr_path' => $path])->save();
            }
        } catch (\Throwable) {
            // QR is cosmetic on the PDF; validation state is already recorded. Poll will not retry — refetch manually if needed.
        }
    }

    private function markRejected(EinvoiceSubmission $submission, array $row): void
    {
        DB::transaction(function () use ($submission, $row) {
            $submission->forceFill([
                'status' => 'rejected',
                'rejected_reason' => $row['reason'] ?? 'Rejected by LHDN validation.',
            ])->save();
            $submission->invoice->forceFill(['einvoice_status' => 'rejected'])->save();
        });
    }

    private function markCancelled(EinvoiceSubmission $submission): void
    {
        DB::transaction(function () use ($submission) {
            $submission->forceFill(['status' => 'cancelled'])->save();
            $submission->invoice->forceFill(['einvoice_status' => 'cancelled'])->save();
        });
    }
}
