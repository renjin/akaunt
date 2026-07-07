<x-filament-panels::page>
    <x-filament::section>
        @include('filament.pages.partials.report-toolbar', ['mode' => 'asof'])
        @php($rows = $this->getRows())
        @php($t = $this->getTotals($rows))
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
                    <td class="py-2">
                        <a href="{{ \App\Filament\Resources\Parties\PartyResource::getUrl('view', ['record' => $b['party']]) }}" class="font-medium text-primary-700 hover:underline">{{ $customer }}</a>
                    </td>
                    <td class="py-2 text-right">{{ number_format($b['current'], 2) }}</td>
                    <td class="py-2 text-right">{{ number_format($b['b30'], 2) }}</td>
                    <td class="py-2 text-right">{{ number_format($b['b60'], 2) }}</td>
                    <td class="py-2 text-right">{{ number_format($b['b90'], 2) }}</td>
                    <td class="py-2 text-right font-semibold">{{ number_format($b['total'], 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="py-4 text-center text-gray-500">No outstanding receivables. 🎉</td></tr>
            @endforelse
            @if($rows->isNotEmpty())
                <tr class="font-bold border-t-2">
                    <td class="py-2">Total</td>
                    <td class="py-2 text-right">{{ number_format($t['current'], 2) }}</td>
                    <td class="py-2 text-right">{{ number_format($t['b30'], 2) }}</td>
                    <td class="py-2 text-right">{{ number_format($t['b60'], 2) }}</td>
                    <td class="py-2 text-right">{{ number_format($t['b90'], 2) }}</td>
                    <td class="py-2 text-right">{{ number_format($t['total'], 2) }}</td>
                </tr>
            @endif
            </tbody>
        </table>
        <p class="mt-2 text-xs text-gray-500">Includes unpaid invoices issued on or before the as-of date, aged by due date.</p>
    </x-filament::section>
</x-filament-panels::page>
