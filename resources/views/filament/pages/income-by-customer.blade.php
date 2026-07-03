<x-filament-panels::page>
    <x-filament::section>
        <div class="flex gap-4 mb-4">
            <label class="text-sm">From <input type="date" wire:model.live="from" class="fi-input rounded border-gray-300 text-sm"></label>
            <label class="text-sm">To <input type="date" wire:model.live="to" class="fi-input rounded border-gray-300 text-sm"></label>
        </div>
        @php($rows = $this->getRows())
        <table class="w-full text-sm">
            <thead>
            <tr class="text-left border-b font-medium">
                <th class="py-2">Customer</th>
                <th class="py-2 text-right">Invoices</th>
                <th class="py-2 text-right">Income (excl. SST)</th>
                <th class="py-2 text-right">Collected</th>
            </tr>
            </thead>
            <tbody>
            @forelse($rows as $customer => $r)
                <tr class="border-b">
                    <td class="py-2">{{ $customer }}</td>
                    <td class="py-2 text-right">{{ $r['count'] }}</td>
                    <td class="py-2 text-right">{{ number_format($r['income'], 2) }}</td>
                    <td class="py-2 text-right">{{ number_format($r['paid'], 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="py-4 text-center text-gray-500">No invoices in this period.</td></tr>
            @endforelse
            </tbody>
        </table>
    </x-filament::section>
</x-filament-panels::page>
