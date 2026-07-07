<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class InvoiceLine extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['tax_code_ids' => 'array'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function taxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class);
    }

    /**
     * Effective tax code ids for this line: the multi-select `tax_code_ids` when set,
     * otherwise the legacy single `tax_code_id` (so pre-migration rows keep working).
     *
     * @return array<int>
     */
    public function effectiveTaxCodeIds(): array
    {
        $ids = array_values(array_filter((array) ($this->tax_code_ids ?? [])));

        if (empty($ids) && $this->tax_code_id) {
            $ids = [$this->tax_code_id];
        }

        return array_map('intval', $ids);
    }

    /** Tax code models for this line, keyed by id, scoped to the invoice's company. */
    public function effectiveTaxCodes(): Collection
    {
        $ids = $this->effectiveTaxCodeIds();

        if (empty($ids)) {
            return collect();
        }

        return TaxCode::query()->whereIn('id', $ids)->get()->keyBy('id');
    }

    public function incomeAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'income_account_id');
    }
}
