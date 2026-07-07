<?php

namespace App\Services;

use App\Models\Bill;
use App\Models\Company;
use App\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PurchaseOrderService
{
    public function nextNumber(Company $company): string
    {
        $last = $company->purchaseOrders()
            ->where('purchase_order_number', 'like', 'PO-%')
            ->orderByDesc('id')
            ->value('purchase_order_number');

        $n = $last ? (int) preg_replace('/\D/', '', substr($last, 3)) : 0;

        return 'PO-'.str_pad((string) ($n + 1), 5, '0', STR_PAD_LEFT);
    }

    public function calculateTotals(PurchaseOrder $purchaseOrder): PurchaseOrder
    {
        $subtotal = '0.00';
        $taxTotal = '0.00';

        foreach ($purchaseOrder->lines as $line) {
            $lineTotal = bcmul((string) $line->quantity, (string) $line->unit_price, 2);
            $taxAmount = $line->taxCode ? $line->taxCode->calculate((float) $lineTotal) : '0.00';

            $line->forceFill(['line_total' => $lineTotal, 'tax_amount' => $taxAmount])->save();
            $subtotal = bcadd($subtotal, $lineTotal, 2);
            $taxTotal = bcadd($taxTotal, $taxAmount, 2);
        }

        $purchaseOrder->forceFill([
            'subtotal' => $subtotal,
            'tax_total' => $taxTotal,
            'total' => bcadd($subtotal, $taxTotal, 2),
        ])->save();

        return $purchaseOrder->refresh();
    }

    public function send(PurchaseOrder $purchaseOrder): PurchaseOrder
    {
        if ($purchaseOrder->status !== 'draft') {
            throw new InvalidArgumentException("Only draft purchase orders can be sent (is: {$purchaseOrder->status}).");
        }

        $purchaseOrder->forceFill(['status' => 'sent'])->save();

        return $purchaseOrder;
    }

    public function approve(PurchaseOrder $purchaseOrder): PurchaseOrder
    {
        if (! in_array($purchaseOrder->status, ['draft', 'sent'], true)) {
            throw new InvalidArgumentException("Only draft or sent purchase orders can be approved (is: {$purchaseOrder->status}).");
        }

        if ($purchaseOrder->lines()->count() === 0) {
            throw new InvalidArgumentException('Cannot approve a purchase order with no lines.');
        }

        $this->calculateTotals($purchaseOrder);
        $purchaseOrder->forceFill(['status' => 'approved'])->save();

        return $purchaseOrder;
    }

    public function cancel(PurchaseOrder $purchaseOrder): PurchaseOrder
    {
        if ($purchaseOrder->status === 'converted') {
            throw new InvalidArgumentException('Converted purchase orders cannot be cancelled.');
        }

        $purchaseOrder->forceFill(['status' => 'cancelled'])->save();

        return $purchaseOrder;
    }

    /** Convert the approved PO into a draft bill. The ledger is untouched until the bill is approved. */
    public function convertToBill(PurchaseOrder $purchaseOrder, BillService $bills): Bill
    {
        if ($purchaseOrder->status !== 'approved') {
            throw new InvalidArgumentException("Only approved purchase orders can be converted (is: {$purchaseOrder->status}).");
        }

        return DB::transaction(function () use ($purchaseOrder, $bills) {
            $bill = $purchaseOrder->company->bills()->create([
                'party_id' => $purchaseOrder->party_id,
                'bill_number' => null,
                'po_number' => $purchaseOrder->purchase_order_number,
                'bill_date' => today()->toDateString(),
                'due_date' => today()->addDays(30)->toDateString(),
                'currency' => $purchaseOrder->currency,
                'fx_rate' => $purchaseOrder->fx_rate,
                'notes' => $purchaseOrder->notes,
            ]);

            foreach ($purchaseOrder->lines as $line) {
                $bill->lines()->create([
                    'item_id' => $line->item_id,
                    'description' => $line->description,
                    'quantity' => $line->quantity,
                    'unit_price' => $line->unit_price,
                    'tax_code_id' => $line->tax_code_id,
                    'expense_account_id' => $line->expense_account_id,
                ]);
            }

            $bills->calculateTotals($bill->refresh());
            $purchaseOrder->forceFill(['status' => 'converted', 'converted_bill_id' => $bill->id])->save();

            return $bill;
        });
    }
}
