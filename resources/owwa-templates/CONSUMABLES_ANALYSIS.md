# Consumables OWWA forms – analysis

From `template-structure.txt` (2026-03-07). Four files in `consumables/`:

---

## 1. Appendix 57 - SLC.xls — **Supplies Ledger Card**

| Area | Cells | Purpose |
|------|--------|--------|
| **Header** | A6 | Entity Name |
| | J6 | Fund Cluster |
| | A8 | Item |
| | I8 | Item Code |
| | A9 | Description |
| | I9 | Re-order Point |
| | A10 | Unit of Measurement |
| **Table** | A11+ | Date, Reference, Receipt (Qty, Unit Cost, Total Cost), Issue (Qty, Unit Cost, Total Cost), Balance (Qty, Unit Cost, Total Cost), No. of Days to Consume |

**Use:** Ledger view per item; multiple rows for each transaction. Filling from the app would mean: header from item/office, then one row per issuance/acquisition.

---

## 2. Appendix 58 - SC.xls — **Stock Card**

| Area | Cells | Purpose |
|------|--------|--------|
| **Header** | A6 | Entity Name |
| | F6 | Fund Cluster |
| | A8 | Item |
| | F8 | Stock No. |
| | A9 | Description |
| | F9 | Re-order Point |
| | A10 | Unit of Measurement |
| **Table** | A11–F12 | Date, Reference, Receipt Qty, Issue (Qty, Office), Balance Qty |

**Use:** Stock card per item; rows = transactions. Map: Entity/Office, Item, Item Code (Stock No.), then rows with date, reference, receipt/issue qty, office (for issue), balance.

---

## 3. Appendix 64 - RSMI.xls — **Report of Supplies and Materials Issued**

| Area | Cells | Purpose |
|------|--------|--------|
| **Header** | A6 | Entity Name |
| | G6 | Serial No. |
| | A7 | Fund Cluster |
| | G7 | Date |
| **Table (one row per issuance)** | A10–H10 | RIS No., Responsibility Center Code, Stock No., Item, Unit, Quantity Issued, Unit Cost, Amount |
| | **Row 11** | First data row: A11 RIS No., B11 Responsibility Center, C11 Stock No., D11 Item, E11 Unit, F11 Qty Issued, G11 Unit Cost, H11 Amount |

**Use:** **Issuance report.** One row per issuance line. Map: Entity (office?), Serial No. (report ref), Fund Cluster, Date; then for each issuance: A11=reference_code, B11=office/department code or name, C11=item_code, D11=item name, E11=unit, F11=quantity, G11=unit cost (if you have it), H11=amount.

**Recapitulation (rows 33–34 and below):** A summary block for accounting. Row 33 = "Recapitulation:" label; row 34 = sub-headers: Stock No. (B34), Quantity (C34), Unit Cost (F34), Total Cost (G34), UACS Object Code (H34). The rows under that (e.g. 35+) are one line per **item** (Stock No.) with **totals**: total quantity issued, unit cost, total cost, and UACS object code. So if the detail section has many lines (e.g. 10 issuances of the same item), the recapitulation collapses them by Stock No. and shows one summary row per item. For a single-issuance export you can fill one recapitulation row (e.g. row 35) with that item’s data; for a multi-issuance report you’d aggregate by item and fill multiple recap rows.

**Suggested mapping (single issuance):**
- A6 = office name (entity)
- G6 = report serial or reference
- A7 = fund cluster (from office/config?)
- G7 = issuance_date
- A11 = reference_code (RIS No.)
- B11 = office name or responsibility center
- C11 = item.item_code (Stock No.)
- D11 = item.name
- E11 = item.unit
- F11 = quantity
- G11 = unit cost (if in DB)
- H11 = amount (if in DB)

---

## 4. Appendix 65 - WMR.xls — **Waste Materials Report (Disposal)**

| Area | Cells | Purpose |
|------|--------|--------|
| **Header** | A7 | Entity Name |
| | G7 | Fund Cluster |
| | A8 | Place of Storage |
| | G8 | Date |
| **Table** | A10–I12 | Item, Quantity, Unit, Description, Record of Sales (Official Receipt No., Date, Amount) |
| | **Rows 13–22** | A13–A22 = row no. 1–10; B13–B22 = qty, C13 = unit, D13 = description (one line per disposal item) |

**Use:** **Disposal report.** Map: A7=entity (office), G7=fund cluster, A8=place of storage (office name?), G8=disposal_date; then one row per disposal line: D13=item name, B13=quantity, C13=unit, D13=description/reason. (G–I for sales if applicable.)

**Suggested mapping (single disposal):**
- A7 = office name
- G7 = fund cluster
- A8 = office name (place of storage)
- G8 = disposal_date
- A13 = 1 (first item)
- B13 = quantity
- C13 = item.unit
- D13 = item name or reason/description

---

## Summary

| File | Best for | Main data row(s) |
|------|----------|-------------------|
| **Appendix 57 - SLC** | Ledger (multi-row) | Header in 6–10; table from 11 |
| **Appendix 58 - SC** | Stock card (multi-row) | Header in 6–10; table from 11 |
| **Appendix 64 - RSMI** | **Issuance** | Header 6–7; first issuance row = **row 11** |
| **Appendix 65 - WMR** | **Disposal** | Header 7–8; first disposal row = **row 13** (B13 qty, C13 unit, D13 item/description) |

Next step: wire these cell references into `OwwaTemplateExportService` so that when a user exports using “Appendix 64 - RSMI” (issuance) or “Appendix 65 - WMR” (disposal), the correct cells are filled from the issuance/disposal and item/office data.
