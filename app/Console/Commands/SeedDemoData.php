<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Party;
use App\Models\TaxCode;
use App\Services\BankTransactionService;
use App\Services\BillService;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Services\ReportService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Seeds ~24 months of realistic transaction history for one company so the
 * reports (P&L, Balance Sheet, Trial Balance, Aged AR/AP) have real data to
 * show. Models a small Malaysian digital agency: retainer + project income,
 * rent/utilities/subscriptions/contractor expenses, and the 1 Jul 2025 SST
 * expansion (rental became taxable at 6% from that date).
 */
class SeedDemoData extends Command
{
    protected $signature = 'demo:seed {--company=o2o-alliance} {--months=24}';

    protected $description = 'Seed 24 months of demo transactions for a company (wipes its existing transactional data first)';

    private InvoiceService $invoices;
    private BillService $bills;
    private PaymentService $payments;
    private BankTransactionService $bankTxns;

    public function handle(): int
    {
        $this->invoices = app(InvoiceService::class);
        $this->bills = app(BillService::class);
        $this->payments = app(PaymentService::class);
        $this->bankTxns = app(BankTransactionService::class);

        $company = Company::where('slug', $this->option('company'))->firstOrFail();
        $months = (int) $this->option('months');

        $this->components->info("Wiping existing transactional data for {$company->name}...");
        $this->wipe($company);

        $this->components->info('Creating customers and vendors...');
        [$retainerCustomers, $projectCustomers] = $this->makeCustomers($company);
        $vendors = $this->makeVendors($company);
        $bank = $company->accounts()->where('code', '1010')->firstOrFail();

        $start = Carbon::now()->subMonths($months - 1)->startOfMonth();
        $recentCutoff = Carbon::now()->subDays(60);
        $counts = ['invoices' => 0, 'bills' => 0, 'payments' => 0, 'bank_txns' => 0];

        $bar = $this->output->createProgressBar($months);
        for ($m = 0; $m < $months; $m++) {
            $monthStart = $start->copy()->addMonths($m);
            $this->seedMonth($company, $monthStart, $retainerCustomers, $projectCustomers, $vendors, $bank, $recentCutoff, $counts);
            $bar->advance();
        }
        $bar->finish();
        $this->newLine(2);

        $this->components->info('Done. Verifying the ledger balances...');
        $this->verify($company, $counts);

        return self::SUCCESS;
    }

    private function wipe(Company $company): void
    {
        $company->invoices()->each(fn (Invoice $i) => $i->delete());
        $company->bills()->each(fn (Bill $b) => $b->delete());
        $company->payments()->delete(); // cascades payment_allocations
        $company->bankTransactions()->delete();
        $company->journalEntries()->each(fn ($e) => $e->delete()); // cascades journal_lines
        $company->parties()->delete();
    }

    /** @return array{0: array<int, array{party: Party, base: float}>, 1: array<Party>} */
    private function makeCustomers(Company $company): array
    {
        $retainer = collect([
            ['name' => 'Maju Retail Sdn Bhd', 'brn' => '201801012345', 'tin' => 'C21458796020', 'base' => 4500],
            ['name' => 'Selera Nusantara F&B Group', 'brn' => '201602034567', 'tin' => 'C20336654098', 'base' => 6800],
            ['name' => 'Bright Path Tuition Centre', 'brn' => '201903078901', 'tin' => 'C22987712345', 'base' => 2800],
        ])->map(fn ($c) => [
            'party' => $company->parties()->create([
                'role' => 'customer', 'name' => $c['name'],
                'registration_scheme' => 'BRN', 'registration_number' => $c['brn'], 'tin' => $c['tin'],
                'email' => \Illuminate\Support\Str::slug($c['name']) . '@example.my',
                'address_line1' => 'Level ' . mt_rand(2, 20) . ', Menara Bumiputra',
                'city' => 'Kuala Lumpur', 'state' => 'Wilayah Persekutuan', 'postcode' => '50450', 'country_code' => 'MY',
            ]),
            'base' => $c['base'],
        ])->all();

        $project = collect([
            ['name' => 'Kluang Ventures Holdings', 'brn' => '201512019988', 'tin' => 'C19887744551'],
            ['name' => 'Damai Homes Property', 'brn' => '202001056677', 'tin' => 'C21567788990'],
        ])->map(fn ($c) => $company->parties()->create([
            'role' => 'customer', 'name' => $c['name'],
            'registration_scheme' => 'BRN', 'registration_number' => $c['brn'], 'tin' => $c['tin'],
            'email' => \Illuminate\Support\Str::slug($c['name']) . '@example.my',
            'address_line1' => mt_rand(1, 99) . ', Jalan Ampang',
            'city' => 'Kuala Lumpur', 'state' => 'Wilayah Persekutuan', 'postcode' => '50450', 'country_code' => 'MY',
        ]))->all();

        return [$retainer, $project];
    }

