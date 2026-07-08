<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Company;
use App\Services\BankTransactionService;
use App\Services\BillService;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Seeds ~3 years of realistic invoices, bills, payments, estimates, and bank
 * transactions for one company, so the app has data to demo/dashboard
 * against instead of an empty tenant. Business flavor + tax treatment vary
 * per profile (SST-registered agency vs SME-exempt small businesses).
 */
class TransactionSeeder
{
    private InvoiceService $invoiceSvc;

    private BillService $billSvc;

    private PaymentService $paymentSvc;

    private BankTransactionService $bankSvc;

    public function __construct()
    {
        $this->invoiceSvc = app(InvoiceService::class);
        $this->billSvc = app(BillService::class);
        $this->paymentSvc = app(PaymentService::class);
        $this->bankSvc = app(BankTransactionService::class);
    }

    public function run(Company $company, string $profile): void
    {
        DB::transaction(function () use ($company, $profile) {
            $data = self::profile($profile);

            $bank = $company->accounts()->where('code', '1010')->firstOrFail();
            $taxCodes = $company->taxCodes()->pluck('id', 'name');
            $saleTax = $taxCodes[$data['sale_tax']] ?? null;
            $purchaseTax = $taxCodes[$data['purchase_tax']] ?? null;

            $customers = collect($data['customers'])->map(fn ($name) => $company->parties()->create([
                'role' => 'customer', 'name' => $name, 'email' => self::slugEmail($name),
                'registration_scheme' => 'NRIC', 'registration_number' => (string) random_int(700101, 991231) . '-01-' . random_int(1000, 9999),
            ]));

            $vendors = collect($data['vendors'])->map(fn ($name) => $company->parties()->create([
                'role' => 'vendor', 'name' => $name, 'email' => self::slugEmail($name),
                'registration_scheme' => 'BRN', 'registration_number' => (string) random_int(200001, 202599) . '01' . random_int(100000, 999999),
            ]));

            $saleItems = collect($data['sale_items'])->map(fn ($it) => $company->items()->create([
                'kind' => 'sales', 'type' => $it[3] ?? 'service', 'name' => $it[0], 'unit_price' => $it[1],
                'income_account_id' => $company->accounts()->where('code', $it[2])->value('id'),
                'default_tax_code_id' => $saleTax,
            ]));

            $purchaseItems = collect($data['purchase_items'])->map(fn ($it) => $company->items()->create([
                'kind' => 'purchase', 'type' => 'service', 'name' => $it[0], 'unit_price' => $it[1],
                'expense_account_id' => $company->accounts()->where('code', $it[2])->value('id'),
                'default_tax_code_id' => $purchaseTax,
            ]));

            $today = Carbon::parse('today');
            $start = $today->copy()->subYears(3)->startOfMonth();

            for ($month = $start->copy(); $month->lte($today); $month->addMonth()) {
                $monthEnd = $month->copy()->endOfMonth()->min($today);
                $ageMonths = (int) $month->diffInMonths($today);

                foreach (range(1, random_int(3, 6)) as $_) {
                    $this->makeInvoice($company, $customers->random(), $saleItems, $saleTax, $bank, $month, $monthEnd, $ageMonths);
                }
                foreach (range(1, random_int(2, 4)) as $_) {
                    $this->makeBill($company, $vendors->random(), $purchaseItems, $purchaseTax, $bank, $month, $monthEnd, $ageMonths);
                }
                foreach (range(1, random_int(1, 2)) as $_) {
                    $this->makeEstimate($company, $customers->random(), $saleItems, $saleTax, $month, $monthEnd);
                }
                $this->makeBankFee($company, $bank, $month, $monthEnd);

                // Leave the last 2 months of misc bank activity unmatched, for the review queue.
                if ($ageMonths <= 1) {
                    $this->makeUnmatchedBankActivity($company, $bank, $month, $monthEnd);
                }
            }
        });
    }

