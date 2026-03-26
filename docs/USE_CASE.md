# OWWA Region IV-A Inventory Management System — Use Case Diagram

The use case diagram summarizes which external actors interact with the proposed inventory system and what functions they can perform. It focuses on user goals (use cases) rather than internal process flow or data movement.

```mermaid
flowchart LR

    %% Styles
    classDef actor fill:#f1f5f9,stroke:#64748b,color:#111827;
    classDef usecase fill:#fef3c7,stroke:#f59e0b,color:#78350f;

    %% Actors
    SC["Supply Custodian"]:::actor
    UH["Unit Head"]:::actor
    EMP["Employee"]:::actor

    %% System boundary
    subgraph SYS["OWWA Inventory Management System"]
        direction TB

        %% Supply Custodian use cases
        UC_SC_Dashboard([View Dashboard]):::usecase
        UC_SC_Stock([View Stock Levels - Regional]):::usecase
        UC_SC_Issue([Issue Item]):::usecase
        UC_SC_Acq([Record Acquisitions]):::usecase
        UC_SC_ViewReq([View Requisitions]):::usecase
        UC_SC_Approve([Approve / Reject Requisitions]):::usecase
        UC_SC_Dispose([Record Disposals]):::usecase
        UC_SC_AI([Run AI Procurement Analysis]):::usecase
        UC_SC_Report([Generate COA Reports]):::usecase
        UC_SC_Admin([Manage Items, Offices,\nDepartments, Users, Fiscal Years]):::usecase

        %% Unit Head use cases
        UC_UH_ViewEmpReq([View Employee Requisitions - Office/Department]):::usecase
        UC_UH_Compile([Compile Employee Requisitions]):::usecase
        UC_UH_Submit([Submit Consolidated Requisition]):::usecase
        UC_UH_Stock([View Stock Levels - Own Office]):::usecase
        UC_UH_Track([Track Requisition Status]):::usecase
        UC_UH_Users([Manage Employee Accounts]):::usecase

        %% Employee use cases
        UC_EMP_Submit([Submit Requisition]):::usecase
        UC_EMP_Track([Track Requisition Status]):::usecase
        UC_EMP_Stock([View Office Stock Levels]):::usecase
    end

    %% Associations (lines only – no arrows)
    SC --- UC_SC_Dashboard
    SC --- UC_SC_Stock
    SC --- UC_SC_Issue
    SC --- UC_SC_Acq
    SC --- UC_SC_ViewReq
    SC --- UC_SC_Approve
    SC --- UC_SC_Dispose
    SC --- UC_SC_AI
    SC --- UC_SC_Report
    SC --- UC_SC_Admin

    UH --- UC_UH_ViewEmpReq
    UH --- UC_UH_Compile
    UH --- UC_UH_Submit
    UH --- UC_UH_Stock
    UH --- UC_UH_Track
    UH --- UC_UH_Users

    EMP --- UC_EMP_Submit
    EMP --- UC_EMP_Track
    EMP --- UC_EMP_Stock

    class SC,UH,EMP actor
    class UC_SC_Dashboard,UC_SC_Stock,UC_SC_Issue,UC_SC_Acq,UC_SC_ViewReq,UC_SC_Approve,UC_SC_Dispose,UC_SC_AI,UC_SC_Report,UC_SC_Admin usecase
    class UC_UH_ViewEmpReq,UC_UH_Compile,UC_UH_Submit,UC_UH_Stock,UC_UH_Track,UC_UH_Users usecase
    class UC_EMP_Submit,UC_EMP_Track,UC_EMP_Stock usecase
```

