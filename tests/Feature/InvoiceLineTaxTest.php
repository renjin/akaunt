<?php

use App\Models\Company;
use App\Models\TaxCode;
use App\Services\ChartOfAccountsTemplate;
use App\Services\InvoiceService;
use App\Services\ReportService;

beforeEach(function () {
    $this->company = Company::create([
        'name' => 'Agency Sdn Bhd', 'slug' => 'agency-'.uniqid(), 'legal_form' => 'sdn_bhd',
        'base_currency' => 'MYR', 'sst_registration_no' => 'W10-1234-56789012',
    ]);
    ChartOfAccountsTemplate::seed($this->company);

    $this->customer = $this->company->parties()->create([
        'role' => 'customer', 'name' => 'Client Sdn Bhd',
        'registration_scheme' => 'BRN', 'registration_number' => '202301012345',
        'tin' => 'C1234567890', 'email' => 'client@example.my',
    ]);

    $this->svc = app(InvoiceService::class);
});

/**
 * A second tax code that posts to a distinct payable account, so we can prove the
 * ledger credits each code's account independently. Falls back to the shared
 * sst_payable account if the chart has no obvious second liability code.
 */
function secondTaxCode($company): TaxCode
{
    $sst = $company->systemAccount('sst_payable');
    // Reuse a distinct payable account when one exists; otherwise the same one is fine
    // for the summed-total assertions (only the two-account test needs distinctness).
    $other = $company->accounts()->where('type', 'liability')->where('id', '!=', $sst->id)->orderBy('code')->first();

    return $company->taxCodes()->create([
        'name' => 'Local Levy 2%',
        'tax_type' => 'service',
        'rate' => '2.00',
        'active' => true,
        'sst_payable_account_id' => ($other ?? $sst)->id,
    ]);
}

it('sums tax across two tax codes on a single line', function () {
    $service = $this->company->taxCodes()->where('name', 'Service Tax 8%')->first();
    $levy = secondTaxCode($this->company);
    $income = $this->company->accounts()->where('code', '4100')->first();

    $invoice = $this->company->invoices()->create([
        'party_id' => $this->customer->id,
        'invoice_number' => $this->svc->nextNumber($this->company),
        'issue_date' => '2026-07-01', 'due_date' => '2026-07-31',
    ]);
    $invoice->lines()->create([
        'description' => 'Consulting', 'quantity' => 1, 'unit_price' => 1000,
        'tax_code_ids' => [$service->id, $levy->id],
        'income_account_id' => $income->id,
    ]);

    $this->svc->calculateTotals($invoice->refresh());
    $invoice->refresh();
    $line = $invoice->lines->first();

    // 8% + 2% of 1000 = 80 + 20 = 100
    expect($line->tax_amount)->toBe('100.00')
        ->and($invoice->tax_total)->toBe('100.00')
        ->and($invoice->total)->toBe('1100.00')
        // legacy single column keeps the FIRST selected code
        ->and($line->tax_code_id)->toBe($service->id);
});

it('posts each tax code to its own payable account and the trial balance nets to zero', function () {
    $service = $this->company->taxCodes()->where('name', 'Service Tax 8%')->first();
    $levy = secondTaxCode($this->company);
    $income = $this->company->accounts()->where('code', '4100')->first();

    $invoice = $this->company->invoices()->create([
        'party_id' => $this->customer->id,
        'invoice_number' => $this->svc->nextNumber($this->company),
        'issue_date' => '2026-07-01', 'due_date' => '2026-07-31',
    ]);
    $invoice->lines()->create([
        'description' => 'Consulting', 'quantity' => 1, 'unit_price' => 1000,
        'tax_code_ids' => [$service->id, $levy->id],
        'income_account_id' => $income->id,
    ]);

    $this->svc->approve($invoice->refresh());
    $invoice->refresh();

    $ar = $this->company->accounts()->where('code', '1100')->first();

    expect($invoice->total)->toBe('1100.00')
        ->and($ar->balance())->toBe('1100.00')
        ->and($income->balance())->toBe('1000.00')
        ->and($service->sstPayableAccount->balance())->toBe('80.00')
        ->and($levy->sstPayableAccount->balance())->toBe('20.00');

    $tb = app(ReportService::class)->trialBalance($this->company);
    expect(bccomp($tb['total_debit'], $tb['total_credit'], 2))->toBe(0);
});

