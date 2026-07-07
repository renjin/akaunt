<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Invoices\Concerns\PreviewsInvoice;
use App\Filament\Resources\Invoices\InvoiceActions;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Services\InvoiceService;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;
use Illuminate\Support\HtmlString;

class EditInvoice extends EditRecord
{
    use PreviewsInvoice;

    protected static string $resource = InvoiceResource::class;

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    public function getSubheading(): HtmlString
    {
        $record = $this->getRecord();
        $fmt = fn ($n) => $record->currency.' '.number_format((float) $n, 2);

        $note = match (true) {
            $record->status === 'void' => '<span style="color:rgb(180,83,9)">Void — read-only.</span>',
            $record->status !== 'draft' => '<span style="color:rgb(107,107,107)">Posted — saving updates the ledger entry.</span>',
            default => '',
        };

        return new HtmlString(
            '<span style="font-variant-numeric:tabular-nums">Total '.e($fmt($record->total))
            .' · Paid '.e($fmt($record->amount_paid))
            .' · Balance '.e($fmt($record->balance_due)).'</span>'
            .($note ? ' &nbsp;·&nbsp; '.$note : '')
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->previewAction(),
            ActionGroup::make(InvoiceActions::make())
                ->label('Actions')
                ->icon('heroicon-m-ellipsis-vertical')
                ->button(),
            DeleteAction::make()->visible(fn () => $this->record->status === 'draft'),
        ];
    }

    protected function afterSave(): void
    {
        if ($this->record->status === 'draft') {
            app(InvoiceService::class)->calculateTotals($this->record);

            return;
        }

        if ($this->record->status !== 'void') {
            app(InvoiceService::class)->repost($this->record);
            Notification::make()
                ->title('Ledger updated')
                ->body('The invoice was re-posted to the ledger with your changes.')
                ->success()
                ->send();
        }
    }
}
