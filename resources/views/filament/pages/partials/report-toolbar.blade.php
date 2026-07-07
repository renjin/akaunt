{{-- Shared report toolbar: period label, date inputs + presets, CSV/print buttons, print CSS.
     Usage: @include('filament.pages.partials.report-toolbar', ['mode' => 'range' or 'asof']) --}}
@php
    $mode = $mode ?? 'range';
    $fmt = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('j M Y') : '—';
@endphp

<p class="text-sm text-gray-500 mb-3">
    @if($mode === 'range')
        {{ $fmt($this->from) }} – {{ $fmt($this->to) }}
    @else
        As of {{ $fmt($this->asOf) }}
    @endif
</p>

<div class="report-toolbar flex flex-wrap items-center gap-4 mb-4">
    @if($mode === 'range')
        <label class="text-sm">From <input type="date" wire:model.live="from" class="rounded border-gray-300 text-sm"></label>
        <label class="text-sm">To <input type="date" wire:model.live="to" class="rounded border-gray-300 text-sm"></label>
        <select x-data class="rounded border-gray-300 text-sm"
                x-on:change="if ($event.target.value) { const [f, t] = $event.target.value.split('|'); $wire.set('from', f, false); $wire.set('to', t); $event.target.value = ''; }">
            <option value="">Preset…</option>
            <option value="{{ today()->startOfMonth()->toDateString() }}|{{ today()->endOfMonth()->toDateString() }}">This month</option>
            <option value="{{ today()->subMonthNoOverflow()->startOfMonth()->toDateString() }}|{{ today()->subMonthNoOverflow()->endOfMonth()->toDateString() }}">Last month</option>
            <option value="{{ today()->firstOfQuarter()->toDateString() }}|{{ today()->lastOfQuarter()->toDateString() }}">This quarter</option>
            <option value="{{ today()->startOfYear()->toDateString() }}|{{ today()->toDateString() }}">This year</option>
            <option value="{{ today()->subYear()->startOfYear()->toDateString() }}|{{ today()->subYear()->endOfYear()->toDateString() }}">Last year</option>
            <option value="1970-01-01|{{ today()->toDateString() }}">All time</option>
        </select>
    @else
        <label class="text-sm">As of <input type="date" wire:model.live="asOf" class="rounded border-gray-300 text-sm"></label>
        <select x-data class="rounded border-gray-300 text-sm"
                x-on:change="if ($event.target.value) { $wire.set('asOf', $event.target.value); $event.target.value = ''; }">
            <option value="">Preset…</option>
            <option value="{{ today()->toDateString() }}">Today</option>
            <option value="{{ today()->startOfMonth()->subDay()->toDateString() }}">End of last month</option>
            <option value="{{ today()->subYear()->endOfYear()->toDateString() }}">End of last year</option>
        </select>
    @endif

    <span class="flex gap-2 ms-auto">
        <x-filament::button color="gray" size="sm" wire:click="downloadCsv">Download CSV</x-filament::button>
        <x-filament::button color="gray" size="sm" type="button" onclick="window.print()">Print</x-filament::button>
    </span>
</div>

<style media="print">
    .fi-sidebar, .fi-topbar, .report-toolbar, button, select, input { display: none !important; }
    .fi-main { max-width: 100% !important; padding: 0 !important; }
    .fi-section { box-shadow: none !important; }
</style>
