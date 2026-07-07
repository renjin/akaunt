<?php

namespace App\Filament\Resources\Invoices;

use App\Mail\InvoiceMail;
use App\Models\Account;
use App\Models\Invoice;
use App\Services\CreditNoteService;
use App\Services\Einvoice\EinvoiceService;
use App\Services\HitPay\HitPayService;
use App\Services\InvoicePdf;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\HtmlString;
use InvalidArgumentException;

/** Lifecycle actions shared by the invoice table and edit page. */
class InvoiceActions
{
    /**
     * @param  array<string>  $except  action names to leave out (e.g. when rendered standalone elsewhere)
     * @return array<Action>
     */
    public static function make(array $except = []): array
    {
        $actions = [
            self::approve(),
            self::recordPayment(),
            self::sendEmail(),
            self::sendReminder(),
            self::downloadPdf(),
            self::createPaymentLink(),
            self::createCreditNote(),
            self::queueEinvoice(),
            self::submitEinvoice(),
            self::cancelEinvoice(),
            self::void(),
        ];

        return array_values(array_filter(
            $actions,
            fn (Action $action) => ! in_array($action->getName(), $except, true),
        ));
    }

    public static function createPaymentLink(): Action
    {
        return Action::make('createPaymentLink')
            ->label('Create payment link')
            ->icon('heroicon-o-link')
            ->visible(fn (Invoice $record) => $record->isPosted()
                && ! $record->isCreditNote()
                && (float) $record->balance_due > 0
                && $record->company->hitpayConfigured()
                && blank($record->payment_url))
            ->requiresConfirmation()
            ->modalDescription(fn (Invoice $record) => "Creates a HitPay checkout for {$record->currency} {$record->balance_due}. The link goes on the PDF and email; payment records automatically when the customer pays.")
            ->action(function (Invoice $record) {
                try {
                    app(HitPayService::class)->createCheckout($record);
                    Notification::make()->success()
                        ->title('Payment link created.')
                        ->body($record->refresh()->payment_url)
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                }
            });
    }

    public static function createCreditNote(): Action
    {
        return Action::make('createCreditNote')
            ->label('Create credit note')
            ->icon('heroicon-o-receipt-refund')
            ->color('warning')
            ->visible(fn (Invoice $record) => ! $record->isCreditNote()
                && $record->isPosted()
                && bccomp($record->balance_due, '0', 2) === 1)
            ->modalHeading('Create credit note')
            ->modalDescription(fn (Invoice $record) => "Reduces {$record->invoice_number}'s outstanding balance. Cannot exceed the unpaid amount ({$record->currency} {$record->balance_due}). Paid amounts need a refund, which is out of scope here.")
            ->schema([
                DatePicker::make('issue_date')->required()->default(today()),
                Repeater::make('lines')
                    ->schema([
                        TextInput::make('description')->required()->default('Credit / correction')->columnSpan(2),
                        TextInput::make('quantity')->numeric()->default(1),
                        TextInput::make('unit_price')->numeric()->required(),
                        Select::make('tax_code_id')
                            ->label('Tax')
                            ->options(fn () => Filament::getTenant()->taxCodes()->where('active', true)->pluck('name', 'id')),
                        Select::make('income_account_id')
                            ->label('Income account')
                            ->options(fn () => Filament::getTenant()->accounts()
                                ->where('type', 'income')->where('active', true)->orderBy('code')->get()
                                ->mapWithKeys(fn (Account $a) => [$a->id => "{$a->code} · {$a->name}"])),
                    ])
                    ->columns(5)
                    ->defaultItems(1)
                    ->columnSpanFull(),
            ])
            ->action(function (Invoice $record, array $data) {
                try {
                    $svc = app(CreditNoteService::class);
                    $creditNote = $svc->create($record, $data['issue_date'], $data['lines']);
                    $svc->approve($creditNote);
                    Notification::make()->success()->title("Credit note {$creditNote->invoice_number} created and posted.")->send();
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                }
            });
    }

    public static function queueEinvoice(): Action
    {
        return Action::make('queueEinvoice')
            ->label('Queue for e-Invoice')
            ->icon('heroicon-o-document-arrow-up')
            ->visible(fn (Invoice $record) => $record->isPosted()
                && $record->company->einvoice_enabled
                && in_array($record->einvoice_status, ['not_applicable', 'rejected', 'cancelled']))
            ->requiresConfirmation()
            ->modalDescription('Stages this invoice for LHDN e-Invoice review. Nothing is transmitted yet.')
            ->action(function (Invoice $record) {
                try {
                    app(EinvoiceService::class)->queueForApproval($record);
                    Notification::make()->success()->title('Queued — review and submit when ready.')->send();
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                }
            });
    }

