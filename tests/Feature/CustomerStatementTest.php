<?php

use App\Filament\Pages\CustomerStatement;
use App\Models\Company;
use App\Services\ChartOfAccountsTemplate;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use Filament\Facades\Filament;

beforeEach(function () {
    $this->company = Company::create([
        'name' => 'Statement Sdn Bhd', 'slug' => 'stmt-'.uniqid(), 'legal_form' => 'sdn_bhd',
    ]);
    Filament::setTenant($this->company, isQuiet: true);
    ChartOfAccountsTemplate::seed($this->company);
    $this->customer = $this->company->parties()->create(['role' => 'customer', 'name' => 'Statement Client']);
    $this->income = $this->company->accounts()->where('code', '4100')->first();
    $this->bank = $this->company->accounts()->where('code', '1010')->first();

    // Prior-period invoice (opening balance)
    $old = $this->company->invoices()->create(['party_id' => $this->customer->id, 'invoice_number' => 'INV-00001', 'issue_date' => '2026-01-15']);
    $old->lines()->create(['description' => 'Old work', 'quantity' => 1, 'unit_price' => 1000, 'income_account_id' => $this->income->id]);
    app(InvoiceService::class)->approve($old->refresh());

    // In-period invoice + partial payment
    $this->invoice = $this->company->invoices()->create(['party_id' => $this->customer->id, 'invoice_number' => 'INV-00002', 'issue_date' => '2026-06-01']);
    $this->invoice->lines()->create(['description' => 'June work', 'quantity' => 1, 'unit_price' => 2000, 'income_account_id' => $this->income->id]);
    app(InvoiceService::class)->approve($this->invoice->refresh());
    app(PaymentService::class)->receiveAgainstInvoice($this->invoice->refresh(), '500.00', '2026-06-10', $this->bank);
});

it('computes opening balance from prior-period activity and a correct running balance', function () {
    $page = new CustomerStatement;
    $page->partyId = $this->customer->id;
    $page->type = 'activity';
    $page->from = '2026-06-01';
    $page->to = '2026-06-30';

    $statement = $page->getStatement();

    expect($statement['opening'])->toBe('1000.00') // the January invoice, unpaid
        ->and($statement['rows'])->toHaveCount(2)  // June invoice + June payment
        ->and($statement['closing'])->toBe('2500.00'); // 1000 + 2000 - 500
});
