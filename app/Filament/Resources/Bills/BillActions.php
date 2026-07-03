<?php

namespace App\Filament\Resources\Bills;

use App\Models\Account;
use App\Models\Bill;
use App\Services\BillService;
use App\Services\PaymentService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use InvalidArgumentException;

class BillActions
{
    /** @return array<Action> */
    public static function make(): array
    {
        return [
            Action::make('approve')
                ->label('Approve')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (Bill $record) => $record->status === 'draft')
                ->requiresConfirmation()
                ->modalDescription('Approving posts this bill to the ledger (SST folded into the expense).')
                ->action(function (Bill $record) {
                    try {
                        app(BillService::class)->approve($record);
                        Notification::make()->success()->title('Bill approved and posted.')->send();
                    } catch (InvalidArgumentException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                    }
                }),
            Action::make('payBill')
                ->label('Record payment')
                ->icon('heroicon-o-banknotes')
                ->color('primary')
                ->visible(fn (Bill $record) => in_array($record->status, ['approved', 'partial']))
                ->schema(fn (Bill $record) => [
                    TextInput::make('amount')
                        ->numeric()
                        ->required()
                        ->default($record->balance_due)
                        ->helperText("Balance due: {$record->currency} {$record->balance_due}"),
                    DatePicker::make('payment_date')->required()->default(today()),
                    Select::make('bank_account_id')
                        ->label('Pay from')
                        ->options(fn () => Filament::getTenant()->accounts()
                            ->where('subtype', 'cash_bank')->where('active', true)->orderBy('code')->get()
                            ->mapWithKeys(fn (Account $a) => [$a->id => "{$a->code} · {$a->name}"]))
                        ->required(),
                    Select::make('method')
                        ->options([
                            'fpx' => 'FPX', 'duitnow' => 'DuitNow', 'bank_transfer' => 'Bank transfer',
                            'cash' => 'Cash', 'card' => 'Card', 'cheque' => 'Cheque',
                        ])
                        ->default('bank_transfer')
                        ->required(),
                    TextInput::make('reference'),
                ])
                ->action(function (Bill $record, array $data) {
                    try {
                        app(PaymentService::class)->payBill(
                            $record,
                            $data['amount'],
                            $data['payment_date'],
                            Account::findOrFail($data['bank_account_id']),
                            $data['method'],
                            $data['reference'] ?? null,
                        );
                        Notification::make()->success()->title('Payment recorded.')->send();
                    } catch (InvalidArgumentException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                    }
                }),
            Action::make('void')
                ->label('Void')
                ->icon('heroicon-o-no-symbol')
                ->color('danger')
                ->visible(fn (Bill $record) => $record->isPosted() && (float) $record->amount_paid == 0)
                ->requiresConfirmation()
                ->modalDescription('Voiding reverses this bill from the ledger.')
                ->action(function (Bill $record) {
                    try {
                        app(BillService::class)->void($record);
                        Notification::make()->success()->title('Bill voided.')->send();
                    } catch (InvalidArgumentException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                    }
                }),
        ];
    }
}