    private function makeInvoice(Company $company, $customer, $items, $saleTax, Account $bank, Carbon $monthStart, Carbon $monthEnd, int $ageMonths): void
    {
        $issueDate = self::randomDate($monthStart, $monthEnd);
        $terms = [7, 14, 30][array_rand([7, 14, 30])];

        $invoice = $company->invoices()->create([
            'party_id' => $customer->id,
            'invoice_number' => $this->invoiceSvc->nextNumber($company),
            'issue_date' => $issueDate->toDateString(),
            'due_date' => $issueDate->copy()->addDays($terms)->toDateString(),
            'discount_type' => random_int(1, 100) <= 12 ? 'percent' : 'fixed',
            'discount_value' => random_int(1, 100) <= 12 ? (float) random_int(5, 15) : 0,
        ]);

        foreach ($items->random(random_int(1, 3)) as $item) {
            $invoice->lines()->create([
                'item_id' => $item->id, 'description' => $item->name,
                'quantity' => random_int(1, 4), 'unit_price' => $item->unit_price,
                'discount' => random_int(1, 100) <= 8 ? round($item->unit_price * 0.05, 2) : 0,
                'tax_code_id' => $saleTax, 'tax_code_ids' => $saleTax ? [$saleTax] : [],
                'income_account_id' => $item->income_account_id,
            ]);
        }

        $this->invoiceSvc->approve($invoice->refresh());

        // Voiding only applies to older, never-paid invoices.
        if ($ageMonths >= 3 && random_int(1, 100) <= 3) {
            $this->invoiceSvc->void($invoice);

            return;
        }

        [$payFraction, $lateDays] = self::paymentBehavior($ageMonths);
        if ($payFraction <= 0) {
            return;
        }

        $amount = bcmul((string) $invoice->total, (string) $payFraction, 2);
        if ((float) $amount <= 0) {
            return;
        }
        $paymentDate = $invoice->due_date->copy()->addDays($lateDays)->min(Carbon::parse('today'))->max($issueDate);

        $this->paymentSvc->receiveAgainstInvoice(
            $invoice->refresh(), $amount, $paymentDate->toDateString(), $bank,
            ['bank_transfer', 'fpx', 'duitnow', 'cheque'][array_rand(['bank_transfer', 'fpx', 'duitnow', 'cheque'])],
        );
    }

    private function makeBill(Company $company, $vendor, $items, $purchaseTax, Account $bank, Carbon $monthStart, Carbon $monthEnd, int $ageMonths): void
    {
        $billDate = self::randomDate($monthStart, $monthEnd);

        $bill = $company->bills()->create([
            'party_id' => $vendor->id,
            'bill_number' => strtoupper(substr($vendor->name, 0, 3)) . '-' . random_int(1000, 99999),
            'bill_date' => $billDate->toDateString(),
            'due_date' => $billDate->copy()->addDays(30)->toDateString(),
        ]);

        foreach ($items->random(random_int(1, 2)) as $item) {
            $bill->lines()->create([
                'item_id' => $item->id, 'description' => $item->name,
                'quantity' => random_int(1, 3), 'unit_price' => $item->unit_price,
                'tax_code_id' => $purchaseTax,
                'expense_account_id' => $item->expense_account_id,
            ]);
        }

        $this->billSvc->approve($bill->refresh());

        [$payFraction, $lateDays] = self::paymentBehavior($ageMonths);
        if ($payFraction <= 0) {
            return;
        }

        $amount = bcmul((string) $bill->total, (string) $payFraction, 2);
        if ((float) $amount <= 0) {
            return;
        }
        $paymentDate = $bill->due_date->copy()->addDays($lateDays)->min(Carbon::parse('today'))->max($billDate);

        $this->paymentSvc->payBill($bill->refresh(), $amount, $paymentDate->toDateString(), $bank, 'bank_transfer');
    }

