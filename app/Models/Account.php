<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    protected $guarded = [];

    public const TYPES = ['asset', 'liability', 'equity', 'income', 'expense'];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    /** Debit-normal accounts increase with debits; credit-normal with credits. */
    public function isDebitNormal(): bool
    {
        return in_array($this->type, ['asset', 'expense']);
    }

    /** Signed balance in base currency (positive = normal-side balance). */
    public function balance(?string $from = null, ?string $to = null): string
    {
        $q = $this->journalLines()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id');
        if ($from) $q->where('journal_entries.entry_date', '>=', $from);
        if ($to) $q->where('journal_entries.entry_date', '<=', $to);

        $sums = $q->selectRaw('COALESCE(SUM(debit_base),0) AS d, COALESCE(SUM(credit_base),0) AS c')->first();

        return $this->isDebitNormal()
            ? bcsub($sums->d, $sums->c, 2)
            : bcsub($sums->c, $sums->d, 2);
    }
}
