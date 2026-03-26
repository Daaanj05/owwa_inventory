-- OWWA Region IV-A Inventory Management System
-- Database schema DDL for drawsql.app import

-- Note: Types are generic (MySQL-style). Adjust as needed for your RDBMS.

CREATE TABLE offices (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(255) NOT NULL,
    code         VARCHAR(50)  NOT NULL UNIQUE,
    is_satellite BOOLEAN      NOT NULL DEFAULT FALSE,
    address      VARCHAR(255),
    created_at   DATETIME,
    updated_at   DATETIME
);

CREATE TABLE departments (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    office_id  INT         NOT NULL,
    name       VARCHAR(255) NOT NULL,
    code       VARCHAR(50),
    created_at DATETIME,
    updated_at DATETIME,
    CONSTRAINT fk_departments_office
        FOREIGN KEY (office_id) REFERENCES offices(id)
);

CREATE TABLE item_categories (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(255) NOT NULL,
    description TEXT,
    created_at  DATETIME,
    updated_at  DATETIME
);

CREATE TABLE items (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    item_category_id INT         NOT NULL,
    name             VARCHAR(255) NOT NULL,
    unit             VARCHAR(50)  NOT NULL,
    reorder_level    INT          NOT NULL DEFAULT 0,
    created_at       DATETIME,
    updated_at       DATETIME,
    CONSTRAINT fk_items_category
        FOREIGN KEY (item_category_id) REFERENCES item_categories(id)
);

CREATE TABLE users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(255) NOT NULL,
    email         VARCHAR(255) NOT NULL UNIQUE,
    password      VARCHAR(255) NOT NULL,
    role          VARCHAR(50)  NOT NULL, -- supply_custodian, unit_head, employee
    office_id     INT          NOT NULL,
    department_id INT          NULL,
    created_at    DATETIME,
    updated_at    DATETIME,
    CONSTRAINT fk_users_office
        FOREIGN KEY (office_id) REFERENCES offices(id),
    CONSTRAINT fk_users_department
        FOREIGN KEY (department_id) REFERENCES departments(id)
);

CREATE TABLE requisitions (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    reference_code VARCHAR(100) NOT NULL UNIQUE,
    office_id      INT          NOT NULL,
    department_id  INT          NULL,
    requested_by   INT          NOT NULL,
    approved_by    INT          NULL,
    status         VARCHAR(50)  NOT NULL, -- pending, approved, rejected, fulfilled, etc.
    created_at     DATETIME,
    updated_at     DATETIME,
    CONSTRAINT fk_requisitions_office
        FOREIGN KEY (office_id) REFERENCES offices(id),
    CONSTRAINT fk_requisitions_department
        FOREIGN KEY (department_id) REFERENCES departments(id),
    CONSTRAINT fk_requisitions_requested_by
        FOREIGN KEY (requested_by) REFERENCES users(id),
    CONSTRAINT fk_requisitions_approved_by
        FOREIGN KEY (approved_by) REFERENCES users(id)
);

CREATE TABLE requisition_items (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    requisition_id INT NOT NULL,
    item_id        INT NOT NULL,
    quantity       INT NOT NULL,
    created_at     DATETIME,
    updated_at     DATETIME,
    CONSTRAINT fk_req_items_requisition
        FOREIGN KEY (requisition_id) REFERENCES requisitions(id),
    CONSTRAINT fk_req_items_item
        FOREIGN KEY (item_id) REFERENCES items(id)
);

CREATE TABLE acquisitions (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    reference_code VARCHAR(100) NOT NULL UNIQUE,
    item_id        INT          NOT NULL,
    office_id      INT          NOT NULL,
    quantity       INT          NOT NULL,
    unit_cost      DECIMAL(10,2) NOT NULL,
    acquired_at    DATETIME,
    recorded_by    INT          NOT NULL,
    created_at     DATETIME,
    updated_at     DATETIME,
    CONSTRAINT fk_acquisitions_item
        FOREIGN KEY (item_id) REFERENCES items(id),
    CONSTRAINT fk_acquisitions_office
        FOREIGN KEY (office_id) REFERENCES offices(id),
    CONSTRAINT fk_acquisitions_user
        FOREIGN KEY (recorded_by) REFERENCES users(id)
);

