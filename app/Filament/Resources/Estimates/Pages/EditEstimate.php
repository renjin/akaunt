<?php

namespace App\Filament\Resources\Estimates\Pages;

use App\Filament\Pages\GeneralLedger;
use App\Filament\Resources\Estimates\EstimateActions;
use App\Filament\Resources\Estimates\EstimateResource;
use App\Models\Estimate;
use App\Models\JournalEntry;
use App\Services\EstimateService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;
use Illuminate\Support\HtmlString;

class EditEstimate extends EditRecord
{
    protected static string $resource = EstimateResource::class;

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    public function getSubheading(): HtmlString
    {
        $record = $this->getRecord();

        return new HtmlString(
            ucfirst($record->status)
            .' · <span style="font-variant-numeric:tabular-nums">Total MYR '
            .number_format((float) $record->total, 2).'</span>'
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('accountTransactions')
                ->label('Account transactions')
                ->icon('heroicon-o-book-open')
                ->url(fn (): ?string => $this->ledgerUrl($this->record))
                ->visible(fn (): bool => filled($this->ledgerUrl($this->record))),
            ActionGroup::make(EstimateActions::make())
                ->label('Actions')
                ->icon('heroicon-m-ellipsis-vertical')
                ->button(),
            DeleteAction::make()->visible(fn () => $this->record->status === 'draft'),
        ];
    }

    protected function afterSave(): void
    {
        if ($this->record->status !== 'converted') {
            app(EstimateService::class)->calculateTotals($this->record);
        }
    }

    private function ledgerUrl(Estimate $record): ?string
    {
        $invoice = $record->convertedInvoice;
        if (! $invoice || ! $invoice->isPosted()) {
            return null;
        }

        $entry = JournalEntry::query()
            ->with('lines.account')
            ->where('source_type', $invoice->getMorphClass())
            ->where('source_id', $invoice->id)
            ->first();

        $account = $entry?->lines
            ->first(fn ($line) => $line->account && $line->account->type === 'income')
            ?->account
            ?? $entry?->lines->first()?->account;

        if (! $account) {
            return null;
        }

        return GeneralLedger::getUrl([
            'account' => $account->id,
            'from' => $invoice->issue_date->toDateString(),
            'to' => $invoice->issue_date->toDateString(),
        ]);
    }
}
