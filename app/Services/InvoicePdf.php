<?php

namespace App\Services;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;

class InvoicePdf
{
    public static function render(Invoice $invoice): \Barryvdh\DomPDF\PDF
    {
        $invoice->loadMissing(['lines.taxCode', 'party', 'company']);

        return Pdf::loadView('pdf.invoice', [
            'invoice' => $invoice,
            'company' => $invoice->company,
            'party' => $invoice->party,
        ]);
    }

    public static function filename(Invoice $invoice): string
    {
        return str_replace(['/', ' '], '-', $invoice->invoice_number) . '.pdf';
    }
}
