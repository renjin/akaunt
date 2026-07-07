<?php

namespace App\Filament\Resources\Parties\Schemas;

use App\Filament\Pages\CustomerStatement;
use App\Filament\Resources\Bills\BillResource;
use App\Filament\Resources\Estimates\EstimateResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\Bill;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\Party;
use App\Models\PaymentAllocation;
use App\Models\PurchaseOrder;
use Filament\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class PartyInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('Profile')
                ->columnSpanFull()
                ->tabs([
                    self::overviewTab(),
                    self::invoicesTab(),
                    self::estimatesTab(),
                    self::billsTab(),
                    self::purchaseOrdersTab(),
                    self::activityTab(),
                ]),
        ]);
    }

    private static function overviewTab(): Tab
    {
        return Tab::make('Overview')
            ->schema([
                Section::make()
                    ->columns(3)
                    ->schema([
                        TextEntry::make('paid_last_12_months')
                            ->label('Paid last 12 months')
                            ->state(fn (Party $record): string => self::paidLastTwelveMonths($record))
                            ->money('MYR')
                            ->weight('bold'),
                        TextEntry::make('total_unpaid')
                            ->label('Total unpaid')
                            ->state(fn (Party $record): string => self::outstandingBalance($record))
                            ->money('MYR')
                            ->weight('bold'),
                        TextEntry::make('last_item_sent')
                            ->label(fn (Party $record): string => $record->isCustomer() ? 'Last item sent' : 'Last bill recorded')
                            ->state(fn (Party $record): ?string => self::lastItem($record)?->issue_label)
                            ->url(fn (Party $record): ?string => self::lastItemUrl($record))
                            ->color(fn (Party $record): ?string => self::lastItem($record) ? 'primary' : null)
                            ->placeholder('—'),
                    ]),
                Section::make('Primary contact')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('role')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => ucfirst($state))
                            ->color(fn (string $state): string => match ($state) {
                                'customer' => 'info',
                                'vendor' => 'warning',
                                default => 'success',
                            }),
                        TextEntry::make('email')->placeholder('—'),
                        TextEntry::make('phone')->placeholder('—'),
                        TextEntry::make('address')
                            ->state(fn (Party $record) => collect([
                                $record->address_line1,
                                $record->address_line2,
                                trim(implode(' ', array_filter([$record->postcode, $record->city]))),
                                $record->state,
                                $record->country_code,
                            ])->filter()->implode(', '))
                            ->placeholder('—')
                            ->columnSpan(2),
                    ]),
                Section::make('Additional contacts')
                    ->visible(fn (Party $record) => $record->contacts()->exists())
                    ->schema([
                        RepeatableEntry::make('contacts')
                            ->hiddenLabel()
                            ->columns(3)
                            ->schema([
                                TextEntry::make('name'),
                                TextEntry::make('email')->placeholder('—'),
                                TextEntry::make('phone')->placeholder('—'),
                            ]),
                    ]),
                Section::make('Registration & tax')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('registration')
                            ->state(fn (Party $record) => trim(implode(' ', array_filter([
                                $record->registration_scheme,
                                $record->registration_number,
                            ]))))
                            ->placeholder('—'),
                        TextEntry::make('tin')->label('TIN')->placeholder('—'),
                        TextEntry::make('sst_registration_no')
                            ->label('SST registration')
                            ->placeholder('—'),
                    ]),
                Section::make('Unpaid invoices')
                    ->visible(fn (Party $record) => $record->isCustomer())
                    ->headerActions([
                        Action::make('sendStatement')
                            ->label('Send statement')
                            ->icon('heroicon-o-envelope')
                            ->url(fn (Party $record): string => CustomerStatement::getUrl(['party' => $record->getKey()])),
                    ])
                    ->schema([
                        RepeatableEntry::make('unpaidInvoices')
                            ->hiddenLabel()
                            ->columns(4)
                            ->placeholder('Nothing unpaid — all caught up.')
                            ->schema([
                                TextEntry::make('invoice_number')
                                    ->label('Invoice')
                                    ->url(fn (Invoice $record) => InvoiceResource::getUrl('view', ['record' => $record]))
                                    ->color('primary'),
                                TextEntry::make('status')
                                    ->badge()
                                    ->formatStateUsing(fn (string $state, Invoice $record): string => $record->isOverdue() ? 'Overdue' : ucfirst($state))
                                    ->color(fn (string $state, Invoice $record): string => $record->isOverdue() ? 'danger' : self::statusColor($state)),
                                TextEntry::make('due_date')
                                    ->label('Due')
                                    ->state(fn (Invoice $record): ?string => $record->due_date ? 'Due '.$record->due_date->diffForHumans() : null)
                                    ->color(fn (Invoice $record): string => $record->isOverdue() ? 'danger' : 'gray')
                                    ->placeholder('No due date'),
                                TextEntry::make('balance_due')
                                    ->label('Balance due')
                                    ->state(fn (Invoice $record) => $record->balance_due)
                                    ->money('MYR'),
                            ]),
                    ]),
                Section::make('Unpaid bills')
                    ->visible(fn (Party $record) => $record->isVendor())
                    ->schema([
                        RepeatableEntry::make('unpaidBills')
                            ->hiddenLabel()
                            ->columns(4)
                            ->placeholder('Nothing unpaid — all caught up.')
                            ->schema([
                                TextEntry::make('bill_number')
                                    ->label('Bill')
                                    ->placeholder('(no number)')
                                    ->url(fn (Bill $record) => BillResource::getUrl('view', ['record' => $record]))
                                    ->color('primary'),
                                TextEntry::make('status')
                                    ->badge()
                                    ->formatStateUsing(fn (string $state, Bill $record): string => self::billIsOverdue($record) ? 'Overdue' : ucfirst($state))
                                    ->color(fn (string $state, Bill $record): string => self::billIsOverdue($record) ? 'danger' : self::statusColor($state)),
                                TextEntry::make('due_date')
                                    ->label('Due')
                                    ->state(fn (Bill $record): ?string => $record->due_date ? 'Due '.$record->due_date->diffForHumans() : null)
                                    ->color(fn (Bill $record): string => self::billIsOverdue($record) ? 'danger' : 'gray')
                                    ->placeholder('No due date'),
                                TextEntry::make('balance_due')
                                    ->label('Balance due')
                                    ->state(fn (Bill $record) => $record->balance_due)
                                    ->money('MYR'),
                            ]),
                    ]),
            ]);
    }

    private static function invoicesTab(): Tab
    {
        return Tab::make('Invoices')
            ->visible(fn (Party $record) => $record->isCustomer())
            ->schema([
                Section::make()
                    ->columns(4)
                    ->schema([
                        TextEntry::make('invoices_total_unpaid')
                            ->label('Total unpaid')
                            ->state(fn (Party $record): string => self::unpaidTotal($record->invoices()))
                            ->money('MYR')
                            ->weight('bold'),
                        TextEntry::make('invoices_overdue')
                            ->label('Overdue')
                            ->state(fn (Party $record): string => self::overdueTotal($record->invoices()))
                            ->money('MYR')
                            ->weight('bold')
                            ->color(fn ($state): string => bccomp((string) $state, '0', 2) === 1 ? 'danger' : 'gray'),
                        TextEntry::make('invoices_not_yet_due')
                            ->label('Not yet due')
                            ->state(fn (Party $record): string => bcsub(
                                self::unpaidTotal($record->invoices()),
                                self::overdueTotal($record->invoices()),
                                2,
                            ))
                            ->money('MYR')
                            ->weight('bold'),
                        TextEntry::make('average_days_to_pay')
                            ->label('Average time to pay')
                            ->state(fn (Party $record) => self::averageDaysToPay($record))
                            ->placeholder('—'),
                    ]),
                Section::make('All invoices')
                    ->description('Most recent first (up to 50).')
                    ->schema([
                        RepeatableEntry::make('profileInvoices')
                            ->hiddenLabel()
                            ->columns(5)
                            ->placeholder('No invoices yet.')
                            ->schema([
                                TextEntry::make('invoice_number')
                                    ->label('Invoice')
                                    ->url(fn (Invoice $record) => InvoiceResource::getUrl('view', ['record' => $record]))
                                    ->color('primary'),
                                TextEntry::make('issue_date')->label('Date')->date(),
                                TextEntry::make('status')
                                    ->badge()
                                    ->formatStateUsing(fn (string $state, Invoice $record): string => $record->isOverdue() ? 'Overdue' : ucfirst($state))
                                    ->color(fn (string $state, Invoice $record): string => $record->isOverdue() ? 'danger' : self::statusColor($state)),
                                TextEntry::make('total')->money('MYR'),
                                TextEntry::make('balance_due')
                                    ->label('Balance due')
                                    ->state(fn (Invoice $record) => $record->balance_due)
                                    ->money('MYR'),
                            ]),
                    ]),
            ]);
    }

    private static function billsTab(): Tab
    {
        return Tab::make('Bills')
            ->visible(fn (Party $record) => $record->isVendor())
            ->schema([
                Section::make()
                    ->columns(3)
                    ->schema([
                        TextEntry::make('bills_total_unpaid')
                            ->label('Total unpaid')
                            ->state(fn (Party $record): string => self::unpaidTotal($record->bills()))
                            ->money('MYR')
                            ->weight('bold'),
                        TextEntry::make('bills_overdue')
                            ->label('Overdue')
                            ->state(fn (Party $record): string => self::overdueTotal($record->bills()))
                            ->money('MYR')
                            ->weight('bold')
                            ->color(fn ($state): string => bccomp((string) $state, '0', 2) === 1 ? 'danger' : 'gray'),
                        TextEntry::make('bills_not_yet_due')
                            ->label('Not yet due')
                            ->state(fn (Party $record): string => bcsub(
                                self::unpaidTotal($record->bills()),
                                self::overdueTotal($record->bills()),
                                2,
                            ))
                            ->money('MYR')
                            ->weight('bold'),
                    ]),
                Section::make('All bills')
                    ->description('Most recent first (up to 50).')
                    ->schema([
                        RepeatableEntry::make('profileBills')
                            ->hiddenLabel()
                            ->columns(5)
                            ->placeholder('No bills yet.')
                            ->schema([
                                TextEntry::make('bill_number')
                                    ->label('Bill')
                                    ->placeholder('(no number)')
                                    ->url(fn (Bill $record) => BillResource::getUrl('view', ['record' => $record]))
                                    ->color('primary'),
                                TextEntry::make('bill_date')->label('Date')->date(),
                                TextEntry::make('status')
                                    ->badge()
                                    ->formatStateUsing(fn (string $state, Bill $record): string => self::billIsOverdue($record) ? 'Overdue' : ucfirst($state))
                                    ->color(fn (string $state, Bill $record): string => self::billIsOverdue($record) ? 'danger' : self::statusColor($state)),
                                TextEntry::make('total')->money('MYR'),
                                TextEntry::make('balance_due')
                                    ->label('Balance due')
                                    ->state(fn (Bill $record) => $record->balance_due)
                                    ->money('MYR'),
                            ]),
                    ]),
            ]);
    }

    private static function estimatesTab(): Tab
    {
        return Tab::make('Estimates')
            ->visible(fn (Party $record) => $record->isCustomer())
            ->schema([
                Section::make()
                    ->columns(3)
                    ->schema([
                        TextEntry::make('estimates_open')
                            ->label('Open estimates')
                            ->state(fn (Party $record): int => $record->estimates()
                                ->whereIn('status', ['draft', 'sent', 'accepted'])
                                ->count())
                            ->weight('bold'),
                        TextEntry::make('estimates_accepted')
                            ->label('Accepted')
                            ->state(fn (Party $record): int => $record->estimates()->where('status', 'accepted')->count())
                            ->weight('bold'),
                        TextEntry::make('estimates_total')
                            ->label('Open estimate value')
                            ->state(fn (Party $record): string => (string) $record->estimates()
                                ->whereIn('status', ['draft', 'sent', 'accepted'])
                                ->sum('total'))
                            ->money('MYR')
                            ->weight('bold'),
                    ]),
                Section::make('All estimates')
                    ->description('Most recent first (up to 50).')
                    ->schema([
                        RepeatableEntry::make('profileEstimates')
                            ->hiddenLabel()
                            ->columns(5)
                            ->placeholder('No estimates yet.')
                            ->schema([
                                TextEntry::make('estimate_number')
                                    ->label('Estimate')
                                    ->url(fn (Estimate $record) => EstimateResource::getUrl('edit', ['record' => $record]))
                                    ->color('primary'),
                                TextEntry::make('issue_date')->label('Date')->date(),
                                TextEntry::make('status')
                                    ->badge()
                                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                                    ->color(fn (string $state): string => self::estimateStatusColor($state)),
                                TextEntry::make('total')->money('MYR'),
                                TextEntry::make('convertedInvoice.invoice_number')
                                    ->label('Invoice')
                                    ->url(fn (Estimate $record): ?string => $record->convertedInvoice
                                        ? InvoiceResource::getUrl('view', ['record' => $record->convertedInvoice])
                                        : null)
                                    ->placeholder('—')
                                    ->color('primary'),
                            ]),
                    ]),
            ]);
    }

    private static function purchaseOrdersTab(): Tab
    {
        return Tab::make('Purchase Orders')
            ->visible(fn (Party $record) => $record->isVendor())
            ->schema([
                Section::make()
                    ->columns(3)
                    ->schema([
                        TextEntry::make('purchase_orders_open')
                            ->label('Open purchase orders')
                            ->state(fn (Party $record): int => $record->purchaseOrders()
                                ->whereIn('status', ['draft', 'sent', 'approved'])
                                ->count())
                            ->weight('bold'),
                        TextEntry::make('purchase_orders_approved')
                            ->label('Approved')
                            ->state(fn (Party $record): int => $record->purchaseOrders()->where('status', 'approved')->count())
                            ->weight('bold'),
                        TextEntry::make('purchase_orders_total')
                            ->label('Open order value')
                            ->state(fn (Party $record): string => (string) $record->purchaseOrders()
                                ->whereIn('status', ['draft', 'sent', 'approved'])
                                ->sum('total'))
                            ->money('MYR')
                            ->weight('bold'),
                    ]),
                Section::make('All purchase orders')
                    ->description('Most recent first (up to 50).')
                    ->schema([
                        RepeatableEntry::make('profilePurchaseOrders')
                            ->hiddenLabel()
                            ->columns(5)
                            ->placeholder('No purchase orders yet.')
                            ->schema([
                                TextEntry::make('purchase_order_number')
                                    ->label('Purchase order')
                                    ->url(fn (PurchaseOrder $record) => PurchaseOrderResource::getUrl('edit', ['record' => $record]))
                                    ->color('primary'),
                                TextEntry::make('order_date')->label('Date')->date(),
                                TextEntry::make('status')
                                    ->badge()
                                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                                    ->color(fn (string $state): string => self::purchaseOrderStatusColor($state)),
                                TextEntry::make('total')->money('MYR'),
                                TextEntry::make('convertedBill.bill_number')
                                    ->label('Bill')
                                    ->state(fn (PurchaseOrder $record): ?string => $record->convertedBill
                                        ? ($record->convertedBill->bill_number ?: 'Bill #'.$record->convertedBill->id)
                                        : null)
                                    ->url(fn (PurchaseOrder $record): ?string => $record->convertedBill
                                        ? BillResource::getUrl('view', ['record' => $record->convertedBill])
                                        : null)
                                    ->placeholder('—')
                                    ->color('primary'),
                            ]),
                    ]),
            ]);
    }

    private static function activityTab(): Tab
    {
        return Tab::make('Activity')
            ->schema([
                Section::make('Activity')
                    ->description('Recent document events, most recent first (up to 30).')
                    ->schema([
                        RepeatableEntry::make('activity')
                            ->hiddenLabel()
                            ->state(fn (Party $record): array => self::activityRows($record))
                            ->columns(4)
                            ->placeholder('No activity yet.')
                            ->schema([
                                TextEntry::make('date')->label('Date')->date(),
                                TextEntry::make('label')
                                    ->label('Event')
                                    ->columnSpan(2)
                                    ->url(fn (string|array|null $state, $record) => is_array($record) ? ($record['url'] ?? null) : null)
                                    ->color(fn ($record) => is_array($record) && ($record['url'] ?? null) ? 'primary' : null),
                                TextEntry::make('amount')
                                    ->label('Amount')
                                    ->money('MYR')
                                    ->placeholder('—')
                                    ->alignEnd(),
                            ]),
                    ]),
            ]);
    }

    /**
     * Builds a read-only, date-desc timeline of the party's document events.
     *
     * Customers: their invoices (issued/sent/paid) and received payments.
     * Vendors: their bills (recorded/paid) and payments made.
     * Sourced by merging the party's invoices/bills (keyed off status + issue/bill date)
     * with the party's payments (payment_date), then sorting by date and capping at 30.
     *
     * @return array<int, array{date: string, label: string, amount: string|null, url: string|null}>
     */
    private static function activityRows(Party $record): array
    {
        $events = collect();

        if ($record->isCustomer()) {
            foreach ($record->estimates()->orderByDesc('issue_date')->orderByDesc('id')->limit(30)->get() as $estimate) {
                $events->push([
                    'date' => optional($estimate->issue_date)->toDateString() ?? '',
                    'label' => 'Estimate '.$estimate->estimate_number.' — '.ucfirst($estimate->status),
                    'amount' => $estimate->total,
                    'url' => EstimateResource::getUrl('edit', ['record' => $estimate]),
                ]);
            }

            foreach ($record->invoices()->orderByDesc('issue_date')->orderByDesc('id')->limit(30)->get() as $invoice) {
                $events->push([
                    'date' => optional($invoice->issue_date)->toDateString() ?? '',
                    'label' => 'Invoice '.$invoice->invoice_number.' — '.ucfirst($invoice->status),
                    'amount' => $invoice->total,
                    'url' => InvoiceResource::getUrl('view', ['record' => $invoice]),
                ]);
            }
        }

        if ($record->isVendor()) {
            foreach ($record->purchaseOrders()->orderByDesc('order_date')->orderByDesc('id')->limit(30)->get() as $purchaseOrder) {
                $events->push([
                    'date' => optional($purchaseOrder->order_date)->toDateString() ?? '',
                    'label' => 'Purchase order '.$purchaseOrder->purchase_order_number.' — '.ucfirst($purchaseOrder->status),
                    'amount' => $purchaseOrder->total,
                    'url' => PurchaseOrderResource::getUrl('edit', ['record' => $purchaseOrder]),
                ]);
            }

            foreach ($record->bills()->orderByDesc('bill_date')->orderByDesc('id')->limit(30)->get() as $bill) {
                $events->push([
                    'date' => optional($bill->bill_date)->toDateString() ?? '',
                    'label' => 'Bill '.($bill->bill_number ?: '(no number)').' — '.ucfirst($bill->status),
                    'amount' => $bill->total,
                    'url' => BillResource::getUrl('view', ['record' => $bill]),
                ]);
            }
        }

        foreach ($record->payments()->orderByDesc('payment_date')->orderByDesc('id')->limit(30)->get() as $payment) {
            $direction = $payment->payment_type === 'made' ? 'Payment made' : 'Payment received';
            $events->push([
                'date' => optional($payment->payment_date)->toDateString() ?? '',
                'label' => $direction.($payment->reference ? ' — '.$payment->reference : ''),
                'amount' => $payment->amount,
                'url' => null,
            ]);
        }

        return $events
            ->sortByDesc('date')
            ->take(30)
            ->values()
            ->all();
    }

    private static function statusColor(string $state): string
    {
        return match ($state) {
            'draft' => 'gray',
            'approved', 'sent' => 'info',
            'partial' => 'warning',
            'paid' => 'success',
            'void' => 'danger',
            default => 'gray',
        };
    }

    private static function estimateStatusColor(string $state): string
    {
        return match ($state) {
            'draft' => 'gray',
            'sent' => 'warning',
            'accepted' => 'success',
            'converted' => 'info',
            'expired' => 'danger',
            default => 'gray',
        };
    }

    private static function purchaseOrderStatusColor(string $state): string
    {
        return match ($state) {
            'draft' => 'gray',
            'sent' => 'warning',
            'approved' => 'success',
            'converted' => 'info',
            'cancelled' => 'danger',
            default => 'gray',
        };
    }

    private static function billIsOverdue(Bill $record): bool
    {
        return $record->due_date !== null
            && $record->due_date->isPast()
            && in_array($record->status, ['approved', 'partial']);
    }

    /** Same basis as the parties table "Outstanding" column. */
    private static function outstandingBalance(Party $record): string
    {
        $total = '0';
        if ($record->isCustomer()) {
            $total = bcadd($total, self::unpaidTotal($record->invoices()), 2);
        }
        if ($record->isVendor()) {
            $total = bcadd($total, self::unpaidTotal($record->bills()), 2);
        }

        return $total;
    }

    /** Sum of posted, not-fully-paid document balances. */
    private static function unpaidTotal(HasMany $relation): string
    {
        return (string) $relation
            ->whereNotIn('status', ['draft', 'void'])
            ->whereColumn('amount_paid', '<', 'total')
            ->sum(DB::raw('total - amount_paid'));
    }

    /** Unpaid balance on documents past their due date. */
    private static function overdueTotal(HasMany $relation): string
    {
        return (string) $relation
            ->whereNotIn('status', ['draft', 'void', 'paid'])
            ->whereColumn('amount_paid', '<', 'total')
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', today())
            ->sum(DB::raw('total - amount_paid'));
    }

    /** Payments allocated to this party's invoices (and bills, for vendors) in the last 12 months. */
    private static function paidLastTwelveMonths(Party $record): string
    {
        $since = today()->subMonths(12)->toDateString();

        $allocated = fn (HasMany $documents, string $type): string => (string) PaymentAllocation::query()
            ->where('allocatable_type', $type)
            ->whereIn('allocatable_id', $documents->select('id'))
            ->whereHas('payment', fn ($query) => $query->where('payment_date', '>=', $since))
            ->sum('amount');

        $total = '0.00';
        if ($record->isCustomer()) {
            $total = bcadd($total, $allocated($record->invoices(), Invoice::class), 2);
        }
        if ($record->isVendor()) {
            $total = bcadd($total, $allocated($record->bills(), Bill::class), 2);
        }

        return $total;
    }

    /** Latest posted invoice (customer) or bill (vendor), for the "Last item sent" KPI. */
    private static function lastItem(Party $record): Invoice|Bill|null
    {
        $item = $record->isCustomer()
            ? $record->invoices()->whereNotIn('status', ['draft', 'void'])->orderByDesc('issue_date')->orderByDesc('id')->first()
            : $record->bills()->whereNotIn('status', ['draft', 'void'])->orderByDesc('bill_date')->orderByDesc('id')->first();

        if ($item instanceof Invoice) {
            $item->issue_label = $item->invoice_number.' — '.$item->issue_date->format('j M Y');
        } elseif ($item instanceof Bill) {
            $item->issue_label = trim(($item->bill_number ?? 'Bill').' — '.$item->bill_date->format('j M Y'));
        }

        return $item;
    }

    private static function lastItemUrl(Party $record): ?string
    {
        $item = self::lastItem($record);

        return match (true) {
            $item instanceof Invoice => InvoiceResource::getUrl('view', ['record' => $item]),
            $item instanceof Bill => BillResource::getUrl('view', ['record' => $item]),
            default => null,
        };
    }

    /** Mean days from issue date to final payment across fully paid invoices. */
    private static function averageDaysToPay(Party $record): ?string
    {
        if (! $record->isCustomer()) {
            return null;
        }

        $days = $record->invoices()
            ->where('status', 'paid')
            ->with('allocations.payment')
            ->get()
            ->map(function (Invoice $invoice): ?float {
                $lastPaymentDate = $invoice->allocations
                    ->map(fn ($allocation) => $allocation->payment?->payment_date)
                    ->filter()
                    ->max();

                return $lastPaymentDate
                    ? $invoice->issue_date->diffInDays($lastPaymentDate, false)
                    : null;
            })
            ->filter(fn (?float $value) => $value !== null);

        if ($days->isEmpty()) {
            return null;
        }

        $average = (int) round($days->avg());

        return $average.' '.str('day')->plural($average);
    }
}
