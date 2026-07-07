<x-filament-panels::page>
    <x-filament::section>
        @include('filament.pages.partials.report-toolbar', ['mode' => 'range'])
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
                    <td class="py-2">
                        <a href="{{ \App\Filament\Resources\Parties\PartyResource::getUrl('view', ['record' => $r['party']]) }}" class="font-medium text-primary-700 hover:underline">{{ $customer }}</a>
                    </td>
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