    /** @return array<string, Party> keyed by short name for lookup */
    private function makeVendors(Company $company): array
    {
        $defs = [
            'landlord' => ['name' => 'Sunrise Properties Sdn Bhd', 'scheme' => 'BRN', 'reg' => '199001011111'],
            'tnb' => ['name' => 'Tenaga Nasional Berhad', 'scheme' => 'BRN', 'reg' => '199001012222'],
            'google' => ['name' => 'Google Asia Pacific Pte Ltd', 'scheme' => 'BRN', 'reg' => 'SG-198800012345'],
            'webhost' => ['name' => 'WebHost Solutions Sdn Bhd', 'scheme' => 'BRN', 'reg' => '201203034444'],
            'freelancer' => ['name' => 'Ahmad Bin Ismail (Freelance Studio)', 'scheme' => 'NRIC', 'reg' => '880112-14-5566'],
            'audit' => ['name' => 'Precision Audit & Tax Advisory PLT', 'scheme' => 'BRN', 'reg' => 'LLP0012345'],
            'marketing' => ['name' => 'AdBoost Digital Sdn Bhd', 'scheme' => 'BRN', 'reg' => '201709098888'],
        ];

        $vendors = [];
        foreach ($defs as $key => $d) {
            $vendors[$key] = $company->parties()->create([
                'role' => 'vendor', 'name' => $d['name'],
                'registration_scheme' => $d['scheme'], 'registration_number' => $d['reg'],
                'email' => \Illuminate\Support\Str::slug($d['name']) . '@example.my',
                'city' => 'Kuala Lumpur', 'state' => 'Wilayah Persekutuan', 'country_code' => 'MY',
            ]);
        }

        return $vendors;
    }

