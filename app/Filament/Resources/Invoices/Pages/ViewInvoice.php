<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Pages\GeneralLedger;
use App\Filament\Resources\Invoices\InvoiceActions;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Invoice;
use App\Models\JournalEntry;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\App;

/**
 * Wave-style invoice workflow page: status strip, Create/Send/Payments timeline
 * cards, then the rendered invoice document itself.
 */
class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    protected string $view = 'filament.pages.view-invoice';

    public function getHeading(): string|Htmlable
    {
        return "Invoice {$this->getRecord()->invoice_number}";
    }

    protected function getHeaderActions(): array
    {
        return [
            InvoiceActions::approve(),
            Action::make('createAnother')
                ->label('Create another invoice')
                ->icon('heroicon-o-plus')
                ->url(InvoiceResource::getUrl('create')),
            ActionGroup::make(InvoiceActions::make(except: [
                // These live on the timeline cards below, not in the dropdown.
                'approve', 'recordPayment', 'sendEmail', 'sendReminder',
            ]))
                ->label('More actions')
                ->icon('heroicon-m-chevron-down')
                ->iconPosition('after')
                ->button()
                ->color('gray'),
        ];
    }

    /** "Create" card: drafts are editable; posted invoices open the read-only form. */
    public function editInvoiceAction(): Action
    {
        $record = $this->getRecord();

        return Action::make('editInvoice')
            ->label($record->status === 'void' ? 'View invoice' : 'Edit invoice')
            ->button()
            ->color('gray')
            ->url(InvoiceResource::getUrl('edit', ['record' => $record]));
    }

    /** "Send" card: first send vs. resend. */
    public function sendEmailAction(): Action
    {
        return InvoiceActions::sendEmail()
            ->label(fn (Invoice $record) => $record->last_sent_at ? 'Resend invoice' : 'Email invoice')
            ->button()
            ->color('primary')
            ->record($this->getRecord());
    }

    /** "Payments" card. */
    public function recordPaymentAction(): Action
    {
        return InvoiceActions::recordPayment()
            ->button()
            ->record($this->getRecord());
    }

    /** "Payments" card, overdue invoices only (visibility handled by the action). */
    public function sendReminderAction(): Action
    {
        return InvoiceActions::sendReminder()
            ->label('Send a reminder now')
            ->button()
            ->record($this->getRecord());
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $invoice = $this->getRecord();
        $invoice->refresh();
        $invoice->load(['party', 'company', 'lines.taxCode', 'allocations.payment']);

        return [
            'invoice' => $invoice,
            'documentHtml' => $this->renderDocumentHtml($invoice),
            'ledgerLinks' => $this->ledgerLinks($invoice),
        ];
    }

    /** @return array<int, array{label: string, url: string, debit: string, credit: string}> */
    private function ledgerLinks(Invoice $invoice): array
    {
        if (! $invoice->isPosted()) {
            return [];
        }

        $entry = JournalEntry::query()
            ->with('lines.account')
            ->where('source_type', $invoice->getMorphClass())
            ->where('source_id', $invoice->id)
            ->first();

        if (! $entry) {
            return [];
        }

        return $entry->lines
            ->filter(fn ($line) => $line->account !== null)
            ->map(fn ($line) => [
                'label' => $line->account->code.' · '.$line->account->name,
                'url' => GeneralLedger::getUrl([
                    'account' => $line->account_id,
                    'from' => $invoice->issue_date->toDateString(),
                    'to' => $invoice->issue_date->toDateString(),
                ]),
                'debit' => $line->debit_base,
                'credit' => $line->credit_base,
            ])
            ->values()
            ->all();
    }

    /**
     * The same blade the PDF is generated from (see InvoicePdf::render), rendered
     * to HTML for an inline iframe preview so the user sees the real document.
     */
    protected function renderDocumentHtml(Invoice $invoice): string
    {
        $previous = App::getLocale();
        App::setLocale($invoice->company->document_locale ?? 'en');

        try {
            return view('pdf.invoice', [
                'invoice' => $invoice,
                'company' => $invoice->company,
                'party' => $invoice->party,
            ])->render();
        } finally {
            App::setLocale($previous);
        }
    }
}
