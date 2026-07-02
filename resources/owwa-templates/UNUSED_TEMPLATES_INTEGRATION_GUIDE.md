# Unused OWWA Templates: Use and Integration Guide

This document explains **what each unused template is for** and **how to integrate it** into the inventory system.

---

## 1. Item-level cards (per item, per office)

These forms show **one item’s** transaction history (receipts, issues, transfers, disposals) and running balance. They are **not** one-per-transaction; they are **reports by item**.

### Appendix 57 – SLC (Supplies Ledger Card)

| What it is | Use in the system |
|------------|-------------------|
| **Supplies Ledger Card** – Ledger for a **consumable** item. Columns typically: Date, Reference, Receipt (acquisitions), Issue (issuances), Balance. One row per transaction. | **When:** User wants a ledger printout for a single consumable item (e.g. “Bond Paper A4” at a given office). **Data:** One **Item** (consumables) + one **Office**; all Acquisitions and Issuances for that item in that office, ordered by date; compute balance. |

**How to integrate**

1. **Where:** From **Items** list or **Item** view: add action **“Export Ledger (SLC)”** or put under a menu “Export OWWA form” → “Supplies Ledger Card”.
2. **Config:** Add to `config/owwa_templates.php` under a new key, e.g. `item_reports.consumables.slc` with file `Appendix 57 - SLC.xlsx` (or `.xls`).
3. **Export service:** In `OwwaTemplateExportService` (or a dedicated `OwwaItemReportExportService`), add a method that:
   - Loads the SLC template.
   - Fills header (item name, code, office, fund cluster).
   - Fills rows from `Acquisition` and `Issuance` for that item + office, with Date, Reference, Receipt qty, Issue qty, running Balance.
4. **Route:** e.g. `GET reports/owwa/item/{item}/ledger?office_id=…` → controller → service → stream Excel download.

---

### Appendix 58 – SC (Stock Card)

| What it is | Use in the system |
|------------|-------------------|
| **Stock Card** – Simpler card for a **consumable** item: Date, Reference, Receipt Qty, Issue (Qty, Office), Balance. | Same idea as SLC but different column layout. **When:** User wants a stock card for one consumable item. **Data:** Same as SLC (item + office; acquisitions + issuances; balance). |

**How to integrate**

- Same as SLC: action from **Item** (consumables) “Export Stock Card (SC)”.
- Config: `item_reports.consumables.sc` → `Appendix 58 - SC.xlsx`.
- Export: Map item + office + transaction rows to the template’s cells (analyze template with `php artisan owwa:analyze-templates` to get cell refs).

---

### Appendix 69 – PC (Property Card)

| What it is | Use in the system |
|------------|-------------------|
| **Property Card** – Per-**PPE** item: Receipt (acquisitions), Issue, Transfer, Disposal, Balance. One row per transaction. | **When:** User wants a property card for one **PPE** item. **Data:** One Item (PPE) + one Office; Acquisitions, Issuances, Transfers, Disposals for that item (and office where relevant); running balance. |

**How to integrate**

- From **Items** (filter or filter by category PPE): action “Export Property Card (PC)”.
- Config: `item_reports.ppe.pc` → `Appendix 69 - PC.xlsx`.
- Export: Build rows from acquisitions, issuances, transfers, disposals; fill template (need cell mapping from template analysis).

---

### Annex A.1 – Semi-expendable Property Card

| What it is | Use in the system |
|------------|-------------------|
| **Semi-Expendable Property Card** – Same idea as Property Card but for **semi-expendable** items: Receipt, Issue/Transfer/Disposal, Balance. | **When:** User wants a property card for one **semi-expendable** item. **Data:** Same as PC but for semi-expendable category. |

**How to integrate**

- From **Items** (semi-expendable): “Export Semi-expendable Property Card”.
- Config: `item_reports.semi_expendable.property_card` → `Annex A.1 - Semi-expendable Property Card.xlsx`.
- Export: Same pattern as PC; map transactions to template cells.

---

### Annex A.4 – Registry of Semi-Expendable Property Issued

| What it is | Use in the system |
|------------|-------------------|
| **Registry of Semi-Expendable Property Issued** – Registry of issuance/return/re-issue/disposal activity. Columns: Date, Reference, Item Description, Issued/Returned/Re-issued/Disposed, Balance. Can be **per item** or **per category/office**. | **When:** User wants a registry of what was issued/returned/disposed for semi-expendable (one item or a group). **Data:** Issuances (and optionally returns/disposals) for selected item(s) or category; running balance. |

**How to integrate**

- From **Items** (semi-expendable) or a new **Reports** page: “Export Registry Issued (Annex A.4)”.
- Config: `item_reports.semi_expendable.registry_issued` → `Annex A.4 - Registry of Semi-Expendable Property Issued.xls`.
- Export: Query issuances (and related disposals/transfers if the form has those columns); fill template. Can add filters: one item, one office, date range.

