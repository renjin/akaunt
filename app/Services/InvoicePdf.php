<?php

namespace App\Services;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\App;

class InvoicePdf
{
    /** Rendered in the issuing company's document_locale (e.g. 'ms' for Bahasa Malaysia), not the viewer's UI locale. */
    public static function render(Invoice $invoice): \Barryvdh\DomPDF\PDF
    {
        $invoice->loadMissing(['lines.taxCode', 'party', 'company']);

        $previous = App::getLocale();
        App::setLocale($invoice->company->document_locale ?? 'en');
        try {
            return Pdf::loadView('pdf.invoice', [
                'invoice' => $invoice,
                'company' => $invoice->company,
                'party' => $invoice->party,
            ]);
        } finally {
            App::setLocale($previous);
        }
    }

    public static function filename(Invoice $invoice): string
    {
        return str_replace(['/', ' '], '-', $invoice->invoice_number) . '.pdf';
    }
}
