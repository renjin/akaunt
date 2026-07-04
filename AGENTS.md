# Repository Guidelines

## Project Structure & Module Organization

This is a Laravel 13 accounting app using Filament. Core code lives in `app/`: models in `app/Models`, accounting/e-invoice logic in `app/Services`, jobs in `app/Jobs`, and Filament pages/resources in `app/Filament`. Routes are in `routes/`. Database schema, factories, and seeders live under `database/`. Blade, mail, and PDF templates are in `resources/views`. The Filament theme is `resources/css/filament/admin/theme.css`. Tests are split into `tests/Feature` and `tests/Unit`.

## Build, Test, and Development Commands

- `composer install && npm install`: install PHP and Node dependencies.
- `composer setup`: install dependencies, create `.env`, generate the app key, migrate, and build assets.
- `composer dev`: run Laravel, queue listener, log tailing, and Vite together.
- `npm run dev`: start only the Vite asset server.
- `npm run build`: compile production frontend assets.
- `composer test` or `php artisan test`: clear config and run the Pest/PHPUnit suite.
- `php artisan migrate --seed`: apply schema changes and seed demo/reference data.

## Coding Style & Naming Conventions

Follow `.editorconfig`: UTF-8, LF endings, final newline, trimmed trailing whitespace, and 4-space indentation for PHP. Use Laravel/PSR-4 conventions: singular models, services ending in `Service`, jobs ending in `Job`, and Filament resources under `app/Filament/Resources/<Domain>`. Keep accounting behavior in services, not page/resource classes. Format PHP with `./vendor/bin/pint`.

## UX Direction

Build toward a Wave-style accounting cockpit: light app shell, intent navigation, quick actions, financial summaries before tables, and workflow pages for sales, purchases, banking, accounting, and reports. Prefer task labels like `Customers & Vendors` over raw model names like `Parties`.

## Testing Guidelines

Tests use Pest with the Laravel plugin. Add feature tests for visible accounting workflows in `tests/Feature`, especially ledger posting, invoices, payments, bills, reports, and e-invoice status handling. Keep isolated unit coverage in `tests/Unit`. Name test files by behavior, for example `InvoiceLifecycleTest.php`. Run `composer test` before handoff.

## Commit & Pull Request Guidelines

The current history uses short, imperative summaries such as `Build Akaunt: Malaysia-compliant accounting SaaS (Phases 0-3)`. Keep commits focused and describe the business or technical outcome. Pull requests should include a concise summary, test results, migration/seed impacts, and screenshots for Filament UI changes. Call out any `.env` or service credential requirements without committing secrets.

## Security & Configuration Tips

Keep `.env` local and never commit credentials, e-invoice keys, mail passwords, or tenant/company data. Use `.env.example` for safe configuration placeholders. Treat generated PDFs, invoices, and exports as potentially sensitive customer data.
