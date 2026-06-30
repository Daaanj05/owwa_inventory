# Measurement unit vs unit cost (OWWA)

OWWA forms use the word **unit** in two different ways. The application keeps one source of truth for each.

## Concepts

| OWWA / UI term | Meaning | Database field | Set when |
| -------------- | ------- | -------------- | -------- |
| **Measurement unit** (UOM) | How quantity is counted (piece, ream, box, unit) | `items.unit` | **Item registration** (required) |
| **Unit cost** | Money (PHP) for **one** measurement unit | `acquisitions.unit_cost`, `issuances.unit_cost` | **Acquisition**; issuance often copies latest acquisition |
| **Quantity** | Count in that UOM | `quantity` on acquisition / issuance | Transaction |

There is **no** `acquisitions.unit` or `issuances.unit` column. Exports and print views read UOM from the linked item.

## Where it appears in the UI

- **Items (setup):** editable **Measurement unit** (`items.unit`).
- **Acquisition / Issuance:** read-only **Measurement unit** preview after item select; editable **Unit cost (₱ per measurement unit)** and quantity.
- **Physical count lines:** **Measurement unit** synced from the item when an item is picked (`unit_of_measure`).

## OWWA template mapping (exports)

Handled in `OwwaTemplateExportService` and `OwwaItemReportService` (no separate acquisition UOM field):

| Template area | Label on form | Source |
| ------------- | ------------- | ------ |
| Stock Card header | Unit of Measurement | `items.unit` (e.g. cell **A10**) |
| RSMI line column | Unit | `item.unit` (column **E**) |
| RSMI line column | Unit Cost | `acquisitions.unit_cost` / `issuance.unit_cost` (column **G**) |
| RSMI line column | Quantity Issued / Receipt Qty | transaction `quantity` |

Printed RSMI/PAR views use **Unit** as the column title for UOM and **Unit Cost** for money—that matches the physical forms.

## RSMI “Serial No.” vs item serial number

- **Serial No.** on RSMI (header) = issuance control number (`issuances.reference_code`), not `items.serial_number`.
- **Item serial number** (manufacturer id) is PPE-only on item registration. See `docs/INVENTORY_NUMBERING.md`.

## Related configuration

Item codes and reference numbers: `docs/INVENTORY_NUMBERING.md`.  
Template cell notes: `storage/app/templates/OWWA_SYSTEM_ALIGNMENT.md`.
