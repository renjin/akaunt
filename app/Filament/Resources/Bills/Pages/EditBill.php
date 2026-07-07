<?php

namespace App\Filament\Resources\Bills\Pages;

use App\Filament\Resources\Bills\BillActions;
use App\Filament\Resources\Bills\BillResource;
use App\Services\BillService;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;
use Illuminate\Support\HtmlString;

class EditBill extends EditRecord
{
    protected static string $resource = BillResource::class;

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
            $record->status !== 'draft' => '<span style="color:rgb(107,107,107)">Posted — the ledger entry is locked; approve/pay from Actions.</span>',
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
            ActionGroup::make(BillActions::make())
                ->label('Actions')
                ->icon('heroicon-m-ellipsis-vertical')
                ->button(),
            DeleteAction::make()->visible(fn () => $this->record->status === 'draft'),
        ];
    }

    protected function afterSave(): void
    {
        if ($this->record->status === 'draft') {
            app(BillService::class)->calculateTotals($this->record);
        }
    }
}
