<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaxCode extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['rate' => 'decimal:2', 'active' => 'boolean'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function sstPayableAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'sst_payable_account_id');
    }

    public function calculate(float $taxableAmount): string
    {
        return number_format($taxableAmount * ((float) $this->rate / 100), 2, '.', '');
    }
}
