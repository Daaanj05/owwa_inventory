# OWWA Region IV-A Inventory Management System — Use Case Diagram

The use case diagram summarizes which external actors interact with the proposed inventory system and what functions they can perform. It focuses on user goals (use cases) rather than internal process flow or data movement.

```plantuml
@startuml
left to right direction
skinparam packageStyle rectangle
skinparam linetype ortho

actor "System Admin" as SA
actor "Supply Custodian" as SC
actor "Unit Consolidator" as UC
actor "Job Order Employee" as JOE
actor "AI Procurement Service" as AI <<secondary>>
actor "Email Verification /\nNotification Service" as NOTIF <<secondary>>

rectangle "OWWA Inventory Management System" {

  ' Shared system behavior
  usecase "Send Verification /\nNotification" as U_SYS_NOTIFY

  ' --- System Admin (setup / governance) ---
  usecase "Manage Fiscal Years\n(Create/Edit/Copy Setup)" as U_SA_FY
  usecase "Manage User Accounts\n(Create/Edit/Assign Scope)" as U_SA_USERS
  usecase "Manage Setup Master Data\n(Offices, Departments,\nItem Categories,\nReference Series)" as U_SA_SETUP
  usecase "View User Logs" as U_SA_LOGS

  ' --- Supply Custodian (operations + analytics) ---
  usecase "Select Inventory Category" as U_SC_CAT
  usecase "Record Stock Transactions\n(Acquisition, Issuance,\nTransfer, Disposal)\n+ COA export" as U_SC_TXN
  usecase "Process Requisitions\n(Review/Approve/Reject/Fulfill)" as U_SC_REQ
  usecase "Procurement Analytics\nand AI Procurement Runs" as U_SC_ANALYTICS

  ' --- Unit Consolidator (office-level consolidation + distribution) ---
  usecase "Select Inventory Category" as U_UC_CAT
  usecase "View Stock Levels and\nLow Stock Alerts (own office)" as U_UC_STOCK
  usecase "Distribute Items to Job Order Employees\n(category-scoped)" as U_UC_DIST
  usecase "Consolidate Requisitions\n(Review/Compile/Submit/Track)" as U_UC_REQ

  ' --- Job Order Employee (request + visibility) ---
  usecase "View Distributed Inventory List" as U_JOE_INV
  usecase "View Stock Levels\n(own office/department)" as U_JOE_STOCK
  usecase "Request Items\n(Submit + Track Requisition)" as U_JOE_REQ

  ' Decomposed use cases for include/extend (kept few)
  usecase "Compute At-Risk Restocking List" as U_SYS_AT_RISK
  usecase "Generate AI Recommendation (Run)" as U_SC_AI_RUN
}

' -----------------------------
' include / extend relationships
' -----------------------------

U_SC_ANALYTICS ..> U_SYS_AT_RISK : <<include>>
U_SC_AI_RUN ..> U_SYS_AT_RISK : <<include>>
U_SC_ANALYTICS ..> U_SC_AI_RUN : <<extend>>

U_SA_USERS ..> U_SYS_NOTIFY : <<include>>
U_SC_REQ ..> U_SYS_NOTIFY : <<include>>
U_UC_REQ ..> U_SYS_NOTIFY : <<include>>
U_JOE_REQ ..> U_SYS_NOTIFY : <<include>>

U_SC_TXN ..> U_SC_CAT : <<include>>
U_UC_STOCK ..> U_UC_CAT : <<include>>
U_UC_DIST ..> U_UC_CAT : <<include>>

SA -- U_SA_FY
SA -- U_SA_USERS
SA -- U_SA_SETUP
SA -- U_SA_LOGS
SA -- U_SYS_NOTIFY

SC -- U_SC_CAT
SC -- U_SC_TXN
SC -- U_SC_REQ
SC -- U_SC_ANALYTICS
SC -- U_SC_AI_RUN
SC -- U_SYS_AT_RISK

UC -- U_UC_CAT
UC -- U_UC_STOCK
UC -- U_UC_DIST
UC -- U_UC_REQ

JOE -- U_JOE_INV
JOE -- U_JOE_STOCK
JOE -- U_JOE_REQ

AI -- U_SC_AI_RUN
NOTIF -- U_SYS_NOTIFY

@enduml
```

Figure 3-20 presents a comprehensive Use Case Diagram that identifies the system’s actors and enumerates the specific functions available to each, providing a user-centered view that complements the data-centered DFDs and the process-oriented flowcharts. At the top of the hierarchy is the System Admin, who is responsible for configuring and safeguarding the system. This actor can manage user accounts and assignments, maintain setup master data such as offices, departments, item categories, and reference series, and review user logs to see who accessed the system and when. The user management process includes sending notification emails, such as account verification and activation messages, ensuring that new and updated accounts are properly confirmed through the Email Verification/Notification Service.

The Supply Custodian remains the primary operational actor for the regional inventory lifecycle. This actor can select an inventory category context and record stock transactions such as acquisitions, issuances, transfers, and disposals, with COA-aligned PDF exports available from within the transaction tasks. The Supply Custodian also handles requisition-related functions by reviewing consolidated requisitions, approving or rejecting them, and fulfilling approved requests through item issuance. In addition, the Supply Custodian can use procurement analytics and AI-assisted procurement runs to generate at-risk restocking lists and AI recommendations through the AI Procurement Service.

The Unit Consolidator serves as an intermediate actor focused on oversight and consolidation within their assigned office. This actor selects an inventory category context to view office-scoped stock levels and low-stock alerts, distributes items to Job Order Employees within the selected category, and consolidates employee requisitions by reviewing requests, compiling them into a consolidated requisition, submitting to the Supply Custodian, and tracking status updates. The Job Order Employee, as the end-user actor, initiates the supply chain process by viewing their distributed inventory list, viewing stock levels scoped to their own office/department, submitting requisitions that specify needed items and quantities, and tracking the status of those requests. Together, these actors and their associated use cases illustrate how individual requests from Job Order Employees and Unit Consolidators feed into centralized inventory decisions and administrative controls managed by the Supply Custodian and System Admin.

**Office vs department:** An **office** is the inventory location (stock ledger per `office_id`; regional vs satellite via `is_satellite`). A **department** is an organizational section within an office (users and requisitions carry both; departments do not hold separate stock). **Regional supply catalog** (Unit Consolidator and Job Order Employee) shows stock at the non-satellite regional supply office so requesters can plan what to ask from the Supply Custodian; requisitions still file under the requester’s own office and department.

