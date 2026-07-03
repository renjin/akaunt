<x-mail::message>
# Invoice {{ $invoice->invoice_number }}

Hi {{ $invoice->party->name }},

Please find attached invoice **{{ $invoice->invoice_number }}** for
**{{ $invoice->currency }} {{ number_format($invoice->total, 2) }}**@if($invoice->due_date), due **{{ $invoice->due_date->format('d M Y') }}**@endif.

Thanks,<br>
{{ $invoice->company->name }}
</x-mail::message>
