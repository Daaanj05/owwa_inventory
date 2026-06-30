# OWWA Region IV-A Inventory Management System — Entity-Relationship Diagram (DBML)

This ERD is written in **DBML** (dbdiagram.io format) using `Table {}` blocks and `Ref:` relationship lines. It reflects the current migrations, including the `distributions` table. Setup tables (`offices`, `departments`, `items`) are global—not scoped by fiscal year.

---

```dbml
// Diagram 1 — Setup

Table offices {
  id           int       [pk, increment]
  name         varchar
  code         varchar
  is_satellite boolean
  address      text
  archived_at  datetime
  created_at   datetime
  updated_at   datetime
}

Table departments {
  id            int       [pk, increment]
  office_id     int
  name          varchar
  code          varchar
  archived_at   datetime
  created_at    datetime
  updated_at    datetime
}

Table item_categories {
  id          int      [pk, increment]
  name        varchar
  description varchar
  created_at  datetime
  updated_at  datetime
}

Table items {
  id               int       [pk, increment]
  item_category_id int
  name             varchar
  unit             varchar
  item_code        varchar
  value_type       varchar
  reorder_level    int
  description      text
  archived_at      datetime
  created_at       datetime
  updated_at       datetime
}

Ref: departments.office_id > offices.id
Ref: items.item_category_id > item_categories.id
```

---

```dbml
// Diagram 2 — Requisitions, Transactions, and Distributions
// Note: This diagram is designed to be exported on its own, so it includes minimal stubs
// for referenced setup tables (offices, departments, items).

Table offices { id int [pk, increment] }
Table departments { id int [pk, increment] }
Table items { id int [pk, increment] }

Table users {
  id            int       [pk, increment]
  name          varchar
  email         varchar   [unique]
  password      varchar
  role          varchar
  office_id     int
  department_id int
  created_at    datetime
  updated_at    datetime
}

Table requisitions {
  id             int       [pk, increment]
  reference_code varchar   [unique]
  office_id      int
  department_id  int
  requested_by   int
  status         varchar
  remarks        text
  approved_by    int
  approved_at    datetime
  created_at     datetime
  updated_at     datetime
}

Table requisition_items {
  id             int      [pk, increment]
  requisition_id int
  item_id        int
  quantity       int
  remarks        text
  created_at     datetime
  updated_at     datetime
}

Table acquisitions {
  id                           int       [pk, increment]
  reference_code               varchar   [unique]
  acquisition_paperwork_id     int
  acquisition_paperwork_line_id int
  item_id                      int
  office_id                    int
  quantity                     int
  unit_cost                    decimal
  acquisition_date             date
  source                       varchar
  remarks                      text
  recorded_by                  int
  created_at                   datetime
  updated_at                   datetime
}

Table acquisition_paperwork {
  id                      int       [pk, increment]
  reference_code          varchar   [unique]
  item_category_id        int
  office_id               int
  department_id           int
  recorded_by             int
  phase                   varchar
  pr_status               varchar
  po_status               varchar
  iar_status              varchar
  pr_number               varchar
  po_number               varchar
  iar_number              varchar
  pr_date                 date
  po_date                 date
  iar_date                date
  pr_submitted_at         datetime
  po_submitted_at         datetime
  iar_submitted_at        datetime
  purpose                 text
  supplier                varchar
  requested_by_name       varchar
  approved_by_name        varchar
  inspection_officer_name varchar
  custodian_name          varchar
  po_data                 json
  iar_data                json
  remarks                 text
  pr_completed_at         datetime
  po_completed_at         datetime
  iar_completed_at        datetime
  received_at             datetime
  created_at              datetime
  updated_at              datetime
}

Table acquisition_paperwork_lines {
  id                       int      [pk, increment]
  acquisition_paperwork_id int
  item_id                  int
  description              varchar
  unit                     varchar
  quantity                 int
  unit_cost                decimal
  amount                   decimal
  line_remarks             text
  created_at               datetime
  updated_at               datetime
}

Table issuances {
  id             int       [pk, increment]
  reference_code varchar   [unique]
  item_id        int
  office_id      int
  department_id  int
  requisition_id int
  quantity       int
  issuance_date  date
  remarks        text
  issued_by      int
  issued_to      int
  created_at     datetime
  updated_at     datetime
}

Table transfers {
  id             int       [pk, increment]
  reference_code varchar   [unique]
  item_id        int
  from_office_id int
  to_office_id   int
  quantity       int
  transfer_date  date
  remarks        text
  recorded_by    int
  created_at     datetime
  updated_at     datetime
}

Table disposals {
  id             int       [pk, increment]
  reference_code varchar   [unique]
  item_id        int
  office_id      int
  quantity       int
  disposal_date  date
  reason         varchar
  remarks        text
  recorded_by    int
  created_at     datetime
  updated_at     datetime
}

Table distributions {
  id               int       [pk, increment]
  office_id         int
  department_id     int
  requisition_id    int
  item_id           int
  quantity          int
  distributed_to    int
  distributed_by    int
  distribution_date date
  remarks           text
  created_at        datetime
  updated_at        datetime
}

Ref: users.office_id > offices.id
Ref: users.department_id > departments.id

Ref: requisitions.office_id > offices.id
Ref: requisitions.department_id > departments.id
Ref: requisitions.requested_by > users.id
Ref: requisitions.approved_by > users.id

Ref: requisition_items.requisition_id > requisitions.id
Ref: requisition_items.item_id > items.id

Ref: acquisitions.item_id > items.id
Ref: acquisitions.office_id > offices.id
Ref: acquisitions.recorded_by > users.id
Ref: acquisitions.acquisition_paperwork_id > acquisition_paperwork.id
Ref: acquisitions.acquisition_paperwork_line_id > acquisition_paperwork_lines.id
Ref: acquisition_paperwork.item_category_id > item_categories.id
Ref: acquisition_paperwork.office_id > offices.id
Ref: acquisition_paperwork.department_id > departments.id
Ref: acquisition_paperwork.recorded_by > users.id
Ref: acquisition_paperwork_lines.acquisition_paperwork_id > acquisition_paperwork.id
Ref: acquisition_paperwork_lines.item_id > items.id

Ref: issuances.item_id > items.id
Ref: issuances.office_id > offices.id
Ref: issuances.department_id > departments.id
Ref: issuances.requisition_id > requisitions.id
Ref: issuances.issued_by > users.id
Ref: issuances.issued_to > users.id

Ref: transfers.item_id > items.id
Ref: transfers.from_office_id > offices.id
Ref: transfers.to_office_id > offices.id
Ref: transfers.recorded_by > users.id

Ref: disposals.item_id > items.id
Ref: disposals.office_id > offices.id
Ref: disposals.recorded_by > users.id

Ref: distributions.office_id > offices.id
Ref: distributions.department_id > departments.id
Ref: distributions.requisition_id > requisitions.id
Ref: distributions.item_id > items.id
Ref: distributions.distributed_to > users.id
Ref: distributions.distributed_by > users.id
```

