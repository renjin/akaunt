<?php

namespace App\Filament\Resources\Parties\Pages;

use App\Filament\Resources\Parties\PartyResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditParty extends EditRecord
{
    protected static string $resource = PartyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
