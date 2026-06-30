# PR / PO / IAR field map

Derived from local OWWA templates under `storage/app/templates/` via `php artisan owwa:analyze-templates`. Layout is **identical across consumables, PPE, and semi-expendable**; only template paths differ per category.

## Template paths

| Category | PR | PO | IAR |
|----------|----|----|-----|
| Consumables | `Consumable/Acquisitions/Appendix 60 - PR.xls` | `Consumable/Acquisitions/Appendix 61 - PO.xls` | `Consumable/Acquisitions/Appendix 62- IAR.xls` |
| PPE | `ppe/Accquisition/Appendix 60 - PR.xls` | `ppe/Accquisition/Appendix 61 - PO.xls` | `ppe/Accquisition/Appendix 62- IAR.xls` |
| Semi-expendable | `Semi-Expendable/Acquisition/Appendix 60 - PR.xls` | `Semi-Expendable/Acquisition/Appendix 61 - PO.xls` | `Semi-Expendable/Acquisition/Appendix 62- IAR.xls` |

## Appendix 60 — Purchase Request (PR)

| Excel label | Cell | DB / form field | Step |
|-------------|------|-----------------|------|
| Entity Name | A6 | `offices.name` | PR |
| Fund Cluster | D6 | `offices.fund_cluster` | PR |
| Office/Section | A7 | `offices.name` or department | PR |
| PR No. | C7 | `acquisition_paperwork.pr_number` | PR |
| Date | E7 | `acquisition_paperwork.pr_date` | PR |
| Responsibility Center Code | C8 | `departments.code` / `offices.code` | PR |
| Stock/Property No. | A (row 10+) | `acquisition_paperwork_lines.item_id` → `items.item_code` | PR lines |
| Unit | B | `acquisition_paperwork_lines.unit` / `items.unit` | PR lines |
| Item Description | C | `acquisition_paperwork_lines.description` / `items.name` | PR lines |
| Quantity | D | `acquisition_paperwork_lines.quantity` | PR lines |
| Unit Cost | E | `acquisition_paperwork_lines.unit_cost` (optional at PR) | PR lines |
| Total Cost | F | computed `quantity × unit_cost` | PR lines |
| Purpose | A33 | `acquisition_paperwork.purpose` | PR |
| Requested by (printed name) | B39 | `acquisition_paperwork.requested_by_name` | PR |
| Approved by (printed name) | D39 | `acquisition_paperwork.approved_by_name` | PR |

Detail rows: **10–32** (max 23 lines).

## Appendix 61 — Purchase Order (PO)

| Excel label | Cell | DB / form field | Step |
|-------------|------|-----------------|------|
| Supplier | A7 | `acquisition_paperwork.supplier` | PO |
| P.O. No. | D7 | `acquisition_paperwork.po_number` | PO |
| Date | D8 | `acquisition_paperwork.po_date` | PO |
| Address | A8 | `acquisition_paperwork.po_data.address` | PO |
| TIN | A9 | `acquisition_paperwork.po_data.tin` | PO |
| Mode of Procurement | D9 | `acquisition_paperwork.po_data.mode_of_procurement` | PO |
| Place of Delivery | A13 | `acquisition_paperwork.po_data.place_of_delivery` | PO |
| Delivery Term | D13 | `acquisition_paperwork.po_data.delivery_term` | PO |
| Date of Delivery | A14 | `acquisition_paperwork.po_data.date_of_delivery` | PO |
| Payment Term | D14 | `acquisition_paperwork.po_data.payment_term` | PO |
| Stock/Property No. | A (row 16+) | line `items.item_code` | PO lines |
| Unit | B | line `unit` | PO lines |
| Description | C | line `description` | PO lines |
| Quantity | D | line `quantity` | PO lines |
| Unit Cost | E | line `unit_cost` | PO lines |
| Amount | F | line `amount` | PO lines |
| Fund Cluster | A45 | `offices.fund_cluster` | PO |

Cells **D45–D47** and **A46** (ORS/BURS No., Funds Available, Date of ORS/BURS, header Amount) are **not** filled by this system — accounting division completes them on the printed Appendix 61 per OWWA instructions.

Detail rows: **16–31** (max 16 lines).

## Appendix 62 — Inspection and Acceptance Report (IAR)

| Excel label | Cell | DB / form field | Step |
|-------------|------|-----------------|------|
| Entity Name | A6 | `offices.name` | IAR |
| Fund Cluster | D6 | `offices.fund_cluster` | IAR |
| Supplier | A8 | `acquisition_paperwork.supplier` | IAR |
| IAR No. | D8 | `acquisition_paperwork.iar_number` | IAR |
| PO No./Date | A9 | `po_number` + `po_date` | IAR |
| Date | D9 | `acquisition_paperwork.iar_date` | IAR |
| Requisitioning Office/Dept. | A10 | `offices.name` | IAR |
| Invoice No. | D10 | `acquisition_paperwork.iar_data.invoice_no` | IAR |
| Responsibility Center Code | A11 | department/office code | IAR |
| Invoice Date | D11 | `acquisition_paperwork.iar_data.invoice_date` | IAR |
| Stock/Property No. | A (row 14+) | line `items.item_code` | IAR lines |
| Description | B | line `description` | IAR lines |
| Unit | D | line `unit` | IAR lines |
| Quantity | E | line `quantity` (accepted qty) | IAR lines |
| Date Inspected | A28 | `acquisition_paperwork.iar_data.date_inspected` | IAR |
| Date Received | C28 | `acquisition_paperwork.iar_data.date_received` | IAR |
| Inspection Officer | A35 | `acquisition_paperwork.inspection_officer_name` | IAR |
| Supply/Property Custodian | C35 | `acquisition_paperwork.custodian_name` | IAR |

Detail rows: **14–26** (max 13 lines).

## Category-specific rules

- **Consumables:** Stock No. = `items.item_code`; no property number at acquisition.
- **PPE:** `unit_cost` required (≥ ₱50k rule via existing observer); property number assigned at **issuance**, not PR.
- **Semi-expendable:** value category preview; useful life on ICS at issuance, not PR.

## In-app workflow

Phases on `acquisition_paperwork.phase`: `pr` → `po` → `iar` → `acquired`.

Each phase unlocks after the prior phase is marked complete. Excel export is available once the phase form validates. Final step creates one `Acquisition` per line with `source` = `PO {po_number} / IAR {iar_number}`.

Cell references for export: `config/owwa_cell_maps.php` keys `PR`, `PO`, `IAR`.
