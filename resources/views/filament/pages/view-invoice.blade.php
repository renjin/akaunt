<x-filament-panels::page>
    @php
        $balanceDue = max(0, (float) $invoice->total - (float) $invoice->amount_paid);
        $statusColors = [
            'draft' => 'background:#F0F0F0;color:#444',
            'approved' => 'background:#E5F0FF;color:#1D4ED8',
            'sent' => 'background:#E5F0FF;color:#1D4ED8',
            'partial' => 'background:#FEF3C7;color:#92400E',
            'paid' => 'background:#E8F5E0;color:#3F6212',
            'void' => 'background:#FEE2E2;color:#B91C1C',
        ];
        $isOverdue = $invoice->isOverdue();
    @endphp

    {{-- Status strip --}}
    <div class="ak-card" style="display:flex; flex-wrap:wrap; gap:2.5rem; padding:1rem 1.5rem; align-items:center;">
        <div>
            <div class="ak-muted" style="font-size:12px;">Status</div>
            <span style="display:inline-block; margin-top:2px; padding:2px 10px; border-radius:999px; font-size:12px; font-weight:600; {{ $statusColors[$isOverdue ? 'void' : $invoice->status] ?? '' }}">
                {{ $isOverdue ? 'Overdue' : ucfirst($invoice->status) }}
            </span>
        </div>
        <div>
            <div class="ak-muted" style="font-size:12px;">Customer</div>
            <a href="{{ \App\Filament\Resources\Parties\PartyResource::getUrl('view', ['record' => $invoice->party]) }}"
               style="font-weight:600; text-decoration:underline;">{{ $invoice->party->name }}</a>
        </div>
        <div>
            <div class="ak-muted" style="font-size:12px;">Amount due</div>
            <div style="font-weight:700;">{{ $invoice->currency }} {{ number_format($balanceDue, 2) }}</div>
        </div>
        <div>
            <div class="ak-muted" style="font-size:12px;">Due on</div>
            <div style="font-weight:600;">{{ $invoice->due_date?->format('M j, Y') ?? '—' }}</div>
        </div>
    </div>

    {{-- Create card --}}
    <x-filament::section>
        <div style="display:flex; justify-content:space-between; align-items:center; gap:1rem; flex-wrap:wrap;">
            <div>
                <div style="font-weight:700; font-size:16px;">Create</div>
                <div class="ak-muted" style="margin-top:4px;">
                    <strong>Created:</strong> {{ $invoice->created_at->format('F j, Y \a\t g:i A') }}
                </div>
            </div>
            {{ $this->editInvoiceAction }}
        </div>
    </x-filament::section>

    {{-- Send card --}}
    <x-filament::section>
        <div style="display:flex; justify-content:space-between; align-items:center; gap:1rem; flex-wrap:wrap;">
            <div>
                <div style="font-weight:700; font-size:16px;">Send</div>
                <div class="ak-muted" style="margin-top:4px;">
                    @if ($invoice->last_sent_at)
                        <strong>Last sent:</strong> {{ $invoice->last_sent_at->format('F j, Y \a\t g:i A') }}
                    @else
                        Not sent yet.
                        @unless ($invoice->party->email)
                            <em>Add an email address to this customer first.</em>
                        @endunless
                    @endif
                </div>
            </div>
            {{ $this->sendEmailAction }}
        </div>
    </x-filament::section>

    {{-- Payments card --}}
    <x-filament::section>
        <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:1rem; flex-wrap:wrap;">
            <div style="min-width:0;">
                <div style="font-weight:700; font-size:16px;">Manage payments</div>
                <div class="ak-muted" style="margin-top:4px;">
                    <strong>Amount due:</strong> {{ $invoice->currency }} {{ number_format($balanceDue, 2) }}
                    @if ($invoice->status === 'paid') — this invoice is fully paid. @endif
                </div>

                @if ((int) $invoice->reminders_sent_count > 0)
                    <div class="ak-muted" style="margin-top:6px;">
                        {{ $invoice->reminders_sent_count }} {{ Str::plural('reminder', $invoice->reminders_sent_count) }} sent.
                        The last reminder was sent on {{ $invoice->last_reminder_at?->format('F j, Y') }}.
                    </div>
                @endif

                @if ($invoice->allocations->isNotEmpty())
                    <div style="margin-top:10px; font-weight:600;">Payments received:</div>
                    <ul style="margin:4px 0 0; padding-left:1.1rem;">
                        @foreach ($invoice->allocations as $allocation)
                            <li class="ak-muted">
                                {{ $allocation->payment->payment_date?->format('M j, Y') }} —
                                {{ $invoice->currency }} {{ number_format((float) $allocation->amount, 2) }}
                                ({{ strtoupper($allocation->payment->method ?? '—') }})
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
            <div style="display:flex; gap:.5rem; flex-wrap:wrap;">
                @if ($isOverdue)
                    {{ $this->sendReminderAction }}
                @endif
                {{ $this->recordPaymentAction }}
            </div>
        </div>
    </x-filament::section>

    @if (! empty($ledgerLinks))
        <x-filament::section>
            <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:1rem; flex-wrap:wrap;">
                <div>
                    <div style="font-weight:700; font-size:16px;">Accounting</div>
                    <div class="ak-muted" style="margin-top:4px;">Posted accounts for this invoice. Open a row to view the matching account transactions.</div>
                </div>
            </div>
            <div style="margin-top:12px; overflow-x:auto;">
                <table class="w-full text-sm">
                    <thead>
                    <tr class="text-left border-b font-medium">
                        <th class="py-2">Account</th>
                        <th class="py-2 text-right">Debit</th>
                        <th class="py-2 text-right">Credit</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($ledgerLinks as $link)
                        <tr class="border-b">
                            <td class="py-2"><a href="{{ $link['url'] }}" class="font-medium text-primary-700 hover:underline">{{ $link['label'] }}</a></td>
                            <td class="py-2 text-right">{{ (float) $link['debit'] ? number_format($link['debit'], 2) : '' }}</td>
                            <td class="py-2 text-right">{{ (float) $link['credit'] ? number_format($link['credit'], 2) : '' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif

    {{-- Rendered invoice document --}}
    <x-filament::section>
        <iframe srcdoc="{{ $documentHtml }}" sandbox=""
                style="width:100%; height:1100px; border:1px solid #E8E8E8; border-radius:8px; background:#fff;"
                title="Invoice document preview"></iframe>
    </x-filament::section>
</x-filament-panels::page>
