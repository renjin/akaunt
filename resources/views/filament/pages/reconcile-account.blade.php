<x-filament-panels::page>
    <x-filament::section>
        <div class="flex gap-4 mb-4 items-end flex-wrap">
            <label class="text-sm block">Account<br>
                <select wire:model.live="accountId" class="rounded border-gray-300 text-sm">
                    @foreach($this->getAccounts() as $account)
                        <option value="{{ $account->id }}">{{ $account->code }} · {{ $account->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm">Statement date <input type="date" wire:model.live="statementDate" class="rounded border-gray-300 text-sm"></label>
            <label class="text-sm">Statement ending balance <input type="text" wire:model.live="statementBalance" class="rounded border-gray-300 text-sm w-32"></label>
        </div>

        <div class="grid grid-cols-3 gap-4 mb-4 text-sm">
            <div class="p-3 rounded bg-gray-50"><div class="text-gray-500">Previously reconciled</div><div class="font-semibold">{{ number_format($this->getPreviousBalance(), 2) }}</div></div>
            <div class="p-3 rounded bg-gray-50"><div class="text-gray-500">Cleared balance (ticked)</div><div class="font-semibold">{{ number_format($this->getClearedBalance(), 2) }}</div></div>
            <div class="p-3 rounded {{ (float) $this->getDifference() === 0.0 ? 'bg-success-50' : 'bg-danger-50' }}">
                <div class="text-gray-500">Difference</div>
                <div class="font-semibold">{{ number_format($this->getDifference(), 2) }}</div>
            </div>
        </div>

        @php($lines = $this->getLines())
        <table class="w-full text-sm">
            <thead>
            <tr class="text-left border-b font-medium">
                <th class="py-2 w-8"></th>
                <th class="py-2">Date</th>
                <th class="py-2">Description</th>
                <th class="py-2 text-right">Debit</th>
                <th class="py-2 text-right">Credit</th>
            </tr>
            </thead>
            <tbody>
            @forelse($lines as $line)
                <tr class="border-b">
                    <td class="py-1.5"><input type="checkbox" wire:model.live="checked.{{ $line->id }}"></td>
                    <td class="py-1.5">{{ \Illuminate\Support\Carbon::parse($line->entry_date)->format('d M Y') }}</td>
                    <td class="py-1.5">{{ $line->entry_description ?? $line->memo }}</td>
                    <td class="py-1.5 text-right">{{ (float) $line->debit_base ? number_format($line->debit_base, 2) : '' }}</td>
                    <td class="py-1.5 text-right">{{ (float) $line->credit_base ? number_format($line->credit_base, 2) : '' }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="py-4 text-center text-gray-500">Nothing outstanding up to this date. Everything's reconciled.</td></tr>
            @endforelse
            </tbody>
        </table>

        @if($lines->isNotEmpty())
            <div class="mt-4">
                <x-filament::button wire:click="finish" :disabled="(float) $this->getDifference() !== 0.0">
                    Finish reconciliation
                </x-filament::button>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
