<?php

namespace App\Filament\Resources\PurchaseOrders;

use App\Filament\Resources\Bills\BillResource;
use App\Models\PurchaseOrder;
use App\Services\BillService;
use App\Services\PurchaseOrderService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Redirect;
use InvalidArgumentException;

class PurchaseOrderActions
{
    /** @return array<Action> */
    public static function make(): array
    {
        return [
            Action::make('send')
                ->label('Mark as sent')
                ->icon('heroicon-o-paper-airplane')
                ->visible(fn (PurchaseOrder $record): bool => $record->status === 'draft')
                ->action(function (PurchaseOrder $record) {
                    try {
                        app(PurchaseOrderService::class)->send($record);
                        Notification::make()->success()->title('Purchase order marked as sent.')->send();
                    } catch (InvalidArgumentException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                    }
                }),
            Action::make('approve')
                ->label('Approve')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (PurchaseOrder $record): bool => in_array($record->status, ['draft', 'sent'], true))
                ->action(function (PurchaseOrder $record) {
                    try {
                        app(PurchaseOrderService::class)->approve($record);
                        Notification::make()->success()->title('Purchase order approved.')->send();
                    } catch (InvalidArgumentException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                    }
                }),
            Action::make('convert')
                ->label('Convert to bill')
                ->icon('heroicon-o-arrow-right-circle')
                ->color('primary')
                ->visible(fn (PurchaseOrder $record): bool => $record->status === 'approved')
                ->requiresConfirmation()
                ->modalDescription('This creates a draft bill from the purchase order. The ledger is untouched until the bill is approved.')
                ->action(function (PurchaseOrder $record) {
                    try {
                        $bill = app(PurchaseOrderService::class)->convertToBill($record, app(BillService::class));
                        Notification::make()->success()->title('Converted to draft bill.')->send();

                        return Redirect::to(BillResource::getUrl('edit', ['record' => $bill]));
                    } catch (InvalidArgumentException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                    }
                }),
            Action::make('cancel')
                ->label('Cancel')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (PurchaseOrder $record): bool => ! in_array($record->status, ['cancelled', 'converted'], true))
                ->requiresConfirmation()
                ->action(function (PurchaseOrder $record) {
                    try {
                        app(PurchaseOrderService::class)->cancel($record);
                        Notification::make()->success()->title('Purchase order cancelled.')->send();
                    } catch (InvalidArgumentException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                    }
                }),
        ];
    }
}
