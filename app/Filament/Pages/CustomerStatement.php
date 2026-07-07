<?php

namespace App\Filament\Pages;

use App\Mail\CustomerStatementMail;
use App\Models\Party;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Mail;

class CustomerStatement extends Page
{
    protected string $view = 'filament.pages.customer-statement';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-currency-dollar';

    protected static string|\UnitEnum|null $navigationGroup = 'Sales & Payments';

    protected static ?string $title = 'Customer Statements';

    protected static ?int $navigationSort = 6;

    public ?int $partyId = null;

    public string $type = 'outstanding';

    public string $from = '';

    public string $to = '';

    public function mount(): void
    {
        $this->from = today()->startOfYear()->toDateString();
        $this->to = today()->toDateString();

        // Allow deep-linking from a customer profile: ?party=<id>
        $party = (int) request()->query('party');
        if ($party > 0 && Filament::getTenant()->parties()->whereKey($party)->whereIn('role', ['customer', 'both'])->exists()) {
            $this->partyId = $party;
        }
    }

    public function getCustomers()
    {
        return Filament::getTenant()->parties()->whereIn('role', ['customer', 'both'])->orderBy('name')->get();
    }

    protected function resolveParty(): ?Party
    {
        if (! $this->partyId) {
            return null;
        }
        $party = Party::find($this->partyId);
        if (! $party || $party->company_id !== Filament::getTenant()->id) {
            return null;
        }

        return $party;
    }

    /**
     * Returns the statement payload for the current customer + type.
     *
     * outstanding: ['type' => 'outstanding', 'invoices' => Collection, 'balance_due' => string, 'as_of' => string]
     * activity:    ['type' => 'activity', 'opening' => string, 'rows' => Collection, 'closing' => string]
     */
    public function getStatement(): ?array
    {
        $party = $this->resolveParty();
        if (! $party) {
            return null;
        }

        return $this->type === 'activity'
            ? $this->activityStatement($party)
            : $this->outstandingStatement($party);
    }

    protected function outstandingStatement(Party $party): array
    {
        $invoices = $party->invoices()
            ->whereNotIn('status', ['draft', 'void'])
            ->whereColumn('amount_paid', '<', 'total')
            ->orderBy('due_date')
            ->orderBy('id')
            ->get();

        $balanceDue = $invoices->reduce(fn ($c, $i) => bcadd($c, $i->balance_due, 2), '0.00');

        return [
            'type' => 'outstanding',
            'invoices' => $invoices,
            'balance_due' => $balanceDue,
            'as_of' => today()->toDateString(),
        ];
    }

    protected function activityStatement(Party $party): array
    {
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

        return ['type' => 'activity', 'opening' => $opening, 'rows' => $rows, 'closing' => $running];
    }

    public function downloadCsv()
    {
        $party = $this->resolveParty();
        $s = $this->getStatement();
        if (! $party || ! $s) {
            return null;
        }

        if ($s['type'] === 'outstanding') {
            return response()->streamDownload(function () use ($s, $party) {
                $out = fopen('php://output', 'w');
                fputcsv($out, ['Customer Statement — Outstanding invoices', $party->name]);
                fputcsv($out, ['As of', $s['as_of']]);
                fputcsv($out, ['Invoice', 'Date', 'Due date', 'Amount', 'Balance due']);
                foreach ($s['invoices'] as $inv) {
                    fputcsv($out, [
                        $inv->invoice_number,
                        optional($inv->issue_date)->toDateString(),
                        optional($inv->due_date)->toDateString(),
                        $inv->total,
                        $inv->balance_due,
                    ]);
                }
                fputcsv($out, ['', '', '', 'Balance due', $s['balance_due']]);
                fclose($out);
            }, 'statement-'.$party->id.'-outstanding-'.$s['as_of'].'.csv');
        }

        return response()->streamDownload(function () use ($s, $party) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Customer Statement — Account activity', $party->name]);
            fputcsv($out, ['Period', $this->from.' to '.$this->to]);
            fputcsv($out, ['Date', 'Description', 'Charges', 'Payments', 'Balance']);
            fputcsv($out, ['', 'Opening balance', '', '', $s['opening']]);
            foreach ($s['rows'] as $row) {
                fputcsv($out, [$row['date'], $row['type'].' '.$row['reference'], $row['charge'], $row['credit'], $row['balance']]);
            }
            fputcsv($out, ['', 'Balance due', '', '', $s['closing']]);
            fclose($out);
        }, 'statement-'.$party->id.'-activity-'.$this->from.'-to-'.$this->to.'.csv');
    }

    public function sendStatement(): void
    {
        $party = $this->resolveParty();
        $s = $this->getStatement();
        if (! $party || ! $s) {
            return;
        }

        if (blank($party->email)) {
            Notification::make()->danger()->title('No email on file for '.$party->name.'.')->send();

            return;
        }

        Mail::to($party->email)->send(new CustomerStatementMail(
            $party,
            $s,
            $this->type === 'activity' ? $this->from : null,
            $this->type === 'activity' ? $this->to : null,
        ));

        Notification::make()->success()->title('Statement sent to '.$party->email.'.')->send();
    }
}
