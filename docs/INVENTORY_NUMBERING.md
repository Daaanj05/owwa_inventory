# Inventory numbering (OWWA-aligned)

This document explains which identifiers are generated automatically, how they map to OWWA form labels, and what Supply Custodians vs System Admins control.

## Quick answer: automatic or admin-editable?

| Who | What they do |
| --- | --- |
| **Supply Custodian** | Creates transactions and items. **Does not type** control numbers, stock numbers, or property numbers (except optional manual stock override by System Admin). |
| **System Admin** | Sets **next number**, **reset period** (yearly vs monthly), and labels in **Setup → Reference series**. Does **not** need to change “code letters” for transactions—the system always prints **YYYY-MM-####** for those. |
| **System (on save)** | Assigns the next number from the correct series via observers. |

**Recommendation:** Keep generation **automatic**. Let admin adjust only **starting sequence** and **yearly/monthly reset** if the office must align with an existing logbook—not the pattern for transaction control numbers.

---

## Stock visibility (catalog vs on-hand)

| Screen | What appears |
| ------ | -------------- |
| **Items (setup)** | All registered catalog items (master list with stock number). |
| **Stock levels** | Only item×office pairs that have **inventory activity** (acquisition, issuance, transfer, or disposal at that office). |
| **COA stock level export / AI context** | Same rule as Stock levels (via `InventoryStockService::getStockLevelsList()`). |

A newly registered item does **not** appear on Stock levels until the first movement at that office (usually an **acquisition** or **transfer in**). Items issued down to **zero** remain visible (depleted position). Catalog-only rows do not inflate **low stock** counts or analytics.

`getStock()` for forms (requisitions, physical count) still returns `0` for items without movement—that is correct for validation.

### Stock vs accountable property (semi-expendable and PPE)

| Concept | What it measures | After issuance to a unit/department |
| ------- | ---------------- | ----------------------------------- |
| **On-hand stock** (Stock levels) | Quantity available to issue from an office | **Decreases** — same as consumables |
| **Accountable property** (PAR, ICS, property registers, `inventory_units`) | Tagged units with property numbers | **Remains on record** — custody transfer, not consumption |

Consumables are **consumed** when issued (no per-unit property trail). Semi-expendable and PPE stay on **issuance registers** and **inventory unit** records after issue; only **available quantity** drops on Stock levels.

