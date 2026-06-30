# OWWA export cell mapping

Excel exports fill **values into the cells defined by your template files** in `storage/app/templates/`. Labels (Entity Name, RIS No., Division, etc.) are already printed on the `.xls` forms; the application writes `Label: value` into the same merged cells the template uses for fill-in lines.

## Source of truth

1. Place OWWA templates under `storage/app/templates/` (keep original filenames; see `config/owwa_templates.php`).
2. Regenerate structure dump after any template change:
   ```bash
   php artisan owwa:analyze-templates --output=storage/app/templates/template-structure.txt
   ```
3. Documented cell refs live in [`config/owwa_cell_maps.php`](../config/owwa_cell_maps.php). [`App\Support\OwwaCellMapping`](../app/Support/OwwaCellMapping.php) applies header maps; [`OwwaTemplateExportService`](../app/Services/OwwaTemplateExportService.php) builds row data.

Audit coverage:
```bash
php artisan app:audit-owwa-templates
```

## Appendix 63 – RIS header layout

From `requisition/Appendix 63 - RIS.xls`:

| Row | Left | Right |
| --- | ---- | ----- |
| 6 | Entity Name (`A6`) | Fund Cluster (`G6`) |
| 8 | Division (`A8`) | Responsibility Center Code (`F8`) |
| 9 | Office (`A9`) | RIS No. (`F9`) |

Detail lines start at **row 12** (headers on row 11). Stock availability uses **Yes (`E`) / No (`F`)** marks; issue quantity and remarks go in **`G` / `H`**.

Purpose text: **`A32`**. Signatures (printed names): **`B37`** requested by, **`D37`** approved by.

**Do not** put RIS No. on row 6 (`G6`) — that row is Fund Cluster only.

## Other forms (verified against template-structure.txt)

| Form | Key header cells | First detail row |
| ---- | ---------------- | ---------------- |
| RSMI (64) | `A6` entity, `G6` serial, `A7` fund, `G7` date | 12 |
| PAR (71) | `A6`, `A7`, `E7` PAR No. | 11 |
| ICS (59) | `A6`, `A7`, `G7` ICS No. | 12 |
| PTR (76) | `A6`, `G6`, `A8`/`H8`, `A9`/`H9` | **17** |
| SC (58) | `A6`–`A10` item header; ledger row **13** | 13 |
| WMR (65) | `A7`, `G7`, `A8`, `G8` | 13 |
| RLSDDP (75) | `B6`–`G11` | 20 |

## RSMI: Serial No. vs RIS No.

| Field | Source | When blank |
| ----- | ------ | ---------- |
| **Serial No.** (header G6) | `issuances.reference_code` | Never on saved issuances |
| **RIS No.** (detail column A) | `requisitions.reference_code` via `issuances.requisition_id` | Only legacy rows without a link |

Issuances are created only from **Requisitions → Accept & issue** (or **Issue remainder** for partial fulfillment). Each accept tranche updates `requisition_items.quantity_issued`, `stock_available`, and `issue_remarks` for RIS export columns G/H. Do not use the RIS prefix on issuance serial codes.

## RIS vs RSMI — where to export

- **RIS (Appendix 63)** — export from **Requisitions** (one slip per request). Use **Export RIS (Appendix 63)** on the requisition view or **Export RIS — selected rows** on the list.
- **RSMI (Appendix 64)** — export from **Issuances** (daily issue report for consumables). Use **Export today's RSMI (Excel)** for all consumable issuances dated today, or **Export RSMI — selected rows** to pick specific issuance lines.
- A **single issuance** still exports as **RSMI** (one detail line with the linked **RIS No.** in column A), not as RIS. Export RIS from the **requisition**, not from Issuances.

## Where to export — COA PDF vs OWWA XLS

