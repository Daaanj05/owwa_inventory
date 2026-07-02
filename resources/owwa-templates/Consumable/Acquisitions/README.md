# Consumable acquisition templates (offline procurement)

This folder holds OWWA **procurement** workbooks and their instruction documents for consumables. They are **not** exported by the application.

## Expected contents

Place original OWWA filenames here, for example:

- Purchase Request (PR) workbook and `Instructions - …` document
- Purchase Order (PO) workbook and instructions
- Inspection and Acceptance Report (IAR) workbook and instructions

Keep instruction PDF/DOC filenames paired with their Excel form (see `php artisan app:audit-owwa-templates`).

## What the app exports instead

After offline PR → PO → IAR and physical receipt:

1. Register or select the item in **Items** (Stock No. = `items.item_code`).
2. Record **Acquisitions** (custody receipt).
3. Export **Stock Card receipt (Appendix 58)** from the acquisition view, or open **Stock levels** for the full Appendix 58 ledger.

Configured path for that export: `Consumable/Stock Levels & Recording/Appendix 58 - SC.xls` (see `config/owwa_templates.php`).

## PR field: Stock / Property No.

For consumables, use the **Stock No.** from the item catalog (`items.item_code`). The Supply Division assigns this when the item is registered — not on the acquisition transaction.

## Audit

After adding or changing templates anywhere under `storage/app/templates/`:

```bash
php artisan app:audit-owwa-templates
php artisan owwa:analyze-templates --output=storage/app/templates/template-structure.txt
```

See [`docs/OWWA_EXPORT_MAPPING.md`](../../../../docs/OWWA_EXPORT_MAPPING.md) (section **Consumable procurement chain (offline)**) and [`docs/INVENTORY_NUMBERING.md`](../../../../docs/INVENTORY_NUMBERING.md).
