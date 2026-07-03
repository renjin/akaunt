<x-filament-panels::page>
    <x-filament::section>
        <div class="flex gap-4 mb-4">
            <label class="text-sm">From <input type="date" wire:model.live="from" class="rounded border-gray-300 text-sm"></label>
            <label class="text-sm">To <input type="date" wire:model.live="to" class="rounded border-gray-300 text-sm"></label>
        </div>
        @php($r = $this->getReport())
        <table class="w-full text-sm">
            <tbody>
            <tr class="font-semibold border-b"><td class="py-2" colspan="2">Income</td></tr>
            @foreach($r['sections']['income'] as $row)
                <tr><td class="py-1 pl-4">{{ $row['account']->code }} · {{ $row['account']->name }}</td>
                    <td class="py-1 text-right">{{ number_format($row['balance'], 2) }}</td></tr>
            @endforeach
            <tr class="border-t font-medium"><td class="py-1">Total income</td>
                <td class="py-1 text-right">{{ number_format($r['totals']['income'], 2) }}</td></tr>

            @if(count($r['sections']['cogs']))
                <tr class="font-semibold border-b"><td class="py-2" colspan="2">Cost of sales</td></tr>
                @foreach($r['sections']['cogs'] as $row)
                    <tr><td class="py-1 pl-4">{{ $row['account']->code }} · {{ $row['account']->name }}</td>
                        <td class="py-1 text-right">({{ number_format($row['balance'], 2) }})</td></tr>
                @endforeach
                <tr class="border-t font-medium"><td class="py-1">Gross profit</td>
                    <td class="py-1 text-right">{{ number_format($r['gross_profit'], 2) }}</td></tr>
            @endif

            <tr class="font-semibold border-b"><td class="py-2" colspan="2">Expenses</td></tr>
            @foreach($r['sections']['expense'] as $row)
                <tr><td class="py-1 pl-4">{{ $row['account']->code }} · {{ $row['account']->name }}</td>
                    <td class="py-1 text-right">({{ number_format($row['balance'], 2) }})</td></tr>
            @endforeach
            <tr class="border-t font-medium"><td class="py-1">Total expenses</td>
                <td class="py-1 text-right">({{ number_format($r['totals']['expense'], 2) }})</td></tr>

            <tr class="font-bold border-t-2 text-base">
                <td class="py-2">Net profit</td>
                <td class="py-2 text-right">{{ number_format($r['net_profit'], 2) }}</td>
            </tr>
            </tbody>
        </table>
    </x-filament::section>
</x-filament-panels::page>
