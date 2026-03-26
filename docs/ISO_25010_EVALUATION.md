# ISO 25010 System Quality Evaluation – OWWA Inventory System

This document supports the evaluation of the Web-Based Data-Driven Inventory System with Procurement Analysis for OWWA IV-A against the ISO 25010 quality model, as required by Research Objective 5.

## 1. Functional Suitability

**Definition**: Degree to which the product provides functions that meet stated and implied needs.

| Criterion | How to evaluate | Evidence / notes |
|-----------|-----------------|-------------------|
| **Functional completeness** | All Chapter 1 features implemented (centralized repository, reference codes, requisition workflow, analytics dashboard, COA reports, low-stock alerts, procurement recommendations). | Checklist: Offices, Departments, Item categories, Items, Acquisitions, Issuances, Transfers, Disposals, Requisitions with items, Dashboard widgets, COA PDF reports, RAG recommendations page. |
| **Functional correctness** | System produces correct results (e.g. stock = acquisitions + transfers in − issuances − transfers out − disposals). | Unit/feature tests for `InventoryStockService::getStock()`; manual verification of reference code uniqueness and requisition approval flow. |
| **Functional appropriateness** | Functions are suitable for the task (e.g. role-based access, approval actions). | Supply Custodian vs Employee roles; Approve/Reject actions on requisitions; Filament panels and navigation groups. |

**Testing**: Run `php artisan test`; add/run tests for stock calculation and reference code generation. Manually verify each menu and workflow.

---

## 2. Performance Efficiency

**Definition**: Performance relative to the amount of resources used under stated conditions.

| Criterion | How to evaluate | Evidence / notes |
|-----------|------------------|------------------|
| **Time behaviour** | Response time for key operations (list items, run report, generate recommendation). | Measure with browser DevTools or Laravel Telescope; target: list pages &lt; 2 s, COA PDF &lt; 5 s, RAG (if Ollama local) &lt; 30 s. |
| **Resource utilization** | CPU/memory usage under normal load. | Monitor server during typical usage; database indexing on `reference_code`, `item_id`, `office_id`, `issuance_date`, etc. |
| **Capacity** | System handles expected number of users and records. | Load test with multiple offices and items; ensure migrations have indexes on foreign keys and frequently filtered columns. |

**Testing**: Add indexes if missing; run `php artisan migrate`; use Laravel Telescope or logging to identify slow queries.

---

## 3. Security

**Definition**: Degree to which the product protects information and data so that persons or other products have the degree of access appropriate to their types and levels of authorization.

| Criterion | How to evaluate | Evidence / notes |
|-----------|------------------|------------------|
| **Confidentiality** | Only authorized users access data; roles enforced. | Laravel auth + Filament `canAccessPanel()`; role-based visibility (e.g. Approve/Reject only for Supply Custodian). |
| **Integrity** | Data not altered by unauthorized parties. | CSRF protection (Laravel/Filament); validated inputs; foreign keys and transactions for inventory updates. |
| **Non-repudiation** | Actions can be traced. | `recorded_by`, `issued_by`, `approved_by` and timestamps on transactions; consider audit log for sensitive actions. |
| **Accountability** | Users are identified for their actions. | Auth required for all admin routes; session and login logging. |

**Testing**: Verify unauthenticated access to `/admin` redirects to login; verify employee cannot see Approve/Reject; run `php artisan route:list` and confirm auth middleware on report and admin routes.

---

## 4. Usability

**Definition**: Degree to which the product can be used by specified users to achieve specified goals with effectiveness, efficiency, and satisfaction.

| Criterion | How to evaluate | Evidence / notes |
|-----------|------------------|------------------|
| **Appropriateness recognizability** | Users recognize that the product is appropriate for their tasks. | Clear navigation groups (Setup, Inventory, Requisitions, Analytics); labels and icons; COA and Procurement recommendations pages. |
| **Learnability** | Users can learn to use the product. | Consistent Filament UI; forms with placeholders and validation messages; help text on COA and RAG pages. |
| **Operability** | User can operate the product with minimal error. | Required fields and validation; confirmation for Approve/Reject; filters and search on tables. |
| **User error protection** | System prevents or recovers from user errors. | Validation on forms; unique reference codes; confirmation dialogs for destructive or critical actions. |
| **User interface aesthetics** | UI is clear and consistent. | Filament default theme; navigation groups and icons; responsive layout. |

**Testing**: Conduct a short user test with Supply Custodian and Employee personas; complete flows: add office → add item → record acquisition → create requisition → approve → issue; download COA report and generate recommendation.

---

## 5. Reliability

**Definition**: Degree to which the system performs specified functions under specified conditions for a specified period of time.

| Criterion | How to evaluate | Evidence / notes |
|-----------|------------------|------------------|
| **Maturity** | No defects that cause failure. | Automated tests for critical logic; manual regression after changes. |
| **Availability** | System is available when needed. | Deploy on a stable server; use Cloudflare Tunnel or reverse proxy; database backups. |
| **Fault tolerance** | Degrades gracefully (e.g. Ollama down). | RAG page shows a clear message when Ollama is unavailable; no crash. |
| **Recoverability** | Data can be recovered after failure. | Regular database backups; migrations and seeders for rebuild. |

**Testing**: Run test suite; intentionally stop Ollama and open Procurement recommendations page; verify error message. Document backup and restore procedure.

---

## Summary checklist for thesis

- [ ] **5.1 Functional suitability**: Checklist of features implemented; test results for stock calculation and reference codes.
- [ ] **5.2 Performance efficiency**: Measured response times and resource usage; list of indexes.
- [ ] **5.3 Security**: Auth and role checks; list of protected routes and role-based rules.
- [ ] **5.4 Usability**: Short user test results and screenshots of main flows.
- [ ] **5.5 Reliability**: Test results; availability/fault-tolerance notes; backup procedure.

Use this document as the basis for the “Evaluation” section of your thesis and attach or reference test outputs and screenshots as evidence.