See also [Process workflow — Stock vs property](PROCESS_WORKFLOW.md#stock-vs-accountable-property).

---

## OWWA form label ↔ one database field

Each transaction has **one** `reference_code`. The **same value** is shown on exports under different labels depending on the template.

**Admin UI:** Filament lists and view screens use these OWWA labels (via `App\Support\OwwaReferenceLabels`) instead of a generic “Reference number”. Issuance views also show linked **RIS No.** when the issue came from a requisition; requisition views list related **Serial No.** (or PAR/ICS) from fulfilled issuances.

| When you create… | Series in Reference series | OWWA form (examples) | Label on printed/exported form | DB field |
| ---------------- | -------------------------- | -------------------- | ------------------------------ | -------- |
| Requisition | `requisition` | Appendix 63 – RIS | RIS / requisition slip number | `requisitions.reference_code` |
| Issuance | `issuance` | Appendix 64 RSMI | **Serial No.** (header) | `issuances.reference_code` |
| Issuance (linked to requisition) | `issuance` + `requisition` | Appendix 64 RSMI | **RIS No.** (table column A) = requisition’s code if linked, else issuance code | `requisitions.reference_code` / `issuances.reference_code` |
| Issuance (PPE) | `issuance` | Appendix 71 – PAR | PAR No. | `issuances.reference_code` |
| Issuance (semi) | `issuance` | Appendix 59 – ICS | ICS No. | `issuances.reference_code` |
| Transfer (PPE/semi) | `transfer` | Appendix 76 – PTR | PTR No. | `transfers.reference_code` |
| Disposal | `disposal` | WMR / RLSDDP / IIRUP | Report reference / RLSDDP No. | `disposals.reference_code` |
| Acquisition | `acquisition` | Stock Card / SC | **Reference** (ledger column) | `acquisitions.reference_code` |

**Not duplicates in the database:** `requisition` and `issuance` are **two separate counters**. A requisition might be `2026-01-0005` and a later issuance `2026-01-0012`. On RSMI, the table “RIS No.” often shows the **requisition** number when `requisition_id` is set; the header “Serial No.” uses the **issuance** number.

**Looks like duplication in admin UI:** Both rows may show prefix `RIS` in Reference series. That prefix is **not** printed on transaction numbers (see below).

---

## Transaction format: what the system actually generates

For `requisition`, `issuance`, `transfer`, `disposal`, and `acquisition`, the app **normalizes** the saved value to:

```text
YYYY-MM-####
```

Examples: `2026-01-0001`, `2026-03-0042`.

| Middle segment `MM` | When |
| --------------------- | ---- |
| `01` | Reset period = **Every year** (default for most series) |
| `01`–`12` | Reset period = **Every month** (use for RSMI-style monthly serial if your office requires it on the **issuance** series) |

The **Code letters** and **Format (advanced)** fields on transaction rows are mainly for documentation; changing prefix from `RIS` to `PTR` does **not** change the issued number—it will still be `YYYY-MM-####`. Exports apply the correct form label (PAR No., PTR No., etc.) in the Excel template.

Legacy values like `RIS-2026-0001` can be converted with:

```bash
php artisan app:backfill-reference-codes --apply
```

---

## Stock numbers and property numbers (different rules)

These **do** use code letters and patterns from Reference series (not forced to YYYY-MM-####):

| Category | OWWA label | Field | Series type | Example |
| -------- | ---------- | ----- | ----------- | ------- |
| Consumables | Stock No. | `items.item_code` | `item_code_consumables` | `CON-2026-0001` |
| PPE | Stock / catalog id | `items.item_code` | `item_code_ppe` | `PPE-2026-0001` |
| Semi-expendable | Stock / catalog id | `items.item_code` | `item_code_semi` | `SE-2026-0001` |
| PPE | Property No. (PAR) | `issuances.property_number` | `property_number_ppe` | `2026-0001` |
| Semi-expendable | Inventory item no. (ICS) | `issuances.property_number` | Bucket counter (`property_number_buckets`) | `SPLV-2024-ICT-106-01-001` |

### Semi-expendable value tiers and composite property numbers (COA Circular 2022-004)

Semi-expendable items below the **₱50,000** capitalization threshold are classified by **unit cost**:

| Unit cost (₱) | Value category | `items.value_type` |
| ------------- | -------------- | ------------------ |
| ≤ 5,000 | **SPLV** (low-valued) | `low` |
| > 5,000 and < 50,000 | **SPHV** (high-valued) | `high` |
| ≥ 50,000 | Not semi — use **PPE** | — |

**Value category is auto-derived** from acquisition/issuance unit cost (not entered manually). **Equipment/supply type** (`items.property_class`, e.g. ICT) is still chosen on the item and appears as its own segment in the property number.

**Inventory item / property number format** (assigned on issuance, no office code segment):

```text
{value_category}-{acq_year}-{supply_type_code}-{uacs_prefix}-{custodian_code}-{seq:3}
```

Example: `SPLV-2024-ICT-106-01-001`

| Segment | Source |
| ------- | ------ |
| SPLV / SPHV | Unit cost tier |
| Year | Latest acquisition year for item×office |
| ICT, OE, … | `items.property_class` → config `semi_supply_type_codes` |
| 106, … | UACS prefix → config `semi_uacs_prefixes` |
| 01 | `departments.code` on issuance (required for semi) |
| 001 | Per-bucket sequence in `property_number_buckets` |

Catalog **stock number** (`items.item_code`, e.g. `SE-2026-0001`) remains separate from the ICS inventory item number above.

---

## PR vs Stock Card vs Acquisition reference

These identifiers are often confused on consumable procurement forms (PR/PO/IAR) versus in-app custody exports.

| Label on form | Meaning | Database field | Used on |
| ------------- | ------- | -------------- | ------- |
| **Stock No.** | Catalog number assigned when Supply registers the item | `items.item_code` | PR lines, Stock Card header (`F8`), item setup |
| PR No. | Purchase request control number | `acquisition_paperwork.pr_number` | Appendix 60 export; series `acquisition_paperwork_pr`; assigned on **Mark PR approved** |
| PO No. | Purchase order control number | `acquisition_paperwork.po_number` | Appendix 61 export; series `acquisition_paperwork_po`; assigned on **Mark PO approved** |
| IAR No. | Inspection & acceptance control number | `acquisition_paperwork.iar_number` | Appendix 62 export; series `acquisition_paperwork_iar`; assigned on **Mark IAR approved** |
| Case reference (app only) | Internal tracking ID for the acquisition case | `acquisition_paperwork.reference_code` (e.g. `AP-…`) | Unified Acquisitions list — **not** printed on OWWA PR/PO/IAR forms |
| **Reference** (Stock Card ledger) | Custody receipt control number for one acquisition line | `acquisitions.reference_code` | Stock Card ledger column B; **not** PR No. |
| **Property No.** / **Inventory item no.** | Tagged asset identifier | `issuances.property_number` | PPE PAR / semi ICS — assigned at **issuance**, not on consumable PR or Acquisition |

**PR instruction — “Stock/Property No. … as provided by the Supply and/or Property Division”:** for **consumables**, copy **`items.item_code`** from the item master. The item must exist in **Items** before PR or Acquisition. The system does not generate a new stock number on the acquisition transaction.

**Property numbers** on PR apply only when requesting PPE/semi property; at request time use the catalog **Stock No.** (`item_code`). The formal property or inventory item number is assigned when stock is **issued** (Accept & issue), not when goods are received in **Acquisitions**.

Acquisition paperwork document numbers (PR No., PO No., IAR No.) are stored on **`acquisition_paperwork`** and assigned when the Supply Custodian **marks each phase approved** after offline sign-off (reference series). Custody **Reference** (`acquisitions.reference_code`) is assigned when **Record custody receipt** runs. There is no OWWA **Acquisition No.** field on instruction PDFs. See **Acquisition paperwork workflow** in [`docs/OWWA_EXPORT_MAPPING.md`](OWWA_EXPORT_MAPPING.md) and [`docs/PR_PO_IAR_FIELD_MAP.md`](PR_PO_IAR_FIELD_MAP.md).

---

## 8. Acquisition case vs custody receipt numbering

| Identifier | When assigned | Series / format | On OWWA export? |
| ---------- | ------------- | ----------------- | --------------- |
| Case reference | New acquisition case | `acquisition_paperwork.reference_code` (`AP-…`) | No — app list only |
| PR No. | Mark PR approved | `acquisition_paperwork_pr` (`YYYY-MM-####`) | Appendix 60 (`C7`/`D7`) |
| PO No. | Mark PO approved | `acquisition_paperwork_po` | Appendix 61 |
| IAR No. | Mark IAR approved | `acquisition_paperwork_iar` | Appendix 62 |
| Stock Card Reference | Record custody receipt | `acquisition` (`YYYY-MM-####`) | Appendix 58 column B |
| Stock No. | Item registration | `items.item_code` | PR lines, Stock Card header |

Audited instruction PDFs under `storage/app/templates/` confirm PR/PO/IAR require **PR No.**, **PO No.**, and **IAR No.** only — not a separate acquisition number. Run `php artisan app:audit-owwa-templates` to re-check mappings.

---

## Serial numbers (manual, PPE only on item form)

Per OWWA practice, **manufacturer serial numbers** identify the physical PPE unit, not the transaction control number. The item registration form shows this field **only for PPE**.

- **PPE:** `items.serial_number` is required when `INVENTORY_REQUIRE_SERIAL_PPE=true` (default).
- **Consumables / semi:** no serial field at registration; use stock number (`item_code`) only.

Do not confuse **Serial No.** on RSMI (header) with **item serial number**—RSMI header Serial No. is the **issuance control number** (`issuances.reference_code`).

---

## Known gaps vs ideal OWWA setup

| Topic | Current system | Ideal per template instructions |
| ----- | -------------- | -------------------------------- |
| PPE vs semi vs consumable issuance | One `issuance` series for all | Separate series (PAR / ICS / RSMI) with separate counters—optional future improvement |
| RSMI monthly serial | Issuance series defaults to **yearly** (`01` month segment) | Set issuance series reset to **Every month** if auditors require monthly middle segment |
| Disposal prefix `DSP` in admin | Label only; export uses `reference_code` as RLSDDP/WMR reference | Could rename label to “Disposal (WMR/RLSDDP)” for clarity |
| Acquisition prefix `ACQ` | Label only; Stock Card shows as “Reference” | Acceptable; not a separate OWWA “ACQ” form number |

---

## Calendar-year reporting

Reporting uses **transaction dates** or **calendar year** windows. Setup master data (offices, departments, items) is not duplicated per year.

---

## Measurement unit vs unit cost

UOM (piece, ream, box) is stored only on **`items.unit`**. Acquisition and issuance store **quantity** and **unit cost (₱ per measurement unit)**. OWWA exports map UOM from the item (Stock Card A10, RSMI column E) and unit cost from the transaction (RSMI column G). See **`docs/INVENTORY_UNITS.md`** for full mapping and UI behavior.

---

## Configuration

See `config/inventory.php` for auto-generation flags and series type mapping per category.

Further mapping notes: `docs/OWWA_EXPORT_MAPPING.md`, `storage/app/templates/PLAN_REFERENCE_CODES_RIS_AND_RELATED.md` and `storage/app/templates/OWWA_FORMS_REFERENCE.md`.