| Need | Export | From |
| ---- | ------ | ---- |
| Internal stock summary (PDF) | **Download COA summary (PDF)** | **Stock levels** header action |
| Stock Card ledger (Appendix 58 XLS) | **Export Stock Card (XLS)** in the ledger modal footer, or on **Items** | **Stock levels** (click item name) or **Items** |
| View ledger in app (all categories) | Click **item name** on Stock Levels → movement modal; **Export** in modal footer | **Stock levels** |
| Daily issue report (RSMI) | **Export today's RSMI** or **Export RSMI — selected rows** | **Issuances** |
| Requisition slip (RIS) | **Export RIS (Appendix 63)** | **Requisitions** |
| PPE Property Card (full ledger, Appendix 69) | **Export Property Card (XLS)** in ledger modal, or **Export Property Cards (XLS)** header action | **Stock levels** (PPE category) |
| PPE acquisition receipt line (Appendix 69) | **Export receipt line (Property Card)** on acquisition view | **Acquisitions** (PPE only — one receipt row, not the full card) |
| Consumable acquisition receipt line (Appendix 58) | **Export Stock Card receipt (Appendix 58)** on acquisition view | **Acquisitions** (consumables — one receipt row; full ledger on Stock levels) |
| Semi-expendable acquisition receipt line (Annex A.1) | **Export receipt line (Annex A.1)** on acquisition view | **Acquisitions** (semi — one receipt row, not the full card) |
| Purchase request (Appendix 60) | **Export PR** on acquisition case view (PR nested modal) | **Acquisitions** |
| Purchase order (Appendix 61) | **Export PO** on acquisition case view (PO nested modal) | **Acquisitions** |
| Inspection & acceptance (Appendix 62) | **Export IAR** on acquisition case view (IAR nested modal) | **Acquisitions** |

**Capstone scope:** Accounting (DV/JEV) stays offline. **PR → PO → IAR → Received** is supported in-app for all inventory categories (consumables, PPE, semi-expendable). The Supply Custodian fills each phase, **submits for offline approval**, **marks approved** after sign-off (assigning PR/PO/IAR numbers), then **records custody receipt** when goods arrive. The **Property Card / Stock Card** ledger is maintained on Stock levels.

## Acquisition paperwork workflow (PR / PO / IAR → Received)

Start from **Inventory category dashboard → Acquisitions**. Each **acquisition case** (`acquisition_paperwork`) moves through four stages:

1. **Purchase request** — header + line items (Stock No. from item catalog). **Submit PR for approval** when ready; export Appendix 60. **Mark PR approved** after offline sign-off (assigns **PR No.**).
2. **Purchase order** — unlocked after PR approval. Supplier, costs, delivery terms. **Submit PO** → export Appendix 61 → **Mark PO approved** (assigns **PO No.**).
3. **Inspection & acceptance** — unlocked after PO approval. Inspection signatories. **Submit IAR** → export Appendix 62 → **Mark IAR approved** (assigns **IAR No.**).
4. **Custody receipt** — after IAR approval, **Record custody receipt** creates one `acquisitions` row per line (Stock Card **Reference**), sets `received_at` on the case, and updates stock levels.

Per-phase status is tracked as `draft`, `pending_approval`, or `approved` on `pr_status`, `po_status`, and `iar_status`. There is no separate “Acquisition No.” on OWWA instruction PDFs — use PR No., PO No., and IAR No. on forms; case `reference_code` (e.g. `AP-…`) is app-internal only.

Templates are configured in `config/owwa_templates.php` under `acquisition_paperwork` (per category). Cell maps: `PR`, `PO`, `IAR` in [`config/owwa_cell_maps.php`](../config/owwa_cell_maps.php). Field reference: [`docs/PR_PO_IAR_FIELD_MAP.md`](PR_PO_IAR_FIELD_MAP.md).

Appendix 63 **RIS** (Requisitions) is an **issue request** from stock, not a purchase request — do not substitute RIS export for PR.

| PR / PO / IAR field | System field |
| ------------------- | ------------ |
| Stock / Property No. | `acquisition_paperwork_lines.item_id` → `items.item_code` |
| Description | `acquisition_paperwork_lines.description` / `items.name` |
| Quantity / UOM | Line `quantity`; UOM from `items.unit` |
| PR No. | `acquisition_paperwork.pr_number` (reference series `acquisition_paperwork_pr`) |
| PO No. | `acquisition_paperwork.po_number` (reference series `acquisition_paperwork_po`) |
| IAR No. | `acquisition_paperwork.iar_number` (reference series `acquisition_paperwork_iar`) |

