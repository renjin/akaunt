<x-filament-panels::page>
    <x-filament::section>
        <div class="report-toolbar mb-4">
            <label class="text-sm block">Account<br>
                <select wire:model.live="accountId" class="rounded border-gray-300 text-sm">
                    <option value="">— choose —</option>
                    @foreach($this->getAccounts() as $account)
                        <option value="{{ $account->id }}">{{ $account->code }} · {{ $account->name }}</option>
                    @endforeach
                </select>
            </label>
        </div>
        @include('filament.pages.partials.report-toolbar', ['mode' => 'range'])
        @php($r = $this->getReport())
        @if($r)
            <div class="mb-4 rounded-lg border border-gray-200 bg-gray-50 p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Account</p>
                <h2 class="mt-1 text-base font-semibold text-gray-950">{{ $r['account']->code }} · {{ $r['account']->name }}</h2>
                <p class="mt-1 text-sm text-gray-500">Under: {{ str($r['account']->type)->headline() }}{{ $r['account']->subtype ? ' > '.str($r['account']->subtype)->replace('_', ' ')->headline() : '' }}</p>
            </div>
            <table class="w-full text-sm">
                <thead>
                <tr class="text-left border-b font-medium">
                    <th class="py-2">Date</th>
                    <th class="py-2">Description</th>
                    <th class="py-2">Source</th>
                    <th class="py-2">Reference</th>
                    <th class="py-2 text-right">Debit</th>
                    <th class="py-2 text-right">Credit</th>
                    <th class="py-2 text-right">Balance</th>
                </tr>
                </thead>
                <tbody>
                <tr class="border-b bg-gray-50">
                    <td class="py-2" colspan="6">Starting balance</td>
                    <td class="py-2 text-right font-medium">{{ number_format($r['opening'], 2) }}</td>
                </tr>
                @forelse($r['rows'] as $row)
                    @php($source = $row['line']->journalEntry->source)
                    @php($sourceUrl = $this->sourceUrl($source))
                    <tr class="border-b">
                        <td class="py-1.5">{{ $row['line']->journalEntry->entry_date->format('d M Y') }}</td>
                        <td class="py-1.5">{{ $row['line']->journalEntry->description ?? $row['line']->memo }}</td>
                        <td class="py-1.5">
                            @if($sourceUrl)
                                <a href="{{ $sourceUrl }}" class="font-medium text-primary-700 hover:underline">{{ $this->sourceLabel($source) }}</a>
                            @else
                                {{ $this->sourceLabel($source) }}
                            @endif
                        </td>
                        <td class="py-1.5">{{ $row['line']->journalEntry->reference }}</td>
                        <td class="py-1.5 text-right">{{ (float) $row['line']->debit_base ? number_format($row['line']->debit_base, 2) : '' }}</td>
                        <td class="py-1.5 text-right">{{ (float) $row['line']->credit_base ? number_format($row['line']->credit_base, 2) : '' }}</td>
                        <td class="py-1.5 text-right">{{ number_format($row['balance'], 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="py-4 text-center text-gray-500">No activity in this period.</td></tr>
                @endforelse
                <tr class="border-t font-medium">
                    <td class="py-2" colspan="4">Totals</td>
                    <td class="py-2 text-right">{{ number_format(collect($r['rows'])->sum(fn ($row) => (float) $row['line']->debit_base), 2) }}</td>
                    <td class="py-2 text-right">{{ number_format(collect($r['rows'])->sum(fn ($row) => (float) $row['line']->credit_base), 2) }}</td>
                    <td class="py-2"></td>
                </tr>
                <tr class="font-bold border-t-2">
                    <td class="py-2" colspan="6">Ending balance</td>
                    <td class="py-2 text-right">{{ number_format($r['closing'], 2) }}</td>
                </tr>
                </tbody>
            </table>
        @else
            <p class="text-sm text-gray-500">Choose an account to view its transactions.</p>
        @endif
    </x-filament::section>
</x-filament-panels::page>
