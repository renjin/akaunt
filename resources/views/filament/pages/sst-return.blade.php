<x-filament-panels::page>
    <x-filament::section>
        <p class="text-sm text-gray-500 mb-4">
            Output tax collected for the taxable period — figures for your SST-02 return.
            SST is single-stage: there is no input tax credit to net off.
        </p>
        <div class="flex gap-4 mb-4">
            <label class="text-sm">From <input type="date" wire:model.live="from" class="rounded border-gray-300 text-sm"></label>
            <label class="text-sm">To <input type="date" wire:model.live="to" class="rounded border-gray-300 text-sm"></label>
        </div>
        @php($r = $this->getReport())
        <table class="w-full text-sm">
            <thead>
            <tr class="text-left border-b font-medium">
                <th class="py-2">Tax code</th>
                <th class="py-2 text-right">Rate</th>
                <th class="py-2 text-right">Taxable amount</th>
                <th class="py-2 text-right">Output tax</th>
            </tr>
            </thead>
            <tbody>
            @forelse($r['rows'] as $row)
                <tr class="border-b">
                    <td class="py-2">{{ $row->name }}</td>
                    <td class="py-2 text-right">{{ rtrim(rtrim(number_format($row->rate, 2), '0'), '.') }}%</td>
                    <td class="py-2 text-right">{{ number_format($row->taxable, 2) }}</td>
                    <td class="py-2 text-right">{{ number_format($row->tax, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="py-4 text-center text-gray-500">No taxed sales in this period.</td></tr>
            @endforelse
            <tr class="font-bold border-t-2">
                <td class="py-2" colspan="2">Total</td>
                <td class="py-2 text-right">{{ number_format($r['total_taxable'], 2) }}</td>
                <td class="py-2 text-right">{{ number_format($r['total_tax'], 2) }}</td>
            </tr>
            </tbody>
        </table>
    </x-filament::section>
</x-filament-panels::page>
