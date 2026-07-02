# OWWA Forms Used in the System

This list shows **which OWWA form is used for which transaction** in the inventory system, with the **full official form name**.

---

## Issuance (release of supplies/materials to a person or department)

| Item category    | Form used in system | Full OWWA form name |
|------------------|---------------------|----------------------|
| **Consumables**  | RSMI                | **Appendix 64 – Report of Supplies and Materials Issued (RSMI)** |
| **PPE / Safety** | PAR                 | **Appendix 71 – Property Acknowledgment Receipt (PAR)** |
| **Semi-expendable** | ICS             | **Appendix 59 – Inventory Custodian Slip (ICS)** |

The system picks the form automatically based on the **item category**. When you create an issuance and export or print, the correct form (RSMI, PAR, or ICS) is used.

---

## Transfer (moving property between offices)

| Item category    | Form used in system | Full OWWA form name |
|------------------|---------------------|----------------------|
| **Consumables**  | RSMI (stand-in)    | **Appendix 64 – Report of Supplies and Materials Issued (RSMI)** *(used as transfer record)* |
| **PPE / Safety** | PTR                 | **Appendix 76 – Property Transfer Report (PTR)** |
| **Semi-expendable** | PTR              | **Appendix 76 – Property Transfer Report (PTR)** |

For PPE and semi-expendable items, the official form is **Appendix 76 – PTR**. For consumables, the system uses RSMI as a stand-in for transfer.

---

## Disposal (removal or write-off of property)

| Disposal type / Item category | Form used in system | Full OWWA form name |
|-------------------------------|---------------------|----------------------|
| **Waste or sale** (e.g. consumables) | WMR   | **Appendix 65 – Waste Materials Report (WMR)** |
| **Unserviceable** (PPE)       | IIRUP               | **Appendix 74 – Inventory and Inspection Report of Unserviceable Property (IIRUP)** |
| **Unserviceable** (semi-expendable) | IIRUSP | **Annex A.10 – Inventory and Inspection Report of Unserviceable Semi-Expendable Property (IIRUSP)** |
| **Lost, stolen, damaged or destroyed** (any category) | RLSDDP | **Appendix 75 – Report on the Lost, Stolen, Damaged or Destroyed Property (RLSDDP)** |

When you create a disposal, you choose the **disposal type**. The system then uses the correct form (WMR, IIRUP, IIRUSP, or RLSDDP) when you export or print.

---

## Other references in the system

| System module   | Reference number label | OWWA form (if any) |
|----------------|------------------------|---------------------|
| **Requisition**| Requisition No.        | Internal; not a specific OWWA appendix. |
| **Acquisition (consumables)** | Reference / Receipt | Appendix 58 – Stock Card (receipt line) |
| **Acquisition (PPE)** | Reference / Receipt | Appendix 69 – Property Card (**receipt line** on view; **full card** on Stock levels) |
| **Acquisition (semi-expendable)** | Reference / Receipt | Annex A.1 – Semi-Expendable Property Card (receipt line) |

---

## Summary table: Transaction → Form(s) → Full name

| Transaction | Form code(s) | Full form name(s) |
|-------------|--------------|-------------------|
| **Issuance – Consumables** | RSMI | Appendix 64 – Report of Supplies and Materials Issued (RSMI) |
| **Issuance – PPE** | PAR | Appendix 71 – Property Acknowledgment Receipt (PAR) |
| **Issuance – Semi-expendable** | ICS | Appendix 59 – Inventory Custodian Slip (ICS) |
| **Transfer – Consumables** | RSMI (stand-in) | Appendix 64 – Report of Supplies and Materials Issued (RSMI) |
| **Transfer – PPE / Semi-expendable** | PTR | Appendix 76 – Property Transfer Report (PTR) |
| **Disposal – Waste/Sale** | WMR | Appendix 65 – Waste Materials Report (WMR) |
| **Disposal – Unserviceable (PPE)** | IIRUP | Appendix 74 – Inventory and Inspection Report of Unserviceable Property (IIRUP) |
| **Disposal – Unserviceable (Semi-expendable)** | IIRUSP | Annex A.10 – Inventory and Inspection Report of Unserviceable Semi-Expendable Property (IIRUSP) |
| **Disposal – Lost/Stolen/Damaged** | RLSDDP | Appendix 75 – Report on the Lost, Stolen, Damaged or Destroyed Property (RLSDDP) |
| **Stock levels – PPE** | PC | Appendix 69 – Property Card (full ledger export) |
| **Stock levels – Semi-expendable** | Annex A.1 | Semi-Expendable Property Card (full ledger export) |
