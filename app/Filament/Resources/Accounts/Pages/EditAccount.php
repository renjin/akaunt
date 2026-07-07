<?php

namespace App\Filament\Resources\Accounts\Pages;

use App\Filament\Resources\Accounts\AccountResource;
use App\Models\Account;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditAccount extends EditRecord
{
    protected static string $resource = AccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->before(function (DeleteAction $action, Account $record) {
                    if ($record->is_system || $record->journalLines()->exists()) {
                        Notification::make()
                            ->danger()
                            ->title($record->is_system
                                ? 'System accounts cannot be deleted.'
                                : 'This account has journal activity and cannot be deleted.')
                            ->send();

                        $action->cancel();
                    }
                }),
        ];
    }
}