    public static function submitEinvoice(): Action
    {
        return Action::make('submitEinvoice')
            ->label('Review & submit e-Invoice')
            ->icon('heroicon-o-paper-airplane')
            ->color('warning')
            ->visible(fn (Invoice $record) => $record->einvoice_status === 'pending_review')
            ->modalHeading('Review e-Invoice before transmission to LHDN')
            ->modalDescription('THIS IS IRREVERSIBLE once validated (corrections need a credit note). Check every field below.')
            ->modalContent(fn (Invoice $record) => new HtmlString(
                '<pre style="font-size:11px;max-height:400px;overflow:auto;background:#f7f7f7;padding:12px;border-radius:8px">'
                .e(json_encode($record->submission?->payload_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
                .'</pre>'
            ))
            ->modalSubmitActionLabel('Approve & transmit to LHDN')
            ->action(function (Invoice $record) {
                try {
                    app(EinvoiceService::class)
                        ->approveAndSubmit($record->submission, auth()->user());
                    Notification::make()->success()->title('Transmitted to LHDN via middleware — status will update on the next poll.')->send();
                } catch (\Throwable $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                }
            });
    }

    public static function cancelEinvoice(): Action
    {
        return Action::make('cancelEinvoice')
            ->label('Cancel e-Invoice')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (Invoice $record) => in_array($record->einvoice_status, ['submitted', 'validated']))
            ->requiresConfirmation()
            ->modalDescription('Cancellation is only possible within the middleware window (same month). Proceed?')
            ->action(function (Invoice $record) {
                try {
                    app(EinvoiceService::class)->cancel($record->submission);
                    Notification::make()->success()->title('e-Invoice cancelled.')->send();
                } catch (\Throwable $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                }
            });
    }

    public static function approve(): Action
    {
        return Action::make('approve')
            ->label('Approve')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (Invoice $record) => $record->status === 'draft')
            ->requiresConfirmation()
            ->modalDescription('Approving locks the invoice and posts it to the ledger.')
            ->action(function (Invoice $record) {
                try {
                    app(InvoiceService::class)->approve($record);
                    Notification::make()->success()->title("Invoice {$record->invoice_number} approved and posted.")->send();
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                }
            });
    }

    public static function recordPayment(): Action
    {
        return Action::make('recordPayment')
            ->label('Record payment')
            ->icon('heroicon-o-banknotes')
            ->color('primary')
            ->visible(fn (Invoice $record) => in_array($record->status, ['approved', 'sent', 'partial']))
            ->schema(fn (Invoice $record) => [
                TextInput::make('amount')
                    ->numeric()
                    ->required()
                    ->default($record->balance_due)
                    ->helperText("Balance due: {$record->currency} {$record->balance_due}"),
                DatePicker::make('payment_date')->required()->default(today()),
                Select::make('bank_account_id')
                    ->label('Deposit to')
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
                TextInput::make('settlement_fx_rate')
                    ->label('Settlement exchange rate to MYR')
                    ->helperText('Leave as booked rate unless the actual conversion rate differs — the difference posts as realized FX gain/loss.')
                    ->numeric()
                    ->default(fn (Invoice $record) => $record->fx_rate)
                    ->visible(fn (Invoice $record) => $record->currency !== 'MYR'),
            ])
            ->action(function (Invoice $record, array $data) {
                try {
                    app(PaymentService::class)->receiveAgainstInvoice(
                        $record,
                        $data['amount'],
                        $data['payment_date'],
                        Account::findOrFail($data['bank_account_id']),
                        $data['method'],
                        $data['reference'] ?? null,
                        $data['settlement_fx_rate'] ?? null,
                    );
                    Notification::make()->success()->title('Payment recorded.')->send();
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                }
            });
    }

    public static function sendEmail(): Action
    {
        return Action::make('sendEmail')
            ->label('Email invoice')
            ->icon('heroicon-o-envelope')
            ->visible(fn (Invoice $record) => $record->isPosted() && filled($record->party->email))
            ->requiresConfirmation()
            ->modalDescription(fn (Invoice $record) => "Send {$record->invoice_number} with PDF attached to {$record->party->email}?")
            ->action(function (Invoice $record) {
                Mail::to($record->party->email)->send(new InvoiceMail($record));
                $updates = ['last_sent_at' => now()];
                if ($record->status === 'approved') {
                    $updates['status'] = 'sent';
                }
                $record->forceFill($updates)->save();
                Notification::make()->success()->title("Sent to {$record->party->email}.")->send();
            });
    }

    public static function sendReminder(): Action
    {
        return Action::make('sendReminder')
            ->label('Send reminder')
            ->icon('heroicon-o-bell-alert')
            ->color('warning')
            ->visible(fn (Invoice $record) => $record->isOverdue()
                && bccomp($record->balance_due, '0', 2) === 1
                && filled($record->party->email))
            ->requiresConfirmation()
            ->modalDescription(fn (Invoice $record) => "Send a payment reminder for {$record->invoice_number} ({$record->currency} {$record->balance_due} outstanding) with PDF attached to {$record->party->email}?")
            ->action(function (Invoice $record) {
                Mail::to($record->party->email)->send(new InvoiceMail($record, reminder: true));
                $updates = [
                    'last_reminder_at' => now(),
                    'reminders_sent_count' => (int) $record->reminders_sent_count + 1,
                ];
                if ($record->status === 'approved') {
                    $updates['status'] = 'sent';
                }
                $record->forceFill($updates)->save();
                Notification::make()->success()->title("Reminder sent to {$record->party->email}.")->send();
            });
    }

    public static function downloadPdf(): Action
    {
        return Action::make('downloadPdf')
            ->label('Download PDF')
            ->icon('heroicon-o-arrow-down-tray')
            ->action(fn (Invoice $record) => response()->streamDownload(
                fn () => print (InvoicePdf::render($record)->output()),
                InvoicePdf::filename($record),
            ));
    }

    public static function void(): Action
    {
        return Action::make('void')
            ->label('Void')
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->visible(fn (Invoice $record) => $record->isPosted() && (float) $record->amount_paid == 0)
            ->requiresConfirmation()
            ->modalDescription('Voiding reverses this invoice from the ledger. This cannot be undone.')
            ->action(function (Invoice $record) {
                try {
                    app(InvoiceService::class)->void($record);
                    Notification::make()->success()->title("Invoice {$record->invoice_number} voided.")->send();
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                }
            });
    }
}
