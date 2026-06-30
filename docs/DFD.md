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
    %% Requisitions (submit -> review/decision -> fulfillment)
    P3a("3.1 Submit Requisition Records")
    P3b("3.2 Review and Decide Requisition")
    P3c("3.3 Fulfill Approved Requisition")

    %% Stock transactions (separate transaction types)
    P2a("2.1 Record Acquisition")
    P2b("2.2 Record Issuance")
    P2c("2.3 Record Transfer")
    P2d("2.4 Record Disposal")
    P4("4.0 Monitor Stock")

    %% Data stores and off-page connectors
    D2[("D2: Items")]
    D1[("D1: Users")]
    D4[("D4: Requisitions")]
    D3[("D3: Inventory Transactions")]
    B1(("B1"))
    B2(("B2"))

    %% Reference data
    D2 -->|"Item Details"| P3a
    D2 -->|"Reorder Levels"| P4

    %% Authentication
    SA -->|"Login Credentials"| P1
    SC -->|"Login Credentials"| P1
    UH -->|"Login Credentials"| P1
    EMP -->|"Login Credentials"| P1
    P1 -->|"Verify User"| D1

    %% Stock and requisitions
    SC -->|"Acquisition Details"| P2a
    SC -->|"Issuance Details"| P2b
    SC -->|"Transfer Details"| P2c
    SC -->|"Disposal Details"| P2d

    UH -->|"Consolidated Requisition"| P3a
    EMP -->|"Requisition Request"| P3a

    P3a -->|"Requisition Records"| D4

    D4 -->|"Pending Requisitions"| P3b
    P3b -->|"Status (Approved/Rejected)"| D4
    P3b -->|"Approved Requisition"| P3c

    P3c -->|"Fulfillment Issuance Details"| P2b

    P2a -->|"Inventory Transactions"| D3
    P2a -->|"Triggers"| P4

    P2b -->|"Inventory Transactions"| D3
    P2b -->|"Triggers"| P4

    P2c -->|"Inventory Transactions"| D3
    P2c -->|"Triggers"| P4

    P2d -->|"Inventory Transactions"| D3
    P2d -->|"Triggers"| P4
    P4 -->|"Reads"| D3

    %% Outputs to external entities
    P4 -->|"Stock Alerts"| SC
    P4 -->|"Stock Level View"| UH

    P3b -->|"Status (Approved/Rejected)"| UH
    P3b -->|"Status (Approved/Rejected)"| EMP
    P3c -->|"Status (Fulfilled)"| UH
    P3c -->|"Status (Fulfilled)"| EMP

    %% Off-page connectors indicating data used in Part B
    D3 --> B1
    D4 --> B2

    classDef entity fill:#dbeafe,color:#1e3a8a,stroke:#3b82f6
    classDef process fill:#fef9c3,color:#713f12,stroke:#ca8a04
    classDef store fill:#dcfce7,color:#14532d,stroke:#16a34a
    classDef connector fill:#f1f5f9,color:#1e293b,stroke:#64748b

    class SA,SC,UH,EMP entity
    class P1,P3a,P3b,P3c,P2a,P2b,P2c,P2d,P4 process
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

    %% AI and analytics (deterministic analytics + AI recommendation + saving results)
    P5a("5.1 Compute Procurement Analytics")
    P5b("5.2 Generate AI Recommendation")
    P5c("5.3 Save AI Run Results")

    %% Reporting (COA-oriented exports)
    P6a("6.1 Prepare Report Request")
    P6b("6.2 Generate COA Report File")

    %% Admin and audit
    P7a("7.1 Manage User Accounts")
    P7b("7.2 Maintain Master Data Setup")
    P7c("7.3 View Audit Logs")

    %% Data stores
    D1[("D1: Users")]
    D2[("D2: Master Data")]
    D3[("D3: Inventory Transactions")]
    D4[("D4: Requisitions")]
    D5[("D5: AI Runs")]
    D6[("D6: User Logs")]

    %% Inputs from Supply Custodian
    SC -->|"AI Request"| P5a
    SC -->|"Report Request"| P6a

    %% Inputs from System Admin
    SA -->|"User Management"| P7a
    SA -->|"Master Data Setup"| P7b
    SA -->|"Audit Log Request"| P7c

    %% Data used for analytics and reports
    B1 -->|"Inventory Transactions"| D3
    B2 -->|"Requisition Records"| D4

    D3 -->|"Reads"| P5a
    D4 -->|"Reads"| P5a
    P5a -->|"Analytics Context"| P5b
    P5b -->|"AI Run Results"| P5c
    P5c -->|"AI Run Records"| D5

    D3 -->|"Reads"| P6b
    D4 -->|"Reads"| P6b
    P6a -->|"Validated Filters"| P6b

    %% Data used for admin and audit
    P7a -->|"Reads / Writes"| D1
    P7b -->|"Reads / Writes"| D2
    P7c -->|"Reads / Writes"| D6

    %% Outputs back to external entities
    P5b -->|"Recommendations"| SC
    P6b -->|"COA Reports"| SC
    P7a -->|"User Accounts"| SA
    P7b -->|"Master Data Updated"| SA
    P7c -->|"Audit Log Results"| SA

    classDef entity fill:#dbeafe,color:#1e3a8a,stroke:#3b82f6
    classDef process fill:#fef9c3,color:#713f12,stroke:#ca8a04
    classDef store fill:#dcfce7,color:#14532d,stroke:#16a34a
    classDef connector fill:#f1f5f9,color:#1e293b,stroke:#64748b

    class SC,SA entity
    class P5a,P5b,P5c,P6a,P6b,P7a,P7b,P7c process
    class D1,D2,D3,D4,D5,D6 store
    class B1,B2 connector
```

---

## Balancing Check

Every data flow in Level 0 maps to a process in Level 1:

| Level 0 Data Flow        | Direction        | Level 1 Process                  |
| ------------------------ | ---------------- | -------------------------------- |
| Transaction Details      | SC → System      | P2a–P2d: Record stock transactions |
| AI Analysis Request      | SC → System      | P5a–P5c: Analytics + AI recommendation |
| Report Request           | SC → System      | P6a–P6b: COA report generation    |
| Consolidated Requisition | UH → System      | P3a: Submit requisition records |
| Requisition Request      | EMP → System     | P3a: Submit requisition records |
| Stock Alerts             | System → SC      | P4: Monitor Stock                |
| Stock Levels             | System → SC, UH  | P4: Monitor Stock                |
| AI Recommendations       | System → SC      | P5b: Generate AI Recommendation  |
| COA Reports              | System → SC      | P6b: Generate COA Report File    |
| Requisition Status       | System → UH, EMP | P3b–P3c: Review/decide and fulfill |
| User Management          | SA → System      | P7a: Manage User Accounts        |
| Item Categories          | SA → System      | P7b: Maintain Master Data Setup  |
| Audit Log Requests       | SA → System      | P7c: View Audit Logs             |
| User Accounts            | System → SA      | P7a: Manage User Accounts        |
| Item Master Data         | System → SA      | P7b: Maintain Master Data Setup  |
| Audit Log Results        | System → SA      | P7c: View Audit Logs             |

> **Note:** P1 (Authenticate User) is an internal security process not shown at Level 0. It is implicit — all actors must log in before any flow in Level 0 is possible.
