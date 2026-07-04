<?php

use App\Models\Company;
use App\Services\ChartOfAccountsTemplate;
use App\Services\PayrollJournalService;
use App\Services\ReportService;

beforeEach(function () {
    $this->company = Company::create([
        'name' => 'Payroll Sdn Bhd', 'slug' => 'pr-' . uniqid(), 'legal_form' => 'sdn_bhd',
    ]);
    ChartOfAccountsTemplate::seed($this->company);
    $this->bank = $this->company->accounts()->where('code', '1010')->first();
    $this->svc = app(PayrollJournalService::class);

    // A realistic hr.my monthly summary: 3 staff, RM18,000 gross
    $this->totals = [
        'gross' => 18000,
        'employer_epf' => 2340,          // 13%
        'employee_epf' => 1980,          // 11%
        'employer_socso_eis' => 350.70,
        'employee_socso_eis' => 122.10,
        'pcb' => 610.50,
        'hrdf' => 180,                   // 1%
    ];
});

it('posts one balanced payroll entry with correct expenses, payables and net pay', function () {
    $this->svc->post($this->company, '2026-07-31', $this->totals, $this->bank, 'PAYROLL-2026-07');

    $balance = fn (string $code) => $this->company->accounts()->where('code', $code)->first()->balance();

    expect($balance('6000'))->toBe('18000.00')   // salaries
        ->and($balance('6010'))->toBe('2340.00') // employer EPF expense
        ->and($balance('6020'))->toBe('350.70')  // employer SOCSO+EIS expense
        ->and($balance('6030'))->toBe('180.00')  // HRDF expense
        ->and($balance('2210'))->toBe('4320.00') // EPF payable = 2340 + 1980
        ->and($balance('2220'))->toBe('472.80')  // SOCSO+EIS payable = 350.70 + 122.10
        ->and($balance('2230'))->toBe('610.50')  // PCB payable
        ->and($balance('2240'))->toBe('180.00')  // HRDF payable
        // net pay = 18000 − 1980 − 122.10 − 610.50 = 15287.40, credited from bank
        ->and($balance('1010'))->toBe('-15287.40');

    $tb = app(ReportService::class)->trialBalance($this->company);
    expect($tb['total_debit'])->toBe($tb['total_credit']);
});

it('remitting a statutory payable clears it against the bank', function () {
    $this->svc->post($this->company, '2026-07-31', $this->totals, $this->bank);
    $this->svc->remitStatutory($this->company, '2210', '4320.00', '2026-08-14', $this->bank);

    $epfPayable = $this->company->accounts()->where('code', '2210')->first();
    expect($epfPayable->balance())->toBe('0.00');

    $tb = app(ReportService::class)->trialBalance($this->company);
    expect($tb['total_debit'])->toBe($tb['total_credit']);
});

it('rejects zero gross and deductions exceeding gross', function () {
    expect(fn () => $this->svc->post($this->company, '2026-07-31', ['gross' => 0], $this->bank))
        ->toThrow(InvalidArgumentException::class, 'positive');

    expect(fn () => $this->svc->post($this->company, '2026-07-31', [
        'gross' => 100, 'pcb' => 500,
    ], $this->bank))->toThrow(InvalidArgumentException::class, 'exceed');
});

it('backfills statutory accounts for companies seeded before they existed', function () {
    // Simulate an old company missing the statutory accounts
    $this->company->accounts()->whereIn('code', ['2210', '2220', '2230', '2240', '6030'])->delete();

    $this->svc->post($this->company, '2026-07-31', $this->totals, $this->bank);

    expect($this->company->accounts()->where('code', '2210')->exists())->toBeTrue();
});

it('handles a simple no-statutory payroll (e.g. director fee only)', function () {
    $entry = $this->svc->post($this->company, '2026-07-31', ['gross' => 5000], $this->bank);

    expect($entry->lines)->toHaveCount(2) // Dr salaries / Cr bank only
        ->and($this->company->accounts()->where('code', '6000')->first()->balance())->toBe('5000.00');
});
