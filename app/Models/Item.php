<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Item extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['unit_price' => 'decimal:2', 'active' => 'boolean'];
    }

    public function scopeSales(Builder $query): Builder
    {
        return $query->where('kind', 'sales');
    }

    public function scopePurchase(Builder $query): Builder
    {
        return $query->where('kind', 'purchase');
    }

    public function isSales(): bool
    {
        return $this->kind === 'sales';
    }

    public function isPurchase(): bool
    {
        return $this->kind === 'purchase';
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function incomeAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'income_account_id');
    }

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'expense_account_id');
    }

    public function defaultTaxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class, 'default_tax_code_id');
    }
}
