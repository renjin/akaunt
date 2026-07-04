<?php

namespace App\Services;

use App\Models\Company;

/**
 * Seeds an MPERS-shaped chart of accounts for a company, with the equity
 * section varying by legal form. British/IFRS terminology, MYR, single-stage
 * SST (SST Payable liability only — no "recoverable tax" asset; purchase SST
 * is folded into the expense).
 */
class ChartOfAccountsTemplate
{
    public static function seed(Company $company): void
    {
        $rows = array_merge(
            self::common(),
            self::equity($company->legal_form),
        );

        foreach ($rows as [$code, $name, $type, $subtype, $system]) {
            $company->accounts()->firstOrCreate(
                ['code' => $code],
                ['name' => $name, 'type' => $type, 'subtype' => $subtype, 'is_system' => $system],
            );
        }

        self::seedTaxCodes($company);
    }

    /** Malaysian SST codes (current rates, mid-2026). Single-stage: output tax only. */
    private static function seedTaxCodes(Company $company): void
    {
        $sstPayable = $company->accounts()->where('subtype', 'sst_payable')->first();

        $codes = [
            ['Service Tax 8%', 'service', 8.00, '01'],
            ['Service Tax 6%', 'service', 6.00, '01'], // F&B, telco, parking, logistics
            ['Sales Tax 10%',  'sales', 10.00, '01'],
            ['Sales Tax 5%',   'sales',  5.00, '01'],
            ['Exempt',         'exempt', 0.00, 'E'],
            ['No SST',         'not_applicable', 0.00, '06'], // SST-unregistered default
        ];

        foreach ($codes as [$name, $type, $rate, $myinvois]) {
            $company->taxCodes()->firstOrCreate(
                ['name' => $name],
                [
                    'tax_type' => $type,
                    'rate' => $rate,
                    'sst_payable_account_id' => $rate > 0 ? $sstPayable?->id : null,
                    'myinvois_tax_type_code' => $myinvois,
                ],
            );
        }
    }

    /** @return array<int, array{0:string,1:string,2:string,3:string,4:bool}> */
    private static function common(): array
    {
        return [
            // ---- Assets ----
            ['1000', 'Cash on Hand',                'asset', 'cash_bank', false],
            ['1010', 'Bank Account',                'asset', 'cash_bank', false],
            ['1100', 'Accounts Receivable',         'asset', 'accounts_receivable', true],
            ['1200', 'Inventory',                   'asset', 'inventory', false],
            ['1300', 'Deposits & Prepayments',      'asset', 'current_asset', false],
            ['1500', 'Property, Plant & Equipment', 'asset', 'fixed_asset', false],
            ['1510', 'Accumulated Depreciation',    'asset', 'fixed_asset', false],
            // ---- Liabilities ----
            ['2100', 'Accounts Payable',            'liability', 'accounts_payable', true],
            ['2200', 'SST Payable',                 'liability', 'sst_payable', true],
            ['2300', 'Accrued Expenses',            'liability', 'current_liability', false],
            ['2400', 'Loans Payable',               'liability', 'loan', false],
            // ---- Income ----
            ['4000', 'Sales Revenue',               'income', 'operating_revenue', false],
            ['4100', 'Service Revenue',             'income', 'operating_revenue', false],
            ['4900', 'Other Income',                'income', 'other_income', false],
            ['4910', 'Foreign Exchange Gain/Loss',  'income', 'fx_gain_loss', true],
            // ---- Cost of sales & expenses ----
            ['5000', 'Cost of Sales',               'expense', 'cogs', false],
            ['6000', 'Salaries & Wages',            'expense', 'operating_expense', false],
            ['6010', 'EPF Contributions',           'expense', 'operating_expense', false],
            ['6020', 'SOCSO & EIS Contributions',   'expense', 'operating_expense', false],
            ['6100', 'Rent',                        'expense', 'operating_expense', false],
            ['6110', 'Utilities',                   'expense', 'operating_expense', false],
            ['6200', 'Marketing & Advertising',     'expense', 'operating_expense', false],
            ['6300', 'Professional Fees',           'expense', 'operating_expense', false],
            ['6400', 'Office Supplies',             'expense', 'operating_expense', false],
            ['6500', 'Travel & Transport',          'expense', 'operating_expense', false],
            ['6600', 'Bank Charges',                'expense', 'operating_expense', false],
            ['6700', 'Depreciation',                'expense', 'operating_expense', false],
            ['6900', 'General Expenses',            'expense', 'operating_expense', false],
        ];
    }

    /** Equity section per legal form. @return array<int, array{0:string,1:string,2:string,3:string,4:bool}> */
    private static function equity(string $legalForm): array
    {
        return match ($legalForm) {
            'partnership' => [
                ['3000', "Partners' Capital",  'equity', 'partner_capital', false],
                ['3010', "Partners' Current",  'equity', 'partner_capital', false],
                ['3100', 'Retained Earnings',  'equity', 'retained_earnings', true],
            ],
            'sole_prop' => [
                ['3000', "Owner's Capital",    'equity', 'owner_capital', false],
                ['3010', "Owner's Drawings",   'equity', 'drawings', false],
                ['3100', 'Retained Earnings',  'equity', 'retained_earnings', true],
            ],
            // sdn_bhd, llp
            default => [
                ['3000', 'Share Capital',      'equity', 'share_capital', false],
                ['3100', 'Retained Earnings',  'equity', 'retained_earnings', true],
            ],
        };
    }
}
