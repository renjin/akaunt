# CLAUDE.md

## Product Direction

Akaunt should feel like a Wave-style accounting workspace for Malaysian small businesses: calm, light, task-oriented, and useful before it is configurable. Prefer workflows and financial context over raw admin tables.

## Current UX Roadmap

1. App shell and dashboard: intent navigation, quick actions, overdue invoices/bills, cash flow, profit/loss, bank review status.
2. Sales & Payments: invoice list metrics, invoice composer, customer profiles, products/services.
3. Purchases: bills, vendors, payment status, payables.
4. Banking: transaction review queue, categorization, reconciliation.
5. Reports: grouped reports index plus polished report pages with date presets and exports.

## Implementation Notes

- Keep accounting rules in `app/Services`; Filament pages/resources should orchestrate UI only.
- Use `resources/css/filament/admin/theme.css` for Filament visual polish.
- Navigation should stay grouped by user intent: Sales & Payments, Purchases, Accounting, Banking, Reports.
- Use real tenant data from `Filament::getTenant()` for dashboard summaries; avoid placeholder metrics.
- For Wave parity work, compare against the live Wave screenshots captured in `/tmp/akaunt-ux-audit/` when available.