---

## 2. Physical count reports (periodic, as-at date)

These are **periodic reports** for stock-taking: compare “balance per card” (system) vs “physical count” (entered or to be entered). They are **not** tied to a single issuance/transfer/disposal.

### Appendix 66 – RPCI (Report on the Physical Count of Inventories)

| What it is | Use in the system |
|------------|-------------------|
| **Report on the Physical Count of Inventories** – Physical count of **consumable** (or general) inventories as at a date. Shows balance per records vs quantity on hand; shortage/overage. | **When:** Supply custodian does periodic physical count of consumables. **Data:** List of items (consumables) with current system balance; columns for “Quantity per count” (user input or from a count table) and variance. |

**How to integrate**

1. **Where:** New Filament page or menu item, e.g. **Reports → Physical count (RPCI)**, or under Inventory.
2. **Flow:** User selects **date** and optionally **office**; system lists consumable items with current balance; user can enter “counted quantity” (or upload/list); system computes shortage/overage.
3. **Export:** Add `reports.physical_count.consumables` (or `rpli`) in config → `Appendix 66 - RPCI.xlsx`. Export service fills template with item list, balance, count, variance.
4. **Data:** Either a one-off export from current stock levels + manual count input, or a **physical_count** table (count_date, item_id, office_id, system_balance, counted_qty) that you fill from a form, then export to RPCI.

---

### Appendix 73 – RPCPPE (Report on the Physical Count of PPE)

| What it is | Use in the system |
|------------|-------------------|
| **Report on the Physical Count of PPE** – Same as RPCI but for **PPE**: balance per card vs physical count, shortage/overage. | **When:** Physical count of PPE. **Data:** PPE items; system balance; counted quantity; variance. |

**How to integrate**

- Same pattern as RPCI: Reports → “Physical count (PPE)” or “RPCPPE”.
- Config: `reports.physical_count.ppe` → `Appendix 73 - RPCPPE.xlsx`.
- Export: Same idea; map PPE items + balances + count to template.

---

### Annex A.8 – RPCSP (Report on the Physical Count of Semi-Expendable Property)

| What it is | Use in the system |
|------------|-------------------|
| **Report on the Physical Count of Semi-Expendable Property** – Physical count of **semi-expendable** property (by type: Office Equipment, F&F, etc.). | **When:** Physical count of semi-expendable. **Data:** Semi-expendable items (optionally by type); system balance; counted quantity; variance. |

**How to integrate**

- Reports → “Physical count (Semi-expendable)” or “RPCSP”.
- Config: `reports.physical_count.semi_expendable` → `Annex A.8 - RPCSP (REPORT).xlsx`.
- Export: Same pattern; fill template from item list + balances + count.

---

## 3. Summary: where each form fits

| Template | Full name | Where in system | Integration idea |
|----------|-----------|-----------------|------------------|
| **Appendix 57 - SLC** | Supplies Ledger Card | Item (consumables) | Action “Export Ledger”; config + export method; route item/ledger. |
| **Appendix 58 - SC** | Stock Card | Item (consumables) | Action “Export Stock Card”; config + export; route item/stock-card. |
| **Appendix 66 - RPCI** | Report on the Physical Count of Inventories | Reports / Physical count | New page or report; list items + balance; optional count input; export to RPCI. |
| **Appendix 69 - PC** | Property Card | Item (PPE) | Action “Export Property Card”; config + export; route item/property-card. |
| **Appendix 73 - RPCPPE** | Report on the Physical Count of PPE | Reports / Physical count | Same as RPCI for PPE; export to RPCPPE. |
| **Annex A.8 - RPCSP** | Report on the Physical Count of Semi-Expendable Property | Reports / Physical count | Same for semi-expendable; export to RPCSP. |
| **Annex A.1** | Semi-expendable Property Card | Item (semi-expendable) | Action “Export Property Card”; config + export. |
| **Annex A.4** | Registry of Semi-Expendable Property Issued | Item or Reports (semi-expendable) | Action or report; issuances (+ returns/disposals); export to Annex A.4. |

---

## 4. Implementation order (suggested)

1. **Item-level exports (no new pages):** SLC, SC, PC, Annex A.1 – add config, cell mapping in export service, and an “Export OWWA form” (or “Export ledger/card”) action on the **Item** resource, filtered by item category so only the right form is offered.
2. **Registry:** Annex A.4 – same as above from Item (semi-expendable) or a simple report screen.
3. **Physical count:** RPCI, RPCPPE, RPCSP – add a **Physical count** report page (select date/office, list items with balance, optional count input), then “Export to RPCI/RPCPPE/RPCSP” that fills the template. Optionally store count data in a table for audit.

All of these re-use the same pattern you already have: **template in `storage/app/templates/{category}/`**, **config entry**, **export method that builds cell values from Eloquent models**, and **route + controller or Filament action** to trigger the download.
