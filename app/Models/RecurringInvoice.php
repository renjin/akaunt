<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecurringInvoice extends Model
{
    protected $guarded = [];

    public const FREQUENCIES = ['weekly', 'monthly', 'quarterly', 'yearly'];

    protected $attributes = ['active' => true, 'currency' => 'MYR', 'due_days' => 30];

    protected function casts(): array
    {
        return [
            'next_run_date' => 'date', 'last_run_date' => 'date', 'end_date' => 'date',
            'active' => 'boolean',
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
        return $this->hasMany(RecurringInvoiceLine::class);
    }

    public function isDue(): bool
    {
        return $this->active
            && $this->next_run_date->lessThanOrEqualTo(today())
            && ($this->end_date === null || $this->next_run_date->lessThanOrEqualTo($this->end_date));
    }

    public function advance(): void
    {
        $next = match ($this->frequency) {
            'weekly' => $this->next_run_date->copy()->addWeek(),
            'monthly' => $this->next_run_date->copy()->addMonthNoOverflow(),
            'quarterly' => $this->next_run_date->copy()->addMonthsNoOverflow(3),
            'yearly' => $this->next_run_date->copy()->addYear(),
        };

        $this->forceFill(['last_run_date' => $this->next_run_date, 'next_run_date' => $next])->save();

        if ($this->end_date !== null && $next->greaterThan($this->end_date)) {
            $this->forceFill(['active' => false])->save();
        }
    }
}