CREATE TABLE transfers (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    reference_code VARCHAR(100) NOT NULL UNIQUE,
    item_id        INT          NOT NULL,
    from_office_id INT          NOT NULL,
    to_office_id   INT          NOT NULL,
    quantity       INT          NOT NULL,
    transferred_at DATETIME,
    recorded_by    INT          NOT NULL,
    created_at     DATETIME,
    updated_at     DATETIME,
    CONSTRAINT fk_transfers_item
        FOREIGN KEY (item_id) REFERENCES items(id),
    CONSTRAINT fk_transfers_from_office
        FOREIGN KEY (from_office_id) REFERENCES offices(id),
    CONSTRAINT fk_transfers_to_office
        FOREIGN KEY (to_office_id) REFERENCES offices(id),
    CONSTRAINT fk_transfers_user
        FOREIGN KEY (recorded_by) REFERENCES users(id)
);

CREATE TABLE disposals (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    reference_code VARCHAR(100) NOT NULL UNIQUE,
    item_id        INT          NOT NULL,
    office_id      INT          NOT NULL,
    quantity       INT          NOT NULL,
    reason         VARCHAR(255),
    disposed_at    DATETIME,
    recorded_by    INT          NOT NULL,
    created_at     DATETIME,
    updated_at     DATETIME,
    CONSTRAINT fk_disposals_item
        FOREIGN KEY (item_id) REFERENCES items(id),
    CONSTRAINT fk_disposals_office
        FOREIGN KEY (office_id) REFERENCES offices(id),
    CONSTRAINT fk_disposals_user
        FOREIGN KEY (recorded_by) REFERENCES users(id)
);

CREATE TABLE issuances (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    reference_code VARCHAR(100) NOT NULL UNIQUE,
    requisition_id INT          NULL,
    item_id        INT          NOT NULL,
    office_id      INT          NOT NULL,
    department_id  INT          NULL,
    quantity       INT          NOT NULL,
    issued_by      INT          NOT NULL,
    issued_to      INT          NOT NULL,
    issued_at      DATETIME,
    created_at     DATETIME,
    updated_at     DATETIME,
    CONSTRAINT fk_issuances_requisition
        FOREIGN KEY (requisition_id) REFERENCES requisitions(id),
    CONSTRAINT fk_issuances_item
        FOREIGN KEY (item_id) REFERENCES items(id),
    CONSTRAINT fk_issuances_office
        FOREIGN KEY (office_id) REFERENCES offices(id),
    CONSTRAINT fk_issuances_department
        FOREIGN KEY (department_id) REFERENCES departments(id),
    CONSTRAINT fk_issuances_issued_by
        FOREIGN KEY (issued_by) REFERENCES users(id),
    CONSTRAINT fk_issuances_issued_to
        FOREIGN KEY (issued_to) REFERENCES users(id)
);

CREATE TABLE ai_procurement_runs (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    period_from DATE        NOT NULL,
    period_to   DATE        NOT NULL,
    status      VARCHAR(50) NOT NULL, -- draft, completed, archived, etc.
    created_by  INT         NOT NULL,
    created_at  DATETIME,
    updated_at  DATETIME,
    CONSTRAINT fk_ai_runs_user
        FOREIGN KEY (created_by) REFERENCES users(id)
);

CREATE TABLE ai_procurement_items (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    run_id            INT NOT NULL,
    item_id           INT NOT NULL,
    office_id         INT NOT NULL,
    suggested_qty_min INT NOT NULL,
    suggested_qty_max INT NOT NULL,
    priority          VARCHAR(50),
    reason            TEXT,
    created_at        DATETIME,
    updated_at        DATETIME,
    CONSTRAINT fk_ai_items_run
        FOREIGN KEY (run_id) REFERENCES ai_procurement_runs(id),
    CONSTRAINT fk_ai_items_item
        FOREIGN KEY (item_id) REFERENCES items(id),
    CONSTRAINT fk_ai_items_office
        FOREIGN KEY (office_id) REFERENCES offices(id)
);

CREATE TABLE fiscal_years (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(100) NOT NULL, -- e.g., FY 2025
    start_date DATE         NOT NULL,
    end_date   DATE         NOT NULL,
    is_default BOOLEAN      NOT NULL DEFAULT FALSE,
    created_at DATETIME,
    updated_at DATETIME
);

CREATE TABLE rag_embeddings (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    source     VARCHAR(255),
    content    TEXT,
    metadata   TEXT,
    embedding  TEXT,
    created_at DATETIME,
    updated_at DATETIME
);

