<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use InvalidArgumentException;

/**
 * Records a month's payroll (run in an external provider — e.g. hr.my,
 * which maintains the EPF/SOCSO/EIS/PCB statutory tables) as ONE balanced
 * journal entry. We deliberately do NOT compute statutory amounts here:
 * ponytail: no PCB/EPF/SOCSO calculation engine — the provider owns the
 * tables and the liability; we just book its monthly summary.
 *
 * Dr Salaries & Wages ........... gross
 * Dr EPF Contributions .......... employer EPF
 * Dr SOCSO & EIS Contributions .. employer SOCSO + EIS
 * Dr HRD Corp Levy .............. levy (if any)
 *   Cr EPF Payable .............. employer + employee EPF
 *   Cr SOCSO & EIS Payable ...... employer + employee SOCSO/EIS
 *   Cr PCB Payable .............. PCB/MTD withheld
 *   Cr HRD Corp Levy Payable .... levy
 *   Cr Bank ..................... net pay (gross − employee deductions)
 */
class PayrollJournalService
{
    public function __construct(private PostingService $poster)
    {
    }

    /**
     * @param array{
     *   gross: string|float, employer_epf?: string|float, employee_epf?: string|float,
     *   employer_socso_eis?: string|float, employee_socso_eis?: string|float,
     *   pcb?: string|float, hrdf?: string|float
     * } $totals
     */
    public function post(Company $company, string $paymentDate, array $totals, Account $bankAccount, ?string $reference = null): JournalEntry
    {
        // Backfill statutory accounts for companies created before they were in the template.
        ChartOfAccountsTemplate::seed($company);

        $n = fn ($key) => number_format((float) ($totals[$key] ?? 0), 2, '.', '');
        [$gross, $erEpf, $eeEpf, $erSocso, $eeSocso, $pcb, $hrdf] = [
            $n('gross'), $n('employer_epf'), $n('employee_epf'),
            $n('employer_socso_eis'), $n('employee_socso_eis'), $n('pcb'), $n('hrdf'),
        ];

        if ((float) $gross <= 0) {
            throw new InvalidArgumentException('Gross salaries must be positive.');
        }

        $deductions = bcadd(bcadd($eeEpf, $eeSocso, 2), $pcb, 2);
        $netPay = bcsub($gross, $deductions, 2);
        if ((float) $netPay < 0) {
            throw new InvalidArgumentException('Employee deductions exceed gross salaries — check the figures.');
        }

        $acct = fn (string $code) => $company->accounts()->where('code', $code)->firstOrFail()->id;

        $lines = array_values(array_filter([
            ['account_id' => $acct('6000'), 'debit' => $gross],
            (float) $erEpf > 0 ? ['account_id' => $acct('6010'), 'debit' => $erEpf] : null,
            (float) $erSocso > 0 ? ['account_id' => $acct('6020'), 'debit' => $erSocso] : null,
            (float) $hrdf > 0 ? ['account_id' => $acct('6030'), 'debit' => $hrdf] : null,
            (float) bcadd($erEpf, $eeEpf, 2) > 0 ? ['account_id' => $acct('2210'), 'credit' => bcadd($erEpf, $eeEpf, 2)] : null,
            (float) bcadd($erSocso, $eeSocso, 2) > 0 ? ['account_id' => $acct('2220'), 'credit' => bcadd($erSocso, $eeSocso, 2)] : null,
            (float) $pcb > 0 ? ['account_id' => $acct('2230'), 'credit' => $pcb] : null,
            (float) $hrdf > 0 ? ['account_id' => $acct('2240'), 'credit' => $hrdf] : null,
            (float) $netPay > 0 ? ['account_id' => $bankAccount->id, 'credit' => $netPay] : null,
        ]));

        return $this->poster->post(
            $company,
            $paymentDate,
            $lines,
            'Payroll — ' . \Illuminate\Support\Carbon::parse($paymentDate)->format('F Y'),
            $reference,
        );
    }

    /**
     * Settle a statutory payable when it's remitted to KWSP/PERKESO/LHDN the
     * following month: Dr payable / Cr bank.
     */
    public function remitStatutory(Company $company, string $accountCode, string $amount, string $paymentDate, Account $bankAccount): JournalEntry
    {
        $payable = $company->accounts()->where('code', $accountCode)->where('subtype', 'statutory_payable')->firstOrFail();

        return $this->poster->post(
            $company,
            $paymentDate,
            [
                ['account_id' => $payable->id, 'debit' => $amount],
                ['account_id' => $bankAccount->id, 'credit' => $amount],
            ],
            "Statutory remittance — {$payable->name}",
        );
    }
}
