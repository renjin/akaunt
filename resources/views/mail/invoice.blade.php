<x-mail::message>
# {{ __('Invoice') }} {{ $invoice->invoice_number }}

{{ __('Hi :name,', ['name' => $invoice->party->name]) }}

{{ __('Please find attached invoice :number for :amount', ['number' => "**{$invoice->invoice_number}**", 'amount' => "**{$invoice->currency} " . number_format($invoice->total, 2) . '**']) }}
@if($invoice->due_date)
{{ __(', due :date.', ['date' => "**{$invoice->due_date->format('d M Y')}**"]) }}
@else
.
@endif

@if($invoice->company->payment_link)
{{ __('Pay online') }}: {{ $invoice->company->payment_link }}

@endif
{{ __('Thanks,') }}<br>
{{ $invoice->company->name }}
</x-mail::message>
