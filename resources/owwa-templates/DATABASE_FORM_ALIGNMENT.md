# Database ↔ Form alignment (OWWA inventory)

The MySQL database **does align** with the forms: every form field is backed by a matching table/column. Table and column names use snake_case; the UI uses the same names as model attributes and shows human-friendly labels.

---

## Issuances

**DB table:** `issuances`

| DB column                     | Form field (model)              | Form label (UI)     |
|------------------------------|----------------------------------|---------------------|
| reference_code               | reference_code                  | Reference number    |
| office_id                    | office_id                       | Office              |
| issuance_date                | issuance_date                   | Date                |
| item_id                      | item_id                         | Item                |
| quantity                     | quantity                        | Quantity            |
| unit_cost                    | unit_cost                       | Unit cost           |
| amount                       | amount                          | Amount              |
| department_id                | department_id                   | Department          |
| requisition_id               | requisition_id                  | Requisition         |
| issued_to                    | issued_to                       | Issued to           |
| remarks                      | remarks                         | Remarks             |
| custodian_printed_name       | custodian_printed_name          | Custodian           |
| accounting_staff_printed_name| accounting_staff_printed_name   | Accounting staff    |
| issued_by                    | (system-set, not in form)       | —                   |

---

## Transfers

**DB table:** `transfers`

| DB column                   | Form field (model)            | Form label (UI)  |
|----------------------------|-------------------------------|------------------|
| reference_code             | reference_code                | Reference number |
| item_id                    | item_id                       | Item             |
| from_office_id             | from_office_id                | From office      |
| to_office_id               | to_office_id                  | To office        |
| quantity                   | quantity                      | Quantity         |
| transfer_date              | transfer_date                 | Date             |
| remarks                    | remarks                       | Remarks          |
| approved_by_printed_name   | approved_by_printed_name      | Approved by      |
| released_by_printed_name   | released_by_printed_name      | Released by      |
| received_by_printed_name    | received_by_printed_name      | Received by      |
| recorded_by                | (system-set, not in form)     | —                |

---

## Disposals

**DB table:** `disposals`

| DB column                        | Form field (model)                 | Form label (UI)     |
|----------------------------------|------------------------------------|---------------------|
| disposal_type                    | disposal_type                      | Type of disposal    |
| reference_code                   | reference_code                     | Reference number    |
| office_id                        | office_id                          | Office              |
| disposal_date                    | disposal_date                      | Date                |
| item_id                          | item_id                            | Item                |
| quantity                         | quantity                           | Quantity            |
| reason                           | reason                             | Reason              |
| remarks                          | remarks                            | Remarks             |
| official_receipt_no              | official_receipt_no                | Official receipt no.|
| sale_date                        | sale_date                          | Date of sale        |
| sale_amount                      | sale_amount                        | Sale amount         |
| custodian_printed_name           | custodian_printed_name             | Custodian           |
| approved_by_printed_name         | approved_by_printed_name           | Approved by         |
| inspection_officer_printed_name  | inspection_officer_printed_name   | Inspection officer  |
| witness_printed_name            | witness_printed_name               | Witness             |
| recorded_by                      | (system-set, not in form)          | —                   |

---

## Related tables (used by forms)

- **offices** — form “Office” (issuances, disposals) and “From office” / “To office” (transfers). `offices` also has `fund_cluster` (used in OWWA exports).
- **items** — form “Item” (all three).
- **departments** — form “Department” (issuances).
- **requisitions** — form “Requisition” (issuances).
- **users** — form “Issued to” (issuances), plus system fields like `issued_by`, `recorded_by`.

---

## Summary

- **Table names:** `issuances`, `transfers`, `disposals` match the Issuance, Transfer, and Disposal forms.
- **Column names:** Every form field maps to a DB column with the same name (snake_case).
- **Labels:** The UI shows friendly labels (e.g. “Reference number”, “Office”); the database stores the same data under the column names above.

No extra or missing columns for the current form fields; the database and forms are aligned.