it('reduces the taxable base by the line discount', function () {
    $service = $this->company->taxCodes()->where('name', 'Service Tax 8%')->first();
    $income = $this->company->accounts()->where('code', '4100')->first();

    $invoice = $this->company->invoices()->create([
        'party_id' => $this->customer->id,
        'invoice_number' => $this->svc->nextNumber($this->company),
        'issue_date' => '2026-07-01', 'due_date' => '2026-07-31',
    ]);
    $invoice->lines()->create([
        'description' => 'Consulting', 'quantity' => 1, 'unit_price' => 1000, 'discount' => 100,
        'tax_code_ids' => [$service->id],
        'income_account_id' => $income->id,
    ]);

    $this->svc->calculateTotals($invoice->refresh());
    $invoice->refresh();
    $line = $invoice->lines->first();

    // net = 1000 - 100 = 900; tax = 8% of 900 = 72
    expect($line->line_total)->toBe('900.00')
        ->and($line->tax_amount)->toBe('72.00')
        ->and($invoice->subtotal)->toBe('900.00')
        ->and($invoice->total)->toBe('972.00');
});

it('applies a whole-invoice percentage discount before tax and posts a balanced net entry', function () {
    $service = $this->company->taxCodes()->where('name', 'Service Tax 8%')->first();
    $incomeA = $this->company->accounts()->where('code', '4100')->first();
    $incomeB = $this->company->accounts()->where('type', 'income')->where('id', '!=', $incomeA->id)->orderBy('code')->first();

    $invoice = $this->company->invoices()->create([
        'party_id' => $this->customer->id,
        'invoice_number' => $this->svc->nextNumber($this->company),
        'issue_date' => '2026-07-01', 'due_date' => '2026-07-31',
        'discount_type' => 'percent', 'discount_value' => 10,
    ]);
    // Two lines across two income accounts so proration is exercised.
    $invoice->lines()->create([
        'description' => 'Consulting', 'quantity' => 1, 'unit_price' => 600,
        'tax_code_ids' => [$service->id], 'income_account_id' => $incomeA->id,
    ]);
    $invoice->lines()->create([
        'description' => 'Hosting', 'quantity' => 1, 'unit_price' => 400,
        'tax_code_ids' => [$service->id], 'income_account_id' => $incomeB->id,
    ]);

    $this->svc->approve($invoice->refresh());
    $invoice->refresh();

    // subtotal 1000, 10% discount = 100 → net 900; tax 8% of 900 = 72; total 972
    expect($invoice->subtotal)->toBe('1000.00')
        ->and($invoice->discount_total)->toBe('100.00')
        ->and($invoice->tax_total)->toBe('72.00')
        ->and($invoice->total)->toBe('972.00');

    // Income booked net of the prorated discount: 600→540, 400→360
    expect($incomeA->balance())->toBe('540.00')
        ->and($incomeB->balance())->toBe('360.00');

    $tb = app(ReportService::class)->trialBalance($this->company);
    expect(bccomp($tb['total_debit'], $tb['total_credit'], 2))->toBe(0);
});

it('caps a fixed whole-invoice discount at the subtotal', function () {
    $income = $this->company->accounts()->where('code', '4100')->first();

    $invoice = $this->company->invoices()->create([
        'party_id' => $this->customer->id,
        'invoice_number' => $this->svc->nextNumber($this->company),
        'issue_date' => '2026-07-01', 'due_date' => '2026-07-31',
        'discount_type' => 'fixed', 'discount_value' => 5000,
    ]);
    $invoice->lines()->create([
        'description' => 'Consulting', 'quantity' => 1, 'unit_price' => 1000,
        'income_account_id' => $income->id,
    ]);

    $this->svc->calculateTotals($invoice->refresh());
    $invoice->refresh();

    expect($invoice->discount_total)->toBe('1000.00')
        ->and($invoice->total)->toBe('0.00');
});

it('still computes tax for a legacy row that only has tax_code_id set', function () {
    $service = $this->company->taxCodes()->where('name', 'Service Tax 8%')->first();
    $income = $this->company->accounts()->where('code', '4100')->first();

    $invoice = $this->company->invoices()->create([
        'party_id' => $this->customer->id,
        'invoice_number' => $this->svc->nextNumber($this->company),
        'issue_date' => '2026-07-01', 'due_date' => '2026-07-31',
    ]);
    $invoice->lines()->create([
        'description' => 'Legacy line', 'quantity' => 1, 'unit_price' => 1000,
        'tax_code_id' => $service->id, // no tax_code_ids
        'income_account_id' => $income->id,
    ]);

    $this->svc->calculateTotals($invoice->refresh());
    $invoice->refresh();

    expect($invoice->lines->first()->tax_amount)->toBe('80.00')
        ->and($invoice->tax_total)->toBe('80.00');
});
