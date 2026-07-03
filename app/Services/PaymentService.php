<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PaymentService
{
    public function __construct(private PostingService $poster)
    {
    }

    /**
     * Pay a vendor bill. Posts Dr Accounts Payable / Cr Bank and rolls the bill status.
     */
    public function payBill(
        \App\Models\Bill $bill,
        string $amount,
        string $paymentDate,
        Account $bankAccount,
        string $method = 'bank_transfer',
        ?string $reference = null,
    ): Payment {
        if (! $bill->isPosted()) {
            throw new InvalidArgumentException('Cannot pay a draft or void bill.');
        }
        $amount = number_format((float) $amount, 2, '.', '');
        if ((float) $amount <= 0) {
            throw new InvalidArgumentException('Payment amount must be positive.');
        }
        if (bccomp($amount, $bill->balance_due, 2) === 1) {
            throw new InvalidArgumentException("Payment {$amount} exceeds balance due {$bill->balance_due}.");
        }

        return DB::transaction(function () use ($bill, $amount, $paymentDate, $bankAccount, $method, $reference) {
            $company = $bill->company;

            $payment = $company->payments()->create([
                'party_id' => $bill->party_id,
                'payment_type' => 'made',
                'method' => $method,
                'payment_date' => $paymentDate,
                'amount' => $amount,
                'currency' => $bill->currency,
                'fx_rate' => $bill->fx_rate,
                'bank_account_id' => $bankAccount->id,
                'reference' => $reference,
            ]);

            $payment->allocations()->create([
                'allocatable_type' => $bill->getMorphClass(),
                'allocatable_id' => $bill->id,
                'amount' => $amount,
            ]);

            $this->poster->post(
                $company,
                $paymentDate,
                [
                    ['account_id' => $company->systemAccount('accounts_payable')->id, 'debit' => $amount, 'currency' => $bill->currency, 'fx_rate' => $bill->fx_rate],
                    ['account_id' => $bankAccount->id, 'credit' => $amount, 'currency' => $bill->currency, 'fx_rate' => $bill->fx_rate],
                ],
                "Payment for bill {$bill->bill_number} — {$bill->party->name}",
                $reference,
                $payment,
            );

            $paid = bcadd($bill->amount_paid, $amount, 2);
            $bill->forceFill([
                'amount_paid' => $paid,
                'status' => bccomp($paid, $bill->total, 2) >= 0 ? 'paid' : 'partial',
            ])->save();

            return $payment;
        });
    }

    /**
     * Record a customer payment against an invoice.
     * Posts Dr Bank / Cr Accounts Receivable and rolls the invoice status.
     */
    public function receiveAgainstInvoice(
        Invoice $invoice,
        string $amount,
        string $paymentDate,
        Account $bankAccount,
        string $method = 'bank_transfer',
        ?string $reference = null,
    ): Payment {
        if (! $invoice->isPosted()) {
            throw new InvalidArgumentException('Cannot receive payment on a draft or void invoice.');
        }
        $amount = number_format((float) $amount, 2, '.', '');
        if ((float) $amount <= 0) {
            throw new InvalidArgumentException('Payment amount must be positive.');
        }
        if (bccomp($amount, $invoice->balance_due, 2) === 1) {
            throw new InvalidArgumentException("Payment {$amount} exceeds balance due {$invoice->balance_due}.");
        }

        return DB::transaction(function () use ($invoice, $amount, $paymentDate, $bankAccount, $method, $reference) {
            $company = $invoice->company;

            $payment = $company->payments()->create([
                'party_id' => $invoice->party_id,
                'payment_type' => 'received',
                'method' => $method,
                'payment_date' => $paymentDate,
                'amount' => $amount,
                'currency' => $invoice->currency,
                'fx_rate' => $invoice->fx_rate,
                'bank_account_id' => $bankAccount->id,
                'reference' => $reference,
            ]);

            $payment->allocations()->create([
                'allocatable_type' => $invoice->getMorphClass(),
                'allocatable_id' => $invoice->id,
                'amount' => $amount,
            ]);

            $this->poster->post(
                $company,
                $paymentDate,
                [
                    ['account_id' => $bankAccount->id, 'debit' => $amount, 'currency' => $invoice->currency, 'fx_rate' => $invoice->fx_rate],
                    ['account_id' => $company->systemAccount('accounts_receivable')->id, 'credit' => $amount, 'currency' => $invoice->currency, 'fx_rate' => $invoice->fx_rate],
                ],
                "Payment for {$invoice->invoice_number} — {$invoice->party->name}",
                $reference,
                $payment,
            );

            $paid = bcadd($invoice->amount_paid, $amount, 2);
            $invoice->forceFill([
                'amount_paid' => $paid,
                'status' => bccomp($paid, $invoice->total, 2) >= 0 ? 'paid' : 'partial',
            ])->save();

            return $payment;
        });
    }
}
