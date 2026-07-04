<?php

namespace App\Filament\Pages;

use App\Models\Account;
use App\Services\BankReconciliationService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use InvalidArgumentException;

class ReconcileAccount extends Page
{
    protected string $view = 'filament.pages.reconcile-account';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-check-badge';

    protected static string|\UnitEnum|null $navigationGroup = 'Banking';

    protected static ?string $title = 'Reconcile';

    public ?int $accountId = null;
    public string $statementDate = '';
    public string $statementBalance = '0.00';

    /** @var array<int, bool> line id => checked */
    public array $checked = [];

    public function mount(): void
    {
        $this->statementDate = today()->toDateString();
        $this->accountId = Filament::getTenant()->accounts()->where('subtype', 'cash_bank')->value('id');
    }

    public function getAccounts()
    {
        return Filament::getTenant()->accounts()->where('subtype', 'cash_bank')->orderBy('code')->get();
    }

    public function getAccount(): ?Account
    {
        if (! $this->accountId) {
            return null;
        }
        $account = Account::find($this->accountId);

        return ($account && $account->company_id === Filament::getTenant()->id) ? $account : null;
    }

    public function getLines()
    {
        $account = $this->getAccount();
        if (! $account) {
            return collect();
        }

        return app(BankReconciliationService::class)->unreconciledLines($account, $this->statementDate);
    }

    public function getPreviousBalance(): string
    {
        $account = $this->getAccount();

        return $account ? app(BankReconciliationService::class)->previouslyReconciledBalance($account, $this->statementDate) : '0.00';
    }

    public function getClearedBalance(): string
    {
        $account = $this->getAccount();
        if (! $account) {
            return '0.00';
        }
        $ids = array_keys(array_filter($this->checked));

        return app(BankReconciliationService::class)->clearedBalance($account, $this->statementDate, $ids);
    }

    public function getDifference(): string
    {
        return bcsub($this->statementBalance ?: '0.00', $this->getClearedBalance(), 2);
    }

    public function finish(): void
    {
        $account = $this->getAccount();
        if (! $account) {
            return;
        }
        $ids = array_keys(array_filter($this->checked));

        try {
            $count = app(BankReconciliationService::class)->finish($account, $this->statementDate, $this->statementBalance, $ids);
            $this->checked = [];
            Notification::make()->success()->title("Reconciled {$count} lines. Account is now balanced to the statement.")->send();
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }
}
