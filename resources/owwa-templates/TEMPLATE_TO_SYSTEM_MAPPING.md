# OWWA template → system mapping

This document maps each OWWA Excel form (template) to the correct part of the system. Use it to configure exports and to know which form to use for which transaction type.

**Note:** Many templates contain sample data (e.g. names, prices, item names like "MESH CHAIR BLACK", "OWWA RWO IV-A"). Treat these as **example/mock data only**; the system will replace them with real data when filling or exporting.

---

## 1. Consumables (`storage/app/templates/consumables/`)

| Template file | Form name | System module | Use when |
|---------------|-----------|---------------|----------|
| **Appendix 57 - SLC.xls** | Supplies Ledger Card | **Item** (report by item) | Export **ledger for one consumable item**: header = item + office; table = Receipt (acquisitions) + Issue (issuances) + Balance. Rows = transactions. |
| **Appendix 58 - SC.xls** | Stock Card | **Item** (report by item) | Export **stock card for one consumable item**: header = item + office; table = Date, Reference, Receipt Qty, Issue (Qty, Office), Balance. |
| **Appendix 64 - RSMI.xls** | Report of Supplies and Materials Issued | **Issuances** | Export/print **one issuance** (or report of issuances). One detail row = one issuance line. **Primary form for Issuances.** |
| **Appendix 65 - WMR.xls** | Waste Materials Report | **Disposals** | Export/print **one disposal**. One row = one disposal line (item, qty, unit, description, optional record of sales). **Primary form for Disposals.** |

---

## 2. PPE – Property, Plant and Equipment (`storage/app/templates/ppe/`)

| Template file | Form name | System module | Use when |
|---------------|-----------|---------------|----------|
| **Appendix 66 - RPCI.xls** | Report on the Physical Count of Inventories | **Report (periodic)** | **Physical count** of inventories (as at a date). Not a single transaction; used for stock-taking. Balance per card vs on hand, shortage/overage. |
| **Appendix 69 - PC.xls** | Property Card | **Item** (report by item) | **Per-PPE-item card**: Receipt, Issue/Transfer/Disposal, Balance. Rows = transactions (acquisitions, issuances, transfers, disposals for that item). |
| **Appendix 71 - PAR.xls** | Property Acknowledgment Receipt | **Issuances** | When PPE is **issued to an end user**. “Received by” / “Issued by”; Quantity, Unit, Description, Property Number, Date Acquired, Amount. **Use for PPE Issuances.** |
| **Appendix 73 - RPCPPE.xls** | Report on the Physical Count of PPE | **Report (periodic)** | **Physical count** of PPE (as at a date). Balance per card vs physical count, shortage/overage. |
| **Appendix 74 - IIRUP.xls** | Inventory and Inspection Report of Unserviceable Property | **Disposals** | **Unserviceable PPE** proposed for disposal. Inventory + inspection + disposal (sale, transfer, destruction, etc.). **Use for PPE Disposals (unserviceable).** |
| **Appendix 75 - RLSDDP.xls** | Report of Lost, Stolen, Damaged or Destroyed Property | **Disposals** | **Lost/stolen/damaged/destroyed** PPE. One form per incident; Property No., Description, Acquisition Cost, Circumstances, certifications. **Use for PPE Disposals (lost/damaged/etc.).** |
| **Appendix 76 - PTR.xls** | Property Transfer Report | **Transfers** | **Transfer of PPE** between accountable officers/agencies. From/To, PTR No., Date, Transfer Type (Donation, Relocate, Reassignment), property lines, reason. **Use for PPE Transfers.** |

---

## 3. Semi-expendable (`storage/app/templates/semi_expendable/`)

| Template file | Form name | System module | Use when |
|---------------|-----------|---------------|----------|
| **Annex A.10 - IIRUSP.xls** | Inventory and Inspection Report of Unserviceable Semi-Expendable Property | **Disposals** | **Unserviceable semi-expendable** for disposal. Same structure as IIRUP (inventory + inspection + disposal). **Use for Semi-expendable Disposals.** |
| **Appendix 59 - ICS.xls** / **ICS.xlsx** | Inventory Custodian Slip | **Issuances** (or Acquisitions) | **Custody slip** when semi-expendable is received/issued. Entity, Fund Cluster, ICS No.; Quantity, Unit, Description, Inventory Item No., Unit Cost, Total Cost; “Received from” / “Received by”. Can map to **Issuance** (issue to officer) or batch receipt. **Use for Semi-expendable Issuances (custodian slip).** |
| **Appendix 66 - RPCI.xls** | Report on the Physical Count of Inventories | **Report (periodic)** | Same as PPE: **physical count** of semi-expendable inventories. |
| **Appendix 75 - RLSDDP.xls** | Report of Lost, Stolen, Damaged or Destroyed Property | **Disposals** | Same as PPE: **lost/stolen/damaged/destroyed** semi-expendable. **Use for Semi-expendable Disposals (lost/damaged/etc.).** |
| **Appendix 76 - PTR.xls** | Property Transfer Report | **Transfers** | Same as PPE: **transfer** of semi-expendable between officers/agencies. **Use for Semi-expendable Transfers.** |
| **Annex A.8 - RPCSP (REPORT).xlsx** | Report on the Physical Count of Semi-Expendable Property | **Report (periodic)** | **Physical count** of semi-expendable (by type: Office Equipment, F&amp;F, etc.). |
| **Annex A.1 - Semi-expendable Property Card.xlsx** | Semi-Expendable Property Card | **Item** (report by item) | **Per-item card** for semi-expendable: Receipt, Issue/Transfer/Disposal, Balance. Like Property Card (Appendix 69) for PPE. |
| **Annex A.4 - Registry of Semi-Expendable Property Issued.xls** | Registry of Semi-Expendable Property Issued | **Item** or **report** | **Registry** of issued semi-expendable: Date, Reference, Item Description, Issued/Returned/Re-issued/Disposed, Balance. Rows = issuance/return/disposal activity. Can be per item or per category. |

