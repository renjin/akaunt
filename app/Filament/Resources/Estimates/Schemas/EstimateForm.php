<?php

namespace App\Filament\Resources\Estimates\Schemas;

use App\Models\Estimate;
use App\Models\Item;
use App\Services\EstimateService;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

class EstimateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            // Estimates never touch the ledger — editable until converted to an invoice
            ->disabled(fn (?Estimate $record) => $record !== null && $record->status === 'converted')
            ->components([
                Section::make('Estimate details')
                    ->columns(['default' => 1, 'sm' => 2, 'xl' => 3])
                    ->columnSpanFull()
                    ->schema([
                        Select::make('party_id')
                            ->label('Customer')
                            ->options(fn () => Filament::getTenant()->parties()
                                ->whereIn('role', ['customer', 'both'])->orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->required(),
                        TextInput::make('estimate_number')
                            ->label('Estimate number')
                            ->default(fn () => app(EstimateService::class)->nextNumber(Filament::getTenant()))
                            ->required()
                            ->maxLength(255)
                            ->unique(
                                table: 'estimates',
                                column: 'estimate_number',
                                ignoreRecord: true,
                                modifyRuleUsing: fn ($rule) => $rule->where('company_id', Filament::getTenant()->id),
                            ),
                        TextInput::make('customer_ref')
                            ->label('Customer reference')
                            ->placeholder('PO number or their reference')
                            ->maxLength(255),
                        DatePicker::make('issue_date')->label('Date')->required()->default(today()),
                        DatePicker::make('expiry_date')->label('Valid until')->default(today()->addDays(30)),
                    ]),
                Section::make('Line items')
                    ->columnSpanFull()
                    ->schema([
                        self::linesRepeater(),
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
                            ->placeholder('Scope, assumptions, or terms shown on the estimate.')
                            ->default(fn () => Filament::getTenant()->estimate_notes_default)
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
                // ── Primary row ───────────────────────────────────────────────
                Select::make('item_id')
                    ->label('Item / service')
                    ->placeholder('Select an item…')
                    ->options(fn () => Filament::getTenant()->items()
                        ->where('kind', 'sales')
                        ->where('active', true)->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->live()
                    ->afterStateUpdated(function ($state, callable $set) {
                        if (! $state) {
                            return;
                        }
                        $item = Item::find($state);
                        $set('description', $item->name);
                        $set('unit_price', $item->unit_price);
                        $set('tax_code_id', $item->default_tax_code_id);
                        $set('tax_code_ids', $item->default_tax_code_id ? [$item->default_tax_code_id] : []);
                        $set('income_account_id', $item->income_account_id);
                        $set('classification_code', $item->classification_code);
                    })
                    ->columnSpan(['default' => 12, 'md' => 4]),
                TextInput::make('description')
                    ->label('Description')
                    ->placeholder('What are you quoting for?')
                    ->required()
                    ->columnSpan(['default' => 12, 'md' => 5]),
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
                    ->columnSpan(['default' => 4, 'md' => 1]),
                Placeholder::make('line_amount')
                    ->label('Amount')
                    ->content(function (callable $get) {
                        $qty = is_numeric($get('quantity')) ? (string) $get('quantity') : '0';
                        $price = is_numeric($get('unit_price')) ? (string) $get('unit_price') : '0';

                        return new HtmlString('<div style="text-align:right;font-weight:600;font-variant-numeric:tabular-nums;padding-top:6px">'
                            .number_format((float) bcmul($qty, $price, 2), 2).'</div>');
                    })
                    ->columnSpan(['default' => 4, 'md' => 1]),

                // ── Secondary row: tax + income account ───────────────────────
                Select::make('income_account_id')
                    ->label('Income account')
                    ->placeholder('Default for this item')
                    ->searchable()
                    ->options(fn () => Filament::getTenant()->accounts()
                        ->where('type', 'income')->orderBy('code')
                        ->get()->mapWithKeys(fn ($a) => [$a->id => "{$a->code} · {$a->name}"]))
                    ->columnSpan(['default' => 12, 'md' => 5]),
                Select::make('tax_code_ids')
                    ->label('Tax')
                    ->placeholder('No tax')
                    ->multiple()
                    ->live()
                    ->options(fn () => Filament::getTenant()->taxCodes()
                        ->where('active', true)->pluck('name', 'id'))
                    // Keep the legacy single FK in sync (first selected code).
                    ->afterStateUpdated(fn ($state, callable $set) => $set('tax_code_id', is_array($state) ? ($state[0] ?? null) : $state))
                    // Legacy rows only carried a single tax_code_id — show it in the multi-select.
                    ->afterStateHydrated(function ($state, callable $get, callable $set) {
                        if (empty($state) && filled($get('tax_code_id'))) {
                            $set('tax_code_ids', [$get('tax_code_id')]);
                        }
                    })
                    ->columnSpan(['default' => 12, 'md' => 5]),
                Placeholder::make('line_tax_amount')
                    ->label('Tax amount')
                    ->content(fn (callable $get) => new HtmlString('<div style="text-align:right;font-variant-numeric:tabular-nums;padding-top:6px">'
                        .number_format((float) self::lineTax($get), 2).'</div>'))
                    ->columnSpan(['default' => 12, 'md' => 2]),
            ])
            ->defaultItems(1)
            ->live()
            ->addActionLabel('Add a line')
            ->columnSpanFull();
    }

    /** Cached tenant tax rates keyed by id. */
    private static function taxRates(): Collection
    {
        return Filament::getTenant()->taxCodes()->pluck('rate', 'id');
    }

    /** Tax for a single repeater line, summed over all selected codes (back-compat aware). */
    private static function lineTax(callable $get): string
    {
        $qty = is_numeric($get('quantity')) ? (string) $get('quantity') : '0';
        $price = is_numeric($get('unit_price')) ? (string) $get('unit_price') : '0';
        $lineTotal = bcmul($qty, $price, 2);

        $ids = $get('tax_code_ids');
        if (! is_array($ids) || $ids === []) {
            $single = $get('tax_code_id');
            $ids = $single ? [$single] : [];
        }

        $rates = self::taxRates();
        $tax = '0.00';
        foreach ($ids as $id) {
            $rate = (float) ($rates[$id] ?? 0);
            $tax = bcadd($tax, number_format((float) $lineTotal * $rate / 100, 2, '.', ''), 2);
        }

        return $tax;
    }

    /** Live Subtotal / Tax / Total. */
    private static function totalsSummary(callable $get): HtmlString
    {
        $subtotal = '0.00';
        $tax = '0.00';
        $rates = null;

        foreach (($get('lines') ?? []) as $line) {
            $qty = is_numeric($line['quantity'] ?? null) ? (string) $line['quantity'] : '0';
            $price = is_numeric($line['unit_price'] ?? null) ? (string) $line['unit_price'] : '0';
            $lineTotal = bcmul($qty, $price, 2);
            $subtotal = bcadd($subtotal, $lineTotal, 2);

            $ids = $line['tax_code_ids'] ?? null;
            if (! is_array($ids) || $ids === []) {
                $ids = ! empty($line['tax_code_id']) ? [$line['tax_code_id']] : [];
            }

            if ($ids !== []) {
                $rates ??= self::taxRates();
                foreach ($ids as $id) {
                    $rate = (float) ($rates[$id] ?? 0);
                    $tax = bcadd($tax, number_format((float) $lineTotal * $rate / 100, 2, '.', ''), 2);
                }
            }
        }

        $fmt = fn (string $n) => 'MYR '.number_format((float) $n, 2);
        $total = bcadd($subtotal, $tax, 2);

        $row = fn (string $label, string $value, bool $strong = false) => '<div style="display:flex;justify-content:space-between;gap:2rem;padding:2px 0'
            .($strong ? ';font-weight:700;font-size:1.05rem;border-top:1px solid var(--gray-200);margin-top:4px;padding-top:8px' : '').'">'
            .'<span>'.e($label).'</span><span style="font-variant-numeric:tabular-nums">'.e($value).'</span></div>';

        return new HtmlString(
            '<div style="max-width:320px;margin-left:auto;font-size:0.95rem">'
            .$row('Subtotal', $fmt($subtotal))
            .$row('Tax', $fmt($tax))
            .$row('Total', $fmt($total), true)
            .'</div>'
        );
    }
}
