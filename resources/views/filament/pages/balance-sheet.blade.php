<x-filament-panels::page>
    <x-filament::section>
        @include('filament.pages.partials.report-toolbar', ['mode' => 'asof'])
        @php($r = $this->getReport())
        @php($gl = fn ($account) => \App\Filament\Pages\GeneralLedger::getUrl(['account' => $account->id, 'from' => '1970-01-01', 'to' => $this->asOf]))
        <table class="w-full text-sm">
            <tbody>
            @foreach(['asset' => 'Assets', 'liability' => 'Liabilities', 'equity' => 'Equity'] as $type => $label)
                <tr class="font-semibold border-b"><td class="py-2" colspan="2">{{ $label }}</td></tr>
                @foreach($r['sections'][$type] as $row)
                    <tr>
                        <td class="py-1 pl-4">
                            @if($row['account'] ?? null)
                                <a href="{{ $gl($row['account']) }}" class="hover:underline">{{ $row['account']->code }} · {{ $row['account']->name }}</a>
                            @else
                                {{ $row['label'] }}
                            @endif
                        </td>
                        <td class="py-1 text-right">{{ number_format($row['balance'], 2) }}</td>
                    </tr>
                @endforeach
                <tr class="border-t font-medium">
                    <td class="py-1">Total {{ strtolower($label) }}</td>
                    <td class="py-1 text-right">{{ number_format($r['totals'][$type], 2) }}</td>
                </tr>
            @endforeach
            <tr class="font-bold border-t-2">
                <td class="py-2">Liabilities + equity</td>
                <td class="py-2 text-right">{{ number_format($r['liabilities_plus_equity'], 2) }}</td>
            </tr>
            </tbody>
        </table>
        @if($r['totals']['asset'] !== $r['liabilities_plus_equity'])
            <div class="mt-2 text-danger-600 font-semibold">⚠ Does not balance!</div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
