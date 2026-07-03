<x-filament-panels::page>
    <x-filament::section>
        <div class="flex gap-4 mb-4 items-end">
            <label class="text-sm block">Account<br>
                <select wire:model.live="accountId" class="rounded border-gray-300 text-sm">
                    <option value="">— choose —</option>
                    @foreach($this->getAccounts() as $account)
                        <option value="{{ $account->id }}">{{ $account->code }} · {{ $account->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm">From <input type="date" wire:model.live="from" class="rounded border-gray-300 text-sm"></label>
            <label class="text-sm">To <input type="date" wire:model.live="to" class="rounded border-gray-300 text-sm"></label>
        </div>
        @php($r = $this->getReport())
        @if($r)
            <table class="w-full text-sm">
                <thead>
                <tr class="text-left border-b font-medium">
                    <th class="py-2">Date</th>
                    <th class="py-2">Description</th>
                    <th class="py-2">Ref</th>
                    <th class="py-2 text-right">Debit</th>
                    <th class="py-2 text-right">Credit</th>
                    <th class="py-2 text-right">Balance</th>
                </tr>
                </thead>
                <tbody>
                @forelse($r['rows'] as $row)
                    <tr class="border-b">
                        <td class="py-1.5">{{ \Illuminate\Support\Carbon::parse($row['line']->entry_date)->format('d M Y') }}</td>
                        <td class="py-1.5">{{ $row['line']->entry_description ?? $row['line']->memo }}</td>
                        <td class="py-1.5">{{ $row['line']->reference }}</td>
                        <td class="py-1.5 text-right">{{ (float) $row['line']->debit_base ? number_format($row['line']->debit_base, 2) : '' }}</td>
                        <td class="py-1.5 text-right">{{ (float) $row['line']->credit_base ? number_format($row['line']->credit_base, 2) : '' }}</td>
                        <td class="py-1.5 text-right">{{ number_format($row['balance'], 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-4 text-center text-gray-500">No activity in this period.</td></tr>
                @endforelse
                <tr class="font-bold border-t-2">
                    <td class="py-2" colspan="5">Closing balance</td>
                    <td class="py-2 text-right">{{ number_format($r['closing'], 2) }}</td>
                </tr>
                </tbody>
            </table>
        @else
            <p class="text-sm text-gray-500">Choose an account to view its ledger.</p>
        @endif
    </x-filament::section>
</x-filament-panels::page>
