# What to change in the system and UI, and how to use the forms

This document recommends changes so the system and UI align with OWWA forms, and explains how each form is used in practice.

---

## 1. How the forms are used (workflow)

| Step | What happens |
|------|----------------|
| **1. Record in system** | User creates an **Issuance**, **Disposal**, or **Transfer** in Filament (same as now). They pick the item (which has a category: consumables, PPE, or semi-expendable). They fill fields that match the target OWWA form where we’ve aligned them (e.g. for consumables issuance: Serial No., Entity, Unit Cost, Amount, custodian/accounting printed names). |
| **2. Export or print** | User clicks **“Export OWWA form”** (default form for that transaction + category) or **“Export OWWA form (choose…)”** to pick a specific form. They get a pre-filled Excel to print and sign. Optionally they use **“Print view”** (we have this for RSMI and WMR) to preview/print a form-style page. |
| **3. Sign physically** | Printed names are in the system; actual signatures are done on the printed Excel or print view. |

**Which form is used?** The system chooses by **transaction type** (issuance / disposal / transfer) and **item category** (consumables / PPE / semi_expendable). Example: consumables issuance → RSMI; PPE issuance → PAR; PPE transfer → PTR.

---

## 2. What to change (in order)

### Phase A – Config and category-aware defaults (quick win)

**Goal:** When the user exports, the **default** form is the correct one for the item’s category (not always RSMI/WMR).

| Change | Where | What to do |
|--------|--------|------------|
| **A1. Point PPE and semi_expendable to the right templates** | `config/owwa_templates.php` | For **issuance**: PPE → Appendix 71 - PAR, semi_expendable → Appendix 59 - ICS. For **disposal**: PPE → Appendix 74 - IIRUP (and optionally Appendix 75 - RLSDDP), semi_expendable → Annex A.10 - IIRUSP (and optionally RLSDDP). For **transfer**: PPE and semi_expendable → Appendix 76 - PTR. Consumables stay as now (RSMI, WMR). |
| **A2. Add form options per category** | Same config | For disposal, add keys so “Export (choose…)” can offer e.g. WMR vs RLSDDP for PPE. Same idea for semi_expendable (IIRUSP vs RLSDDP). |

**Result:** Export uses the correct template file per category. If we don’t yet fill cells for PAR/PTR/IIRUP/etc., the file still opens with the right layout; we can fill the cells we know and leave the rest for manual entry until Phase B.

---

### Phase B – Fill the right cells for each form (export logic)

**Goal:** Pre-fill as many cells as possible when exporting.

| Change | Where | What to do |
|--------|--------|------------|
| **B1. Cell mappings for PPE/semi_expendable** | `OwwaTemplateExportService` | Add detection by template path (e.g. “PAR”, “PTR”, “IIRUP”, “IIRUSP”, “RLSDDP”) and implement `cellValuesForIssuancePar()`, `cellValuesForTransferPtr()`, `cellValuesForDisposalIirup()`, etc., using the analyzer output for each file. Start with header + one detail row; expand later. |
| **B2. Optional: disposal type** | DB + model + form | Add something like `disposal_type` or `reason_type` (e.g. `unserviceable`, `lost_stolen_damaged`, `waste_sale`) so we can default to IIRUP/IIRUSP vs RLSDDP vs WMR and pre-fill the right form. |

**Result:** Export pre-fills the correct OWWA form for each transaction type and category.

---

### Phase C – UI clarity (labels and hints)

**Goal:** Users understand which OWWA form they’re filling and exporting.