    private function makeEstimate(Company $company, $customer, $items, $saleTax, Carbon $monthStart, Carbon $monthEnd): void
    {
        $issueDate = self::randomDate($monthStart, $monthEnd);
        $number = 'EST-' . str_pad((string) ($company->estimates()->count() + 1), 5, '0', STR_PAD_LEFT);

        $estimate = $company->estimates()->create([
            'party_id' => $customer->id, 'estimate_number' => $number,
            'issue_date' => $issueDate->toDateString(),
            'expiry_date' => $issueDate->copy()->addDays(30)->toDateString(),
            'status' => $issueDate->lt(Carbon::parse('today')->subDays(30)) ? 'expired' : (random_int(0, 1) ? 'accepted' : 'sent'),
        ]);

        $subtotal = '0.00';
        $tax = '0.00';
        foreach ($items->random(random_int(1, 2)) as $item) {
            $qty = random_int(1, 3);
            $lineTotal = bcmul((string) $qty, (string) $item->unit_price, 2);
            $lineTax = $saleTax ? $company->taxCodes()->find($saleTax)->calculate((float) $lineTotal) : '0.00';

            $estimate->lines()->create([
                'item_id' => $item->id, 'description' => $item->name,
                'quantity' => $qty, 'unit_price' => $item->unit_price,
                'tax_code_id' => $saleTax, 'tax_code_ids' => $saleTax ? [$saleTax] : [],
                'tax_amount' => $lineTax, 'line_total' => $lineTotal,
                'income_account_id' => $item->income_account_id,
            ]);
            $subtotal = bcadd($subtotal, $lineTotal, 2);
            $tax = bcadd($tax, $lineTax, 2);
        }

        $estimate->forceFill([
            'subtotal' => $subtotal, 'tax_total' => $tax, 'total' => bcadd($subtotal, $tax, 2),
        ])->save();
    }

    private function makeBankFee(Company $company, Account $bank, Carbon $monthStart, Carbon $monthEnd): void
    {
        $fees = $company->accounts()->where('code', '6600')->firstOrFail();
        $txn = $company->bankTransactions()->create([
            'account_id' => $bank->id,
            'txn_date' => self::randomDate($monthStart, $monthEnd)->toDateString(),
            'description' => 'Monthly account fee',
            'amount' => number_format(random_int(500, 1500) / 100, 2, '.', ''),
            'direction' => 'out',
        ]);
        $this->bankSvc->categorize($txn, $fees);
    }

    private function makeUnmatchedBankActivity(Company $company, Account $bank, Carbon $monthStart, Carbon $monthEnd): void
    {
        $misc = [
            ['DUITNOW TRANSFER RECEIVED', 'in', [50, 800]],
            ['POS PURCHASE - PETROL STATION', 'out', [50, 150]],
            ['ATM WITHDRAWAL', 'out', [100, 500]],
            ['ONLINE TRANSFER - UTILITIES', 'out', [80, 300]],
            ['INTEREST CREDIT', 'in', [5, 40]],
        ];

        foreach (array_rand($misc, min(count($misc), random_int(2, 4))) as $i) {
            [$desc, $dir, [$lo, $hi]] = $misc[$i];
            $company->bankTransactions()->create([
                'account_id' => $bank->id,
                'txn_date' => self::randomDate($monthStart, $monthEnd)->toDateString(),
                'description' => $desc,
                'amount' => number_format(random_int($lo * 100, $hi * 100) / 100, 2, '.', ''),
                'direction' => $dir,
            ]);
        }
    }

    /** [payFraction 0..1, days after due date payment lands] by document age in months. */
    private static function paymentBehavior(int $ageMonths): array
    {
        return match (true) {
            $ageMonths >= 3 => match (true) {
                random_int(1, 100) <= 85 => [1.0, -random_int(0, 20)],   // paid on/before term
                random_int(1, 100) <= 70 => [1.0, random_int(1, 25)],    // paid late
                random_int(1, 100) <= 50 => [0.5, random_int(1, 15)],    // partial
                default => [0.0, 0],                                     // stale unpaid
            },
            $ageMonths >= 1 => match (true) {
                random_int(1, 100) <= 45 => [1.0, -random_int(0, 10)],
                random_int(1, 100) <= 50 => [0.5, random_int(0, 10)],
                default => [0.0, 0],
            },
            default => match (true) {
                random_int(1, 100) <= 30 => [1.0, -random_int(0, 5)],
                default => [0.0, 0],
            },
        };
    }

    private static function randomDate(Carbon $start, Carbon $end): Carbon
    {
        $startTs = $start->copy()->startOfDay()->timestamp;
        $endTs = max($startTs, $end->copy()->startOfDay()->timestamp);

        return Carbon::createFromTimestamp(random_int($startTs, $endTs));
    }

    private static function slugEmail(string $name): string
    {
        return \Illuminate\Support\Str::slug($name) . '@example.my';
    }