    private function seedMonth(
        Company $company,
        Carbon $monthStart,
        array $retainerCustomers,
        array $projectCustomers,
        array $vendors,
        Account $bank,
        Carbon $recentCutoff,
        array &$counts,
    ): void {
        $isRecent = $monthStart->greaterThan($recentCutoff);
        $svcTax8 = $company->taxCodes()->where('name', 'Service Tax 8%')->first();
        $svcTax6 = $company->taxCodes()->where('name', 'Service Tax 6%')->first();
        $noSst = $company->taxCodes()->where('name', 'No SST')->first();
        $income = $company->accounts()->where('code', '4100')->first(); // Service Revenue
        $rentAcct = $company->accounts()->where('code', '6100')->first();
        $utilAcct = $company->accounts()->where('code', '6110')->first();
        $marketingAcct = $company->accounts()->where('code', '6200')->first();
        $professionalAcct = $company->accounts()->where('code', '6300')->first();
        $cogsAcct = $company->accounts()->where('code', '5000')->first();
        $generalAcct = $company->accounts()->where('code', '6900')->first();
        $bankChargesAcct = $company->accounts()->where('code', '6600')->first();

        // Rental became taxable (Service Tax) from the 1 Jul 2025 SST expansion.
        $rentTax = $monthStart->greaterThanOrEqualTo(Carbon::parse('2025-07-01')) ? $svcTax6 : $noSst;

        // --- Retainer invoices (monthly, every customer) ---
        foreach ($retainerCustomers as $entry) {
            $customer = $entry['party'];
            $variance = mt_rand(-5, 8) / 100; // small month-to-month variance
            $amount = round((float) $entry['base'] * (1 + $variance), 2);
            $issueDate = $monthStart->copy()->addDays(mt_rand(1, 5));
            $invoice = $this->createInvoice($company, $customer, $issueDate, [
                ['description' => "Retainer — {$issueDate->format('F Y')}", 'quantity' => 1, 'unit_price' => $amount, 'tax_code_id' => $svcTax8->id, 'income_account_id' => $income->id, 'classification_code' => '022'],
            ]);
            $this->invoices->approve($invoice);
            $counts['invoices']++;
            $this->settleInvoice($invoice, $bank, $isRecent, $counts);
        }

        // --- Occasional project invoices ---
        foreach ($projectCustomers as $customer) {
            if (mt_rand(1, 100) > 35) {
                continue;
            }
            $amount = mt_rand(8000, 42000);
            $issueDate = $monthStart->copy()->addDays(mt_rand(1, 20));
            $invoice = $this->createInvoice($company, $customer, $issueDate, [
                ['description' => 'Web development & design project — Phase ' . mt_rand(1, 3), 'quantity' => 1, 'unit_price' => $amount, 'tax_code_id' => $svcTax8->id, 'income_account_id' => $income->id, 'classification_code' => '022'],
            ]);
            $this->invoices->approve($invoice);
            $counts['invoices']++;
            $this->settleInvoice($invoice, $bank, $isRecent, $counts);
        }

        // --- Rent (monthly) ---
        $bill = $this->createBill($company, $vendors['landlord'], $monthStart->copy()->addDay(), [
            ['description' => 'Office rent — ' . $monthStart->format('F Y'), 'quantity' => 1, 'unit_price' => 3500, 'tax_code_id' => $rentTax->id, 'expense_account_id' => $rentAcct->id],
        ]);
        $this->bills->approve($bill);
        $counts['bills']++;
        $this->settleBill($bill, $bank, $isRecent, $counts);

        // --- Utilities (monthly) ---
        $bill = $this->createBill($company, $vendors['tnb'], $monthStart->copy()->addDays(mt_rand(3, 10)), [
            ['description' => 'Electricity — ' . $monthStart->format('F Y'), 'quantity' => 1, 'unit_price' => mt_rand(380, 640), 'tax_code_id' => $noSst->id, 'expense_account_id' => $utilAcct->id],
        ]);
        $this->bills->approve($bill);
        $counts['bills']++;
        $this->settleBill($bill, $bank, $isRecent, $counts);

        // --- Google Workspace (monthly, no SST — foreign vendor) ---
        $bill = $this->createBill($company, $vendors['google'], $monthStart->copy()->addDays(2), [
            ['description' => 'Google Workspace subscription', 'quantity' => 1, 'unit_price' => 350, 'tax_code_id' => $noSst->id, 'expense_account_id' => $generalAcct->id],
        ]);
        $this->bills->approve($bill);
        $counts['bills']++;
        $this->settleBill($bill, $bank, $isRecent, $counts);

        // --- Hosting (monthly) ---
        $bill = $this->createBill($company, $vendors['webhost'], $monthStart->copy()->addDays(5), [
            ['description' => 'Hosting & domains', 'quantity' => 1, 'unit_price' => 280, 'tax_code_id' => $svcTax8->id, 'expense_account_id' => $generalAcct->id],
        ]);
        $this->bills->approve($bill);
        $counts['bills']++;
        $this->settleBill($bill, $bank, $isRecent, $counts);

        // --- Freelance contractor (sporadic) ---
        if (mt_rand(1, 100) <= 45) {
            $bill = $this->createBill($company, $vendors['freelancer'], $monthStart->copy()->addDays(mt_rand(8, 22)), [
                ['description' => 'Subcontracted design work', 'quantity' => 1, 'unit_price' => mt_rand(2000, 6000), 'tax_code_id' => $noSst->id, 'expense_account_id' => $cogsAcct->id],
            ]);
            $this->bills->approve($bill);
            $counts['bills']++;
            $this->settleBill($bill, $bank, $isRecent, $counts);
        }

        // --- Audit/tax advisory (quarterly) ---
        if ($monthStart->month % 3 === 0) {
            $bill = $this->createBill($company, $vendors['audit'], $monthStart->copy()->addDays(10), [
                ['description' => 'Quarterly accounting & tax advisory', 'quantity' => 1, 'unit_price' => 1800, 'tax_code_id' => $svcTax8->id, 'expense_account_id' => $professionalAcct->id],
            ]);
            $this->bills->approve($bill);
            $counts['bills']++;
            $this->settleBill($bill, $bank, $isRecent, $counts);
        }

        // --- Marketing spend (sporadic) ---
        if (mt_rand(1, 100) <= 50) {
            $bill = $this->createBill($company, $vendors['marketing'], $monthStart->copy()->addDays(mt_rand(5, 25)), [
                ['description' => 'Paid ads & campaign management', 'quantity' => 1, 'unit_price' => mt_rand(1500, 5000), 'tax_code_id' => $svcTax8->id, 'expense_account_id' => $marketingAcct->id],
            ]);
            $this->bills->approve($bill);
            $counts['bills']++;
            $this->settleBill($bill, $bank, $isRecent, $counts);
        }

        // --- Bank fee (stray transaction, categorized directly) ---
        $feeDate = $monthStart->copy()->addDays(mt_rand(25, 28));
        if ($feeDate->lessThanOrEqualTo(Carbon::now())) {
            $txn = $company->bankTransactions()->create([
                'account_id' => $bank->id, 'txn_date' => $feeDate->toDateString(),
                'description' => 'Bank service fee', 'amount' => number_format(mt_rand(18, 35), 2, '.', ''), 'direction' => 'out',
            ]);
            $this->bankTxns->categorize($txn, $bankChargesAcct);
            $counts['bank_txns']++;
        }
    }