| Change | Where | What to do |
|--------|--------|------------|
| **C1. Form section titles by category** | Issuance/Disposal/Transfer form schemas | Keep consumables as “RSMI” / “WMR”. For PPE/semi_expendable, use section titles that match the form (e.g. “Property Acknowledgment Receipt (PAR)” for PPE issuance, “Property Transfer Report (PTR)” for transfer). Optional: show these sections only when the selected item’s category is PPE/semi_expendable (e.g. with `visible(fn ($get) => ...)`. |
| **C2. Short hint on View/Edit** | View/Edit pages or form description | One line: “Export uses the OWWA form for this item’s category (e.g. consumables → RSMI/WMR; PPE → PAR/PTR/IIRUP).” |
| **C3. Disposal: choose form by reason** | Disposal form | If we add disposal_type, add a Select “Disposal type” (Unserviceable / Lost–Stolen–Damaged–Destroyed / Waste–Sale) and use it to suggest or default the form (IIRUP/IIRUSP vs RLSDDP vs WMR). |

**Result:** UI clearly reflects which form applies and how export/print will use it.

---

### Phase D – Print views for other forms (optional)

**Goal:** “Print view” matches the OWWA form layout for more transaction types.

| Change | Where | What to do |
|--------|--------|------------|
| **D1. Print views for PAR, PTR, IIRUP, etc.** | New Blade views + routes + controller | Same idea as RSMI/WMR: a read-only page that looks like the form, with printed names (no signature image). Add e.g. “Print view (PAR)” for PPE issuance, “Print view (PTR)” for transfer, and optionally for IIRUP/IIRUSP/RLSDDP. |
| **D2. Show the right print action** | Filament View/Edit header and table | Show “Print view (PAR)” only when item category is PPE; “Print view (PTR)” for transfers when category is PPE or semi_expendable; etc. |

**Result:** Users can preview and print a form-style page for the main forms, not only RSMI/WMR.

---

### Phase E – Item-level and periodic reports (later)

**Goal:** Support SLC, SC, Property Card, Registry, and Physical Count forms.

| Form type | System change | How it’s used |
|-----------|----------------|----------------|
| **SLC, SC, Property Card, Annex A.1** | “Export by Item” flow | New UI: choose **Item** (and optionally office), choose form (SLC, SC, or Property Card). System aggregates Acquisitions + Issuances (and Transfers/Disposals for balance) for that item and fills the template. |
| **Annex A.4 Registry** | Same or similar | Choose item or category; fill registry from issuance/return/disposal history. |
| **RPCI, RPCPPE, RPCSP (physical count)** | “Physical count” flow | New UI: choose date and type (inventory / PPE / semi-expendable). Either enter “on hand” counts and the system fills the template, or export a blank/skeleton and fill manually. |

These need new screens and possibly new tables (e.g. physical count header + lines), so they’re a separate phase.

---

## 3. Summary: what to change and how forms are used

| Area | Change | How forms are used |
|------|--------|---------------------|
| **Config** | Map PPE/semi_expendable to PAR, ICS, PTR, IIRUP, IIRUSP, RLSDDP in `owwa_templates.php`. | Export automatically uses the right form for the item’s category. |
| **Export service** | Add cell-mapping methods for PAR, PTR, IIRUP, IIRUSP, RLSDDP (and ICS if used as issuance). | Pre-filled Excel matches the chosen OWWA form. |
| **Disposal (optional)** | Add disposal type (unserviceable / lost–damaged / waste–sale). | Correct default form (IIRUP vs RLSDDP vs WMR) and clearer “Export (choose…)”. |
| **UI** | Category-aware section titles and a one-line hint; optional visibility by category. | Users see which form they’re filling and that export follows OWWA by category. |
| **Print views** | Add PAR, PTR, and optionally IIRUP/IIRUSP/RLSDDP print views and actions. | Same workflow as RSMI/WMR: preview and print for physical signatures. |
| **Item-level / count** | New “Export by Item” and “Physical count” flows (later). | SLC, SC, Property Card, Registry, RPCI, RPCPPE, RPCSP used from dedicated screens. |

**Recommended order:** Do **Phase A** first (config) so the right template is used for each category. Then **Phase B** (cell mappings) so exports are pre-filled. Then **Phase C** (UI labels/hints). Add **Phase D** (print views) if you want parity with RSMI/WMR for other forms. Leave **Phase E** (item-level and count) for a later iteration.
