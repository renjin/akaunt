@php
    $money = fn ($amount) => 'MYR ' . number_format((float) $amount, 2);
    $cashMax = max(1, collect($cashFlowBars)->flatMap(fn ($bar) => [$bar['inflow'], $bar['outflow']])->max());
@endphp

<x-filament-panels::page>
    <div class="ak-dashboard">
        <section class="ak-notice">
            <div>
                <strong>Malaysia-ready accounting workspace</strong>
                <p>Review cash flow, invoices, bills, and posting activity before you jump into detailed records.</p>
            </div>
            <a href="{{ \App\Filament\Resources\Invoices\InvoiceResource::getUrl() }}">Review invoices</a>
        </section>

        <section class="ak-hero">
            <div>
                <h2>Good morning</h2>
                <p>{{ $company->name }} has {{ $summary['unmatched'] }} bank transactions waiting for review.</p>
            </div>
            <a class="ak-secondary" href="{{ \App\Filament\Pages\CompanySettings::getUrl() }}">Company settings</a>
        </section>

        <div class="ak-actions" aria-label="Quick actions">
            @foreach ($actions as $action)
                <a class="ak-action ak-action-{{ $action['tone'] }}" href="{{ $action['href'] }}">
                    <span class="ak-action-icon">{{ strtoupper(substr($action['label'], 0, 1)) }}</span>
                    <span>{{ $action['label'] }}</span>
                </a>
            @endforeach
        </div>

        <div class="ak-grid ak-grid-main">
            <section class="ak-panel ak-span-2">
                <div class="ak-panel-head">
                    <div>
                        <h3>Overdue invoices and bills</h3>
                        <p>Prioritize collections and vendor obligations.</p>
                    </div>
                    <a href="{{ \App\Filament\Pages\AgedReceivables::getUrl() }}">View aging</a>
                </div>
                <div class="ak-split">
                    <div>
                        <div class="ak-metric-label">Overdue invoices</div>
                        <div class="ak-metric">{{ $money($summary['overdue_invoices']) }}</div>
                        <div class="ak-list">
                            @forelse ($openInvoices as $invoice)
                                <a href="{{ \App\Filament\Resources\Invoices\InvoiceResource::getUrl('edit', ['record' => $invoice]) }}">
                                    <span>{{ $invoice->party?->name ?? 'Customer' }}</span>
                                    <strong>{{ $money($invoice->balance_due) }}</strong>
                                </a>
                            @empty
                                <p>No open invoices.</p>
                            @endforelse
                        </div>
                    </div>
                    <div>
                        <div class="ak-metric-label">Overdue bills</div>
                        <div class="ak-metric">{{ $money($summary['overdue_bills']) }}</div>
                        <div class="ak-list">
                            @forelse ($openBills as $bill)
                                <a href="{{ \App\Filament\Resources\Bills\BillResource::getUrl('edit', ['record' => $bill]) }}">
                                    <span>{{ $bill->party?->name ?? 'Vendor' }}</span>
                                    <strong>{{ $money($bill->balance_due) }}</strong>
                                </a>
                            @empty
                                <p>No open bills.</p>
                            @endforelse
                        </div>
                    </div>
                </div>
            </section>

            <section class="ak-panel">
                <div class="ak-panel-head">
                    <div>
                        <h3>Cash flow</h3>
                        <p>Bank activity by month.</p>
                    </div>
                    <a href="{{ \App\Filament\Resources\BankTransactions\BankTransactionResource::getUrl() }}">Transactions</a>
                </div>
                <div class="ak-bars">
                    @foreach ($cashFlowBars as $bar)
                        <div class="ak-bar-row">
                            <span>{{ $bar['label'] }}</span>
                            <div>
                                <i class="ak-inflow" style="width: {{ max(4, ($bar['inflow'] / $cashMax) * 100) }}%"></i>
                                <i class="ak-outflow" style="width: {{ max(4, ($bar['outflow'] / $cashMax) * 100) }}%"></i>
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="ak-panel">
                <div class="ak-panel-head">
                    <div>
                        <h3>Profit and loss</h3>
                        <p>Last 12 months, accrual basis.</p>
                    </div>
                    <a href="{{ \App\Filament\Pages\ProfitAndLoss::getUrl() }}">View report</a>
                </div>
                <div class="ak-metric">{{ $money($summary['net_profit']) }}</div>
                <div class="ak-profit-bars">
                    @foreach ($profitBars as $bar)
                        <div>
                            <span>{{ $bar['label'] }}</span>
                            <strong>{{ $money($bar['value']) }}</strong>
                            <i class="ak-{{ $bar['tone'] }}" style="width: {{ $bar['width'] }}%"></i>
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="ak-panel">
                <div class="ak-panel-head">
                    <div>
                        <h3>Payable and owing</h3>
                        <p>Amounts due in the next 30 days.</p>
                    </div>
                </div>
                <div class="ak-dues">
                    <div>
                        <span>Invoices due soon</span>
                        <strong>{{ $money($summary['due_soon']) }}</strong>
                    </div>
                    <div>
                        <span>Bank inflow</span>
                        <strong>{{ $money($summary['bank_inflow']) }}</strong>
                    </div>
                    <div>
                        <span>Bank outflow</span>
                        <strong>{{ $money($summary['bank_outflow']) }}</strong>
                    </div>
                </div>
            </section>

            <section class="ak-panel">
                <div class="ak-panel-head">
                    <div>
                        <h3>Connected accounts</h3>
                        <p>Import statements and keep posting queues current.</p>
                    </div>
                </div>
                <div class="ak-bank-card">
                    <span>Bank Account</span>
                    <strong>{{ $summary['unmatched'] }} unmatched</strong>
                    <a href="{{ \App\Filament\Resources\BankTransactions\BankTransactionResource::getUrl() }}">Review queue</a>
                </div>
            </section>
        </div>
    </div>
</x-filament-panels::page>
