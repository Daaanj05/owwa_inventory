# Plan: Base the system on OWWA forms

**Goal:** The supply custodian fills the same fields in the system as on the Excel form; the system has a print view and export that match the form exactly.

**Workflow:** Fill details in system (form-like UI) → Print view (form-like) → Export/print file (pre-filled Excel or PDF).

---

## 1. Is it possible?

**Yes.** You can:

- Define **database tables/columns** that match each form’s fields and tables.
- Build **Filament forms** whose sections and labels match the form (Entity Name, Fund Cluster, RIS No., Responsibility Center Code, etc.).
- Add a **print view** (Blade or Livewire) that lays out the same sections and table as the form.
- **Export** by filling the Excel template from the same DB (already partially done).

So: one source of truth (DB), form-like input, form-like print view, and form-faithful export.

---

## 2. Form-by-form structure (what the system must mirror)

### 2.1 Appendix 64 – RSMI (Report of Supplies and Materials Issued)

**Header (one per report):**

| Form label         | Suggested DB / source        | Notes                    |
|--------------------|-----------------------------|--------------------------|
| Entity Name        | Office name                 | From `offices` or report  |
| Serial No.         | Report serial / reference    | e.g. RIS document number |
| Fund Cluster       | New or from office          | Add if not in DB         |
| Date               | Report date                 | Single date for the form |

**Detail table (one row per issuance line):**

| Column                  | Form header           | Suggested DB / source              |
|--------------------------|-----------------------|------------------------------------|
| A                        | RIS No.               | Issuance reference (e.g. per line) |
| B                        | Responsibility Center Code | Office/Department code         |
| C                        | Stock No.             | Item code                          |
| D                        | Item                  | Item name                          |
| E                        | Unit                  | Item unit                          |
| F                        | Quantity Issued       | Quantity                           |
| G                        | Unit Cost             | From acquisition or new field      |
| H                        | Amount                | Qty × Unit Cost (or new field)     |

**Recapitulation table (one row per item – totals):**

| Column        | Form header     | Source                          |
|---------------|-----------------|----------------------------------|
| B             | Stock No.       | Item code (grouped)              |
| C             | Quantity        | Sum of quantity per item         |
| F             | Unit Cost       | From item/issuance               |
| G             | Total Cost      | Sum of amount per item           |
| H             | UACS Object Code| New field if required            |

**Signatures (later):** Posted by, Custodian, Accounting Staff, Date – can be config or user.

So for RSMI the system needs:

- **Header:** entity, serial no., fund cluster, date (stored per “RSMI report” or derived from first line).
- **Detail:** table with columns exactly as above (one row per issuance line).
- **Recapitulation:** same columns, one row per item (aggregated from detail).

---

### 2.2 Appendix 65 – WMR (Waste Materials Report / Disposal)

**Header:**

| Form label       | Suggested DB / source |
|------------------|------------------------|
| Entity Name      | Office name            |
| Fund Cluster     | From office or new     |
| Place of Storage | Office name            |
| Date             | Disposal date          |

**Items table (up to 10 rows):**

| Column | Form header   | Suggested DB / source   |
|--------|---------------|--------------------------|
| A      | No. (1–10)    | Row index                |
| B      | Quantity      | Disposal quantity        |
| C      | Unit          | Item unit                |
| D      | Description   | Item name + reason       |
| G      | Official Receipt No. | New if needed   |
| H      | Date (sales)  | New if needed            |
| I      | Amount        | New if needed            |

**Certificate of Inspection (fixed text + signatures):** Can be static in print/export or future fields.

So for WMR the system needs:

- **Header:** entity, fund cluster, place of storage, date.
- **Items:** table with columns as above (one row per disposal line; optionally sales columns).

---

### 2.3 Appendix 57 – SLC (Supplies Ledger Card)

**Header:** Entity Name, Fund Cluster, Item, Item Code, Description, Re-order Point, Unit of Measurement.

**Ledger table (many rows):**

| Columns      | Content                                      |
|-------------|-----------------------------------------------|
| Date        | Transaction date                              |
| Reference   | Reference code                                |
| Receipt     | Qty, Unit Cost, Total Cost                    |
| Issue       | Qty, Unit Cost, Total Cost                    |
| Balance     | Qty, Unit Cost, Total Cost                    |
| No. of Days to Consume | Optional                  |

So for SLC the system needs: same header fields (from item + office), then a table built from acquisitions (receipt) and issuances (issue), plus balance and optional days to consume.

---

### 2.4 Appendix 58 – SC (Stock Card)

**Header:** Entity Name, Fund Cluster, Item, Stock No., Description, Re-order Point, Unit of Measurement.

**Stock table:** Date, Reference, Receipt Qty, Issue (Qty, Office), Balance Qty, No. of Days to Consume.

So for SC: same idea as SLC but with Issue Qty + Office and simpler cost columns.

---

## 3. Database alignment (to follow the forms)

### 3.1 Keep and extend existing

- **Offices:** Add `fund_cluster` (nullable) if you want it on forms.
- **Items:** Already have name, item_code, unit, reorder_level, description – enough for SLC/SC/RSMI/WMR headers and columns.
- **Issuances:** Add optional `unit_cost`, `amount` (or compute from acquisition) for RSMI columns G and H.
- **Disposals:** Add optional `official_receipt_no`, `sale_date`, `sale_amount` if you need Record of Sales on WMR.

### 3.2 “Report” or “document” layer (optional but useful)

