<?php

use App\Models\Company;
use App\Models\User;
use App\Services\ChartOfAccountsTemplate;
use App\Services\PostingService;

beforeEach(function () {
    $this->company = Company::create([
        'name' => 'Test Sdn Bhd', 'slug' => 'test-co-' . uniqid(), 'legal_form' => 'sdn_bhd',
    ]);
    ChartOfAccountsTemplate::seed($this->company);
});

it('seeds an MPERS chart with system accounts', function () {
    expect($this->company->accounts()->count())->toBeGreaterThan(25)
        ->and($this->company->accounts()->where('subtype', 'accounts_receivable')->where('is_system', true)->exists())->toBeTrue()
        ->and($this->company->accounts()->where('subtype', 'sst_payable')->exists())->toBeTrue()
        // single-stage SST: there must be NO recoverable-tax asset account
        ->and($this->company->accounts()->where('name', 'ilike', '%recoverable%')->exists())->toBeFalse();
});

it('seeds partner capital for partnerships, share capital for sdn bhd', function () {
    $partnership = Company::create(['name' => 'P', 'slug' => 'p-' . uniqid(), 'legal_form' => 'partnership']);
    ChartOfAccountsTemplate::seed($partnership);

    expect($partnership->accounts()->where('subtype', 'partner_capital')->count())->toBe(2)
        ->and($partnership->accounts()->where('subtype', 'share_capital')->exists())->toBeFalse()
        ->and($this->company->accounts()->where('subtype', 'share_capital')->exists())->toBeTrue();
});

it('posts a balanced journal entry', function () {
    $bank = $this->company->accounts()->where('code', '1010')->first();
    $revenue = $this->company->accounts()->where('code', '4100')->first();

    $entry = app(PostingService::class)->post($this->company, '2026-07-01', [
        ['account_id' => $bank->id, 'debit' => 1000],
        ['account_id' => $revenue->id, 'credit' => 1000],
    ], 'Cash sale');

    expect($entry->lines)->toHaveCount(2)
        ->and($bank->balance())->toBe('1000.00')
        ->and($revenue->balance())->toBe('1000.00');
});

it('rejects an unbalanced entry', function () {
    $bank = $this->company->accounts()->where('code', '1010')->first();
    $revenue = $this->company->accounts()->where('code', '4100')->first();

    app(PostingService::class)->post($this->company, '2026-07-01', [
        ['account_id' => $bank->id, 'debit' => 1000],
        ['account_id' => $revenue->id, 'credit' => 999.99],
    ]);
})->throws(InvalidArgumentException::class, 'Unbalanced');

it('rejects a line that is both debit and credit', function () {
    $bank = $this->company->accounts()->where('code', '1010')->first();

    app(PostingService::class)->post($this->company, '2026-07-01', [
        ['account_id' => $bank->id, 'debit' => 100, 'credit' => 100],
        ['account_id' => $bank->id, 'credit' => 0],
    ]);
})->throws(InvalidArgumentException::class);

it('trial balance nets to zero across the company', function () {
    $bank = $this->company->accounts()->where('code', '1010')->first();
    $ar = $this->company->accounts()->where('code', '1100')->first();
    $revenue = $this->company->accounts()->where('code', '4100')->first();
    $svc = app(PostingService::class);

    // invoice: Dr A/R 500 / Cr Revenue 500; payment: Dr Bank 500 / Cr A/R 500
    $svc->post($this->company, '2026-07-01', [
        ['account_id' => $ar->id, 'debit' => 500],
        ['account_id' => $revenue->id, 'credit' => 500],
    ]);
    $svc->post($this->company, '2026-07-02', [
        ['account_id' => $bank->id, 'debit' => 500],
        ['account_id' => $ar->id, 'credit' => 500],
    ]);

    $totals = \App\Models\JournalLine::query()
        ->whereHas('journalEntry', fn ($q) => $q->where('company_id', $this->company->id))
        ->selectRaw('COALESCE(SUM(debit_base),0) AS d, COALESCE(SUM(credit_base),0) AS c')
        ->first();

    expect($totals->d)->toBe($totals->c)
        ->and($ar->balance())->toBe('0.00');
});

it('scopes tenancy: users only access their companies', function () {
    $user = User::factory()->create();
    $user->companies()->attach($this->company);
    $other = Company::create(['name' => 'Other', 'slug' => 'other-' . uniqid(), 'legal_form' => 'sdn_bhd']);

    expect($user->canAccessTenant($this->company))->toBeTrue()
        ->and($user->canAccessTenant($other))->toBeFalse();
});
