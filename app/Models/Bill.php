<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Bill extends Model
{
    protected $guarded = [];

    public const STATUSES = ['draft', 'approved', 'partial', 'paid', 'void'];

    protected $attributes = ['status' => 'draft', 'currency' => 'MYR', 'fx_rate' => 1, 'amount_paid' => 0, 'subtotal' => 0, 'tax_total' => 0, 'total' => 0];

    protected function casts(): array
    {
        return ['bill_date' => 'date', 'due_date' => 'date'];
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
        return $this->hasMany(BillLine::class);
    }

    public function allocations(): MorphMany
    {
        return $this->morphMany(PaymentAllocation::class, 'allocatable');
    }

    public function getBalanceDueAttribute(): string
    {
        return bcsub($this->total, $this->amount_paid, 2);
    }

    public function isPosted(): bool
    {
        return ! in_array($this->status, ['draft', 'void']);
    }
}
