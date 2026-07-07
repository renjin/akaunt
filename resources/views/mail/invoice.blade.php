<x-mail::message>
# {{ ($reminder ?? false) ? __('Payment reminder') : __('Invoice') }} {{ $invoice->invoice_number }}

{{ __('Hi :name,', ['name' => $invoice->party->name]) }}

@if($reminder ?? false)
{{ __('This is a friendly reminder that invoice :number has an outstanding balance of :amount', ['number' => "**{$invoice->invoice_number}**", 'amount' => "**{$invoice->currency} " . number_format($invoice->balance_due, 2) . '**']) }}
@if($invoice->due_date)
{{ __(', which was due :date.', ['date' => "**{$invoice->due_date->format('d M Y')}**"]) }}
@else
.
@endif

{{ __('If you have already made payment, please disregard this email.') }}
@else
{{ __('Please find attached invoice :number for :amount', ['number' => "**{$invoice->invoice_number}**", 'amount' => "**{$invoice->currency} " . number_format($invoice->total, 2) . '**']) }}
@if($invoice->due_date)
{{ __(', due :date.', ['date' => "**{$invoice->due_date->format('d M Y')}**"]) }}
@else
.
@endif
@endif

@php($payUrl = $invoice->payment_url ?: $invoice->company->payment_link)
@if($payUrl)
{{ __('Pay online') }}: {{ $payUrl }}

@endif
{{ __('Thanks,') }}<br>
{{ $invoice->company->name }}
</x-mail::message>
