<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Party extends Model
{
    protected $guarded = [];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
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

    /** Additional contacts beyond the party's own primary email/phone. */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    /** Last 10 invoices, newest first — for the profile page activity list. */
    public function recentInvoices(): HasMany
    {
        return $this->hasMany(Invoice::class)
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->limit(10);
    }

    /** Last 10 bills, newest first — for the profile page activity list. */
    public function recentBills(): HasMany
    {
        return $this->hasMany(Bill::class)
            ->orderByDesc('bill_date')
            ->orderByDesc('id')
            ->limit(10);
    }

    /** Posted invoices with a balance due, soonest due first — for the profile Overview tab. */
    public function unpaidInvoices(): HasMany
    {
        return $this->hasMany(Invoice::class)
            ->whereNotIn('status', ['draft', 'void'])
            ->whereColumn('amount_paid', '<', 'total')
            ->orderBy('due_date')
            ->orderBy('id')
            ->limit(50);
    }

    /** Posted bills with a balance due, soonest due first — for the profile Overview tab. */
    public function unpaidBills(): HasMany
    {
        return $this->hasMany(Bill::class)
            ->whereNotIn('status', ['draft', 'void'])
            ->whereColumn('amount_paid', '<', 'total')
            ->orderBy('due_date')
            ->orderBy('id')
            ->limit(50);
    }

    /** Up to 50 invoices, newest first — for the profile Invoices tab. */
    public function profileInvoices(): HasMany
    {
        return $this->hasMany(Invoice::class)
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->limit(50);
    }

    /** Up to 50 estimates, newest first — for the profile Estimates tab. */
    public function profileEstimates(): HasMany
    {
        return $this->hasMany(Estimate::class)
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->limit(50);
    }

    /** Up to 50 bills, newest first — for the profile Bills tab. */
    public function profileBills(): HasMany
    {
        return $this->hasMany(Bill::class)
            ->orderByDesc('bill_date')
            ->orderByDesc('id')
            ->limit(50);
    }

    /** Up to 50 purchase orders, newest first — for the profile Purchase Orders tab. */
    public function profilePurchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class)
            ->orderByDesc('order_date')
            ->orderByDesc('id')
            ->limit(50);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function isCustomer(): bool
    {
        return in_array($this->role, ['customer', 'both']);
    }

    public function isVendor(): bool
    {
        return in_array($this->role, ['vendor', 'both']);
    }
}