For RSMI you often have **one report (document)** with **many issuance lines**. Options:

- **A) Keep current model:** One Issuance = one line. “Report” = one issuance or a batch; export builds one form from one or many issuances. No new table.
- **B) Add RSMI report table:** e.g. `rsmi_reports` (id, entity/office_id, serial_no, fund_cluster, report_date, ...) and `rsmi_report_lines` (report_id, issuance_id, order, ...). Then the form header and detail table map 1:1 to DB. Same idea possible for WMR (disposal report with multiple lines).

Recommendation: Start with **A** (current model + optional fields); add **B** if you need to store “this printout is RSMI report #X with these N lines” as a single document.

### 3.3 Summary of DB changes to “follow the forms”

| Change | Purpose |
|--------|--------|
| `offices.fund_cluster` (nullable) | Entity/Fund Cluster on all forms |
| `issuances.unit_cost`, `issuances.amount` (nullable) | RSMI Unit Cost & Amount columns |
| `disposals.official_receipt_no`, `sale_date`, `sale_amount` (nullable) | WMR Record of Sales (optional) |
| Optional: `rsmi_reports` + lines | If you want one saved “RSMI document” per print/export |
| Optional: UACS / object code on item or issuance | Recapitulation “UACS Object Code” |

---

## 4. UI alignment (forms in the system = form layout)

### 4.1 Principle

- **Section titles** in the app = section titles on the form (e.g. “Entity Name & Fund Cluster”, “Detail of Items Issued”, “Recapitulation”).
- **Field labels** = form labels (e.g. “Serial No.”, “Responsibility Center Code”, “Stock No.”, “Quantity Issued”, “Unit Cost”, “Amount”).
- **Tables** in the form = **Repeater** or **RelationManager** with columns matching the form table (same column order and names).

### 4.2 RSMI (Issuance) – form-like screen

- **Section 1 – Header**
  - Entity Name (from office or dropdown)
  - Serial No. (text or auto)
  - Fund Cluster (from office or text)
  - Date (report date)
- **Section 2 – Detail (table)**
  - Repeater or relation: RIS No. | Responsibility Center Code | Stock No. | Item | Unit | Quantity Issued | Unit Cost | Amount.
  - One row = one issuance line (or one “line” in a new RSMI-line table).
- **Section 3 – Recapitulation (table, read-only or editable)**
  - Stock No. | Quantity | Unit Cost | Total Cost | UACS Object Code.
  - Filled from detail (group by item) or manually.

Then: **Print view** = same sections and tables, laid out like the Excel form. **Export** = fill template from this same data.

### 4.3 WMR (Disposal) – form-like screen

- **Section 1 – Header**
  - Entity Name, Fund Cluster, Place of Storage, Date.
- **Section 2 – Items for disposal (table)**
  - No. | Quantity | Unit | Description | (optional) Official Receipt No. | Date | Amount.
  - One row per disposal line (from `disposals` or a WMR “document” with lines).

Print view and export mirror this.

### 4.4 SLC / SC (ledger and stock card)

- **Header:** Same as form (entity, fund cluster, item, item code, description, reorder point, unit).
- **Table:** Built from transactions (acquisitions + issuances); columns = form columns. Can be read-only “view” of data with same column order and labels.

---

## 5. Workflow (how it fits together)

1. **Supply custodian** opens e.g. “Create RSMI” or “Create Issuance (RSMI)”.
2. **Fills the form in the system** with the same fields as the Excel form (header + detail table; recapitulation can be auto from detail).
3. **Saves** → data stored in DB (issuances + optional report header).
4. **Print view** → Opens a page that looks like RSMI (same sections and table); can print to PDF from browser.
5. **Export** → “Export OWWA form” fills the Excel template (Appendix 64 - RSMI.xls) from the same DB so the file matches what they see on screen and in print.

Same idea for WMR (disposal): form-like create/edit → form-like print view → export to WMR template.

---

## 6. Suggested implementation order

| Phase | What | Outcome |
|-------|------|--------|
| **1** | DB: Add `fund_cluster` (offices), optional `unit_cost`/`amount` (issuances), optional sales fields (disposals). | DB can store all form fields. |
| **2** | RSMI UI: Restructure Issuance create/edit to match RSMI layout (header section + detail table with form column labels). Optionally one “RSMI report” that groups several issuance lines. | Input screen = form layout. |
| **3** | RSMI print view: New Blade/Livewire view that renders header + detail table + recapitulation in the same order as the form. | Print view = form layout. |
| **4** | RSMI export: Already partially done; ensure header, detail, and recapitulation cells are filled from DB. | Export = same data as form. |
| **5** | WMR: Same pattern for disposal (form-like UI, print view, export). | Disposal flow follows form. |
| **6** | SLC/SC: If needed, add “Ledger” / “Stock card” views (and optional export) that use the same column layout. | Ledger/stock card follow form. |

---

## 7. Summary

- **Yes, you can base the system on the forms:** same fields, same table columns, same order in DB, UI, print view, and export.
- **DB:** Add a few optional columns (fund_cluster, unit_cost/amount, disposal sales) and optionally a report/document layer for RSMI/WMR.
- **UI:** Form-like sections and tables (same labels as the form); Repeater or relation for detail/recapitulation.
- **Print view:** Dedicated view that mirrors the form layout.
- **Export:** Keep filling the Excel template from the same DB so the file matches the form and the system.

If you tell me which form you want to do first (RSMI or WMR), the next step is to draft the exact DB migration and the Filament form section/table layout for that one.
