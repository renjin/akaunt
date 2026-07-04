<?php

namespace App\Filament\Resources\RecurringInvoices\Pages;

use App\Filament\Resources\RecurringInvoices\RecurringInvoiceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRecurringInvoice extends CreateRecord
{
    protected static string $resource = RecurringInvoiceResource::class;
}
