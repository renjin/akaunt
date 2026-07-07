<x-mail::message>
# {{ __('Statement of account') }}

{{ __('Hi :name,', ['name' => $party->name]) }}

@if($statement['type'] === 'outstanding')
{{ __('Here is a summary of your outstanding invoices as of :date.', ['date' => \Illuminate\Support\Carbon::parse($statement['as_of'])->format('d M Y')]) }}

<x-mail::table>
| {{ __('Invoice') }} | {{ __('Due date') }} | {{ __('Balance due') }} |
|:--------|:--------|--------:|
@foreach($statement['invoices'] as $inv)
| {{ $inv->invoice_number }} | {{ optional($inv->due_date)->format('d M Y') ?? '—' }} | {{ $party->company->base_currency }} {{ number_format($inv->balance_due, 2) }} |
@endforeach
</x-mail::table>

**{{ __('Total balance due') }}: {{ $party->company->base_currency }} {{ number_format($statement['balance_due'], 2) }}**
@else
{{ __('Here is your account activity for :from – :to.', ['from' => \Illuminate\Support\Carbon::parse($from)->format('d M Y'), 'to' => \Illuminate\Support\Carbon::parse($to)->format('d M Y')]) }}

<x-mail::table>
| {{ __('Date') }} | {{ __('Description') }} | {{ __('Charges') }} | {{ __('Payments') }} | {{ __('Balance') }} |
|:--------|:--------|--------:|--------:|--------:|
| | {{ __('Opening balance') }} | | | {{ number_format($statement['opening'], 2) }} |
@foreach($statement['rows'] as $row)
| {{ \Illuminate\Support\Carbon::parse($row['date'])->format('d M Y') }} | {{ $row['type'] }} {{ $row['reference'] }} | {{ (float) $row['charge'] ? number_format($row['charge'], 2) : '' }} | {{ (float) $row['credit'] ? number_format($row['credit'], 2) : '' }} | {{ number_format($row['balance'], 2) }} |
@endforeach
</x-mail::table>

**{{ __('Balance due') }}: {{ $party->company->base_currency }} {{ number_format($statement['closing'], 2) }}**
@endif

{{ __('Thanks,') }}<br>
{{ $party->company->name }}
</x-mail::message>
