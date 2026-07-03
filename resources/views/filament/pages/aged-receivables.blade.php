<x-filament-panels::page>
    @php($rows = $this->getRows())
    <x-filament::section>
        <table class="w-full text-sm fi-ta-table">
            <thead>
            <tr class="text-left border-b font-medium">
                <th class="py-2">Customer</th>
                <th class="py-2 text-right">Current</th>
                <th class="py-2 text-right">1–30 days</th>
                <th class="py-2 text-right">31–60 days</th>
                <th class="py-2 text-right">60+ days</th>
                <th class="py-2 text-right">Total due</th>
            </tr>
            </thead>
            <tbody>
            @forelse($rows as $customer => $b)
                <tr class="border-b">
                    <td class="py-2">{{ $customer }}</td>
                    <td class="py-2 text-right">{{ number_format($b['current'], 2) }}</td>
                    <td class="py-2 text-right">{{ number_format($b['b30'], 2) }}</td>
                    <td class="py-2 text-right">{{ number_format($b['b60'], 2) }}</td>
                    <td class="py-2 text-right">{{ number_format($b['b90'], 2) }}</td>
                    <td class="py-2 text-right font-semibold">{{ number_format($b['total'], 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="py-4 text-center text-gray-500">No outstanding receivables. 🎉</td></tr>
            @endforelse
            </tbody>
        </table>
    </x-filament::section>
</x-filament-panels::page>
