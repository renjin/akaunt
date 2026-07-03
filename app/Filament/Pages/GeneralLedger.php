<?php

namespace App\Filament\Pages;

use App\Models\Account;
use App\Services\ReportService;
use Filament\Facades\Filament;
use Filament\Pages\Page;

class GeneralLedger extends Page
{
    protected string $view = 'filament.pages.general-ledger';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-book-open';

    protected static string|\UnitEnum|null $navigationGroup = 'Reports';

    public ?int $accountId = null;
    public string $from = '';
    public string $to = '';

    public function mount(): void
    {
        $this->from = today()->startOfYear()->toDateString();
        $this->to = today()->toDateString();
    }

    public function getAccounts()
    {
        return Filament::getTenant()->accounts()->orderBy('code')->get();
    }

    public function getReport(): ?array
    {
        if (! $this->accountId) {
            return null;
        }
        $account = Account::find($this->accountId);
        if (! $account || $account->company_id !== Filament::getTenant()->id) {
            return null;
        }

        return app(ReportService::class)->generalLedger(Filament::getTenant(), $account, $this->from, $this->to);
    }
}