    private function createInvoice(Company $company, Party $customer, Carbon $issueDate, array $lines): Invoice
    {
        $invoice = $company->invoices()->create([
            'party_id' => $customer->id,
            'invoice_number' => $this->invoices->nextNumber($company),
            'issue_date' => $issueDate->toDateString(),
            'due_date' => $issueDate->copy()->addDays(30)->toDateString(),
        ]);
        foreach ($lines as $line) {
            $invoice->lines()->create($line);
        }

        return $invoice->refresh();
    }

    private function createBill(Company $company, Party $vendor, Carbon $billDate, array $lines): Bill
    {
        $bill = $company->bills()->create([
            'party_id' => $vendor->id,
            'bill_number' => strtoupper(\Illuminate\Support\Str::random(2)) . '-' . mt_rand(1000, 9999),
            'bill_date' => $billDate->toDateString(),
            'due_date' => $billDate->copy()->addDays(14)->toDateString(),
        ]);
        foreach ($lines as $line) {
            $bill->lines()->create($line);
        }

        return $bill->refresh();
    }

    /** Older invoices always resolve; recent ones may stay open/partial for AR aging. */
    private function settleInvoice(Invoice $invoice, Account $bank, bool $isRecent, array &$counts): void
    {
        $invoice->refresh();
        $roll = mt_rand(1, 100);

        if ($isRecent) {
            if ($roll <= 25) {
                return; // left fully unpaid
            }
            if ($roll <= 50) {
                $this->pay($invoice, $bank, bcmul($invoice->total, '0.5', 2), $invoice->due_date, $counts);

                return; // partially paid
            }
        }

        // fully paid — 70% on time, 30% late (but never in the future)
        $onTime = mt_rand(1, 100) <= 70;
        $payDate = $onTime
            ? $invoice->due_date->copy()->subDays(mt_rand(0, 5))
            : $invoice->due_date->copy()->addDays(mt_rand(5, 40));
        $payDate = $payDate->greaterThan(Carbon::now()) ? Carbon::now() : $payDate;
        $payDate = $payDate->lessThan($invoice->issue_date) ? $invoice->issue_date->copy() : $payDate;

        $this->pay($invoice, $bank, $invoice->total, $payDate, $counts);
    }

    private function pay(Invoice $invoice, Account $bank, string $amount, Carbon $date, array &$counts): void
    {
        $method = collect(['fpx', 'duitnow', 'bank_transfer'])->random();
        $this->payments->receiveAgainstInvoice($invoice->refresh(), $amount, $date->toDateString(), $bank, $method);
        $counts['payments']++;
    }

    private function settleBill(Bill $bill, Account $bank, bool $isRecent, array &$counts): void
    {
        $bill->refresh();
        $roll = mt_rand(1, 100);

        if ($isRecent && $roll <= 20) {
            return; // left unpaid, aging payable
        }

        $onTime = mt_rand(1, 100) <= 80;
        $payDate = $onTime
            ? $bill->due_date->copy()->subDays(mt_rand(0, 3))
            : $bill->due_date->copy()->addDays(mt_rand(3, 20));
        $payDate = $payDate->greaterThan(Carbon::now()) ? Carbon::now() : $payDate;
        $payDate = $payDate->lessThan($bill->bill_date) ? $bill->bill_date->copy() : $payDate;

        $method = collect(['duitnow', 'bank_transfer'])->random();
        $this->payments->payBill($bill->refresh(), $bill->total, $payDate->toDateString(), $bank, $method);
        $counts['payments']++;
    }

    private function verify(Company $company, array $counts): void
    {
        $tb = app(ReportService::class)->trialBalance($company);
        $balanced = bccomp($tb['total_debit'], $tb['total_credit'], 2) === 0;

        $this->table(['Metric', 'Value'], [
            ['Invoices created', $counts['invoices']],
            ['Bills created', $counts['bills']],
            ['Payments recorded', $counts['payments']],
            ['Bank transactions categorized', $counts['bank_txns']],
            ['Trial balance debit', $tb['total_debit']],
            ['Trial balance credit', $tb['total_credit']],
            ['Balanced?', $balanced ? 'YES ✓' : 'NO — BUG'],
        ]);

        if (! $balanced) {
            $this->error('Trial balance does not net to zero — seeding produced an unbalanced ledger.');
            exit(self::FAILURE);
        }
    }
}
