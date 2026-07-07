<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstimateLine extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['tax_code_ids' => 'array'];
    }

    /**
     * Tax codes applied to this line. Falls back to the legacy single
     * tax_code_id when tax_code_ids is empty (back-compat).
     *
     * @return array<int, int>
     */
    public function effectiveTaxCodeIds(): array
    {
        $ids = $this->tax_code_ids;

        if (is_array($ids) && $ids !== []) {
            return array_values(array_map('intval', $ids));
        }

        return $this->tax_code_id ? [(int) $this->tax_code_id] : [];
    }

    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function taxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class);
    }

    public function incomeAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'income_account_id');
    }
}
