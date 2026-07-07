<x-filament-panels::page>
    <x-filament::section>
        @include('filament.pages.partials.report-toolbar', ['mode' => 'range'])
        @php($report = $this->getReport())

        <table class="w-full text-sm">
            <thead>
            <tr class="border-b text-left font-medium">
                <th class="py-2">Product or service</th>
                <th class="py-2 text-right">Sales qty</th>
                <th class="py-2 text-right">Sales amount</th>
                <th class="py-2 text-right">Purchase qty</th>
                <th class="py-2 text-right">Purchase amount</th>
                <th class="py-2 text-right">Net amount</th>
            </tr>
            </thead>
            <tbody>
            @forelse($report['rows'] as $row)
                <tr class="border-b align-top">
                    <td class="py-2">
                        <details>
                            <summary class="cursor-pointer font-medium text-primary-700 hover:underline">{{ $row['label'] }}</summary>
                            <div class="mt-3 overflow-x-auto rounded-lg border border-gray-200">
                                <table class="w-full text-xs">
                                    <thead class="bg-gray-50 text-left text-gray-500">
                                    <tr>
                                        <th class="px-3 py-2">Date</th>
                                        <th class="px-3 py-2">Type</th>
                                        <th class="px-3 py-2">Source</th>
                                        <th class="px-3 py-2">Customer/Vendor</th>
                                        <th class="px-3 py-2">Description</th>
                                        <th class="px-3 py-2 text-right">Qty</th>
                                        <th class="px-3 py-2 text-right">Amount</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @foreach($row['details'] as $detail)
                                        @php($url = $this->sourceUrl($detail['source']))
                                        <tr class="border-t">
                                            <td class="px-3 py-2">{{ $detail['date']->format('d M Y') }}</td>
                                            <td class="px-3 py-2">{{ $detail['type'] }}</td>
                                            <td class="px-3 py-2">
                                                @if($url)
                                                    <a href="{{ $url }}" class="font-medium text-primary-700 hover:underline">{{ $detail['reference'] }}</a>
                                                @else
                                                    {{ $detail['reference'] }}
                                                @endif
                                            </td>
                                            <td class="px-3 py-2">{{ $detail['party'] }}</td>
                                            <td class="px-3 py-2">{{ $detail['description'] }}</td>
                                            <td class="px-3 py-2 text-right">{{ number_format($detail['quantity'], 2) }}</td>
                                            <td class="px-3 py-2 text-right">{{ number_format($detail['amount'], 2) }}</td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </details>
                    </td>
                    <td class="py-2 text-right">{{ number_format($row['sales_quantity'], 2) }}</td>
                    <td class="py-2 text-right">{{ number_format($row['sales_amount'], 2) }}</td>
                    <td class="py-2 text-right">{{ number_format($row['purchase_quantity'], 2) }}</td>
                    <td class="py-2 text-right">{{ number_format($row['purchase_amount'], 2) }}</td>
                    <td class="py-2 text-right">{{ number_format($row['net_amount'], 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="py-4 text-center text-gray-500">No product or service activity in this period.</td></tr>
            @endforelse
            <tr class="border-t-2 font-bold">
                <td class="py-2">Totals</td>
                <td class="py-2 text-right">{{ number_format($report['totals']['sales_quantity'], 2) }}</td>
                <td class="py-2 text-right">{{ number_format($report['totals']['sales_amount'], 2) }}</td>
                <td class="py-2 text-right">{{ number_format($report['totals']['purchase_quantity'], 2) }}</td>
                <td class="py-2 text-right">{{ number_format($report['totals']['purchase_amount'], 2) }}</td>
                <td class="py-2 text-right">{{ number_format($report['totals']['net_amount'], 2) }}</td>
            </tr>
            </tbody>
        </table>
    </x-filament::section>
</x-filament-panels::page>
