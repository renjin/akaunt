<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EinvoiceCredential extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['keysecret' => 'encrypted'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function baseUrl(): string
    {
        return $this->environment === 'production'
            ? config('services.einvoiceapp.production_url')
            : config('services.einvoiceapp.staging_url');
    }
}
