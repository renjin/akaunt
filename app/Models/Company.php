<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Company extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'einvoice_enabled' => 'boolean',
            'einvoice_threshold_crossed' => 'boolean',
            'hitpay_api_key' => 'encrypted',
            'hitpay_salt' => 'encrypted',
        ];
    }

    public function hitpayConfigured(): bool
    {
        return filled($this->hitpay_api_key) && filled($this->hitpay_salt);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class);
    }

    public function parties(): HasMany
    {
        return $this->hasMany(Party::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    public function taxCodes(): HasMany
    {
        return $this->hasMany(TaxCode::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class);
    }

    public function estimates(): HasMany
    {
        return $this->hasMany(Estimate::class);
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function recurringInvoices(): HasMany
    {
        return $this->hasMany(RecurringInvoice::class);
    }

    public function bankTransactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class);
    }

    public function einvoiceCredential(): HasOne
    {
        return $this->hasOne(EinvoiceCredential::class);
    }

    public function systemAccount(string $subtype): Account
    {
        return $this->accounts()->where('subtype', $subtype)->where('is_system', true)->firstOrFail();
    }
}
