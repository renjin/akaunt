<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Contact extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        // Contacts are created through the Party form's relationship Repeater,
        // which only sets party_id — inherit the tenant company from the party.
        static::creating(function (Contact $contact): void {
            if (empty($contact->company_id) && $contact->party_id) {
                $contact->company_id = Party::whereKey($contact->party_id)->value('company_id');
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }
}
