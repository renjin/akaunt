<?php

use App\Models\Company;
use App\Models\User;
use App\Services\ChartOfAccountsTemplate;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::create([
        'name' => 'Smoke Sdn Bhd', 'slug' => 'smoke', 'legal_form' => 'sdn_bhd',
    ]);
    $this->company->users()->attach($this->user);
    ChartOfAccountsTemplate::seed($this->company);
});

it('redirects /admin to the user tenant', function () {
    $this->actingAs($this->user)->get('/admin')->assertRedirect();
});

it('renders the tenant dashboard', function () {
    $this->actingAs($this->user)->get('/admin/smoke')->assertOk();
});

it('renders the chart of accounts list', function () {
    $this->actingAs($this->user)->get('/admin/smoke/accounts')->assertOk();
});

it('renders the journal entries list and create pages', function () {
    $this->actingAs($this->user)->get('/admin/smoke/journal-entries')->assertOk();
    $this->actingAs($this->user)->get('/admin/smoke/journal-entries/create')->assertOk();
});

it('renders phase 1 resource pages', function () {
    foreach (['parties', 'items', 'tax-codes', 'invoices', 'invoices/create'] as $path) {
        $this->actingAs($this->user)->get("/admin/smoke/{$path}")->assertOk();
    }
});

it('renders report pages', function () {
    foreach ([
        'aged-receivables', 'income-by-customer', 'trial-balance', 'profit-and-loss',
        'balance-sheet', 'aged-payables', 'sst-return', 'general-ledger',
    ] as $path) {
        $this->actingAs($this->user)->get("/admin/smoke/{$path}")->assertOk();
    }
});

it('renders phase 2 resource pages', function () {
    foreach (['bills', 'bills/create', 'bank-transactions'] as $path) {
        $this->actingAs($this->user)->get("/admin/smoke/{$path}")->assertOk();
    }
});

it('blocks a user from another tenant', function () {
    $stranger = User::factory()->create();
    $this->actingAs($stranger)->get('/admin/smoke')->assertNotFound();
});
