<?php

namespace App\Console\Commands;

use App\Models\BankTransaction;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\Party;
use App\Services\BillService;
use App\Services\EstimateService;
use App\Services\InvoiceService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Seeds pipeline data that has NOT hit the ledger: draft invoices and bills,
 * draft/sent estimates, unreviewed bank transactions, plus the customers,
 * vendors, and products/services they reference. Additive — does not wipe
 * existing data. `demo:seed` remains the posted-history seeder.
 */
class SeedUnpostedDemoData extends Command
{
    protected $signature = 'demo:seed-unposted {--company=o2o-alliance}';

    protected $description = 'Seed draft/unposted demo records (estimates, draft invoices & bills, unreviewed bank transactions, items, parties)';

    public function handle(): int
    {
        $company = Company::where('slug', $this->option('company'))->firstOrFail();
        $journalCountBefore = $company->journalEntries()->count();

        $customers = $this->makeCustomers($company);
        $vendors = $this->makeVendors($company);
        $items = $this->makeItems($company);
        $estimates = $this->makeEstimates($company, $customers, $items);
        $invoices = $this->makeDraftInvoices($company, $customers, $items);
        $bills = $this->makeDraftBills($company, $vendors);
        $bankTxns = $this->makeBankTransactions($company);

        $journalCountAfter = $company->journalEntries()->count();

        $this->table(['Created', 'Count'], [
            ['Customers', count($customers)],
            ['Vendors', count($vendors)],
            ['Products & services', count($items)],
            ['Estimates (draft/sent)', count($estimates)],
            ['Draft invoices', count($invoices)],
            ['Draft bills', count($bills)],
            ['Bank transactions (for review)', count($bankTxns)],
            ['Journal entries added', $journalCountAfter - $journalCountBefore],
        ]);

        if ($journalCountAfter !== $journalCountBefore) {
            $this->error('Ledger was touched — this seeder must not post journal entries.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** @return array<Party> */
    private function makeCustomers(Company $company): array
    {
        $defs = [
            ['Sri Emas Logistics Sdn Bhd', '201404023344', 'C18665544332'],
            ['Nusa Metro Clinic Group', '201707045566', 'C20114477889'],
            ['Harbour Lights Cafe Sdn Bhd', '201909067788', 'C21778899001'],
            ['Puncak Jaya Engineering', '201211089900', 'C17335566778'],
            ['Melur & Co Boutique', '202102012233', 'C22446688990'],
            ['Titiwangsa Fitness Studio', '201808034455', 'C20887766554'],
            ['Borneo Trade Exports Sdn Bhd', '201306056677', 'C18224466880'],
            ['Ceria Kids Edu Centre', '202003078899', 'C21990011223'],
            ['Rimba Digital Media', '201910090011', 'C22113355779'],
            ['Anggun Interior Design Sdn Bhd', '201505012345', 'C19556677881'],
        ];

        return collect($defs)->map(fn ($d) => $company->parties()->create([
            'role' => 'customer', 'name' => $d[0],
            'registration_scheme' => 'BRN', 'registration_number' => $d[1], 'tin' => $d[2],
            'email' => Str::slug($d[0]).'@example.my',
            'address_line1' => mt_rand(1, 120).', Jalan '.collect(['Tun Razak', 'Bukit Bintang', 'Ipoh', 'Klang Lama', 'Damansara'])->random(),
            'city' => collect(['Kuala Lumpur', 'Petaling Jaya', 'Shah Alam', 'Johor Bahru'])->random(),
            'state' => 'Selangor', 'postcode' => (string) mt_rand(40000, 68100), 'country_code' => 'MY',
            'phone' => '01'.mt_rand(10000000, 99999999),
        ]))->all();
    }

    /** @return array<Party> */
    private function makeVendors(Company $company): array
    {
        $defs = [
            ['Pantas Courier Services Sdn Bhd', 'BRN', '201104031122'],
            ['KL Office Supplies Trading', 'BRN', '201503042233'],
            ['Selangor Water (Air Selangor)', 'BRN', '199601053344'],
            ['Maxis Business Broadband', 'BRN', '199201064455'],
            ['CloudStack Software Pte Ltd', 'BRN', 'SG-201500075566'],
            ['Restu Cleaning Services', 'NRIC', '900215-10-6677'],
            ['Perdana Legal & Secretarial PLT', 'BRN', 'LLP0067788'],
            ['TechParts Distribution Sdn Bhd', 'BRN', '201709088990'],
            ['Amanah Insurance Brokers', 'BRN', '200801090011'],
            ['Great Print Media Sdn Bhd', 'BRN', '201302010122'],
        ];

        return collect($defs)->map(fn ($d) => $company->parties()->create([
            'role' => 'vendor', 'name' => $d[0],
            'registration_scheme' => $d[1], 'registration_number' => $d[2],
            'email' => Str::slug($d[0]).'@example.my',
            'city' => 'Kuala Lumpur', 'state' => 'Wilayah Persekutuan', 'country_code' => 'MY',
        ]))->all();
    }

    /** @return array<Item> 10 services + 10 goods */
    private function makeItems(Company $company): array
    {
        $income = $company->accounts()->where('code', '4100')->firstOrFail();
        $salesIncome = $company->accounts()->where('code', '4000')->firstOrFail();
        $cogs = $company->accounts()->where('code', '5000')->firstOrFail();
        $svcTax8 = $company->taxCodes()->where('name', 'Service Tax 8%')->firstOrFail();
        $salesTax10 = $company->taxCodes()->where('name', 'Sales Tax 10%')->firstOrFail();

        $services = [
            ['Website design & build', 4500, '022'],
            ['Monthly website maintenance', 350, '022'],
            ['SEO retainer (monthly)', 1200, '022'],
            ['Social media management (monthly)', 1800, '022'],
            ['Brand identity package', 3800, '022'],
            ['E-commerce store setup', 6500, '022'],
            ['Copywriting (per page)', 180, '022'],
            ['Hosting & domain (annual)', 480, '022'],
            ['Consultation (hourly)', 250, '022'],
            ['Email marketing campaign', 950, '022'],
        ];
        $goods = [
            ['Business cards (box of 500)', 'PRT-BC500', 95, '008'],
            ['Vinyl banner 8x4 ft', 'PRT-BN84', 160, '008'],
            ['Brochures A5 (pack of 1000)', 'PRT-BR1K', 420, '008'],
            ['Corporate T-shirt (printed)', 'MER-TS01', 28, '008'],
            ['Branded mug', 'MER-MG01', 18, '008'],
            ['Signage lightbox 4x2 ft', 'SGN-LB42', 780, '008'],
            ['Sticker labels (roll of 500)', 'PRT-ST500', 65, '008'],
            ['Pull-up display stand', 'DSP-PU01', 220, '008'],
            ['Corporate notebook (branded)', 'MER-NB01', 22, '008'],
            ['Event backdrop 10x8 ft', 'PRT-BD108', 950, '008'],
        ];

        $created = [];
        foreach ($services as [$name, $price, $class]) {
            $created[] = $company->items()->create([
                'type' => 'service', 'name' => $name, 'unit_price' => $price,
                'income_account_id' => $income->id, 'default_tax_code_id' => $svcTax8->id,
                'classification_code' => $class, 'unit_of_measure' => 'unit',
            ]);
        }
        foreach ($goods as [$name, $sku, $price, $class]) {
            $created[] = $company->items()->create([
                'type' => 'goods', 'name' => $name, 'sku' => $sku, 'unit_price' => $price,
                'income_account_id' => $salesIncome->id, 'expense_account_id' => $cogs->id,
                'default_tax_code_id' => $salesTax10->id,
                'classification_code' => $class, 'unit_of_measure' => 'unit',
            ]);
        }

        return $created;
    }

    /** @return array<Estimate> */
    private function makeEstimates(Company $company, array $customers, array $items): array
    {
        $svc = app(EstimateService::class);
        $created = [];

        for ($i = 0; $i < 10; $i++) {
            $issue = Carbon::now()->subDays(mt_rand(0, 45));
            $estimate = $company->estimates()->create([
                'party_id' => $customers[$i % count($customers)]->id,
                'estimate_number' => $svc->nextNumber($company),
                'issue_date' => $issue->toDateString(),
                'expiry_date' => $issue->copy()->addDays(30)->toDateString(),
            ]);
            foreach ($this->randomLines($items, 'income_account_id') as $line) {
                $estimate->lines()->create($line);
            }
            $svc->calculateTotals($estimate->refresh());
            if ($i % 2 === 1) {
                $svc->send($estimate->refresh()); // sent estimates never post to the ledger
            }
            $created[] = $estimate;
        }

        return $created;
    }

    /** @return array<Invoice> */
    private function makeDraftInvoices(Company $company, array $customers, array $items): array
    {
        $svc = app(InvoiceService::class);
        $created = [];

        for ($i = 0; $i < 10; $i++) {
            $issue = Carbon::now()->subDays(mt_rand(0, 20));
            $invoice = $company->invoices()->create([
                'party_id' => $customers[($i + 3) % count($customers)]->id,
                'invoice_number' => $svc->nextNumber($company),
                'issue_date' => $issue->toDateString(),
                'due_date' => $issue->copy()->addDays(30)->toDateString(),
            ]);
            foreach ($this->randomLines($items, 'income_account_id') as $line) {
                $invoice->lines()->create($line);
            }
            $svc->calculateTotals($invoice->refresh()); // stays draft — no approve(), nothing posts
            $created[] = $invoice;
        }

        return $created;
    }

    /** @return array<Bill> */
    private function makeDraftBills(Company $company, array $vendors): array
    {
        $svc = app(BillService::class);
        $noSst = $company->taxCodes()->where('name', 'No SST')->firstOrFail();
        $svcTax8 = $company->taxCodes()->where('name', 'Service Tax 8%')->firstOrFail();
        $expenseAccounts = $company->accounts()->whereIn('code', ['5000', '6200', '6300', '6400', '6500', '6900'])->get();

        $descriptions = [
            'Courier & delivery charges', 'Office stationery order', 'Water utility',
            'Business broadband (monthly)', 'SaaS subscription (annual)', 'Office cleaning (monthly)',
            'Company secretarial fees', 'Computer parts & accessories', 'Business insurance premium',
            'Flyer printing job',
        ];

        $created = [];
        for ($i = 0; $i < 10; $i++) {
            $billDate = Carbon::now()->subDays(mt_rand(0, 25));
            $bill = $company->bills()->create([
                'party_id' => $vendors[$i % count($vendors)]->id,
                'bill_number' => strtoupper(Str::random(2)).'-'.mt_rand(1000, 9999),
                'bill_date' => $billDate->toDateString(),
                'due_date' => $billDate->copy()->addDays(14)->toDateString(),
            ]);
            $bill->lines()->create([
                'description' => $descriptions[$i],
                'quantity' => 1,
                'unit_price' => mt_rand(80, 3500),
                'tax_code_id' => ($i % 3 === 0 ? $svcTax8 : $noSst)->id,
                'expense_account_id' => $expenseAccounts->random()->id,
            ]);
            $svc->calculateTotals($bill->refresh()); // stays draft — no approve(), nothing posts
            $created[] = $bill;
        }

        return $created;
    }

    /** @return array<BankTransaction> unmatched — left in the review queue */
    private function makeBankTransactions(Company $company): array
    {
        $bank = $company->accounts()->where('code', '1010')->firstOrFail();
        $defs = [
            ['DuitNow transfer from SRI EMAS LOGISTICS', 4500, 'in'],
            ['FPX payment received - RIMBA DIGITAL', 1272, 'in'],
            ['Cheque deposit 004512', 3800, 'in'],
            ['IBG transfer MELUR & CO', 950, 'in'],
            ['Cash deposit at branch', 600, 'in'],
            ['POS purchase - SHOPEE *OFFICE CHAIR', 489, 'out'],
            ['Standing instruction - MAXIS BROADBAND', 212, 'out'],
            ['DuitNow QR - RESTU CLEANING', 280, 'out'],
            ['Card payment - CANVA* SUBSCRIPTION', 52, 'out'],
            ['ATM withdrawal', 500, 'out'],
            ['Interbank GIRO - KL OFFICE SUPPLIES', 341, 'out'],
            ['Interest credit', 12, 'in'],
        ];

        $created = [];
        foreach ($defs as $i => [$desc, $amount, $direction]) {
            $created[] = $company->bankTransactions()->create([
                'account_id' => $bank->id,
                'txn_date' => Carbon::now()->subDays(mt_rand(1, 30))->toDateString(),
                'description' => $desc,
                'amount' => number_format($amount, 2, '.', ''),
                'direction' => $direction,
                // status defaults to 'unmatched' — waiting in the review queue
            ]);
        }

        return $created;
    }

    /** 1–3 random lines built from seeded items. @return array<array<string, mixed>> */
    private function randomLines(array $items, string $accountKey): array
    {
        $lines = [];
        foreach (collect($items)->random(mt_rand(1, 3)) as $item) {
            $lines[] = [
                'item_id' => $item->id,
                'description' => $item->name,
                'quantity' => $item->type === 'goods' ? mt_rand(1, 20) : 1,
                'unit_price' => $item->unit_price,
                'tax_code_id' => $item->default_tax_code_id,
                'classification_code' => $item->classification_code,
                $accountKey => $item->income_account_id,
            ];
        }

        return $lines;
    }
}
