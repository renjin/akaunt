<?php

namespace App\Filament\Resources\PurchaseOrders\Schemas;

use App\Models\Account;
use App\Models\Item;
use App\Models\PurchaseOrder;
use App\Services\PurchaseOrderService;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class PurchaseOrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->disabled(fn (?PurchaseOrder $record) => $record !== null && $record->status === 'converted')
            ->components([
                Section::make('Purchase order details')
                    ->columns(['default' => 1, 'sm' => 2, 'xl' => 3])
                    ->columnSpanFull()
                    ->schema([
                        Select::make('party_id')
                            ->label('Vendor')
                            ->options(fn () => Filament::getTenant()->parties()
                                ->whereIn('role', ['vendor', 'both'])
                                ->orderBy('name')
                                ->pluck('name', 'id'))
                            ->searchable()
                            ->required(),
                        TextInput::make('purchase_order_number')
                            ->label('Purchase order number')
                            ->default(fn () => app(PurchaseOrderService::class)->nextNumber(Filament::getTenant()))
                            ->required()
                            ->maxLength(255)
                            ->unique(
                                table: 'purchase_orders',
                                column: 'purchase_order_number',
                                ignoreRecord: true,
                                modifyRuleUsing: fn ($rule) => $rule->where('company_id', Filament::getTenant()->id),
                            ),
                        DatePicker::make('order_date')
                            ->label('Date')
                            ->required()
                            ->default(today()),
                        DatePicker::make('expected_date')
                            ->label('Expected delivery'),
                        Select::make('currency')
                            ->options(['MYR' => 'MYR', 'USD' => 'USD', 'SGD' => 'SGD', 'CNY' => 'CNY', 'EUR' => 'EUR'])
                            ->default(fn () => Filament::getTenant()->base_currency ?? 'MYR')
                            ->live()
                            ->required(),
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
                Section::make('Notes')
                    ->columnSpanFull()
                    ->collapsible()
                    ->schema([
                        Textarea::make('notes')
                            ->hiddenLabel()
                            ->rows(4)
                            ->placeholder('Terms, delivery notes, or internal purchasing notes.')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    private static function linesRepeater(): Repeater
    {
        return Repeater::make('lines')
            ->relationship('lines')
            ->hiddenLabel()
            ->table([
                TableColumn::make('Item')->width('200px'),
                TableColumn::make('Expense category')->width('220px'),
                TableColumn::make('Description'),
                TableColumn::make('Qty')->width('90px')->alignEnd(),
                TableColumn::make('Unit price')->width('130px')->alignEnd(),
                TableColumn::make('Tax')->width('180px'),
                TableColumn::make('Amount')->width('130px')->alignEnd(),
            ])
            ->schema([
                Select::make('item_id')
                    ->hiddenLabel()
                    ->placeholder('Select...')
                    ->options(fn () => Filament::getTenant()->items()
                        ->where('active', true)
                        ->where('kind', 'purchase')
                        ->orderBy('name')
                        ->pluck('name', 'id'))
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
                        $set('expense_account_id', $item->expense_account_id);
                    }),
                Select::make('expense_account_id')
                    ->hiddenLabel()
                    ->placeholder('Choose account')
                    ->options(fn () => Filament::getTenant()->accounts()
                        ->where('type', 'expense')
                        ->where('active', true)
                        ->orderBy('code')
                        ->get()
                        ->mapWithKeys(fn (Account $account) => [$account->id => "{$account->code} · {$account->name}"]))
                    ->searchable()
                    ->required(),
                TextInput::make('description')
                    ->hiddenLabel()
                    ->placeholder('What are you ordering?')
                    ->required(),
                TextInput::make('quantity')
                    ->hiddenLabel()
                    ->numeric()
                    ->default(1)
                    ->live(onBlur: true),
                TextInput::make('unit_price')
                    ->hiddenLabel()
                    ->numeric()
                    ->default(0)
                    ->live(onBlur: true),
                Select::make('tax_code_id')
                    ->hiddenLabel()
                    ->placeholder('No tax')
                    ->options(fn () => Filament::getTenant()->taxCodes()
                        ->where('active', true)
                        ->pluck('name', 'id')),
                Placeholder::make('line_amount')
                    ->hiddenLabel()
                    ->content(function (callable $get) {
                        $qty = is_numeric($get('quantity')) ? (string) $get('quantity') : '0';
                        $price = is_numeric($get('unit_price')) ? (string) $get('unit_price') : '0';

                        return new HtmlString('<div style="text-align:right;font-variant-numeric:tabular-nums">'
                            .number_format((float) bcmul($qty, $price, 2), 2).'</div>');
                    }),
            ])
            ->defaultItems(1)
            ->live()
            ->addActionLabel('Add a line')
            ->columnSpanFull();
    }

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

            if (! empty($line['tax_code_id'])) {
                $rates ??= Filament::getTenant()->taxCodes()->pluck('rate', 'id');
                $rate = (float) ($rates[$line['tax_code_id']] ?? 0);
                $tax = bcadd($tax, number_format((float) $lineTotal * $rate / 100, 2, '.', ''), 2);
            }
        }

        $currency = $get('currency') ?: 'MYR';
        $fmt = fn (string $number) => $currency.' '.number_format((float) $number, 2);
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
