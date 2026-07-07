<x-filament-panels::page>
    <x-filament::section>
        {{-- Controls --}}
        <div class="report-toolbar flex flex-wrap items-end gap-4 mb-4">
            <label class="text-sm">Customer<br>
                <select wire:model.live="partyId" class="rounded border-gray-300 text-sm mt-1">
                    <option value="">— choose —</option>
                    @foreach($this->getCustomers() as $party)
                        <option value="{{ $party->id }}">{{ $party->name }}</option>
                    @endforeach
                </select>
            </label>

            <label class="text-sm">Type<br>
                <select wire:model.live="type" class="rounded border-gray-300 text-sm mt-1">
                    <option value="outstanding">Outstanding invoices</option>
                    <option value="activity">Account activity</option>
                </select>
            </label>

            @if($type === 'activity')
                <label class="text-sm">From<br>
                    <input type="date" wire:model.live="from" class="rounded border-gray-300 text-sm mt-1">
                </label>
                <label class="text-sm">To<br>
                    <input type="date" wire:model.live="to" class="rounded border-gray-300 text-sm mt-1">
                </label>
            @endif

            <span class="flex gap-2 ms-auto">
                <x-filament::button color="gray" size="sm" wire:click="downloadCsv">Download CSV</x-filament::button>
                <x-filament::button color="gray" size="sm" type="button" onclick="window.print()">Print</x-filament::button>
                <x-filament::button color="primary" size="sm" wire:click="sendStatement">Send statement</x-filament::button>
            </span>
        </div>

        @php($s = $this->getStatement())
        @php($party = $partyId ? $this->getCustomers()->firstWhere('id', $partyId) : null)

        @if($s && $party)
            @php($company = \Filament\Facades\Filament::getTenant())
            @php($ccy = $company->base_currency ?? 'MYR')

            {{-- Statement document --}}
            <div class="statement-doc space-y-6">
                {{-- Header: company + customer + period --}}
                <div class="flex flex-wrap justify-between gap-6 border-b pb-4">
                    <div>
                        <h2 class="text-lg font-bold">{{ $company->name }}</h2>
                        @if($company->address_line1)<p class="text-sm text-gray-500">{{ $company->address_line1 }}</p>@endif
                        @if($company->address_line2)<p class="text-sm text-gray-500">{{ $company->address_line2 }}</p>@endif
                        @if($company->city || $company->postcode)<p class="text-sm text-gray-500">{{ trim($company->postcode . ' ' . $company->city) }}</p>@endif
                        @if($company->email)<p class="text-sm text-gray-500">{{ $company->email }}</p>@endif
                    </div>
                    <div class="text-right">
                        <h1 class="text-xl font-semibold">Statement of account</h1>
                        <p class="text-sm text-gray-500 mt-1">
                            {{ $s['type'] === 'outstanding' ? 'Outstanding invoices' : 'Account activity' }}
                        </p>
                        <p class="text-sm text-gray-500">
                            @if($s['type'] === 'outstanding')
                                As of {{ \Illuminate\Support\Carbon::parse($s['as_of'])->format('d M Y') }}
                            @else
                                {{ \Illuminate\Support\Carbon::parse($from)->format('d M Y') }} – {{ \Illuminate\Support\Carbon::parse($to)->format('d M Y') }}
                            @endif
                        </p>
                    </div>
                </div>

                {{-- Customer block --}}
                <div>
                    <p class="text-xs uppercase tracking-wide text-gray-400">Statement for</p>
                    <p class="font-medium">{{ $party->name }}</p>
                    @if($party->email)<p class="text-sm text-gray-500">{{ $party->email }}</p>@endif
                </div>

                {{-- Body --}}
                @if($s['type'] === 'outstanding')
                    <table class="w-full text-sm">
                        <thead>
                        <tr class="text-left border-b font-medium">
                            <th class="py-2">Invoice</th>
                            <th class="py-2">Date</th>
                            <th class="py-2">Due date</th>
                            <th class="py-2 text-right">Amount</th>
                            <th class="py-2 text-right">Balance due</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse($s['invoices'] as $inv)
                            <tr class="border-b">
                                <td class="py-1.5">{{ $inv->invoice_number }}</td>
                                <td class="py-1.5">{{ optional($inv->issue_date)->format('d M Y') }}</td>
                                <td class="py-1.5">{{ optional($inv->due_date)->format('d M Y') ?? '—' }}</td>
                                <td class="py-1.5 text-right">{{ number_format($inv->total, 2) }}</td>
                                <td class="py-1.5 text-right">{{ number_format($inv->balance_due, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-4 text-center text-gray-500">No outstanding invoices. This customer is all paid up.</td></tr>
                        @endforelse
                        <tr class="font-bold border-t-2">
                            <td class="py-2" colspan="4">Balance due</td>
                            <td class="py-2 text-right">{{ $ccy }} {{ number_format($s['balance_due'], 2) }}</td>
                        </tr>
                        </tbody>
                    </table>
                @else
                    <table class="w-full text-sm">
                        <thead>
                        <tr class="text-left border-b font-medium">
                            <th class="py-2">Date</th>
                            <th class="py-2">Description</th>
                            <th class="py-2 text-right">Charges</th>
                            <th class="py-2 text-right">Payments</th>
                            <th class="py-2 text-right">Balance</th>
                        </tr>
                        </thead>
                        <tbody>
                        <tr class="border-b font-medium">
                            <td class="py-1.5" colspan="4">Opening balance</td>
                            <td class="py-1.5 text-right">{{ number_format($s['opening'], 2) }}</td>
                        </tr>
                        @forelse($s['rows'] as $row)
                            <tr class="border-b">
                                <td class="py-1.5">{{ \Illuminate\Support\Carbon::parse($row['date'])->format('d M Y') }}</td>
                                <td class="py-1.5">{{ $row['type'] }} {{ $row['reference'] }}</td>
                                <td class="py-1.5 text-right">{{ (float) $row['charge'] ? number_format($row['charge'], 2) : '' }}</td>
                                <td class="py-1.5 text-right">{{ (float) $row['credit'] ? number_format($row['credit'], 2) : '' }}</td>
                                <td class="py-1.5 text-right">{{ number_format($row['balance'], 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-4 text-center text-gray-500">No activity in this period.</td></tr>
                        @endforelse
                        <tr class="font-bold border-t-2">
                            <td class="py-2" colspan="4">Balance due</td>
                            <td class="py-2 text-right">{{ $ccy }} {{ number_format($s['closing'], 2) }}</td>
                        </tr>
                        </tbody>
                    </table>
                @endif
            </div>
        @else
            <p class="text-sm text-gray-500">Choose a customer to view their statement.</p>
        @endif
    </x-filament::section>

    <style media="print">
        .fi-sidebar, .fi-topbar, .report-toolbar, button, select, input { display: none !important; }
        .fi-main { max-width: 100% !important; padding: 0 !important; }
        .fi-section { box-shadow: none !important; }
    </style>
</x-filament-panels::page>
