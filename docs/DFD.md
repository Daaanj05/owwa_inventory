# OWWA Region IV-A Inventory Management System - Data Flow Diagrams

---

## DFD Level 0 - Context Diagram

The entire system is a single black box. This shows the system boundary, who interacts with it, and what data crosses in and out.

```mermaid
flowchart TD
    SA["System Admin"]
    SC["Supply Custodian"]
    UH["Unit Head"]
    EMP["Employee"]

    SYS(("
    OWWA Inventory
    Management System"))

    %% Supply Custodian ↔ System
    SC -->|"Transaction Details
    AI Analysis Request
    Report Request"| SYS

    SYS -->|"Stock Alerts
    Stock Levels
    AI Recommendations
    COA Reports"| SC

    %% Unit Head ↔ System
    UH -->|"Consolidated Requisition"| SYS
    SYS -->|"Requisition Status
    Stock Level View"| UH

    %% Employee ↔ System
    EMP -->|"Requisition Request"| SYS
    SYS -->|"Requisition Status"| EMP

    %% System Admin ↔ System
    SA -->|"User Management
    Item Categories
    Audit Log Requests"| SYS

    SYS -->|"User Accounts
    Item Master Data
    Audit Log Results"| SA

    classDef entity fill:#dbeafe,color:#1e3a8a,stroke:#3b82f6
    classDef process fill:#fef9c3,color:#713f12,stroke:#ca8a04

    class SA,SC,UH,EMP entity
    class SYS process
```

---

## DFD Level 1 - System Decomposition

The black box is cracked open into its major functional processes. To keep the diagram readable on a standard page, Level 1 is partitioned into two coordinated parts that together still represent a single, balanced Level 1 view.

### DFD Level 1 - Part A: Core Operations (Authentication, Stock, and Requisitions)

```mermaid
flowchart TD

    %% External entities
    SA["System Admin"]
    SC["Supply Custodian"]
    UH["Unit Head"]
    EMP["Employee"]

    %% Core operational processes
    P1("1.0 Authenticate User")
    P3("3.0 Process Requisitions")
    P2("2.0 Manage Stock")
    P4("4.0 Monitor Stock")

    %% Data stores and off-page connectors
    D2[("D2: Items")]
    D1[("D1: Users")]
    D4[("D4: Requisitions")]
    D3[("D3: Inventory Transactions")]
    B1(("B1"))
    B2(("B2"))

    %% Reference data
    D2 -->|"Item Details"| P3
    D2 -->|"Reorder Levels"| P4

    %% Authentication
    SA -->|"Login Credentials"| P1
    SC -->|"Login Credentials"| P1
    UH -->|"Login Credentials"| P1
    EMP -->|"Login Credentials"| P1
    P1 -->|"Verify User"| D1

    %% Stock and requisitions
    SC -->|"Transaction Details"| P2
    UH -->|"Consolidated Requisition"| P3
    EMP -->|"Requisition Request"| P3

    P3 -->|"Requisition Records"| D4
    P3 -->|"Approved Requisition"| P2
    P2 -->|"Inventory Transactions"| D3
    P2 -->|"Triggers"| P4
    P4 -->|"Reads"| D3

    %% Outputs to external entities
    P4 -->|"Stock Alerts"| SC
    P4 -->|"Stock Level View"| UH
    P3 -->|"Status"| UH
    P3 -->|"Status"| EMP

    %% Off-page connectors indicating data used in Part B
    D3 --> B1
    D4 --> B2

    classDef entity fill:#dbeafe,color:#1e3a8a,stroke:#3b82f6
    classDef process fill:#fef9c3,color:#713f12,stroke:#ca8a04
    classDef store fill:#dcfce7,color:#14532d,stroke:#16a34a
    classDef connector fill:#f1f5f9,color:#1e293b,stroke:#64748b

    class SA,SC,UH,EMP entity
    class P1,P2,P3,P4 process
    class D1,D2,D3,D4 store
    class B1,B2 connector
```

### DFD Level 1 - Part B: Analytics and Reporting

```mermaid
flowchart LR

    %% External entities
    SC["Supply Custodian"]
    SA["System Admin"]

    %% Off-page connectors from Part A
    B1(("B1"))
    B2(("B2"))

    %% Analytics, reporting, and admin processes
    P5("5.0 AI Analysis")
    P6("6.0 Generate Reports")
    P7("7.0 Admin Configuration & Audit")

    %% Data stores
    D3[("D3: Inventory Transactions")]
    D4[("D4: Requisitions")]
    D5[("D5: AI Runs")]
    D6[("D6: User Logs")]

    %% Inputs from Supply Custodian
    SC -->|"AI Request"| P5
    SC -->|"Report Request"| P6

    %% Inputs from System Admin
    SA -->|"User Management
    Item Categories"| P7
    SA -->|"Audit Log Request"| P7

    %% Data used for analytics and reports
    B1 -->|"Inventory Transactions"| D3
    B2 -->|"Requisition Records"| D4

    P5 -->|"Reads"| D3
    P5 -->|"AI Run Records"| D5
    P6 -->|"Reads"| D3
    P6 -->|"Reads"| D4

    %% Data used for admin and audit
    P7 -->|"Reads / Writes"| D6

    %% Outputs back to external entities
    P5 -->|"Recommendations"| SC
    P6 -->|"COA Reports"| SC
    P7 -->|"Audit Log Results"| SA

    classDef entity fill:#dbeafe,color:#1e3a8a,stroke:#3b82f6
    classDef process fill:#fef9c3,color:#713f12,stroke:#ca8a04
    classDef store fill:#dcfce7,color:#14532d,stroke:#16a34a
    classDef connector fill:#f1f5f9,color:#1e293b,stroke:#64748b

    class SC,SA entity
    class P5,P6,P7 process
    class D3,D4,D5,D6 store
    class B1,B2 connector
```

---

## Balancing Check

Every data flow in Level 0 maps to a process in Level 1:

| Level 0 Data Flow        | Direction        | Level 1 Process                  |
| ------------------------ | ---------------- | -------------------------------- |
| Transaction Details      | SC → System      | P2: Manage Stock                 |
| AI Analysis Request      | SC → System      | P5: AI Analysis                  |
| Report Request           | SC → System      | P6: Generate Reports             |
| Consolidated Requisition | UH → System      | P3: Process Requisitions         |
| Requisition Request      | EMP → System     | P3: Process Requisitions         |
| Stock Alerts             | System → SC      | P4: Monitor Stock                |
| Stock Levels             | System → SC, UH  | P4: Monitor Stock                |
| AI Recommendations       | System → SC      | P5: AI Analysis                  |
| COA Reports              | System → SC      | P6: Generate Reports             |
| Requisition Status       | System → UH, EMP | P3: Process Requisitions         |
| User Management          | SA → System      | P7: Admin Configuration & Audit  |
| Item Categories          | SA → System      | P7: Admin Configuration & Audit  |
| Audit Log Requests       | SA → System      | P7: Admin Configuration & Audit  |
| User Accounts            | System → SA      | P7: Admin Configuration & Audit  |
| Item Master Data         | System → SA      | P7: Admin Configuration & Audit  |
| Audit Log Results        | System → SA      | P7: Admin Configuration & Audit  |

> **Note:** P1 (Authenticate User) is an internal security process not shown at Level 0. It is implicit — all actors must log in before any flow in Level 0 is possible.
