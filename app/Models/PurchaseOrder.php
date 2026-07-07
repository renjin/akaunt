<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    protected $guarded = [];

    public const STATUSES = ['draft', 'sent', 'approved', 'cancelled', 'converted'];

    protected $attributes = [
        'status' => 'draft',
        'currency' => 'MYR',
        'fx_rate' => 1,
        'subtotal' => 0,
        'tax_total' => 0,
        'total' => 0,
    ];

    protected function casts(): array
    {
        return ['order_date' => 'date', 'expected_date' => 'date'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }

    public function convertedBill(): BelongsTo
    {
        return $this->belongsTo(Bill::class, 'converted_bill_id');
    }
}
