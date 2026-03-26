# OWWA Region IV-A Inventory Management System - Process Workflow

---

## Supply Custodian Workflow

### Part 1 of 3 — Login and Task Selection

```mermaid
%%{init: {'theme': 'base'}}%%
flowchart TD
    START([Start]):::terminal

    START --> I1[/"Login<br/>Credentials"/]:::input
    I1 --> P1[Validate Credentials and Load Dashboard]:::process
    P1 --> O1[/Dashboard with Stock Alerts/]:::output
    O1 --> D1{Low stock alerts?}:::decide
    D1 -- Yes --> I2[/Items Flagged as Low Stock/]:::input
    I2 --> P2[Record Items for Restocking]:::process
    P2 --> O2[/Restocking List Prepared/]:::output
    O2 --> SEL
    D1 -- No --> SEL

    SEL[/Task Selection/]:::input
    SEL -- Acquisition --> CA[\A/]:::connector
    SEL -- Transfer --> CB[\B/]:::connector
    SEL -- Disposal --> CC[\C/]:::connector
    SEL -- Issuance --> CD[\D/]:::connector
    SEL -- Requisition --> CE[\E/]:::connector
    SEL -- AI Analysis --> CF[\F/]:::connector
    SEL -- Report --> CG[\G/]:::connector

    classDef terminal fill:#ede9fe,stroke:#7c3aed,color:#4c1d95
    classDef input fill:#dbeafe,stroke:#3b82f6,color:#1e3a8a
    classDef process fill:#fef9c3,stroke:#ca8a04,color:#713f12
    classDef output fill:#dcfce7,stroke:#16a34a,color:#14532d
    classDef decide fill:#ffedd5,stroke:#ea580c,color:#7c2d12
    classDef connector fill:#f1f5f9,stroke:#64748b,color:#1e293b
```

### Part 2 of 3 — Stock Transactions

```mermaid
%%{init: {'theme': 'base'}}%%
flowchart LR
    CA[\A/]:::connector
    CB[\B/]:::connector
    CC[\C/]:::connector
    CD[\D/]:::connector

    CA --> I3[/Item, Office, Qty, Cost, Source, Date/]:::input
    I3 --> P3[Generate Reference Code and Save Acquisition]:::process
    P3 --> O3[/Stock Level Increased/]:::output

    CB --> I4[/Item, From and To Office, Qty, Date/]:::input
    I4 --> P4[Generate Reference Code and Save Transfer]:::process
    P4 --> O4[/Stock Adjusted at Both Offices/]:::output

    CC --> I5[/Item, Office, Qty, Reason, Date/]:::input
    I5 --> P5[Generate Reference Code and Save Disposal]:::process
    P5 --> O5[/Stock Level Decreased/]:::output

    CD --> I6[/Item, Qty, Issued To, Office, Date/]:::input
    I6 --> P6[Generate Reference Code and Save Issuance]:::process
    P6 --> O6[/Stock Decreased, Issuance Recorded/]:::output

    O3 --> END([Done]):::terminal
    O4 --> END
    O5 --> END
    O6 --> END

    classDef terminal fill:#ede9fe,stroke:#7c3aed,color:#4c1d95
    classDef input fill:#dbeafe,stroke:#3b82f6,color:#1e3a8a
    classDef process fill:#fef9c3,stroke:#ca8a04,color:#713f12
    classDef output fill:#dcfce7,stroke:#16a34a,color:#14532d
    classDef decide fill:#ffedd5,stroke:#ea580c,color:#7c2d12
    classDef connector fill:#f1f5f9,stroke:#64748b,color:#1e293b
```

### Part 3 of 3 — Requisition, AI Analysis, and Reports

```mermaid
%%{init: {'theme': 'base'}}%%
flowchart TD
    CE[\E/]:::connector
    CF[\F/]:::connector
    CG[\G/]:::connector

    CE --> I6[/Consolidated Requisition from Unit Head/]:::input
    I6 --> P6[Review Requisition Details]:::process
    P6 --> O6b[/Requisition Details Reviewed/]:::output
    O6b --> D4{Approve?}:::decide
    D4 -- Yes --> I7[/Issuance Details: Item, Qty, Issued To/]:::input
    I7 --> P7[Save Issuance and Mark as Fulfilled]:::process
    P7 --> O6[/Stock Decreased, Requisition Fulfilled/]:::output
    D4 -- No --> I8[/Rejection Remarks/]:::input
    I8 --> P8[Save Rejection and Update Status]:::process
    P8 --> O7[/Requisition Marked as Rejected/]:::output

    CF --> I9[/Review Period, Item Category/]:::input
    I9 --> P9[Compute Consumption Rate and Generate Recommendations]:::process
    P9 --> O8[/Recommendations with Priority and Suggested Quantities/]:::output
    O8 --> D5{Accept?}:::decide
    D5 -- Yes --> P9b[Accept and forward quantities to acquisition]:::process
    P9b --> O9[/Recommended Quantities Forwarded to Acquisition/]:::output
    D5 -- No --> P9c[Dismiss and archive AI run]:::process
    P9c --> O10[/AI Run Archived for Reference/]:::output

    CG --> I10[/Date Range, Office or Department Filter/]:::input
    I10 --> P10[Query Records, Format Layout, and Generate PDF]:::process
    P10 --> O11[/Downloadable COA Report in PDF Format/]:::output

    O6 --> END([Done]):::terminal
    O7 --> END
    O9 --> END
    O10 --> END
    O11 --> END

    classDef terminal fill:#ede9fe,stroke:#7c3aed,color:#4c1d95
    classDef input fill:#dbeafe,stroke:#3b82f6,color:#1e3a8a
    classDef process fill:#fef9c3,stroke:#ca8a04,color:#713f12
    classDef output fill:#dcfce7,stroke:#16a34a,color:#14532d
    classDef decide fill:#ffedd5,stroke:#ea580c,color:#7c2d12
    classDef connector fill:#f1f5f9,stroke:#64748b,color:#1e293b
```

