<?php

namespace App\Filament\Pages;

use App\Models\Party;
use Filament\Facades\Filament;
use Filament\Pages\Page;

class CustomerStatement extends Page
{
    protected string $view = 'filament.pages.customer-statement';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-currency-dollar';

    protected static string|\UnitEnum|null $navigationGroup = 'Reports';

    public ?int $partyId = null;
    public string $from = '';
    public string $to = '';

    public function mount(): void
    {
        $this->from = today()->startOfYear()->toDateString();
        $this->to = today()->toDateString();
    }

    public function getCustomers()
    {
        return Filament::getTenant()->parties()->whereIn('role', ['customer', 'both'])->orderBy('name')->get();
    }

    /** @return array{opening: string, rows: array, closing: string}|null */
    public function getStatement(): ?array
    {
        if (! $this->partyId) {
            return null;
        }
        $party = Party::find($this->partyId);
        if (! $party || $party->company_id !== Filament::getTenant()->id) {
            return null;
        }

        // Opening balance: net of everything before the period start.
        $before = $party->invoices()
            ->whereNotIn('status', ['draft', 'void'])
            ->where('issue_date', '<', $this->from)
            ->get();
        $opening = $before->reduce(fn ($c, $i) => bcadd($c, $i->total, 2), '0.00');
        $opening = bcsub($opening, $party->payments()->where('payment_date', '<', $this->from)->sum('amount'), 2);

        $activity = collect();
        foreach ($party->invoices()->whereNotIn('status', ['draft', 'void'])->whereBetween('issue_date', [$this->from, $this->to])->get() as $invoice) {
            $activity->push([
                'date' => $invoice->issue_date, 'type' => $invoice->isCreditNote() ? 'Credit note' : 'Invoice',
                'reference' => $invoice->invoice_number, 'charge' => $invoice->isCreditNote() ? '0.00' : $invoice->total,
                'credit' => $invoice->isCreditNote() ? $invoice->total : '0.00',
            ]);
        }
        foreach ($party->payments()->whereBetween('payment_date', [$this->from, $this->to])->get() as $payment) {
            $activity->push([
                'date' => $payment->payment_date, 'type' => 'Payment', 'reference' => $payment->reference ?? strtoupper($payment->method),
                'charge' => '0.00', 'credit' => $payment->amount,
            ]);
        }
        $activity = $activity->sortBy('date')->values();

        $running = $opening;
        $rows = $activity->map(function ($row) use (&$running) {
            $running = bcadd(bcsub($running, $row['credit'], 2), $row['charge'], 2);

            return $row + ['balance' => $running];
        });

        return ['opening' => $opening, 'rows' => $rows, 'closing' => $running];
    }
}