    /** @return array{customers: array, vendors: array, sale_items: array, purchase_items: array, sale_tax: string, purchase_tax: string} */
    private static function profile(string $key): array
    {
        return match ($key) {
            'o2o-alliance' => [
                'sale_tax' => 'Service Tax 8%', 'purchase_tax' => 'Service Tax 8%',
                'customers' => [
                    'Bintang Retail Sdn Bhd', 'Nadi Logistics Sdn Bhd', 'Kelana Foods Sdn Bhd',
                    'Cahaya Fintech Sdn Bhd', 'Warisan Property Group', 'Sinar Health Sdn Bhd',
                    'Pantai Resorts Sdn Bhd', 'Delima Education Group', 'Mercu Telco Sdn Bhd', 'Ombak Media Sdn Bhd',
                ],
                'vendors' => [
                    'CloudStack Hosting Sdn Bhd', 'Freelance Studio Co', 'Menara Office Suites',
                    'Jaringan Telco Bhd', 'Kilat Courier Services', 'Amanah Chartered Accountants',
                ],
                'sale_items' => [
                    ['Website Development', 8000, '4100'], ['SEO Retainer', 2500, '4100'],
                    ['Social Media Management', 1800, '4100'], ['Brand Strategy Workshop', 4500, '4100'],
                    ['Mobile App Development', 15000, '4100'], ['Consulting Hours', 250, '4100'],
                ],
                'purchase_items' => [
                    ['Cloud Hosting Subscription', 350, '6900'], ['Freelance Design Fees', 1200, '6300'],
                    ['Office Rent', 4500, '6100'], ['Internet & Telco', 280, '6110'],
                    ['Courier & Delivery', 60, '6500'], ['Accounting Retainer', 800, '6300'],
                ],
            ],
            'pet-grooming' => [
                'sale_tax' => 'No SST', 'purchase_tax' => 'No SST',
                'customers' => [
                    'Aisyah Rahman', 'Wei Ling Tan', 'Kavitha Selvam', 'Mohd Farid', 'Siti Nurhaliza',
                    'Jason Lim', 'Priya Kumar', 'Ahmad Zulkifli', 'Michelle Wong', 'Rajesh Naidu',
                    'Nurul Izzah', 'Kenny Chong', 'Fatimah Zahra', 'Steven Yap', 'Devi Ramasamy',
                ],
                'vendors' => [
                    'PetCo Wholesale Supplies', 'Groom Pro Equipment', 'TNB Utilities',
                    'Shoplot Landlord Sdn Bhd', 'Etiqa Insurance',
                ],
                'sale_items' => [
                    ['Full Grooming Package', 120, '4100'], ['Bath & Brush', 60, '4100'],
                    ['Nail Trimming', 25, '4100'], ['Flea & Tick Treatment', 80, '4100'],
                    ['Pet Shampoo (Retail)', 35, '4000', 'goods'], ['Pet Leash & Collar (Retail)', 45, '4000', 'goods'],
                ],
                'purchase_items' => [
                    ['Grooming Supplies', 300, '5000'], ['Shop Rent', 2200, '6100'],
                    ['Electricity & Water', 350, '6110'], ['Business Insurance', 180, '6900'],
                ],
            ],
            'agritech' => [
                'sale_tax' => 'No SST', 'purchase_tax' => 'No SST',
                'customers' => [
                    'Ladang Sejahtera Sdn Bhd', 'Kebun Makmur Farm', 'Sawit Damai Estate',
                    'Padi Emas Cooperative', 'Ternak Jaya Farm', 'Buah Segar Plantation',
                    'Hijau Lestari Agro', 'Tani Bahagia Sdn Bhd',
                ],
                'vendors' => [
                    'AgriParts Supply Co', 'Petronas Fuel Station', 'Traktor Sewa Equipment Rental',
                    'Digi Telco Bhd', 'Cekap Bookkeeping Services',
                ],
                'sale_items' => [
                    ['Irrigation System Install', 6500, '4100'], ['Soil Testing Service', 450, '4100'],
                    ['Drone Crop Survey', 1800, '4100'], ['Equipment Rental (Daily)', 350, '4100'],
                    ['Farm Consulting', 300, '4100'],
                ],
                'purchase_items' => [
                    ['Spare Parts', 400, '6900'], ['Fuel & Diesel', 600, '6500'],
                    ['Equipment Lease', 1500, '6900'], ['Telco & Data', 150, '6110'],
                    ['Bookkeeping Fees', 350, '6300'],
                ],
            ],
        };
    }
}
