# Plan: Reference numbers (RIS No., PAR No., PTR No., etc.) – mapping, relations, and format

This document maps OWWA form labels to the system’s reference codes, explains how they relate, and recommends who sets the format and how auto-increment should work.

---

## 1. OWWA form labels ↔ system field

The system uses a single **reference code** per transaction. On OWWA forms it appears under different labels:

| Transaction type | OWWA form(s) | Label on form | System field | Notes |
|------------------|--------------|----------------|--------------|--------|
| **Issuance** (consumables) | RSMI (Appendix 64) | **Serial No.** (header), **RIS No.** (table) | `issuances.reference_code` | One issuance = one RIS line; “Serial No.” can be same as report/serial. |
| **Issuance** (PPE) | PAR (Appendix 71) | **PAR No.** | `issuances.reference_code` | Property Acknowledgment Receipt number. |
| **Issuance** (semi-expendable) | ICS (Appendix 59) | **ICS No.** | `issuances.reference_code` | Inventory Custodian Slip number. |
| **Transfer** | PTR (Appendix 76) | **PTR No.** | `transfers.reference_code` | Property Transfer Report number. |
| **Disposal** (consumables) | WMR (Appendix 65) | (no explicit “WMR No.”) | `disposals.reference_code` | Used in export/print. |
| **Disposal** (PPE/semi, lost/damaged) | RLSDDP (Appendix 75) | **RLSDDP No.** | `disposals.reference_code` | Report of Lost, Stolen, Damaged or Destroyed Property. |
| **Requisition** | (internal / optional print) | **Requisition No.** / **REQ No.** | `requisitions.reference_code` | Not on standard OWWA forms; used in system and reports. |
| **Acquisition** | SLC, SC (as “Reference” in table) | **Reference** (in ledger/stock rows) | `acquisitions.reference_code` | Shown in Receipt column of SLC/SC. |

So: **one `reference_code` per record**; the same value is shown on the right form under the right label (RIS No., PAR No., ICS No., PTR No., RLSDDP No., etc.).

---

## 2. Relations and flow

```
Requisition (requisitions.reference_code)
    │
    └──► Issuance(s) (issuances.reference_code)  [one issuance per item/line; each has its own RIS/PAR/ICS No.]
              │
              ├──► Optional link: issuance.requisition_id → requisition.id
              └──► On RSMI: one row per issuance; A11 = issuance.reference_code (RIS No.)

Acquisition (acquisitions.reference_code)
    └──► Shown as “Reference” in SLC/SC receipt rows

Transfer (transfers.reference_code)
    └──► On PTR: H8 = transfer.reference_code (PTR No.)

Disposal (disposals.reference_code)
    └──► On WMR/RLSDDP: export uses disposal.reference_code (e.g. RLSDDP No. in form)
```

- **Requisition → Issuance:** One requisition can have many issuances (e.g. one per item). Each issuance has its **own** `reference_code` (its RIS No. / PAR No. / ICS No.). Optionally you can display or print the requisition’s `reference_code` as “Requisition No.” and keep issuance’s as “RIS No.” so the link is clear.
- **No cross-type series:** RIS, PAR, ICS, PTR, RLSDDP, REQ, ACQ are **separate series**. Each type has its own sequence (and optionally its own format). They do not share one global number.

---

## 3. Current behavior (for context)

- **Generation:** Observers call `ReferenceCodeService::forIssuance()`, `forTransfer()`, etc. when `reference_code` is empty on create.
- **Format:** `OWWA-{TYPE}-{Ymd}-{seq}` (e.g. `OWWA-ISS-20260307-0001`). Prefix and date/sequence logic are in code.
- **Sequence:** Per transaction type, per calendar day (sequence resets by day).

So today the “format” is fixed in code and the **system** auto-increments; no one “inputs” the format in the UI.

---

## 4. Who should set the format, and how

**Recommendation: system admin (or super admin) defines the format; supply custodian does not.**

Reasons:

- Reference patterns are usually **organization-wide** (e.g. “RIS-2026-0001”, “PTR-2026-001”) and should be consistent.
- Changing the pattern can affect reporting, exports, and auditing; that’s an administrative concern.
- Supply custodians should only **use** the numbers (create requisition/issuance/transfer/disposal and get the next number), not change how they are built.

So:

- **System admin** (or a dedicated “reference format” admin role):
  - Defines, per transaction type (and optionally per office/category), the **pattern** and **sequence rules**.
  - Pattern could be: prefix + optional year/month + sequence with padding (e.g. `RIS-{Y}-{seq:4}`, `PTR-{Y}-{seq:5}`).
