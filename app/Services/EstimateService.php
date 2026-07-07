<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Estimate;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Estimates carry NO ledger impact until converted to an invoice — matching
 * Wave's model. Draft → sent → accepted/expired → convert (one-way).
 */
class EstimateService
{
    public function nextNumber(Company $company): string
    {
        $last = $company->estimates()
            ->where('estimate_number', 'like', 'EST-%')
            ->orderByDesc('id')
            ->value('estimate_number');

        $n = $last ? (int) preg_replace('/\D/', '', substr($last, 4)) : 0;

        return 'EST-'.str_pad((string) ($n + 1), 5, '0', STR_PAD_LEFT);
    }

    public function calculateTotals(Estimate $estimate): Estimate
    {
        $subtotal = '0.00';
        $taxTotal = '0.00';

        $taxCodes = $estimate->company->taxCodes()->get()->keyBy('id');

        foreach ($estimate->lines as $line) {
            $lineTotal = bcsub(
                bcmul((string) $line->quantity, (string) $line->unit_price, 2),
                '0.00', 2
            );

            // Multi-tax: sum every selected code's calculated tax against the line total.
            $ids = $line->effectiveTaxCodeIds();
            $taxAmount = '0.00';
            foreach ($ids as $id) {
                if ($code = $taxCodes->get($id)) {
                    $taxAmount = bcadd($taxAmount, $code->calculate((float) $lineTotal), 2);
                }
            }

            $line->forceFill([
                'line_total' => $lineTotal,
                'tax_amount' => $taxAmount,
                // Keep the single FK pointing at the first selected code for back-compat.
                'tax_code_id' => $ids[0] ?? null,
            ])->save();

            $subtotal = bcadd($subtotal, $lineTotal, 2);
            $taxTotal = bcadd($taxTotal, $taxAmount, 2);
        }

        $estimate->forceFill([
            'subtotal' => $subtotal,
            'tax_total' => $taxTotal,
            'total' => bcadd($subtotal, $taxTotal, 2),
        ])->save();

        return $estimate->refresh();
    }

    public function send(Estimate $estimate): Estimate
    {
        if ($estimate->status !== 'draft') {
            throw new InvalidArgumentException("Only draft estimates can be sent (is: {$estimate->status}).");
        }
        $estimate->forceFill(['status' => 'sent'])->save();

        return $estimate;
    }

    public function accept(Estimate $estimate): Estimate
    {
        if ($estimate->status !== 'sent') {
            throw new InvalidArgumentException("Only sent estimates can be accepted (is: {$estimate->status}).");
        }
        $estimate->forceFill(['status' => 'accepted'])->save();

        return $estimate;
    }

    public function expire(Estimate $estimate): Estimate
    {
        if ($estimate->status !== 'sent') {
            throw new InvalidArgumentException("Only sent estimates can expire (is: {$estimate->status}).");
        }
        $estimate->forceFill(['status' => 'expired'])->save();

        return $estimate;
    }

    /** One-way conversion into a draft invoice carrying the estimate's lines. */
    public function convertToInvoice(Estimate $estimate, InvoiceService $invoices): Invoice
    {
        if ($estimate->status !== 'accepted') {
            throw new InvalidArgumentException("Only accepted estimates can be converted (is: {$estimate->status}).");
        }

        return DB::transaction(function () use ($estimate, $invoices) {
            $invoice = $estimate->company->invoices()->create([
                'party_id' => $estimate->party_id,
                'invoice_number' => $invoices->nextNumber($estimate->company),
                'issue_date' => today()->toDateString(),
                'due_date' => today()->addDays(30)->toDateString(),
                'currency' => $estimate->currency,
                'fx_rate' => $estimate->fx_rate,
                'notes' => $estimate->notes,
            ]);

            foreach ($estimate->lines as $line) {
                $invoice->lines()->create([
                    'item_id' => $line->item_id,
                    'description' => $line->description,
                    'quantity' => $line->quantity,
                    'unit_price' => $line->unit_price,
                    'tax_code_id' => $line->tax_code_id,
                    'classification_code' => $line->classification_code,
                    'income_account_id' => $line->income_account_id,
                ]);
            }

            $invoices->calculateTotals($invoice->refresh());

            $estimate->forceFill(['status' => 'converted', 'converted_invoice_id' => $invoice->id])->save();

            return $invoice;
        });
    }
}
