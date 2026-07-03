<?php

namespace App\Filament\Resources\JournalEntries\Pages;

use App\Filament\Resources\JournalEntries\JournalEntryResource;
use App\Services\PostingService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class CreateJournalEntry extends CreateRecord
{
    protected static string $resource = JournalEntryResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(PostingService::class)->post(
                Filament::getTenant(),
                $data['entry_date'],
                $data['lines'] ?? [],
                $data['description'] ?? null,
                $data['reference'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['data.lines' => $e->getMessage()]);
        }
    }
}