---

## Unit Head Workflow

### Part 1 of 2 — Login and Task Entry

```mermaid
%%{init: {'theme': 'base'}}%%
flowchart TD
    START([Start]):::terminal

    START --> I1[/"Login<br/>Credentials"/]:::input
    I1 --> P1[Validate Credentials and Load Dashboard]:::process
    P1 --> O1[/Dashboard with Stock Alerts/]:::output
    O1 --> SEL[/Task Selection/]:::input

    SEL -- Check Stock --> P2[Browse stock levels for own office]:::process
    P2 --> O2[/Current Stock Levels Displayed/]:::output
    O2 --> D1{Any items running low?}:::decide
    D1 -- No --> END([Done]):::terminal
    D1 -- Yes --> CA[\A/]:::connector

    SEL -- Review Requests --> I3[/Pending Requisitions from Employees/]:::input
    I3 --> P3[Review each request and note remarks]:::process
    P3 --> O3[/Requests Reviewed with Accepted Items/]:::output
    O3 --> CA

    classDef terminal fill:#ede9fe,stroke:#7c3aed,color:#4c1d95
    classDef input fill:#dbeafe,stroke:#3b82f6,color:#1e3a8a
    classDef process fill:#fef9c3,stroke:#ca8a04,color:#713f12
    classDef output fill:#dcfce7,stroke:#16a34a,color:#14532d
    classDef decide fill:#ffedd5,stroke:#ea580c,color:#7c2d12
    classDef connector fill:#f1f5f9,stroke:#64748b,color:#1e293b
```

### Part 2 of 2 — Compile, Submit, and Monitor

```mermaid
%%{init: {'theme': 'base'}}%%
flowchart TD
    CA[\A/]:::connector

    CA --> I4[/Accepted Items to Compile/]:::input
    I4 --> P4[Compile into one consolidated requisition]:::process
    P4 --> O4[/Consolidated Requisition Prepared/]:::output
    O4 --> P5[Review, finalize, and submit to Supply Custodian]:::process
    P5 --> O5[/Requisition Submitted for Approval/]:::output
    O5 --> P6[Monitor requisition status on dashboard]:::process
    P6 --> O6[/Status Update Received/]:::output
    subgraph outcomes[" "]
        direction LR
        D2{Fulfilled?}:::decide
        D2 -- Yes --> I7[/Fulfilled Items/]:::input
        I7 --> P7[Receive and distribute items to employees]:::process
        P7 --> O7[/Items Distributed to Employees/]:::output
        D2 -- No --> I8[/Rejection Remarks from Supply Custodian/]:::input
        I8 --> P8[Read remarks and prepare resubmission]:::process
        P8 --> O8[/Resubmission Prepared/]:::output
    end

    O6 --> D2
    O7 --> END([Done]):::terminal
    O8 --> END

    style outcomes fill:transparent,stroke:transparent

    classDef terminal fill:#ede9fe,stroke:#7c3aed,color:#4c1d95
    classDef input fill:#dbeafe,stroke:#3b82f6,color:#1e3a8a
    classDef process fill:#fef9c3,stroke:#ca8a04,color:#713f12
    classDef output fill:#dcfce7,stroke:#16a34a,color:#14532d
    classDef decide fill:#ffedd5,stroke:#ea580c,color:#7c2d12
    classDef connector fill:#f1f5f9,stroke:#64748b,color:#1e293b
```

---

## Employee Workflow

```mermaid
%%{init: {'theme': 'base'}}%%
flowchart TD
    START([Start]):::terminal

    START --> I1[/"Login<br/>Credentials"/]:::input
    I1 --> P1[Validate Credentials and Load Dashboard]:::process
    P1 --> O1[/Dashboard Loaded/]:::output
    O1 --> I2[/Items, Quantities, and Requisition Details/]:::input
    I2 --> P2[Submit Requisition Form]:::process
    P2 --> O2[/Requisition Submitted/]:::output
    O2 --> P3[Monitor requisition status on dashboard]:::process
    P3 --> O3[/Status Update Received/]:::output
    O3 --> D1{Fulfilled?}:::decide

    D1 -- Yes --> P4[Acknowledge Fulfilled Requisition]:::process
    P4 --> O4[/Requisition Fulfilled - Items Ready/]:::output

    D1 -- No --> I5[/Rejection Remarks/]:::input
    I5 --> P5[Read remarks and prepare resubmission]:::process
    P5 --> O5[/Resubmission Prepared/]:::output

    O4 --> END([Done]):::terminal
    O5 --> END

    classDef terminal fill:#ede9fe,stroke:#7c3aed,color:#4c1d95
    classDef input fill:#dbeafe,stroke:#3b82f6,color:#1e3a8a
    classDef process fill:#fef9c3,stroke:#ca8a04,color:#713f12
    classDef output fill:#dcfce7,stroke:#16a34a,color:#14532d
    classDef decide fill:#ffedd5,stroke:#ea580c,color:#7c2d12
    classDef connector fill:#f1f5f9,stroke:#64748b,color:#1e293b
```
