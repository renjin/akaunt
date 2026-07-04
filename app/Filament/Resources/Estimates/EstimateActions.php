<?php

namespace App\Filament\Resources\Estimates;

use App\Models\Estimate;
use App\Services\EstimateService;
use App\Services\InvoiceService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Redirect;
use InvalidArgumentException;

class EstimateActions
{
    /** @return array<Action> */
    public static function make(): array
    {
        return [
            Action::make('send')
                ->label('Mark as sent')
                ->icon('heroicon-o-paper-airplane')
                ->visible(fn (Estimate $record) => $record->status === 'draft')
                ->action(function (Estimate $record) {
                    try {
                        app(EstimateService::class)->send($record);
                        Notification::make()->success()->title('Estimate marked as sent.')->send();
                    } catch (InvalidArgumentException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                    }
                }),
            Action::make('accept')
                ->label('Mark as accepted')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (Estimate $record) => $record->status === 'sent')
                ->action(function (Estimate $record) {
                    try {
                        app(EstimateService::class)->accept($record);
                        Notification::make()->success()->title('Estimate accepted.')->send();
                    } catch (InvalidArgumentException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                    }
                }),
            Action::make('expire')
                ->label('Mark as expired')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (Estimate $record) => $record->status === 'sent')
                ->requiresConfirmation()
                ->action(function (Estimate $record) {
                    try {
                        app(EstimateService::class)->expire($record);
                        Notification::make()->success()->title('Estimate marked as expired.')->send();
                    } catch (InvalidArgumentException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                    }
                }),
            Action::make('convert')
                ->label('Convert to invoice')
                ->icon('heroicon-o-arrow-right-circle')
                ->color('primary')
                ->visible(fn (Estimate $record) => $record->status === 'accepted')
                ->requiresConfirmation()
                ->modalDescription('This creates a draft invoice from the estimate. The conversion is one-way.')
                ->action(function (Estimate $record) {
                    try {
                        $invoice = app(EstimateService::class)->convertToInvoice($record, app(InvoiceService::class));
                        Notification::make()->success()->title("Converted to draft invoice {$invoice->invoice_number}.")->send();

                        return Redirect::to(\App\Filament\Resources\Invoices\InvoiceResource::getUrl('edit', ['record' => $invoice]));
                    } catch (InvalidArgumentException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                    }
                }),
        ];
    }
}