---

## 4. Summary: which form for which system action

| System module | Primary form(s) | Category | Template file(s) |
|---------------|------------------|----------|-------------------|
| **Issuances** | Report of Supplies and Materials Issued | consumables | Appendix 64 - RSMI |
| | Property Acknowledgment Receipt | ppe | Appendix 71 - PAR |
| | Inventory Custodian Slip | semi_expendable | Appendix 59 - ICS / ICS.xlsx |
| **Disposals** | Waste Materials Report | consumables | Appendix 65 - WMR |
| | Inventory and Inspection Report of Unserviceable Property | ppe | Appendix 74 - IIRUP |
| | Report of Lost, Stolen, Damaged or Destroyed Property | ppe / semi_expendable | Appendix 75 - RLSDDP |
| | Inventory and Inspection Report of Unserviceable Semi-Expendable Property | semi_expendable | Annex A.10 - IIRUSP |
| **Transfers** | Property Transfer Report | ppe / semi_expendable | Appendix 76 - PTR |
| | (Consumables: no dedicated form; RSMI used as stand-in if needed) | consumables | Appendix 64 - RSMI |
| **Acquisitions** | No single “one acquisition = one form” | — | Acquisitions appear as **Receipt** rows in SLC, SC, Property Card, Semi-expendable Property Card |
| **Item-level reports** | Supplies Ledger Card / Stock Card | consumables | Appendix 57 - SLC, Appendix 58 - SC |
| | Property Card | ppe | Appendix 69 - PC |
| | Semi-Expendable Property Card / Registry Issued | semi_expendable | Annex A.1, Annex A.4 |
| **Periodic counts** | Report on Physical Count (Inventories / PPE / Semi-expendable) | all | Appendix 66 - RPCI, Appendix 73 - RPCPPE, Annex A.8 - RPCSP |

---

## 5. Quick reference by system module

| When you are in the system… | Use this form |
|-----------------------------|----------------|
| **Issuance** (consumables) | Appendix 64 - RSMI |
| **Issuance** (PPE) | Appendix 71 - PAR |
| **Issuance** (semi-expendable) | Appendix 59 - ICS |
| **Disposal** (consumables) | Appendix 65 - WMR |
| **Disposal** (PPE – unserviceable) | Appendix 74 - IIRUP |
| **Disposal** (PPE – lost/damaged/etc.) | Appendix 75 - RLSDDP |
| **Disposal** (semi-expendable – unserviceable) | Annex A.10 - IIRUSP |
| **Disposal** (semi-expendable – lost/damaged/etc.) | Appendix 75 - RLSDDP |
| **Transfer** (PPE or semi-expendable) | Appendix 76 - PTR |
| **Transfer** (consumables) | No dedicated form; RSMI as stand-in if required |
| **Acquisition** | No 1:1 form; appears as receipt rows in ledger/stock/property cards |
| **Ledger/stock card for one item** (consumables) | Appendix 57 - SLC, Appendix 58 - SC |
| **Property card for one item** (PPE / semi-expendable) | Appendix 69 - PC, Annex A.1 |
| **Physical count report** | Appendix 66 - RPCI, Appendix 73 - RPCPPE, Annex A.8 - RPCSP |

---

## 6. Config reference (`config/owwa_templates.php`)

- **consumables:** `issuance` → RSMI; `disposal` → WMR; `transfer` → no dedicated form (RSMI stand-in if needed).
- **ppe:** `issuance` → PAR; `disposal` → IIRUP or RLSDDP depending on reason; `transfer` → PTR.
- **semi_expendable:** `issuance` → ICS; `disposal` → IIRUSP or RLSDDP; `transfer` → PTR.

SLC, SC, Property Card, Annex A.1, Annex A.4, and physical count forms (RPCI, RPCPPE, RPCSP) are **item-level or periodic reports**, not tied to a single transaction type in the config.

---

## 7. File layout

Templates are under category folders:

- **consumables/** — Appendix 57, 58, 64, 65  
- **ppe/** — Appendix 66, 69, 71, 73, 74, 75, 76  
- **semi_expendable/** — Annex A.1, A.4, A.8, A.10; Appendix 59, 66, 75, 76; ICS.xlsx  

Both `.xls` and `.xlsx` are supported. Any names, prices, and item names in these files are **example/mock data only** and are overwritten by the system when generating exports or reports.
