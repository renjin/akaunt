<?php

namespace App\Filament\Resources\Invoices\Schemas;

use App\Filament\Resources\Items\Schemas\ItemForm;
use App\Models\Account;
use App\Models\Invoice;
use App\Models\Item;
use App\Services\InvoiceService;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rules\Unique;

class InvoiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            // Wave-style: posted invoices stay editable (saving re-posts the ledger entry); only void is locked
            ->disabled(fn (?Invoice $record) => $record !== null && $record->status === 'void')
            ->components([
                Section::make('Invoice details')
                    ->columns(['default' => 1, 'sm' => 2, 'xl' => 3])
                    ->columnSpanFull()
                    ->schema([
                        Select::make('party_id')
                            ->label('Customer')
                            ->options(fn () => Filament::getTenant()->parties()
                                ->whereIn('role', ['customer', 'both'])->orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->required(),
                        TextInput::make('invoice_number')
                            ->label('Invoice number')
                            ->default(fn () => app(InvoiceService::class)->nextNumber(Filament::getTenant()))
                            ->required()
                            ->maxLength(64)
                            ->unique(
                                ignoreRecord: true,
                                modifyRuleUsing: fn (Unique $rule) => $rule
                                    ->where('company_id', Filament::getTenant()->getKey()),
                            ),
                        TextInput::make('po_number')
                            ->label('P.O. / S.O. number')
                            ->maxLength(64),
                        DatePicker::make('issue_date')
                            ->required()
                            ->default(today())
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (?string $state, callable $get, callable $set, string $operation) {
                                $terms = $get('payment_terms');
                                if ($operation === 'create' && $state && is_numeric($terms)) {
                                    $set('due_date', Carbon::parse($state)->addDays((int) $terms)->toDateString());
                                }
                            }),
                        Select::make('payment_terms')
                            ->label('Payment terms')
                            ->options([
                                '0' => 'On receipt',
                                '7' => 'Net 7',
                                '14' => 'Net 14',
                                '30' => 'Net 30',
                                'custom' => 'Custom',
                            ])
                            ->default(fn () => (string) (Filament::getTenant()->payment_terms_days_default ?? 30))
                            ->dehydrated(false)
                            ->live()
                            ->afterStateUpdated(function ($state, callable $get, callable $set) {
                                if (is_numeric($state) && $get('issue_date')) {
                                    $set('due_date', Carbon::parse($get('issue_date'))->addDays((int) $state)->toDateString());
                                }
                            })
                            ->afterStateHydrated(function (Select $component, ?Invoice $record) {
                                if (! $record?->issue_date || ! $record->due_date) {
                                    return;
                                }
                                $days = (int) $record->issue_date->diffInDays($record->due_date, false);
                                $component->state(in_array($days, [0, 7, 14, 30], true) ? (string) $days : 'custom');
                            }),
                        DatePicker::make('due_date')
                            ->label('Payment due')
                            ->default(fn () => today()
                                ->addDays((int) (Filament::getTenant()->payment_terms_days_default ?? 30))
                                ->toDateString()),
                        Select::make('currency')
                            ->options(['MYR' => 'MYR', 'USD' => 'USD', 'SGD' => 'SGD', 'CNY' => 'CNY', 'EUR' => 'EUR'])
                            ->default(fn () => Filament::getTenant()->base_currency ?? 'MYR')
                            ->live()
                            ->required(),
                        TextInput::make('fx_rate')
                            ->label('Exchange rate to MYR')
                            ->numeric()
                            ->default(1)
                            ->visible(fn (callable $get) => $get('currency') !== 'MYR')
                            ->required(),
                    ]),
                Section::make('Line items')
                    ->columnSpanFull()
                    ->schema([
                        self::linesRepeater(),
                        Section::make()
                            ->columns(['default' => 1, 'sm' => 2])
                            ->extraAttributes(['style' => 'max-width:320px;margin-left:auto'])
                            ->schema([
                                Select::make('discount_type')
                                    ->label('Discount type')
                                    ->options(['fixed' => 'Fixed amount', 'percent' => 'Percentage'])
                                    ->default('fixed')
                                    ->live(),
                                TextInput::make('discount_value')
                                    ->label('Discount')
                                    ->helperText('Off the whole invoice, before tax')
                                    ->numeric()
                                    ->minValue(0)
                                    ->default(0)
                                    ->live(onBlur: true)
                                    ->prefix(fn (callable $get) => $get('discount_type') === 'percent' ? null : ($get('currency') ?: 'MYR'))
                                    ->suffix(fn (callable $get) => $get('discount_type') === 'percent' ? '%' : null),
                            ]),
                        Placeholder::make('totals')
                            ->hiddenLabel()
                            ->content(fn (callable $get) => self::totalsSummary($get))
                            ->columnSpanFull(),
                    ]),
                Section::make('Notes & terms')
                    ->columnSpanFull()
                    ->collapsible()
                    ->schema([
                        Textarea::make('notes')
                            ->hiddenLabel()
                            ->rows(4)
                            ->placeholder('Payment instructions, terms, or a thank-you note shown on the invoice.')
                            ->default(fn () => Filament::getTenant()->invoice_notes_default)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    private static function linesRepeater(): Repeater
    {
        return Repeater::make('lines')
            ->relationship('lines')
            ->hiddenLabel()
            ->columns(12)
            ->schema([
                // ── Primary row: what & how much ──────────────────────────────
                Select::make('item_id')
                    ->label('Item / service')
                    ->placeholder('Select an item…')
                    ->options(fn () => Filament::getTenant()->items()
                        ->where('active', true)->where('kind', 'sales')->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->live()
                    ->createOptionForm(fn (Schema $schema) => ItemForm::configure($schema))
                    ->createOptionUsing(fn (array $data) => Filament::getTenant()->items()->create($data)->getKey())
                    ->afterStateUpdated(function ($state, callable $set) {
                        if (! $state) {
                            return;
                        }
                        $item = Item::find($state);
                        $set('description', $item->name);
                        $set('unit_price', $item->unit_price);
                        $set('tax_code_ids', $item->default_tax_code_id ? [$item->default_tax_code_id] : []);
                        $set('income_account_id', $item->income_account_id);
                        $set('classification_code', $item->classification_code);
                        $set('unit_of_measure', $item->unit_of_measure);
                    })
                    ->columnSpan(['default' => 12, 'md' => 4]),
                TextInput::make('description')
                    ->label('Description')
                    ->placeholder('What are you billing for?')
                    ->required()
                    ->columnSpan(['default' => 12, 'md' => 4]),
                TextInput::make('quantity')
                    ->label('Qty')
                    ->numeric()
                    ->default(1)
                    ->live(onBlur: true)
                    ->columnSpan(['default' => 4, 'md' => 1]),
                TextInput::make('unit_price')
                    ->label('Price')
                    ->numeric()
                    ->default(0)
                    ->live(onBlur: true)
                    ->columnSpan(['default' => 4, 'md' => 2]),
                Placeholder::make('line_amount')
                    ->label('Amount')
                    ->content(fn (callable $get) => new HtmlString('<div style="text-align:right;font-weight:600;font-variant-numeric:tabular-nums;padding-top:6px">'
                        .number_format(self::lineNet($get('quantity'), $get('unit_price'), $get('discount')), 2)
                        .'</div>'))
                    ->columnSpan(['default' => 4, 'md' => 1]),

                // ── Secondary row: tax, account, discount (muted sub-line) ────
                Select::make('income_account_id')
                    ->label('Income account')
                    ->placeholder('Default for this item')
                    ->options(fn () => Filament::getTenant()->accounts()
                        ->where('type', 'income')->where('active', true)->orderBy('code')->get()
                        ->mapWithKeys(fn (Account $a) => [$a->id => "{$a->code} · {$a->name}"]))
                    ->searchable()
                    ->columnSpan(['default' => 12, 'md' => 4]),
                Select::make('tax_code_ids')
                    ->label('Tax')
                    ->placeholder('No tax')
                    ->multiple()
                    ->live()
                    ->options(fn () => Filament::getTenant()->taxCodes()
                        ->where('active', true)->pluck('name', 'id'))
                    // Legacy rows only carried a single tax_code_id — show it in the multi-select.
                    ->afterStateHydrated(function ($state, callable $get, callable $set) {
                        if (empty($state) && filled($get('tax_code_id'))) {
                            $set('tax_code_ids', [$get('tax_code_id')]);
                        }
                    })
                    ->columnSpan(['default' => 12, 'md' => 4]),
                TextInput::make('discount')
                    ->label('Discount')
                    ->helperText('Amount off this line')
                    ->numeric()
                    ->default(0)
                    ->live(onBlur: true)
                    ->columnSpan(['default' => 6, 'md' => 2]),
                Placeholder::make('line_tax_amount')
                    ->label('Tax amount')
                    ->content(fn (callable $get) => new HtmlString('<div style="text-align:right;font-variant-numeric:tabular-nums;padding-top:6px">'
                        .number_format(self::lineTax($get('quantity'), $get('unit_price'), $get('discount'), $get('tax_code_ids')), 2)
                        .'</div>'))
                    ->columnSpan(['default' => 6, 'md' => 2]),
            ])
            ->defaultItems(1)
            ->live()
            ->addActionLabel('Add a line')
            ->columnSpanFull();
    }

    /** Discounted net for a single line (qty * unit_price - discount), 2dp. */
    private static function lineNet(mixed $qty, mixed $price, mixed $discount): float
    {
        $qty = is_numeric($qty) ? (string) $qty : '0';
        $price = is_numeric($price) ? (string) $price : '0';
        $discount = is_numeric($discount) ? (string) $discount : '0';

        return (float) bcsub(bcmul($qty, $price, 2), $discount, 2);
    }

    /** Tax for a single line: sum over selected codes of rate% of the discounted net, 2dp each. */
    private static function lineTax(mixed $qty, mixed $price, mixed $discount, mixed $taxCodeIds): float
    {
        $net = self::lineNet($qty, $price, $discount);
        $ids = array_filter((array) ($taxCodeIds ?? []));
        if (empty($ids)) {
            return 0.0;
        }

        $rates = self::taxRates();
        $tax = '0.00';
        foreach ($ids as $id) {
            $rate = (float) ($rates[$id] ?? 0);
            $tax = bcadd($tax, number_format($net * $rate / 100, 2, '.', ''), 2);
        }

        return (float) $tax;
    }

    /** Cached tenant tax rate lookup keyed by id. */
    private static function taxRates(): Collection
    {
        static $rates = null;

        return $rates ??= Filament::getTenant()->taxCodes()->pluck('rate', 'id');
    }

    /** Live Subtotal / Tax / Total, same rounding as InvoiceService::calculateTotals(). */
    private static function totalsSummary(callable $get): HtmlString
    {
        $subtotal = '0.00';
        $tax = '0.00';

        foreach (($get('lines') ?? []) as $line) {
            $net = self::lineNet($line['quantity'] ?? null, $line['unit_price'] ?? null, $line['discount'] ?? null);
            $subtotal = bcadd($subtotal, number_format($net, 2, '.', ''), 2);
            $tax = bcadd($tax, number_format(
                self::lineTax($line['quantity'] ?? null, $line['unit_price'] ?? null, $line['discount'] ?? null, $line['tax_code_ids'] ?? null),
                2, '.', ''
            ), 2);
        }

        // Whole-invoice discount, applied before tax (matches InvoiceService).
        // Note: the live Tax above is on the gross subtotal; the saved invoice recomputes
        // tax on the discounted net. Close enough for a preview, exact on save.
        $value = is_numeric($get('discount_value')) ? (string) $get('discount_value') : '0';
        $discount = '0.00';
        if ((float) $value > 0 && (float) $subtotal > 0) {
            $discount = $get('discount_type') === 'percent'
                ? bcdiv(bcmul($subtotal, $value, 4), '100', 2)
                : bcadd($value, '0', 2);
            if ((float) $discount > (float) $subtotal) {
                $discount = bcadd($subtotal, '0', 2);
            }
        }

        $currency = $get('currency') ?: 'MYR';
        $fmt = fn (string $n) => $currency.' '.number_format((float) $n, 2);
        $total = bcadd(bcsub($subtotal, $discount, 2), $tax, 2);

        $row = fn (string $label, string $value, bool $strong = false) => '<div style="display:flex;justify-content:space-between;gap:2rem;padding:2px 0'
            .($strong ? ';font-weight:700;font-size:1.05rem;border-top:1px solid var(--gray-200);margin-top:4px;padding-top:8px' : '').'">'
            .'<span>'.e($label).'</span><span style="font-variant-numeric:tabular-nums">'.e($value).'</span></div>';

        return new HtmlString(
            '<div style="max-width:320px;margin-left:auto;font-size:0.95rem">'
            .$row('Subtotal', $fmt($subtotal))
            .((float) $discount > 0 ? $row('Discount', '−'.$fmt($discount)) : '')
            .$row('Tax', $fmt($tax))
            .$row('Total', $fmt($total), true)
            .'</div>'
        );
    }
}
