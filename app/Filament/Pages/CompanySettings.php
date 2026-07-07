<?php

namespace App\Filament\Pages;

use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CompanySettings extends EditTenantProfile
{
    public static function getLabel(): string
    {
        return 'Company settings';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Company')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->required(),
                        Select::make('legal_form')
                            ->options([
                                'sdn_bhd' => 'Sdn Bhd', 'partnership' => 'Partnership',
                                'sole_prop' => 'Sole proprietorship', 'llp' => 'LLP',
                            ])
                            ->disabled() // drives the CoA template; changing it post-setup would orphan accounts
                            ->required(),
                        TextInput::make('brn')->label('BRN (SSM)'),
                        TextInput::make('tin')->label('TIN (LHDN)'),
                        TextInput::make('sst_registration_no')->label('SST registration no.')
                            ->helperText('Leave empty if not SST-registered'),
                        TextInput::make('msic_code')->label('MSIC code')->maxLength(5),
                        TextInput::make('email')->email(),
                        TextInput::make('phone'),
                        Select::make('document_locale')
                            ->label('Invoice document language')
                            ->options(['en' => 'English', 'ms' => 'Bahasa Malaysia'])
                            ->default('en')
                            ->helperText('Language used for outgoing invoice PDFs and emails.')
                            ->required(),
                    ]),
                Section::make('Address')
                    ->columns(2)
                    ->schema([
                        TextInput::make('address_line1')->columnSpanFull(),
                        TextInput::make('address_line2')->columnSpanFull(),
                        TextInput::make('city'),
                        TextInput::make('state'),
                        TextInput::make('postcode'),
                        TextInput::make('country_code')->maxLength(2),
                    ]),
                Section::make('Getting paid')
                    ->description('Shown on unpaid invoice PDFs and emails so customers can pay you directly.')
                    ->columns(2)
                    ->schema([
                        Textarea::make('duitnow_qr_payload')
                            ->label('DuitNow QR payload')
                            ->helperText('The QR text string from your bank\'s DuitNow QR merchant enrolment. We render it as a scannable QR on invoices.')
                            ->rows(3)
                            ->columnSpanFull(),
                        TextInput::make('payment_link')
                            ->label('Fallback payment link')
                            ->url()
                            ->helperText('Used when an invoice has no HitPay checkout link.')
                            ->columnSpanFull(),
                        TextInput::make('hitpay_api_key')
                            ->label('HitPay API key')
                            ->password()
                            ->helperText('Per-invoice checkout links (FPX, DuitNow, cards) with automatic payment recording.'),
                        TextInput::make('hitpay_salt')
                            ->label('HitPay salt')
                            ->password()
                            ->helperText('Used to verify payment webhooks.'),
                        Select::make('hitpay_environment')
                            ->label('HitPay environment')
                            ->options(['sandbox' => 'Sandbox', 'production' => 'Production'])
                            ->default('sandbox'),
                        Select::make('hitpay_deposit_account_id')
                            ->label('Deposit HitPay payments to')
                            ->options(fn () => Filament::getTenant()->accounts()
                                ->where('subtype', 'cash_bank')->where('active', true)->orderBy('code')
                                ->get()->mapWithKeys(fn ($a) => [$a->id => "{$a->code} · {$a->name}"])),
                    ]),
                Section::make('Invoice & estimate defaults')
                    ->description('Prefilled on every new document — you can still change them per invoice or estimate.')
                    ->columns(2)
                    ->schema([
                        Select::make('payment_terms_days_default')
                            ->label('Default payment terms')
                            ->options([0 => 'On receipt', 7 => 'Net 7', 14 => 'Net 14', 30 => 'Net 30'])
                            ->default(30)
                            ->helperText('Drives the payment due date suggested on new invoices.')
                            ->columnSpanFull(),
                        Textarea::make('invoice_notes_default')
                            ->label('Standard invoice notes / terms')
                            ->rows(4)
                            ->helperText('Prefilled into the notes of every new invoice.'),
                        Textarea::make('estimate_notes_default')
                            ->label('Standard estimate notes / terms')
                            ->rows(4)
                            ->helperText('Prefilled into the notes of every new estimate.'),
                    ]),
                Section::make('LHDN e-Invoice')
                    ->description('Businesses under RM1,000,000 annual turnover are currently exempt (Dec 2025 LHDN decision — verify against the current timeline). Enable when you cross the threshold or want to pilot.')
                    ->columns(2)
                    ->schema([
                        Toggle::make('einvoice_enabled')->label('Enable e-Invoicing')->columnSpanFull(),
                        TextInput::make('credential_keyid')
                            ->label('einvoiceapp.my Key ID')
                            ->dehydrated(false),
                        TextInput::make('credential_keysecret')
                            ->label('einvoiceapp.my Key Secret')
                            ->password()
                            ->dehydrated(false),
                        Select::make('credential_environment')
                            ->label('Environment')
                            ->options(['staging' => 'Staging (dev-api)', 'production' => 'Production'])
                            ->dehydrated(false),
                    ]),
            ]);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $credential = Filament::getTenant()->einvoiceCredential;
        $data['credential_keyid'] = $credential?->keyid;
        $data['credential_environment'] = $credential?->environment ?? 'staging';
        // keysecret intentionally not shown back

        return $data;
    }

    protected function afterSave(): void
    {
        $state = $this->form->getRawState();
        if (filled($state['credential_keyid'] ?? null) && filled($state['credential_keysecret'] ?? null)) {
            Filament::getTenant()->einvoiceCredential()->updateOrCreate([], [
                'keyid' => $state['credential_keyid'],
                'keysecret' => $state['credential_keysecret'],
                'environment' => $state['credential_environment'] ?? 'staging',
            ]);
        }
    }
}
