<x-filament-panels::page>
    <x-filament::section>
        <div class="flex gap-4 mb-4 items-end flex-wrap">
            <label class="text-sm block">Customer<br>
                <select wire:model.live="partyId" class="rounded border-gray-300 text-sm">
                    <option value="">— choose —</option>
                    @foreach($this->getCustomers() as $party)
                        <option value="{{ $party->id }}">{{ $party->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm">From <input type="date" wire:model.live="from" class="rounded border-gray-300 text-sm"></label>
            <label class="text-sm">To <input type="date" wire:model.live="to" class="rounded border-gray-300 text-sm"></label>
        </div>

        @php($s = $this->getStatement())
        @if($s)
            <table class="w-full text-sm">
                <thead>
                <tr class="text-left border-b font-medium">
                    <th class="py-2">Date</th>
                    <th class="py-2">Type</th>
                    <th class="py-2">Reference</th>
                    <th class="py-2 text-right">Charge</th>
                    <th class="py-2 text-right">Credit</th>
                    <th class="py-2 text-right">Balance</th>
                </tr>
                </thead>
                <tbody>
                <tr class="border-b font-medium">
                    <td class="py-1.5" colspan="5">Opening balance</td>
                    <td class="py-1.5 text-right">{{ number_format($s['opening'], 2) }}</td>
                </tr>
                @forelse($s['rows'] as $row)
                    <tr class="border-b">
                        <td class="py-1.5">{{ \Illuminate\Support\Carbon::parse($row['date'])->format('d M Y') }}</td>
                        <td class="py-1.5">{{ $row['type'] }}</td>
                        <td class="py-1.5">{{ $row['reference'] }}</td>
                        <td class="py-1.5 text-right">{{ (float) $row['charge'] ? number_format($row['charge'], 2) : '' }}</td>
                        <td class="py-1.5 text-right">{{ (float) $row['credit'] ? number_format($row['credit'], 2) : '' }}</td>
                        <td class="py-1.5 text-right">{{ number_format($row['balance'], 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-4 text-center text-gray-500">No activity in this period.</td></tr>
                @endforelse
                <tr class="font-bold border-t-2">
                    <td class="py-2" colspan="5">Balance due</td>
                    <td class="py-2 text-right">{{ number_format($s['closing'], 2) }}</td>
                </tr>
                </tbody>
            </table>
        @else
            <p class="text-sm text-gray-500">Choose a customer to view their statement.</p>
        @endif
    </x-filament::section>
</x-filament-panels::page>
