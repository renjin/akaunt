<?php

use App\Models\Company;
use App\Models\User;
use App\Services\ChartOfAccountsTemplate;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::create([
        'name' => 'Smoke4 Sdn Bhd', 'slug' => 'smoke4', 'legal_form' => 'sdn_bhd',
    ]);
    $this->company->users()->attach($this->user);
    ChartOfAccountsTemplate::seed($this->company);
});

it('renders phase 4 resource and report pages', function () {
    foreach ([
        'estimates', 'estimates/create',
        'purchase-orders', 'purchase-orders/create',
        'recurring-invoices', 'recurring-invoices/create',
        'reconcile-account', 'customer-statement',
    ] as $path) {
        $this->actingAs($this->user)->get("/admin/smoke4/{$path}")->assertOk();
    }
});
