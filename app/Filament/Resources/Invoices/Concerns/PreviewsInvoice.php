<?php

namespace App\Filament\Resources\Invoices\Concerns;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Party;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\App;
use Illuminate\Support\HtmlString;

/** Shared "Preview" header action rendering the live form state through the invoice PDF blade. */
trait PreviewsInvoice
{
    protected function previewAction(): Action
    {
        return Action::make('preview')
            ->label('Preview')
            ->icon('heroicon-o-eye')
            ->color('gray')
            ->modalHeading('Invoice preview')
            ->modalWidth('5xl')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalContent(fn () => $this->renderPreview());
    }

    protected function renderPreview(): HtmlString
    {
        $state = $this->form->getRawState();
        $company = Filament::getTenant();
        $party = filled($state['party_id'] ?? null) ? Party::find($state['party_id']) : null;

        if (! $party) {
            return new HtmlString('<p style="padding:1rem 0">Choose a customer first to preview this invoice.</p>');
        }

        $rates = $company->taxCodes()->pluck('rate', 'id');
        $subtotal = '0.00';
        $tax = '0.00';

        $lines = collect($state['lines'] ?? [])->map(function (array $line) use ($rates, &$subtotal, &$tax) {
            $qty = is_numeric($line['quantity'] ?? null) ? (string) $line['quantity'] : '0';
            $price = is_numeric($line['unit_price'] ?? null) ? (string) $line['unit_price'] : '0';
            $lineTotal = bcmul($qty, $price, 2);
            $rate = (float) ($rates[$line['tax_code_id'] ?? null] ?? 0);
            $lineTax = number_format((float) $lineTotal * $rate / 100, 2, '.', '');
            $subtotal = bcadd($subtotal, $lineTotal, 2);
            $tax = bcadd($tax, $lineTax, 2);

            return new InvoiceLine([
                'description' => $line['description'] ?? '',
                'quantity' => $qty,
                'unit_price' => $price,
                'tax_amount' => $lineTax,
                'line_total' => $lineTotal,
            ]);
        })->values();

        $invoice = new Invoice([
            'invoice_number' => $state['invoice_number'] ?? 'INV-DRAFT',
            'po_number' => $state['po_number'] ?? null,
            'issue_date' => $state['issue_date'] ?? today()->toDateString(),
            'due_date' => $state['due_date'] ?? null,
            'currency' => $state['currency'] ?? 'MYR',
            'notes' => $state['notes'] ?? null,
            'subtotal' => $subtotal,
            'tax_total' => $tax,
            'total' => bcadd($subtotal, $tax, 2),
        ]);
        $invoice->setRelation('lines', $lines);
        $invoice->setRelation('party', $party);
        $invoice->setRelation('company', $company);
        $invoice->setRelation('submission', null);

        // Same locale rule as InvoicePdf::render() so the preview matches the PDF.
        $previous = App::getLocale();
        App::setLocale($company->document_locale ?? 'en');
        try {
            $html = view('pdf.invoice', [
                'invoice' => $invoice,
                'company' => $company,
                'party' => $party,
            ])->render();
        } finally {
            App::setLocale($previous);
        }

        return new HtmlString(
            '<iframe srcdoc="'.htmlspecialchars($html, ENT_QUOTES).'"'
            .' style="width:100%;height:70vh;border:0;background:#fff;border-radius:8px"></iframe>'
        );
    }
}