- **System (application):**
  - When a new record is created and `reference_code` is empty, it **generates** the next code from the configured pattern and **increments** the sequence (e.g. in a `reference_sequences` table or config-backed counter).
- **Supply custodian:**
  - Creates the transaction; the system assigns the reference number automatically. Custodian can **see** and **copy** it (e.g. for OWWA forms) but does not type the format.

If you later need “custodian A uses prefix RIS-RO4A, custodian B uses RIS-RO4B”, that can be done by making the format configurable **per office** or **per user role**, still managed by admin, not by the custodian typing a free‑text format.

---

## 5. Suggested implementation (configurable format + auto-increment)

### 5.1 Where to store format and sequence

| Option | Who configures | Stored where | Pros | Cons |
|--------|----------------|-------------|------|------|
| **A. Config file** | Developer / deploy | `config/reference_codes.php` | Simple, no DB; good for one format per type. | Changing format requires deploy; no UI. |
| **B. DB table + Filament** | System admin | e.g. `reference_series` (type, prefix, pattern, next_number, reset_period) | Flexible; admin can change prefix/pattern and see next number. | More code; need to handle concurrency (lock when generating). |
| **C. Settings table / JSON** | System admin | e.g. `settings` key `reference_codes` | Single place; can be edited via Filament Settings. | Same concurrency need as B. |

**Recommendation:** Start with **A** (config) so format is explicit and easy to version. Add **B** or **C** later if admin must change formats without code deploy.

### 5.2 Pattern idea (for config or DB)

Per transaction type (and optionally office):

- **Prefix:** e.g. `RIS`, `PAR`, `PTR`, `RLSDDP`, `ICS`, `REQ`, `ACQ`.
- **Optional date part:** `{Y}`, `{Y-m}`, or `{Ymd}` (year, month, or day).
- **Sequence:** `{seq}` with optional padding, e.g. `{seq:4}` → 0001, 0002.
- **Example patterns:**
  - Issuance (RIS): `RIS-{Y}-{seq:4}` → RIS-2026-0001, RIS-2026-0002.
  - Transfer (PTR): `PTR-{Y}-{seq:4}` → PTR-2026-0001.
  - Disposal (RLSDDP): `RLSDDP-{Y}-{seq:4}`.
  - Requisition: `REQ-{Y}-{seq:4}`.
  - Acquisition: `ACQ-{Y}-{seq:4}`.

Sequence can be:

- **Global per type:** one counter per type (e.g. all RIS for the year).
- **Per type per year/month/day:** e.g. reset each year so you get RIS-2026-0001… RIS-2026-9999, then RIS-2027-0001.

Same logic can support PAR, ICS, PTR, RLSDDP, REQ, ACQ with their own prefixes and sequences.

### 5.3 Who configures (summary)

- **Format (pattern + prefix + padding):** **System admin** (via config now, or later via Filament/Settings).
- **Auto-increment:** **System** (application) using the configured pattern and stored next sequence.
- **Supply custodian:** Only **creates** the transaction; system assigns the number. Custodian does **not** input the format.

---

## 6. Optional: link requisition number to issuance (RIS)

- **Requisition** has `requisitions.reference_code` (e.g. REQ-2026-0001).
- **Issuance** has `issuances.reference_code` (RIS No. / PAR No. / ICS No.) and `issuances.requisition_id` (optional).
- On RSMI (or internal reports) you can show:
  - **RIS No.** = `issuance.reference_code`
  - **Requisition No.** (if needed) = `issuance.requisition->reference_code` when `requisition_id` is set.

No change to how reference codes are generated; only display/linking.

---

## 7. Summary

| Question | Answer |
|----------|--------|
| What is RIS No. / PAR No. / PTR No. / etc.? | Same as the transaction’s `reference_code`; the form label (RIS, PAR, PTR, ICS, RLSDDP) depends on transaction type and category. |
| How do they relate? | One reference per record; requisition → many issuances (each with its own ref); acquisitions/transfers/disposals each have one ref. |
| Who should input the format? | **System admin** (or super admin). Supply custodian does not; they only create records and get the next number. |
| Should it auto-increment? | **Yes.** System generates the next code from the configured pattern and increments the sequence (per type, and optionally per year/month). |

If you want to proceed, the next step is to define the exact pattern (e.g. in `config/reference_codes.php`) and update `ReferenceCodeService` to use it (and, if needed, a small table or config for “next number” and reset rules).