---

```dbml
// Diagram 3 — AI, Admin & Audit
// Note: This diagram is designed to be exported on its own, so it includes minimal stubs
// for referenced tables (users, items, offices).

Table users { id int [pk, increment] }
Table items { id int [pk, increment] }
Table offices { id int [pk, increment] }

Table ai_procurement_runs {
  id           int       [pk, increment]
  ran_at       datetime
  period_from  date
  period_to    date
  summary      varchar
  raw_response text
  status       varchar
  created_by   int
  deleted_at   datetime
  created_at   datetime
  updated_at   datetime
}

Table ai_procurement_items {
  id                 int       [pk, increment]
  run_id             int
  section            varchar
  priority           varchar
  item_name          varchar
  item_id            int
  office_name        varchar
  office_id          int
  current_stock      int
  avg_monthly_usage  decimal
  months_cover       decimal
  suggested_qty_min  int
  suggested_qty_max  int
  reason             text
  include_in_request boolean
  created_at         datetime
  updated_at         datetime
}

Table user_logs {
  id               int       [pk, increment]
  user_id          int
  ip_address       varchar
  user_agent       varchar
  path             varchar
  panel            varchar
  logged_in_at     datetime
  logged_out_at    datetime
  logout_reason    varchar
  last_activity_at datetime
  session_id       varchar
  archived_at      datetime
  created_at       datetime
  updated_at       datetime
}

Table user_activity_logs {
  id           int       [pk, increment]
  user_id      int
  user_log_id  int
  action       varchar
  subject_type varchar
  subject_id   int
  summary      varchar
  properties   text
  ip_address   varchar
  panel        varchar
  archived_at  datetime
  created_at   datetime
  updated_at   datetime
}

Table rag_embeddings {
  id         int       [pk, increment]
  source     varchar
  content    text
  metadata   text
  embedding  text
  created_at datetime
  updated_at datetime
}

Ref: ai_procurement_runs.created_by > users.id
Ref: ai_procurement_items.run_id > ai_procurement_runs.id
Ref: ai_procurement_items.item_id > items.id
Ref: ai_procurement_items.office_id > offices.id
Ref: user_logs.user_id > users.id
Ref: user_activity_logs.user_id > users.id
Ref: user_activity_logs.user_log_id > user_logs.id
```
