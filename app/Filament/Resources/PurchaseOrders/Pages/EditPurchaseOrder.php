<?php

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Filament\Resources\PurchaseOrders\PurchaseOrderActions;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Services\PurchaseOrderService;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;
use Illuminate\Support\HtmlString;

class EditPurchaseOrder extends EditRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    public function getSubheading(): HtmlString
    {
        $record = $this->getRecord();

        return new HtmlString(
            ucfirst($record->status)
            .' · <span style="font-variant-numeric:tabular-nums">Total '
            .e($record->currency.' '.number_format((float) $record->total, 2)).'</span>'
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make(PurchaseOrderActions::make())
                ->label('Actions')
                ->icon('heroicon-m-ellipsis-vertical')
                ->button(),
            DeleteAction::make()->visible(fn () => $this->record->status === 'draft'),
        ];
    }

    protected function afterSave(): void
    {
        if ($this->record->status !== 'converted') {
            app(PurchaseOrderService::class)->calculateTotals($this->record);
        }
    }
}
