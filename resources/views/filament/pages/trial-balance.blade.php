<x-filament-panels::page>
    <x-filament::section>
        <div class="mb-4">
            <label class="text-sm">As of <input type="date" wire:model.live="asOf" class="rounded border-gray-300 text-sm"></label>
        </div>
        @php($r = $this->getReport())
        <table class="w-full text-sm">
            <thead>
            <tr class="text-left border-b font-medium">
                <th class="py-2">Account</th>
                <th class="py-2 text-right">Debit</th>
                <th class="py-2 text-right">Credit</th>
            </tr>
            </thead>
            <tbody>
            @foreach($r['rows'] as $row)
                <tr class="border-b">
                    <td class="py-1.5">{{ $row['account']->code }} · {{ $row['account']->name }}</td>
                    <td class="py-1.5 text-right">{{ (float) $row['debit'] ? number_format($row['debit'], 2) : '' }}</td>
                    <td class="py-1.5 text-right">{{ (float) $row['credit'] ? number_format($row['credit'], 2) : '' }}</td>
                </tr>
            @endforeach
            <tr class="font-bold border-t-2">
                <td class="py-2">Total</td>
                <td class="py-2 text-right">{{ number_format($r['total_debit'], 2) }}</td>
                <td class="py-2 text-right">{{ number_format($r['total_credit'], 2) }}</td>
            </tr>
            </tbody>
        </table>
        @if($r['total_debit'] !== $r['total_credit'])
            <div class="mt-2 text-danger-600 font-semibold">⚠ Out of balance!</div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
