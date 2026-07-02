# Unused templates and report forms

## 1. Clearing cache so UI changes show

If you don’t see the latest labels or form layout (e.g. Issuance, Transfer, Disposal, Reference numbers):

1. Clear Laravel caches:
   ```bash
   php artisan optimize:clear
   ```
   Or at least:
   ```bash
   php artisan view:clear
   php artisan config:clear
   php artisan cache:clear
   ```

2. Hard-refresh the browser (Ctrl+F5 or Cmd+Shift+R).

3. Confirm you’re on the right panel and page:
   - **Reference number formats:** System Admin panel → **Setup** → **Reference numbers** (path like `/system-admin/...`).
   - **Issuance / Transfer / Disposal fill-up forms:** Admin panel → **Issuances** (or Transfers, Disposals) → **Create** or **Edit** a record.

---

## 2. Templates referenced in the system (used for export)

These OWWA Excel templates are configured in `config/owwa_templates.php` and are used when you use **Export OWWA form** (or **Export OWWA form (choose…)**) from Issuance, Transfer, or Disposal:

| Template file | Used for |
|---------------|----------|
| Appendix 64 - RSMI.xlsx / .xls | Issuance (consumables), Transfer (consumables stand-in) |
| Appendix 71 - PAR.xlsx / .xls | Issuance (PPE) |
| Appendix 59 - ICS.xlsx / .xls | Issuance (semi-expendable) |
| Appendix 76 - PTR.xlsx / .xls | Transfer (PPE, semi-expendable) |
| Appendix 65 - WMR.xlsx / .xls | Disposal (consumables, waste/sale) |
| Appendix 74 - IIRUP.xlsx / .xls | Disposal (PPE unserviceable) |
| Annex A.10- IIRUSP.xlsx / .xls | Disposal (semi-expendable unserviceable) |
| Appendix 75 - RLSDDP.xlsx / .xls | Disposal (lost/stolen/damaged) |

Place these under `storage/app/templates/` in the right category folder (e.g. `consumables/`, `ppe/`, `semi_expendable/`) as in the config.

---

## 3. Templates not used by the current export (unused by the app)

These OWWA forms appear in the mapping doc (`TEMPLATE_TO_SYSTEM_MAPPING.md`) but are **not** in `config/owwa_templates.php`, so there is **no “Export OWWA form”** action that uses them in the app right now:

| Template file | OWWA form name | Intended use |
|---------------|-----------------|--------------|
| Appendix 57 - SLC.xls | Supplies Ledger Card | Item-level ledger (receipt/issue/balance) |
| Appendix 58 - SC.xls | Stock Card | Item-level stock card |
| Appendix 66 - RPCI.xls | Report on the Physical Count of Inventories | Periodic physical count (inventories) |
| Appendix 69 - PC.xls | Property Card | Item-level PPE property card |
| Appendix 73 - RPCPPE.xls | Report on the Physical Count of PPE | Periodic physical count (PPE) |
| Annex A.8 - RPCSP (REPORT).xlsx | Report on the Physical Count of Semi-Expendable Property | Periodic physical count (semi-expendable) |
| Annex A.1 - Semi-expendable Property Card.xlsx | Semi-Expendable Property Card | Item-level semi-expendable card |
| Annex A.4 - Registry of Semi-Expendable Property Issued.xls | Registry of Semi-Expendable Property Issued | Registry of issued semi-expendable |

If you add any of these files under `storage/app/templates/`, they are **unused** until you add them to the config and implement an export (or report) that uses them.

---

## 4. Reports in the system (non-OWWA and OWWA)

### Built-in reports (PDF, not OWWA Excel)

- **COA reports** (Filament: **COA reports** or similar):
  - Stock level report (PDF)
  - Issuance report (PDF)

These are PDF reports, not OWWA Excel templates.

### OWWA “report” forms (periodic / item-level)

OWWA has Excel forms that act as **reports** (e.g. physical count, ledger, stock card). Right now the app does **not** have an export that fills these:

- **Physical count:** Appendix 66 (RPCI), Appendix 73 (RPCPPE), Annex A.8 (RPCSP)
- **Item-level:** Appendix 57 (SLC), Appendix 58 (SC), Appendix 69 (PC), Annex A.1, Annex A.4

So: **there is no dedicated “template or form for reports”** in the sense of “one button that exports an OWWA report form”. The app has:

- **OWWA transaction forms:** Issuance (RSMI/PAR/ICS), Transfer (PTR), Disposal (WMR/IIRUP/IIRUSP/RLSDDP) — used by **Export OWWA form**.
- **PDF reports:** Stock level and Issuance (COA-style).

To support an OWWA report form (e.g. Stock Card, Physical Count), you would add the template under `storage/app/templates/`, add it to config (or a new report config), and implement an export that fills it (e.g. from Items or from a report screen).
