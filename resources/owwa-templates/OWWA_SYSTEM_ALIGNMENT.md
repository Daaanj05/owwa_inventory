# OWWA Forms vs System Alignment

What we can infer from the forms and how it matches your current database and UI.

---

## 1. What the analyzer gives you

From the template structure we can see:

| From the forms | Use |
|----------------|-----|
| **Cell addresses** | Which cells to fill (e.g. A11, G7) for export |
| **Labels / headers** | What each field means (Entity Name, RIS No., Stock No., etc.) |
| **Layout** | Header vs table, which row is first data row, multi-sheet |
| **Required fields** | What OWWA expects (entity, fund cluster, date, item, qty, unit, etc.) |

So we can: map form fields → your DB columns, wire export (cell values), and spot missing fields or naming mismatches.

---

## 2. Issuance: Appendix 64 (RSMI) vs your system

| OWWA form field | Cell | Your system | Aligned? |
|-----------------|------|-------------|----------|
| Entity Name | A6, A7 | `Office.name` | ✅ |
| Serial No. | G6 | `Issuance.reference_code` | ✅ |
| Fund Cluster | A7 | Not stored | ⚠️ See below |
| Date | G7 | `Issuance.issuance_date` | ✅ |
| RIS No. | A11 | `Issuance.reference_code` | ✅ |
| Responsibility Center Code | B11 | `Office.code` or `Department.code` | ✅ (Office/Department have `code`) |
| Stock No. | C11 | `Item.item_code` | ✅ |
| Item | D11 | `Item.name` | ✅ |
| Unit | E11 | `Item.unit` | ✅ |
| Quantity Issued | F11 | `Issuance.quantity` | ✅ |
| Unit Cost | G11 | Not on Issuance | ⚠️ Only on Acquisition |
| Amount | H11 | Not on Issuance | ⚠️ Could be qty × unit cost |

**Summary (RSMI):** Almost fully aligned. G11/H11 (Unit Cost, Amount) are not on Issuance; you can leave them blank, or add them (e.g. from last acquisition or new columns).

---

## 3. Disposal: Appendix 65 (WMR) vs your system

| OWWA form field | Cell | Your system | Aligned? |
|-----------------|------|-------------|----------|
| Entity Name | A7 | `Office.name` | ✅ |
| Fund Cluster | G7 | Not stored | ⚠️ Same as above |
| Place of Storage | A8 | `Office.name` | ✅ |
| Date | G8 | `Disposal.disposal_date` | ✅ |
| Item (row 13) | D13 | `Item.name` + `Disposal.reason` | ✅ |
| Quantity | B13 | `Disposal.quantity` | ✅ |
| Unit | C13 | `Item.unit` | ✅ |
| Record of Sales (OR No., Date, Amount) | G–I | Not on Disposal | ⚠️ Optional for disposal |

**Summary (WMR):** Aligned for the main disposal line. Sales info (G–I) is optional; add only if you need to track it.

---

## 4. Ledger / Stock Card (Appendix 57 SLC, Appendix 58 SC)

These need:

- **Header:** Entity, Fund Cluster, Item, Item Code (Stock No.), Description, Re-order Point, Unit of Measurement  
- **Table:** Date, Reference, Receipt (Qty, Unit Cost, Total Cost), Issue (Qty, Office), Balance (Qty, etc.)

Your system has:

- Entity → `Office.name`
- Item, Item Code, Description, Reorder, Unit → `Item.*` (name, item_code, description, reorder_level, unit)
- Table rows → from `Acquisition` (receipt) and `Issuance` (issue); balance from your stock logic

So SLC/SC are alignable with your data; they just need multi-row export (one row per transaction) and possibly “Fund Cluster” and unit cost/totals where you have them (e.g. Acquisition.unit_cost).

---

## 5. Gaps and options

### A. Fund Cluster

- **OWWA:** Entity Name, Fund Cluster (often separate).
- **You:** Only office/organization (e.g. `Office.name`).
- **Options:**  
  - Use a single value (e.g. `Office.name` or `Office.code`) for both, or  
  - Add `fund_cluster` (or similar) to `offices` and use it for the form.

### B. Unit Cost / Amount on Issuance (RSMI)

- **OWWA:** Unit Cost (G11), Amount (H11).
- **You:** `Issuance` has no unit cost; `Acquisition` has `unit_cost`.
- **Options:**  
  - Leave G11/H11 blank (current export).  
  - Derive unit cost from last acquisition for that item/office and compute amount = qty × unit cost.  
  - Add optional `unit_cost` (and/or `amount`) to `issuances` if you want to store them.

### C. Responsibility Center

- **OWWA:** “Responsibility Center Code” (B11 on RSMI).
- **You:** `Office.code`, `Department.code`.
- **Options:** Use office or department code/name depending on policy (we currently use office/department name in the export; you can switch to code if needed).

### D. Disposal – Record of Sales (WMR)

- **OWWA:** Official Receipt No., Date, Amount (G–I).
- **You:** No disposal sales fields.
- **Options:** Leave blank, or add columns (e.g. `disposals.official_receipt_no`, `disposals.sale_date`, `disposals.sale_amount`) if you need to report sales.

---

## 6. Overall alignment

| Area | Verdict |
|------|--------|
| **Issuance (RSMI)** | Well aligned; only Unit Cost/Amount and Fund Cluster are optional extras. |
| **Disposal (WMR)** | Well aligned for the main disposal line; Fund Cluster and sales fields are optional. |
| **Items & offices** | Item (name, code, unit, reorder, description) and Office (name, code) match form needs. |
| **Ledger/Stock card (SLC/SC)** | Alignable; need multi-row export and possibly Fund Cluster / unit cost from acquisitions. |

So: the forms are largely aligned with your system. The main optional improvements are: **Fund Cluster** (office or new field), **Unit Cost/Amount** for issuance (from acquisition or new fields), and **multi-row** and **sales** fields if you want full SLC/SC and WMR compliance.