On **Acquisition export** (Appendix 58), the Stock Card header **Stock No.** (`F8`) is filled from `items.item_code`. The ledger **Reference** column is `acquisitions.reference_code` (YYYY-MM-####) — not the Stock No.

See [`storage/app/templates/Consumable/Acquisitions/README.md`](../storage/app/templates/Consumable/Acquisitions/README.md) for the local template folder layout and audit command.

**Type of supplies** on OWWA forms is the inventory or property classification (e.g. _Office Supplies Inventory_ on RPCI, _ICT_ / _Office Equipment_ tabs on semi-expendable RPCSP and Annex A.1). Consumable Stock Card (SC) is one sheet per item — no type tabs. Semi-expendable items store **Property class** on the item record; exports pick the matching Excel tab (ICT, Office equipment, etc.). Physical count sessions for Annex A.8 RPCSP can set **Property class** or infer it from count lines.

**Annex A.1 (semi-expendable property card)** uses `Semi-Expendable/Recording (Stock Levels)/Property-Form-Annex-A.1-Semi-expendable-Property-Card.xlsx`. The on-disk workbook has a single master sheet **`SPC`**. At export time the app clones `SPC`, clears sample cells (`E15`, `L15`, ledger rows), fills property card data, and renames each clone to the OWWA property-class tab (ICT, OFFICE EQUIPMENT, SPORTS EQUIPMENT, etc.). Property class comes from **`items.property_class`** (Setup → Items); missing values default to Office equipment with a warning on bulk export.

- **Single-item export** (Stock Levels ledger modal): one tab named after the item’s property class; block 0 only.
- **Bulk export** (Stock Levels header on semi-expendable category): one workbook with tabs only for classes that have items in the current list; multiple items on the same tab stack vertically with an **18-row stride** (`block_stride` in `ANNEX_A1`). Cell references are in `config/owwa_cell_maps.php` (`ANNEX_A1`) and `App\Support\AnnexA1BlockLayout`.

**PPE Property Card (Appendix 69)** uses `ppe/Accquisition/Appendix 69 - PC.xls`. The ledger merges acquisitions (receipt), issuances (issue), transfers, and disposals via `OwwaItemReportService::buildTransactionHistory()`. Cell references are in `config/owwa_cell_maps.php` (`PC`) and `App\Support\PropertyCardLayout`.

- **Single-item export** (Stock Levels ledger modal): full card for that item×office.
- **Bulk export** (Stock Levels header on PPE category): one worksheet per visible item×office row (sheet title = stock/item code).
- **Acquisition view export**: one **receipt line** only — use Stock levels for the complete card.

---

## Estimated useful life (semi-expendable only)

Per **COA Circular 2022-004**, semi-expendable property must have useful life **greater than 1 year**. PPE custody forms (**Property Card**, **PAR**, **RPCPPE**) do **not** include an estimated useful life column — that field is out of scope for PPE in this system.

| Form | Column | Source |
| ---- | ------ | ------ |
| **ICS** (Appendix 59) | **H — Estimated Useful Life** | `issuances.estimated_useful_life`, fallback `items.estimated_useful_life` |
| **Registry** (Annex A.4) | **E — Estimated Useful Life** | Issuance registry rows (same fallback) |
| **Annex A.1** (SPC) | *(none)* | Receipt/issue ledger only |
| **PAR / Property Card (PPE)** | *(none)* | Not captured on custody slips |

**Catalog default:** `items.estimated_useful_life` — suggested from **Property class** via `config/inventory.php` (`semi_useful_life_defaults`). Custodian may override per item.

**Per issuance (ICS source of truth):** `issuances.estimated_useful_life` — required on semi issuances; auto-filled from the item default when a requisition is fulfilled or when an item is selected on the issuance form. Values must parse as years/months and exceed the configured minimum (`semi_min_useful_life_years`, default 1).

---

## Transfer & disposal signatories

Signatory names are **typed printed names** on each transaction (not user dropdowns). On create, empty fields are prefilled from **office defaults** (`offices.supply_custodian_*`, `authorized_officer_*`, etc.) and the authenticated user where applicable. Override per record on the form.

### Appendix 76 – PTR (PPE + semi transfers)

| DB field | Cell | Template label |
| -------- | ---- | -------------- |
| `from_accountable_officer` | A8 | From Accountable Officer |
| `to_accountable_officer` | A9 | To Accountable Officer |
| `approved_by_printed_name` | B53 | Approved by |
| `approved_by_designation` | A54 | Designation |
| `released_by_printed_name` | F53 | Released by |
| `released_by_designation` | F54 | Designation |
| `received_by_printed_name` | H53 | Received by |
| `received_by_designation` | H54 | Designation |
| `reason_for_transfer` / `remarks` | A43 | Reason |

Config: `PTR.signatures` and `PTR.transfer_type_marks` in `config/owwa_cell_maps.php`. Layout helper: `App\Support\PtrSignatureLayout`.

### Consumable transfer (RSMI stand-in)

Consumable inter-office transfers export as **Appendix 64 RSMI** with one detail line (transfer reference in column A, item/qty, “Transfer to {office}” in description). Custodian name maps to **A52**. There is no dedicated OWWA consumable transfer form in this project.

### Appendix 65 – WMR (consumable disposal)

| DB field | Cell |
| -------- | ---- |
| `custodian_printed_name` | B25 (Prepared by) |
| `approved_by_printed_name` | G25 |
| `inspection_officer_printed_name` | B37 |
| `witness_printed_name` | G37 |

Disposal mode checkmarks: `WMR.disposal_mode_marks` (B32–B35). Layout: `App\Support\DisposalExportLayout`.

### Appendix 74 – IIRUP / Annex A.10 – IIRUSP (unserviceable)

| DB field | IIRUP cell | IIRUSP cell |
| -------- | ---------- | ----------- |
| `custodian_printed_name` (header + endorsement) | B7 / C40 | C10 / C37 |
| `accountable_officer_designation` | F7 | F10 |
| `accountable_officer_station` | K7 | K10 |
| `approved_by_printed_name` | H40 | H37 |
| `inspection_officer_printed_name` | L40 | L38 |
| `witness_printed_name` | Q40 | Q38 |

### Appendix 75 – RLSDDP (lost/stolen/damaged)

| DB field | Cell |
| -------- | ---- |
| `custodian_printed_name` | B9 (header), B39 (signature) |
| `accountable_officer_designation` | B10 |
| `immediate_supervisor_printed_name` | F39 (Noted by) |
| `disposal_date` | B41, F41 |

Police block and property-status marks: `RLSDDP.police`, `RLSDDP.property_status_marks` in cell maps.

### Physical count – RPCI / RPCPPE / RPCSP

| DB field | RPCI | RPCPPE | RPCSP |
| -------- | ---- | ------ | ----- |
| `certified_by_printed_name` | B35 | C35 | B35 |
| `approved_by_printed_name` | F35 | G35 | F35 |
| `verified_by_printed_name` | B37 | C37 | B37 |

Accountable officer narrative: RPCI **B10**, RPCPPE **C10**, RPCSP **B10** (from `accountable_officer_name`, designation, office, date of assumption). Entity name: **A6** on RPCPPE/RPCSP/RPCI exports.

### QR physical count (PPE + semi)

Per-unit QR tags are generated at **acquisition** for PPE and semi-expendable items (`inventory_units` table — one row per physical unit when `quantity = N`).

**QR payload format** (versioned; physical count and legacy stickers):

```
OWWA|1|pn=PPE-2026-00042|item=12|office=3|sn=PPE-001
```

**New labels** (when `INVENTORY_QR_PUBLIC_LOOKUP=true`, default): QR encodes a public HTTPS URL:

```
{APP_URL}/assets/{propertyNumber}
```

Scanning with a phone camera opens a read-only asset card (property number, item name, category, office, status, unit cost — no personal names). Supply custodians who are logged in also see **Open in admin**.

Legacy plain property numbers, `OWWA:PN:` prefix, and `OWWA|1|...` text payloads still work for physical count scanning.

| Step | Where |
| ---- | ----- |
| Generate tags | Save PPE/Semi **Acquisition** → auto-creates `inventory_units` |
| Print labels | **Acquisition** view → **Print unit QR labels** (bulk PDF); legacy: Issuance view → Print QR label |
| Public lookup | Scan printed QR with phone camera → `/assets/{propertyNumber}` (no login) |
| Start count (mobile) | Physical counts list → **Start count (mobile)** → office + category → **scan-first** (empty session; no book preload) |
| Desktop create | Physical counts → Create (optional; scan-first until book load) |
| Load expected list | Session view → **Load expected assets** (from in-stock units; falls back to issuances; sets `book_list_loaded`) |
| Scan | **Scan with phone** — scan mode shows tags scanned; after book load, progress / Missing / Found / Overage |
| Complete | Desktop session view → **Load expected assets** → fill header/signatories → **Mark complete** (blocked until book loaded and shortages resolved) |
| Compare | `on_hand_count` vs `balance_per_card` per line; shortage/overage on session view and OWWA export |
| Audit | `physical_count_scan_events` — each scan (found / duplicate / overage / not_found) |

**Session status:** `in_progress` → `incomplete` (after Finish counting) → `complete` (book list loaded, all fields + zero shortages).

**Mobile vs desktop:** Mobile starts with zero lines and records only scanned tags. Desktop **Load expected assets** merges the custody book list; unscanned units become shortages. **Mark complete** requires `book_list_loaded = true`.

**Tally:** per line `on_hand_count - balance_per_card` (0 = OK, negative = shortage, positive = overage). List page shows tally column e.g. `10/12 (2 short)`.

Services: `AcquisitionUnitService`, `PhysicalCountPreloadService`, `PhysicalCountScanService`, `PhysicalCountCompletionService`, `InventoryQrLabelService`, `InventoryUnitQrPayload`, `InventoryUnitPublicLookupService`. QR scan is enabled for `rpcppe` and `rpcsp` sessions only (not consumable RPCI).

**Which form to use**

| Category | Report form | Count method |
| -------- | ----------- | ------------ |
| Consumables | Appendix 66 RPCI | Manual count lines on create/edit |
| PPE | Appendix 73 RPCPPE | Mobile scan-first → desktop load book → reconcile → complete |
| Semi-expendable | Annex A.8 RPCSP | Same scan-first QR flow as PPE |

On create, PPE/Semi sessions auto-default from the active inventory category, auto-fill office header fields, hide manual count lines, and redirect to the session view with next-step guidance. Primary QR labels are printed from **acquisitions** (unit tags); issuance print remains for legacy rows.

### Issuance signatories

Captured on **Accept & issue** (requisition modal) and editable on the issuance record. Category-specific labels: `App\Support\IssuanceSignatoryLabels`. Export mapping: RSMI A52 (custodian), PAR/ICS per `config/owwa_cell_maps.php`. View issuance warns before export when custodian name is blank.

## Estimated useful life (semi-expendable only)

**Scope:** Semi-expendable custody forms only. PPE useful life / depreciation is an **accounting** function and is **not** captured on supply custody forms in this application.

### Cell map

| Form | `useful_life` column? | Notes |
| ---- | --------------------- | ----- |
| **ICS** (Appendix 59) | **Yes** — column **H** | Field #9 Estimated Useful Life |
| PAR (71) | No | |
| Property Card (69) | No | |
| RPCPPE (73) | No | |
| PTR (76) | No | |
| IIRUP (74) | No | Accumulated depreciation language only (accounting valuation) |
| PR / PO / IAR (60–62) | No | |

PHPUnit: `tests/Unit/OwwaCellMappingUsefulLifeTest.php` asserts `useful_life` exists only on the `ICS` detail column map.

### PPE instruction PDF audit (June 2026)

Full-text search of instruction PDFs under `storage/app/templates/ppe/`:

| Instruction PDF | "Estimated Useful Life" / "useful life"? |
| ----------------- | ---------------------------------------- |
| Appendix 60 PR | **No** |
| Appendix 61 PO | **No** |
| Appendix 62 IAR | **No** |
| Appendix 69 PC (Acquisition + Recording) | **No** |
| Appendix 71 PAR | **No** |
| Appendix 73 RPCPPE | **No** |
| Appendix 76 PTR | **No** |
| Appendix 75 RLSDDP | **No** |
| Appendix 74 IIRUP | **No EUL** — mentions accumulated depreciation / carrying amount |

**Semi contrast:** Appendix 59 ICS field #9 and Annex A.4 registry column E require estimated useful life.

**Do not add PPE EUL fields** unless an accounting PPE ledger module is added.

---

## When templates change

Update `config/owwa_cell_maps.php`, adjust the matching `cellValuesFor*` method if needed, run `tests/Unit/OwwaTemplateExportMappingTest.php`, and re-export one sample record per form to visually confirm labels remain visible.

See also [`docs/INVENTORY_NUMBERING.md`](INVENTORY_NUMBERING.md) for control-number semantics (RIS No. vs RSMI Serial No.).
