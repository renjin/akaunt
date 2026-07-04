<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<style>
    * { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1a1a1a; }
    body { margin: 32px; }
    h1 { font-size: 22px; margin: 0 0 2px; letter-spacing: 1px; }
    table { width: 100%; border-collapse: collapse; }
    .meta td { padding: 1px 0; vertical-align: top; }
    .lines th { text-align: left; border-bottom: 2px solid #1a1a1a; padding: 6px 4px; font-size: 11px; text-transform: uppercase; }
    .lines td { border-bottom: 1px solid #ddd; padding: 6px 4px; }
    .num { text-align: right; }
    .totals td { padding: 3px 4px; }
    .totals .grand { font-size: 14px; font-weight: bold; border-top: 2px solid #1a1a1a; }
    .muted { color: #777; }
    .badge { font-size: 10px; color: #777; text-transform: uppercase; letter-spacing: 1px; }
</style>
</head>
<body>
<table class="meta">
    <tr>
        <td>
            <h1>{{ strtoupper(__('Invoice')) }}</h1>
            <div class="badge">{{ $invoice->invoice_number }}</div>
        </td>
        <td style="text-align:right">
            <strong>{{ $company->name }}</strong><br>
            @if($company->brn) {{ $company->brn }}<br> @endif
            @if($company->sst_registration_no) SST: {{ $company->sst_registration_no }}<br> @endif
            @if($company->address_line1) {{ $company->address_line1 }}<br> @endif
            @if($company->address_line2) {{ $company->address_line2 }}<br> @endif
            @if($company->postcode || $company->city) {{ $company->postcode }} {{ $company->city }} {{ $company->state }}<br> @endif
            @if($company->email) {{ $company->email }} @endif
        </td>
    </tr>
</table>

<br>

<table class="meta">
    <tr>
        <td>
            <span class="muted">{{ __('Bill to') }}</span><br>
            <strong>{{ $party->name }}</strong><br>
            @if($party->registration_number) {{ $party->registration_scheme }}: {{ $party->registration_number }}<br> @endif
            @if($party->tin) TIN: {{ $party->tin }}<br> @endif
            @if($party->address_line1) {{ $party->address_line1 }}<br> @endif
            @if($party->postcode || $party->city) {{ $party->postcode }} {{ $party->city }} {{ $party->state }} @endif
        </td>
        <td style="text-align:right">
            <span class="muted">{{ __('Issue date') }}:</span> {{ $invoice->issue_date->format('d M Y') }}<br>
            @if($invoice->due_date)<span class="muted">{{ __('Due date') }}:</span> {{ $invoice->due_date->format('d M Y') }}<br>@endif
            <span class="muted">{{ __('Currency') }}:</span> {{ $invoice->currency }}
        </td>
    </tr>
</table>

<br>

<table class="lines">
    <thead>
    <tr>
        <th style="width:46%">{{ __('Description') }}</th>
        <th class="num">{{ __('Qty') }}</th>
        <th class="num">{{ __('Unit price') }}</th>
        <th class="num">{{ __('Tax') }}</th>
        <th class="num">{{ __('Amount') }}</th>
    </tr>
    </thead>
    <tbody>
    @foreach($invoice->lines as $line)
        <tr>
            <td>{{ $line->description }}</td>
            <td class="num">{{ rtrim(rtrim(number_format($line->quantity, 2), '0'), '.') }}</td>
            <td class="num">{{ number_format($line->unit_price, 2) }}</td>
            <td class="num">{{ number_format($line->tax_amount, 2) }}</td>
            <td class="num">{{ number_format($line->line_total, 2) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<table style="width: 40%; margin-left: 60%;" class="totals">
    <tr><td class="muted">{{ __('Subtotal') }}</td><td class="num">{{ number_format($invoice->subtotal, 2) }}</td></tr>
    @if((float) $invoice->tax_total > 0)
        <tr><td class="muted">{{ __('SST') }}</td><td class="num">{{ number_format($invoice->tax_total, 2) }}</td></tr>
    @endif
    @if((float) $invoice->rounding != 0)
        <tr><td class="muted">{{ __('Rounding') }}</td><td class="num">{{ number_format($invoice->rounding, 2) }}</td></tr>
    @endif
    <tr class="grand"><td class="grand">{{ __('Total') }} ({{ $invoice->currency }})</td><td class="num grand">{{ number_format($invoice->total, 2) }}</td></tr>
    @if((float) $invoice->amount_paid > 0)
        <tr><td class="muted">{{ __('Paid') }}</td><td class="num">-{{ number_format($invoice->amount_paid, 2) }}</td></tr>
        <tr><td class="muted">{{ __('Balance due') }}</td><td class="num"><strong>{{ number_format($invoice->balance_due, 2) }}</strong></td></tr>
    @endif
</table>

@if($invoice->notes)
    <br><br>
    <div class="muted">{{ __('Notes') }}</div>
    <div>{{ $invoice->notes }}</div>
@endif

@php($showPay = ! $invoice->isCreditNote() && (float) $invoice->balance_due > 0 && ($company->duitnow_qr_payload || $company->payment_link))
@if($showPay)
    <br><br>
    <table class="meta">
        <tr>
            <td>
                <div class="badge">{{ __('How to pay') }}</div>
                @if($company->duitnow_qr_payload)<div class="muted">{{ __('Scan the DuitNow QR with any Malaysian banking app') }}</div>@endif
                @if($company->payment_link)<div>{{ __('Pay online') }}: {{ $company->payment_link }}</div>@endif
            </td>
            @if($company->duitnow_qr_payload)
                <td style="text-align:right">
                    <img src="{{ App\Services\DuitnowQr::dataUri($company->duitnow_qr_payload) }}" width="90" height="90" alt="DuitNow QR">
                </td>
            @endif
        </tr>
    </table>
@endif

@php($submission = $invoice->einvoice_status === 'validated' ? $invoice->submission : null)
@if($submission)
    <br><br>
    <table class="meta">
        <tr>
            <td>
                <div class="badge">{{ __('LHDN e-Invoice — validated') }}</div>
                @if($submission->einvoice_url)<div class="muted">{{ $submission->einvoice_url }}</div>@endif
                @if($submission->lhdn_uuid)<div class="muted">UUID: {{ $submission->lhdn_uuid }}</div>@endif
            </td>
            <td style="text-align:right">
                @if($submission->qr_path && Illuminate\Support\Facades\Storage::exists($submission->qr_path))
                    <img src="data:image/jpeg;base64,{{ base64_encode(Illuminate\Support\Facades\Storage::get($submission->qr_path)) }}" width="90" height="90" alt="e-Invoice QR">
                @endif
            </td>
        </tr>
    </table>
@endif
</body>
</html>
