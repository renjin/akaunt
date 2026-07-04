<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Invoice extends Model
{
    protected $guarded = [];

    public const STATUSES = ['draft', 'approved', 'sent', 'partial', 'paid', 'void'];

    protected $attributes = [
        'status' => 'draft', 'currency' => 'MYR', 'fx_rate' => 1, 'amount_paid' => 0,
        'subtotal' => 0, 'discount_total' => 0, 'tax_total' => 0, 'rounding' => 0, 'total' => 0,
        'einvoice_type_code' => '01', 'einvoice_status' => 'not_applicable',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'due_date' => 'date',
            'issue_time_utc' => 'datetime',
        ];
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
        return $this->hasMany(InvoiceLine::class);
    }

    public function allocations(): MorphMany
    {
        return $this->morphMany(PaymentAllocation::class, 'allocatable');
    }

    public function originalInvoice(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_invoice_id');
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(self::class, 'original_invoice_id')->where('einvoice_type_code', '02');
    }

    public function isCreditNote(): bool
    {
        return $this->einvoice_type_code === '02';
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(EinvoiceSubmission::class);
    }

    /** Latest submission, for display. */
    public function submission(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(EinvoiceSubmission::class)->latestOfMany();
    }

    public function getBalanceDueAttribute(): string
    {
        return bcsub($this->total, $this->amount_paid, 2);
    }

    public function isPosted(): bool
    {
        return ! in_array($this->status, ['draft', 'void']);
    }

    public function isOverdue(): bool
    {
        return $this->due_date !== null
            && $this->due_date->isPast()
            && in_array($this->status, ['approved', 'sent', 'partial']);
    }
}
