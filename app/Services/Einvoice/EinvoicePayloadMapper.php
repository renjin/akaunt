<?php

namespace App\Services\Einvoice;

use App\Models\Invoice;
use InvalidArgumentException;

/**
 * Maps our invoice to the einvoiceapp.my flat payload.
 * Seller identity (TIN/SST/MSIC) is implicit from the API credentials —
 * never sent per-invoice. MYR only until the vendor documents FX fields.
 */
class EinvoicePayloadMapper
{
    public function map(Invoice $invoice): array
    {
        if ($invoice->currency !== 'MYR') {
            // ponytail: middleware has no documented FX field — MYR only until vendor confirms
            throw new InvalidArgumentException('e-Invoice submission currently supports MYR invoices only.');
        }
        if (! $invoice->isPosted()) {
            throw new InvalidArgumentException('Only approved invoices can be submitted as e-Invoices.');
        }

        $party = $invoice->party;

        $items = $invoice->lines->map(fn ($line) => [
            'name' => $line->description,
            'price' => (string) $line->unit_price,
            'quantity' => (int) $line->quantity,
            'item_tax_total' => (string) $line->tax_amount,
            'item_price_total' => (string) $line->line_total,
            'classification_code' => $line->classification_code ?: '008',
        ])->values()->all();

        return array_filter([
            'order_id' => $invoice->invoice_number,
            'order_date' => ($invoice->issue_time_utc ?? $invoice->issue_date)->format('Y-m-d H:i:s'),
            'customer_name' => $party->name,
            'telephone' => $party->phone,
            'email' => $party->email,
            'identification_type' => $party->registration_scheme,
            'identification_value' => $party->registration_number,
            'tin_no' => $party->tin,
            'sst_no' => $party->sst_registration_no,
            'address1' => $party->address_line1,
            'address2' => $party->address_line2,
            'city' => $party->city,
            'postcode' => $party->postcode,
            'state' => $party->state,
            'country' => 'Malaysia',
            'country_code' => $party->country_code ?: 'MY',
            'currency_code' => 'MYR',
            'order_tax_total' => (string) $invoice->tax_total,
            'order_total' => (string) $invoice->total,
            'items' => $items,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
