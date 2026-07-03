<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EinvoiceSubmission extends Model
{
    protected $guarded = [];

    protected $attributes = ['status' => 'pending_approval'];

    protected function casts(): array
    {
        return [
            'payload_snapshot' => 'array',
            'response_snapshot' => 'array',
            'reviewed_at' => 'datetime',
            'submitted_at' => 'datetime',
            'validated_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
