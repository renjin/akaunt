<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Estimate extends Model
{
    protected $guarded = [];

    public const STATUSES = ['draft', 'sent', 'accepted', 'expired', 'converted'];

    protected $attributes = [
        'status' => 'draft', 'currency' => 'MYR', 'fx_rate' => 1,
        'subtotal' => 0, 'tax_total' => 0, 'total' => 0,
    ];

    protected function casts(): array
    {
        return ['issue_date' => 'date', 'expiry_date' => 'date'];
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
        return $this->hasMany(EstimateLine::class);
    }

    public function convertedInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'converted_invoice_id');
    }

    public function isExpired(): bool
    {
        return $this->expiry_date !== null && $this->expiry_date->isPast() && $this->status === 'sent';
    }
}
