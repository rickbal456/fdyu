# AIKAFLOW - System Flow Diagrams

This document contains comprehensive flow diagrams for the AIKAFLOW application using Mermaid syntax.

---

## Table of Contents

1. [System Architecture Overview](#1-system-architecture-overview)
2. [User Authentication Flow](#2-user-authentication-flow)
3. [Workflow Execution Flow](#3-workflow-execution-flow)
4. [Credit System Flow](#4-credit-system-flow)
5. [Payment Processing Flow](#5-payment-processing-flow)
6. [API Integration Flow](#6-api-integration-flow)
7. [Webhook Processing Flow](#7-webhook-processing-flow)
8. [Plugin System Flow](#8-plugin-system-flow)
9. [Social Media Publishing Flow](#9-social-media-publishing-flow)
10. [Background Worker Flow](#10-background-worker-flow)
11. [User Journey Flow](#11-user-journey-flow)
12. [Database Entity Relationship](#12-database-entity-relationship)

---

## 1. System Architecture Overview

```mermaid
graph TB
    subgraph "Client Layer"
        Browser[Web Browser]
        MobileApp[Mobile Browser]
        APIClient[API Client]
    end

    subgraph "Web Server Layer"
        WebServer[Apache/Nginx]
        PHP[PHP 8.1+ Application]
    end

    subgraph "Application Layer"
        Auth[Authentication Module]
        WorkflowEngine[Workflow Engine]
        PluginManager[Plugin Manager]
        CreditSystem[Credit System]
        APIHandler[API Handler]
    end

    subgraph "Background Processing"
        Worker[Worker Process]
        Cron[Cron Jobs]
        Queue[Task Queue]
    end

    subgraph "Data Layer"
        MySQL[(MySQL Database)]
        Sessions[(Session Store)]
        FileSystem[File System]
    end

    subgraph "External Services"
        BunnyCDN[BunnyCDN Storage]
        RunningHub[RunningHub.ai]
        Kie[Kie.ai]
        JsonCut[JsonCut.com]
        Postforme[Postforme API]
        PayPal[PayPal Gateway]
    end

    Browser --> WebServer
    MobileApp --> WebServer
    APIClient --> WebServer
    
    WebServer --> PHP
    PHP --> Auth
    PHP --> WorkflowEngine
    PHP --> PluginManager
    PHP --> CreditSystem
    PHP --> APIHandler
    
    WorkflowEngine --> Queue
    Queue --> Worker
    Worker --> PluginManager
    Cron --> MySQL
    
    Auth --> MySQL
    Auth --> Sessions
    WorkflowEngine --> MySQL
    CreditSystem --> MySQL
    Worker --> MySQL
    
    PluginManager --> RunningHub
    PluginManager --> Kie
    PluginManager --> JsonCut
    PluginManager --> Postforme
    
    Worker --> BunnyCDN
    FileSystem --> BunnyCDN
    
    CreditSystem --> PayPal
    
    RunningHub -.Webhook.-> APIHandler
    Kie -.Webhook.-> APIHandler
    JsonCut -.Webhook.-> APIHandler
    Postforme -.Webhook.-> APIHandler

    style Browser fill:#e1f5ff
    style MySQL fill:#ffe1e1
    style Worker fill:#fff4e1
    style BunnyCDN fill:#e1ffe1
```

---

## 2. User Authentication Flow

```mermaid
flowchart TD
    Start([User Visits Site]) --> CheckSession{Session<br/>Valid?}
    
    CheckSession -->|Yes| LoadDashboard[Load Dashboard]
    CheckSession -->|No| ShowLogin[Show Login Page]
    
    ShowLogin --> LoginChoice{Login<br/>Method?}
    
    LoginChoice -->|Email/Password| EmailLogin[Enter Credentials]
    LoginChoice -->|Google OAuth| GoogleAuth[Redirect to Google]
    LoginChoice -->|Register| RegisterFlow[Registration Form]
    
    EmailLogin --> ValidateCreds{Credentials<br/>Valid?}
    ValidateCreds -->|No| LoginError[Show Error]
    LoginError --> ShowLogin
    ValidateCreds -->|Yes| CheckVerified{Email<br/>Verified?}
    
    GoogleAuth --> GoogleCallback[Google Callback]
    GoogleCallback --> CheckGoogleUser{User<br/>Exists?}
    CheckGoogleUser -->|No| CreateGoogleUser[Create User Account]
    CheckGoogleUser -->|Yes| CheckVerified
    CreateGoogleUser --> AddWelcomeCredits[Add Welcome Credits]
    
    RegisterFlow --> FillForm[Fill Registration Form]
    FillForm --> CheckInvite{Has Invitation<br/>Code?}
    CheckInvite -->|Yes| ValidateInvite{Code<br/>Valid?}
    CheckInvite -->|No| CreateAccount
    ValidateInvite -->|No| InviteError[Show Error]
    InviteError --> FillForm
    ValidateInvite -->|Yes| CreateAccount[Create User Account]
    
    CreateAccount --> AddWelcomeCredits
    AddWelcomeCredits --> CheckInviteReward{Has<br/>Referrer?}
    CheckInviteReward -->|Yes| AddReferralCredits[Add Referral Credits<br/>to Both Users]
    CheckInviteReward -->|No| SendVerification
    AddReferralCredits --> SendVerification[Send Verification Email]
    
    SendVerification --> CheckEmailVerif{Email Verification<br/>Required?}
    CheckEmailVerif -->|Yes| WaitVerification[Wait for Email Click]
    CheckEmailVerif -->|No| CheckWhatsApp
    WaitVerification --> CheckWhatsApp{WhatsApp Verification<br/>Required?}
    
    CheckVerified -->|No| VerificationRequired[Verification Required]
    VerificationRequired --> ShowLogin
    CheckVerified -->|Yes| CheckWhatsApp
    
    CheckWhatsApp -->|Yes| WhatsAppVerif[WhatsApp Verification]
    CheckWhatsApp -->|No| CreateSession
    WhatsAppVerif --> SendOTP[Send OTP via WhatsApp]
    SendOTP --> EnterOTP[User Enters OTP]
    EnterOTP --> ValidateOTP{OTP<br/>Valid?}
    ValidateOTP -->|No| OTPError[Show Error]
    OTPError --> EnterOTP
    ValidateOTP -->|Yes| SavePhone[Save Phone Number]
    SavePhone --> CreateSession
    
    CreateSession[Create Session] --> UpdateLastLogin[Update Last Login]
    UpdateLastLogin --> LoadDashboard
    LoadDashboard --> End([Dashboard Loaded])

    style Start fill:#e1f5ff
    style End fill:#e1ffe1
    style LoginError fill:#ffe1e1
    style InviteError fill:#ffe1e1
    style OTPError fill:#ffe1e1
```

---

## 3. Workflow Execution Flow

```mermaid
flowchart TD
    Start([User Clicks Run]) --> CheckCredits{Sufficient<br/>Credits?}
    
    CheckCredits -->|No| ShowError[Show Credit Error]
    ShowError --> End([Execution Failed])
    
    CheckCredits -->|Yes| CreateExecution[Create workflow_execution<br/>Status: pending]
    CreateExecution --> ParseWorkflow[Parse Workflow JSON]
    ParseWorkflow --> FindEntry[Find Entry Nodes<br/>manual-trigger]
    
    FindEntry --> HasEntry{Entry Node<br/>Found?}
    HasEntry -->|No| ErrorNoEntry[Error: No Entry Node]
    ErrorNoEntry --> UpdateStatus1[Update Status: failed]
    UpdateStatus1 --> End
    
    HasEntry -->|Yes| CreateNodeTasks[Create node_tasks<br/>for All Nodes]
    CreateNodeTasks --> AddToQueue[Add Tasks to task_queue]
    AddToQueue --> ReturnExecID[Return execution_id to User]
    ReturnExecID --> UpdateUIStatus[Update UI: Running]
    
    UpdateUIStatus --> WorkerPicks[Worker Picks Task]
    WorkerPicks --> CheckDependencies{Dependencies<br/>Complete?}
    
    CheckDependencies -->|No| RequeueTask[Requeue Task]
    RequeueTask --> WorkerPicks
    
    CheckDependencies -->|Yes| UpdateTaskStatus[Update Task: processing]
    UpdateTaskStatus --> GetNodeDef[Get Node Definition<br/>from Plugin]
    
    GetNodeDef --> CheckNodeType{Node<br/>Type?}
    
    CheckNodeType -->|Local| ExecuteLocal[Execute Local Logic]
    CheckNodeType -->|API| CheckRateLimit{Rate Limit<br/>Available?}
    
    CheckRateLimit -->|No| QueueForLater[Add to API Queue]
    QueueForLater --> WaitForSlot[Wait for Slot]
    WaitForSlot --> CheckRateLimit
    
    CheckRateLimit -->|Yes| AcquireSlot[Acquire Rate Limit Slot]
    AcquireSlot --> DeductCredits[Deduct Credits]
    DeductCredits --> CallAPI[Call External API]
    
    CallAPI --> APISuccess{API Call<br/>Success?}
    APISuccess -->|No| ReleaseSlot1[Release Slot]
    ReleaseSlot1 --> HandleError[Handle Error]
    APISuccess -->|Yes| StoreTaskID[Store external_task_id]
    StoreTaskID --> UpdateTaskID[Update Rate Limit<br/>with Real Task ID]
    UpdateTaskID --> WaitWebhook[Wait for Webhook]
    
    ExecuteLocal --> LocalSuccess{Execution<br/>Success?}
    LocalSuccess -->|No| HandleError
    LocalSuccess -->|Yes| StoreResult
    
    WaitWebhook --> WebhookReceived[Webhook Received]
    WebhookReceived --> ReleaseSlot2[Release Rate Limit Slot]
    ReleaseSlot2 --> StoreResult[Store Result URL/Data]
    
    StoreResult --> UploadToCDN{Upload to<br/>CDN?}
    UploadToCDN -->|Yes| UploadFile[Upload to BunnyCDN]
    UploadToCDN -->|No| SaveLocal[Save Locally]
    UploadFile --> UpdateGallery
    SaveLocal --> UpdateGallery[Update user_gallery]
    
    UpdateGallery --> UpdateTaskComplete[Update Task: completed]
    UpdateTaskComplete --> CheckAllTasks{All Tasks<br/>Complete?}
    
    CheckAllTasks -->|No| WorkerPicks
    CheckAllTasks -->|Yes| UpdateExecComplete[Update Execution: completed]
    UpdateExecComplete --> NotifyUser[Notify User]
    NotifyUser --> Success([Execution Complete])
    
    HandleError --> CheckRetries{Retries<br/>Left?}
    CheckRetries -->|Yes| IncrementAttempt[Increment Attempts]
    IncrementAttempt --> RequeueTask
    CheckRetries -->|No| UpdateTaskFailed[Update Task: failed]
    UpdateTaskFailed --> UpdateExecFailed[Update Execution: failed]
    UpdateExecFailed --> End

    style Start fill:#e1f5ff
    style Success fill:#e1ffe1
    style End fill:#ffe1e1
    style ShowError fill:#ffe1e1
```

---

## 4. Credit System Flow

```mermaid
flowchart TD
    Start([User Action]) --> ActionType{Action<br/>Type?}
    
    ActionType -->|Registration| WelcomeCredits[Add Welcome Credits]
    ActionType -->|Referral| ReferralCredits[Add Referral Credits]
    ActionType -->|Purchase| PurchaseFlow[Credit Purchase Flow]
    ActionType -->|Usage| UsageFlow[Credit Usage Flow]
    ActionType -->|Expiration| ExpirationFlow[Credit Expiration Flow]
    
    WelcomeCredits --> GetWelcomeAmount[Get welcome_amount<br/>from Settings]
    GetWelcomeAmount --> CreateLedger1[Create credit_ledger Entry]
    CreateLedger1 --> SetExpiry1[Set Expiry Date<br/>+365 days]
    SetExpiry1 --> CreateTrans1[Create Transaction<br/>Type: welcome]
    CreateTrans1 --> End1([Credits Added])
    
    ReferralCredits --> GetReferralAmount[Get referral_credits<br/>from Settings]
    GetReferralAmount --> CreateLedger2A[Create Ledger for Referrer]
    CreateLedger2A --> CreateLedger2B[Create Ledger for Referee]
    CreateLedger2B --> CreateTrans2A[Create Transaction<br/>for Referrer]
    CreateTrans2A --> CreateTrans2B[Create Transaction<br/>for Referee]
    CreateTrans2B --> End1
    
    PurchaseFlow --> SelectPackage[User Selects Package]
    SelectPackage --> ApplyCoupon{Apply<br/>Coupon?}
    ApplyCoupon -->|Yes| ValidateCoupon{Coupon<br/>Valid?}
    ValidateCoupon -->|No| CouponError[Show Error]
    CouponError --> SelectPackage
    ValidateCoupon -->|Yes| CalculateDiscount[Calculate Discount]
    ApplyCoupon -->|No| SelectPayment
    CalculateDiscount --> SelectPayment[Select Payment Method]
    
    SelectPayment --> PaymentType{Payment<br/>Method?}
    
    PaymentType -->|Bank Transfer| UploadProof[Upload Payment Proof]
    UploadProof --> CreateTopupReq[Create topup_request<br/>Status: pending]
    CreateTopupReq --> WaitApproval[Wait for Admin Approval]
    WaitApproval --> AdminReview{Admin<br/>Decision?}
    AdminReview -->|Reject| RejectTopup[Update Status: rejected]
    RejectTopup --> NotifyRejection[Notify User]
    NotifyRejection --> End2([Purchase Failed])
    AdminReview -->|Approve| ApproveTopup[Update Status: approved]
    
    PaymentType -->|QRIS| ShowQR[Show QR Code]
    ShowQR --> UploadProof
    
    PaymentType -->|PayPal| CreatePayPalOrder[Create PayPal Order]
    CreatePayPalOrder --> RedirectPayPal[Redirect to PayPal]
    RedirectPayPal --> PayPalPayment[User Completes Payment]
    PayPalPayment --> CapturePayment[Capture Payment]
    CapturePayment --> PayPalSuccess{Payment<br/>Success?}
    PayPalSuccess -->|No| End2
    PayPalSuccess -->|Yes| ApproveTopup
    
    ApproveTopup --> CalculateTotal[Calculate Total Credits<br/>Package + Bonus]
    CalculateTotal --> CreateLedger3[Create credit_ledger Entry]
    CreateLedger3 --> SetExpiry2[Set Expiry Date]
    SetExpiry2 --> CreateTrans3[Create Transaction<br/>Type: topup]
    CreateTrans3 --> UpdateCoupon{Coupon<br/>Used?}
    UpdateCoupon -->|Yes| IncrementUsage[Increment Coupon Usage]
    UpdateCoupon -->|No| NotifySuccess
    IncrementUsage --> NotifySuccess[Notify User]
    NotifySuccess --> End3([Credits Added])
    
    UsageFlow --> GetNodeCost[Get Node Cost<br/>from node_costs]
    GetNodeCost --> CheckBalance{Balance >=<br/>Cost?}
    CheckBalance -->|No| InsufficientError[Error: Insufficient Credits]
    InsufficientError --> End2
    CheckBalance -->|Yes| GetOldestCredits[Get Oldest Non-Expired<br/>Credits FIFO]
    GetOldestCredits --> DeductFromLedger[Deduct from<br/>credit_ledger.remaining]
    DeductFromLedger --> CreateTransUsage[Create Transaction<br/>Type: usage]
    CreateTransUsage --> UpdateBalance[Update User Balance]
    UpdateBalance --> CheckLowBalance{Balance <<br/>Threshold?}
    CheckLowBalance -->|Yes| SendLowAlert[Send Low Balance Alert]
    CheckLowBalance -->|No| End4
    SendLowAlert --> End4([Credits Deducted])
    
    ExpirationFlow --> FindExpired[Find Expired Credits<br/>WHERE expires_at < NOW]
    FindExpired --> HasExpired{Expired<br/>Credits?}
    HasExpired -->|No| End5([No Action])
    HasExpired -->|Yes| LoopExpired[For Each Expired Entry]
    LoopExpired --> CreateTransExpired[Create Transaction<br/>Type: expired]
    CreateTransExpired --> ZeroRemaining[Set remaining = 0]
    ZeroRemaining --> NotifyExpiry[Notify User]
    NotifyExpiry --> End5

    style End1 fill:#e1ffe1
    style End2 fill:#ffe1e1
    style End3 fill:#e1ffe1
    style End4 fill:#e1ffe1
    style End5 fill:#fff4e1
```

---

## 5. Payment Processing Flow

```mermaid
sequenceDiagram
    participant User
    participant Frontend
    participant Backend
    participant PayPal
    participant Admin
    participant Database

    Note over User,Database: PayPal Payment Flow
    
    User->>Frontend: Select Credit Package
    Frontend->>User: Show Payment Options
    User->>Frontend: Choose PayPal
    Frontend->>Backend: POST /api/payments/paypal-create.php
    Backend->>Database: Get Package Details
    Database-->>Backend: Package Info
    Backend->>Backend: Calculate Amount (USD)
    Backend->>PayPal: Create Order API
    PayPal-->>Backend: Order ID & Approval URL
    Backend->>Database: Save topup_request (pending)
    Backend-->>Frontend: Return Approval URL
    Frontend->>User: Redirect to PayPal
    User->>PayPal: Complete Payment
    PayPal->>User: Redirect to Success URL
    User->>Frontend: Return to Site
    Frontend->>Backend: POST /api/payments/paypal-capture.php
    Backend->>PayPal: Capture Payment API
    PayPal-->>Backend: Payment Confirmed
    Backend->>Database: Update topup_request (approved)
    Backend->>Database: Add Credits to Ledger
    Backend->>Database: Create Transaction Record
    Backend-->>Frontend: Success Response
    Frontend->>User: Show Success Message

    Note over User,Database: Bank Transfer Flow
    
    User->>Frontend: Select Credit Package
    Frontend->>User: Show Payment Options
    User->>Frontend: Choose Bank Transfer
    Frontend->>User: Show Bank Account Details
    User->>User: Make Bank Transfer
    User->>Frontend: Upload Payment Proof
    Frontend->>Backend: POST /api/credits/topup.php
    Backend->>Database: Create topup_request (pending)
    Backend->>Database: Save Payment Proof
    Backend-->>Frontend: Request Created
    Frontend->>User: Show Pending Message
    
    Admin->>Backend: GET /api/admin/credits.php
    Backend->>Database: Get Pending Requests
    Database-->>Backend: Request List
    Backend-->>Admin: Show Pending Requests
    Admin->>Admin: Review Payment Proof
    Admin->>Backend: POST /api/admin/credits.php (approve)
    Backend->>Database: Update topup_request (approved)
    Backend->>Database: Add Credits to Ledger
    Backend->>Database: Create Transaction Record
    Backend->>Backend: Send Email Notification
    Backend-->>Admin: Success
    Admin->>User: Credits Approved

    Note over User,Database: QRIS Payment Flow
    
    User->>Frontend: Select Credit Package
    Frontend->>User: Show Payment Options
    User->>Frontend: Choose QRIS
    Frontend->>Backend: GET /api/admin/settings.php
    Backend->>Database: Get QRIS Image URL
    Database-->>Backend: QRIS URL
    Backend-->>Frontend: QRIS Image
    Frontend->>User: Display QR Code
    User->>User: Scan & Pay with E-Wallet
    User->>Frontend: Upload Payment Screenshot
    Frontend->>Backend: POST /api/credits/topup.php
    Backend->>Database: Create topup_request (pending)
    Backend-->>Frontend: Request Created
    Frontend->>User: Show Pending Message
    Note right of Admin: Same approval flow as Bank Transfer
```

---

## 6. API Integration Flow

```mermaid
flowchart TD
    Start([Node Execution]) --> GetPlugin[Get Plugin Definition<br/>from plugin.json]
    GetPlugin --> CheckExecType{Execution<br/>Type?}
    
    CheckExecType -->|local| ExecuteLocal[Execute Local Logic]
    ExecuteLocal --> ReturnLocal[Return Result]
    ReturnLocal --> End([Complete])
    
    CheckExecType -->|api| GetAPIConfig[Get apiConfig<br/>from plugin.json]
    GetAPIConfig --> GetAPIKey[Get API Key]
    GetAPIKey --> KeySource{Key<br/>Source?}
    
    KeySource -->|Node Input| UseNodeKey[Use inputData.apiKey]
    KeySource -->|Admin Config| LoadAdminKey[Load from site_settings<br/>integration_keys]
    KeySource -->|None| KeyError[Error: No API Key]
    KeyError --> End
    
    UseNodeKey --> CheckRateLimit
    LoadAdminKey --> CheckRateLimit{Rate Limit<br/>Check}
    
    CheckRateLimit --> GetProvider[Get Provider<br/>rhub/kie/jcut/sapi]
    GetProvider --> QueryActiveSlots[Query api_active_calls<br/>for Provider + API Key]
    QueryActiveSlots --> CountSlots{Count <<br/>Limit?}
    
    CountSlots -->|No| QueueRequest[Add to api_call_queue]
    QueueRequest --> WaitForSlot[Wait for Available Slot]
    WaitForSlot --> SlotAvailable[Slot Available Event]
    SlotAvailable --> CheckRateLimit
    
    CountSlots -->|Yes| AcquireSlot[Create api_active_calls Entry<br/>with Temp Task ID]
    AcquireSlot --> MapRequest[Map Input Data to Request]
    MapRequest --> HasMapping{Has<br/>apiMapping?}
    
    HasMapping -->|Yes| UseMapping[Use Mapping Template<br/>{{variable}} syntax]
    HasMapping -->|No| DirectPass[Direct Passthrough<br/>Remove _ fields]
    
    UseMapping --> BuildRequest
    DirectPass --> BuildRequest[Build Request Body]
    BuildRequest --> AddWebhook[Add webhook_url]
    AddWebhook --> DetermineEndpoint[Determine API Endpoint]
    
    DetermineEndpoint --> ProviderURL{Provider?}
    ProviderURL -->|rhub| SetRHub[https://api.runninghub.ai]
    ProviderURL -->|kie| SetKie[https://api.kie.ai]
    ProviderURL -->|jcut| SetJCut[https://api.jsoncut.com]
    ProviderURL -->|sapi| SetSAPI[https://api.postforme.dev]
    
    SetRHub --> MakeRequest
    SetKie --> MakeRequest
    SetJCut --> MakeRequest
    SetSAPI --> MakeRequest[Make HTTP POST Request]
    
    MakeRequest --> SetHeaders[Set Headers:<br/>Authorization: Bearer {key}<br/>Content-Type: application/json]
    SetHeaders --> SendRequest[Send cURL Request]
    SendRequest --> CheckResponse{HTTP<br/>Success?}
    
    CheckResponse -->|No| ReleaseSlot1[Release api_active_calls]
    ReleaseSlot1 --> ReturnError[Return Error Response]
    ReturnError --> End
    
    CheckResponse -->|Yes| ParseResponse[Parse JSON Response]
    ParseResponse --> CheckAPIError{API Error<br/>Code?}
    
    CheckAPIError -->|Yes| ReleaseSlot1
    CheckAPIError -->|No| ExtractTaskID[Extract Task ID<br/>from Response]
    
    ExtractTaskID --> HasTaskID{Task ID<br/>Found?}
    HasTaskID -->|No| DirectResult[Return Direct Result]
    DirectResult --> ReleaseSlot1
    
    HasTaskID -->|Yes| UpdateSlot[Update api_active_calls<br/>with Real Task ID]
    UpdateSlot --> MapResponse[Map Response to Output]
    MapResponse --> HasResponseMap{Has Response<br/>Mapping?}
    
    HasResponseMap -->|Yes| UseResponseMap[Use $.path Mapping]
    HasResponseMap -->|No| PassThrough[Pass Through Response]
    
    UseResponseMap --> ReturnTaskID
    PassThrough --> ReturnTaskID[Return Task ID & Output]
    ReturnTaskID --> WaitWebhook[Wait for Webhook Callback]
    
    WaitWebhook --> WebhookArrives[Webhook Received]
    WebhookArrives --> ProcessWebhook[Process in webhook.php]
    ProcessWebhook --> ReleaseSlot2[Release api_active_calls]
    ReleaseSlot2 --> UpdateNodeTask[Update node_tasks<br/>with Result]
    UpdateNodeTask --> End

    style Start fill:#e1f5ff
    style End fill:#e1ffe1
    style KeyError fill:#ffe1e1
    style ReturnError fill:#ffe1e1
```

---

## 7. Webhook Processing Flow

```mermaid
flowchart TD
    Start([Webhook Received]) --> ParseSource{Source<br/>Provider?}
    
    ParseSource -->|rhub| ProcessRHub[Process RunningHub Webhook]
    ParseSource -->|rhub-enhance| ProcessEnhance[Process Enhancement Webhook]
    ParseSource -->|kie| ProcessKie[Process Kie Webhook]
    ParseSource -->|jcut| ProcessJCut[Process JsonCut Webhook]
    ParseSource -->|sapi| ProcessSAPI[Process Postforme Webhook]
    ParseSource -->|unknown| LogUnknown[Log Unknown Source]
    LogUnknown --> End([Webhook Ignored])
    
    ProcessRHub --> ExtractRHubData[Extract Task ID & Status]
    ExtractRHubData --> FindNodeTask1[Find node_tasks by<br/>external_task_id]
    FindNodeTask1 --> TaskFound1{Task<br/>Found?}
    TaskFound1 -->|No| LogNotFound1[Log: Task Not Found]
    LogNotFound1 --> End
    TaskFound1 -->|Yes| CheckRHubStatus{Status?}
    CheckRHubStatus -->|completed| ExtractRHubResult[Extract Result URL]
    CheckRHubStatus -->|failed| ExtractRHubError[Extract Error Message]
    ExtractRHubResult --> UpdateTask1[Update node_tasks<br/>Status: completed<br/>result_url: URL]
    ExtractRHubError --> UpdateTask1Fail[Update node_tasks<br/>Status: failed<br/>error_message: Error]
    
    ProcessEnhance --> ExtractEnhanceData[Extract Node ID & Status]
    ExtractEnhanceData --> FindEnhanceTask[Find enhancement_tasks<br/>by node_id]
    FindEnhanceTask --> EnhanceFound{Task<br/>Found?}
    EnhanceFound -->|No| LogNotFound2[Log: Enhancement Not Found]
    LogNotFound2 --> End
    EnhanceFound -->|Yes| CheckEnhanceStatus{Status?}
    CheckEnhanceStatus -->|completed| ExtractEnhanceResult[Extract Enhanced Image URL]
    CheckEnhanceStatus -->|failed| ExtractEnhanceError[Extract Error]
    ExtractEnhanceResult --> UploadToCDN[Upload to BunnyCDN]
    UploadToCDN --> UpdateEnhance[Update enhancement_tasks<br/>Status: completed<br/>result_data: URL]
    ExtractEnhanceError --> UpdateEnhanceFail[Update enhancement_tasks<br/>Status: failed]
    
    ProcessKie --> ExtractKieData[Extract Task ID & Status]
    ExtractKieData --> FindNodeTask2[Find node_tasks by<br/>external_task_id]
    FindNodeTask2 --> TaskFound2{Task<br/>Found?}
    TaskFound2 -->|No| LogNotFound3[Log: Task Not Found]
    LogNotFound3 --> End
    TaskFound2 -->|Yes| CheckKieStatus{Status?}
    CheckKieStatus -->|completed| ExtractKieResult[Extract Audio URL]
    CheckKieStatus -->|failed| ExtractKieError[Extract Error]
    ExtractKieResult --> UpdateTask2[Update node_tasks<br/>Status: completed<br/>result_url: URL]
    ExtractKieError --> UpdateTask2Fail[Update node_tasks<br/>Status: failed]
    
    ProcessJCut --> ExtractJCutData[Extract Task ID & Status]
    ExtractJCutData --> FindNodeTask3[Find node_tasks by<br/>external_task_id]
    FindNodeTask3 --> TaskFound3{Task<br/>Found?}
    TaskFound3 -->|No| LogNotFound4[Log: Task Not Found]
    LogNotFound4 --> End
    TaskFound3 -->|Yes| CheckJCutStatus{Status?}
    CheckJCutStatus -->|completed| ExtractJCutResult[Extract Video URL]
    CheckJCutStatus -->|failed| ExtractJCutError[Extract Error]
    ExtractJCutResult --> UpdateTask3[Update node_tasks<br/>Status: completed<br/>result_url: URL]
    ExtractJCutError --> UpdateTask3Fail[Update node_tasks<br/>Status: failed]
    
    ProcessSAPI --> ExtractSAPIData[Extract Post ID & Status]
    ExtractSAPIData --> FindNodeTask4[Find node_tasks by<br/>external_task_id]
    FindNodeTask4 --> TaskFound4{Task<br/>Found?}
    TaskFound4 -->|No| LogNotFound5[Log: Task Not Found]
    LogNotFound5 --> End
    TaskFound4 -->|Yes| CheckSAPIStatus{Status?}
    CheckSAPIStatus -->|completed| FetchResults[Fetch Post Results<br/>from Postforme API]
    CheckSAPIStatus -->|failed| ExtractSAPIError[Extract Error]
    FetchResults --> ExtractPlatformURLs[Extract Platform URLs]
    ExtractPlatformURLs --> UpdateTask4[Update node_tasks<br/>Status: completed<br/>output_data: URLs]
    ExtractSAPIError --> UpdateTask4Fail[Update node_tasks<br/>Status: failed]
    
    UpdateTask1 --> ReleaseSlot
    UpdateTask1Fail --> ReleaseSlot
    UpdateTask2 --> ReleaseSlot
    UpdateTask2Fail --> ReleaseSlot
    UpdateTask3 --> ReleaseSlot
    UpdateTask3Fail --> ReleaseSlot
    UpdateTask4 --> ReleaseSlot
    UpdateTask4Fail --> ReleaseSlot
    UpdateEnhance --> ReleaseSlot
    UpdateEnhanceFail --> ReleaseSlot
    
    ReleaseSlot[Release api_active_calls Slot]
    ReleaseSlot --> CheckQueue{Queued<br/>Requests?}
    CheckQueue -->|Yes| ProcessQueue[Process Next in Queue]
    CheckQueue -->|No| CheckExecution
    ProcessQueue --> CheckExecution
    
    CheckExecution[Check workflow_executions]
    CheckExecution --> AllTasksComplete{All Tasks<br/>Complete?}
    AllTasksComplete -->|Yes| UpdateWorkflow[Update workflow_executions<br/>Status: completed]
    AllTasksComplete -->|No| KeepRunning[Keep Status: running]
    UpdateWorkflow --> AddToGallery[Add Results to user_gallery]
    AddToGallery --> NotifyUser[Notify User]
    KeepRunning --> LogWebhook
    NotifyUser --> LogWebhook[Log to webhook_logs]
    LogWebhook --> Success([Webhook Processed])

    style Start fill:#e1f5ff
    style Success fill:#e1ffe1
    style End fill:#fff4e1
```

---

## 8. Plugin System Flow

```mermaid
flowchart TD
    Start([Application Start]) --> ScanPlugins[Scan plugins/ Directory]
    ScanPlugins --> FindPlugins[Find All plugin.json Files]
    FindPlugins --> LoopPlugins{For Each<br/>Plugin}
    
    LoopPlugins -->|Next| ReadJSON[Read plugin.json]
    LoopPlugins -->|Done| PluginsLoaded[All Plugins Loaded]
    
    ReadJSON --> ParseJSON[Parse JSON Metadata]
    ParseJSON --> CheckEnabled{enabled:<br/>true?}
    
    CheckEnabled -->|No| SkipPlugin[Skip Plugin]
    SkipPlugin --> LoopPlugins
    
    CheckEnabled -->|Yes| ValidatePlugin{Valid<br/>Structure?}
    ValidatePlugin -->|No| LogError[Log Error]
    LogError --> SkipPlugin
    
    ValidatePlugin -->|Yes| GetPluginType{Plugin<br/>Type?}
    
    GetPluginType -->|node| RegisterNode[Register Node Types]
    GetPluginType -->|storage| RegisterStorage[Register Storage Handler]
    GetPluginType -->|ui| RegisterUI[Register UI Components]
    GetPluginType -->|integration| RegisterIntegration[Register Integration]
    
    RegisterNode --> LoadNodeDef[Load Node Definitions]
    LoadNodeDef --> StoreNodeTypes[Store in nodeDefinitions Map]
    StoreNodeTypes --> CheckScripts
    
    RegisterStorage --> LoadHandler[Load handler.php]
    LoadHandler --> StoreStorage[Store in storagePlugins Map]
    StoreStorage --> CheckScripts
    
    RegisterUI --> LoadUIScripts[Load UI Scripts]
    LoadUIScripts --> CheckScripts
    
    RegisterIntegration --> LoadIntegration[Load Integration Logic]
    LoadIntegration --> CheckScripts
    
    CheckScripts{Has<br/>Scripts?}
    CheckScripts -->|Yes| LoadScripts[Load JavaScript Files]
    CheckScripts -->|No| CheckStyles
    LoadScripts --> CheckStyles{Has<br/>Styles?}
    
    CheckStyles -->|Yes| LoadStyles[Load CSS Files]
    CheckStyles -->|No| CheckDeps
    LoadStyles --> CheckDeps{Has<br/>Dependencies?}
    
    CheckDeps -->|Yes| LoadDeps[Load Dependent Plugins]
    CheckDeps -->|No| StorePlugin
    LoadDeps --> StorePlugin[Store in plugins Map]
    StorePlugin --> LoopPlugins
    
    PluginsLoaded --> RenderUI[Render Plugin UI Elements]
    RenderUI --> InjectNodes[Inject Nodes into Library]
    InjectNodes --> Ready([Plugins Ready])
    
    Ready --> UserAction([User Adds Node])
    UserAction --> GetNodeType[Get Node Type]
    GetNodeType --> LookupPlugin[Lookup Plugin Definition]
    LookupPlugin --> PluginFound{Plugin<br/>Found?}
    
    PluginFound -->|No| ErrorNode[Error: Unknown Node]
    ErrorNode --> EndAction([Action Failed])
    
    PluginFound -->|Yes| CreateNode[Create Node Instance]
    CreateNode --> LoadProperties[Load Node Properties<br/>from plugin.json]
    LoadProperties --> RenderNode[Render Node on Canvas]
    RenderNode --> EndAction2([Node Added])
    
    EndAction2 --> ExecuteAction([User Executes Workflow])
    ExecuteAction --> GetExecType{Execution<br/>Type?}
    
    GetExecType -->|local| CallLocalHandler[Call executeLocalNode]
    GetExecType -->|api| CallAPIHandler[Call executeApiNode]
    
    CallLocalHandler --> LocalLogic[Execute Plugin Logic]
    LocalLogic --> ReturnResult[Return Result]
    
    CallAPIHandler --> GetAPIConfig[Get apiConfig]
    GetAPIConfig --> GetMapping[Get apiMapping]
    GetMapping --> MapInputs[Map Inputs to Request]
    MapInputs --> CallAPI[Call External API]
    CallAPI --> MapResponse[Map Response to Output]
    MapResponse --> ReturnResult
    
    ReturnResult --> EndExec([Execution Complete])

    style Start fill:#e1f5ff
    style Ready fill:#e1ffe1
    style EndAction2 fill:#e1ffe1
    style EndExec fill:#e1ffe1
    style ErrorNode fill:#ffe1e1
```

---

## 9. Social Media Publishing Flow

```mermaid
sequenceDiagram
    participant User
    participant Frontend
    participant Backend
    participant Postforme
    participant Instagram
    participant TikTok
    participant Facebook

    Note over User,Facebook: Account Connection Flow
    
    User->>Frontend: Click "Connect Social Account"
    Frontend->>Backend: POST /api/social/connect.php
    Backend->>Postforme: Get OAuth URL
    Postforme-->>Backend: OAuth URL
    Backend-->>Frontend: Return OAuth URL
    Frontend->>User: Redirect to Postforme
    User->>Postforme: Authorize Platform Access
    Postforme->>Instagram: Request Authorization
    Instagram-->>Postforme: Authorization Token
    Postforme->>User: Redirect to Callback URL
    User->>Frontend: Return to AIKAFLOW
    Frontend->>Backend: Handle OAuth Callback
    Backend->>Postforme: Exchange Code for Token
    Postforme-->>Backend: Access Token
    Backend->>Backend: Store in user_settings
    Backend-->>Frontend: Account Connected
    Frontend->>User: Show Success Message

    Note over User,Facebook: Publishing Flow
    
    User->>Frontend: Add Social Post Node
    Frontend->>User: Show Node Properties
    User->>Frontend: Configure:<br/>- Select Accounts<br/>- Enter Caption<br/>- Connect Media Input
    User->>Frontend: Click "Run Workflow"
    Frontend->>Backend: POST /api/workflows/execute.php
    Backend->>Backend: Execute Workflow
    Backend->>Backend: Reach Social Post Node
    Backend->>Backend: Get Connected Accounts
    Backend->>Backend: Prepare Media & Caption
    Backend->>Postforme: POST /v1/social-posts
    Note right of Postforme: {<br/>  accounts: [ids],<br/>  caption: "text",<br/>  media: [{url, type}],<br/>  webhook_url: "..."<br/>}
    Postforme-->>Backend: Post ID
    Backend->>Backend: Store external_task_id
    Backend->>Backend: Update Status: processing
    Backend-->>Frontend: Execution Started
    Frontend->>User: Show Progress
    
    Postforme->>Instagram: Publish Post
    Postforme->>TikTok: Publish Post
    Postforme->>Facebook: Publish Post
    
    Instagram-->>Postforme: Post URL
    TikTok-->>Postforme: Post URL
    Facebook-->>Postforme: Post URL
    
    Postforme->>Backend: Webhook: Post Completed
    Backend->>Backend: Update node_tasks: completed
    Backend->>Postforme: GET /v1/social-post-results
    Postforme-->>Backend: Platform URLs
    Backend->>Backend: Store Platform URLs
    Backend->>Backend: Update workflow_executions
    Backend->>Backend: Add to user_gallery
    Backend-->>Frontend: Execution Complete
    Frontend->>User: Show Success & URLs
    User->>Frontend: Click Platform URL
    Frontend->>Instagram: Open Post
```

---

## 10. Background Worker Flow

```mermaid
flowchart TD
    Start([Worker Process Starts]) --> InitWorker[Initialize Worker]
    InitWorker --> ConnectDB[Connect to Database]
    ConnectDB --> StartLoop[Start Main Loop]
    
    StartLoop --> QueryQueue[Query task_queue<br/>WHERE status = 'pending'<br/>ORDER BY priority DESC, created_at ASC<br/>LIMIT 1]
    
    QueryQueue --> HasTask{Task<br/>Found?}
    
    HasTask -->|No| Sleep[Sleep 2 seconds]
    Sleep --> CheckShutdown1{Shutdown<br/>Signal?}
    CheckShutdown1 -->|Yes| Cleanup1[Cleanup & Exit]
    CheckShutdown1 -->|No| StartLoop
    Cleanup1 --> End([Worker Stopped])
    
    HasTask -->|Yes| LockTask[Lock Task<br/>SET locked_at = NOW<br/>locked_by = worker_id<br/>status = 'processing']
    
    LockTask --> LockSuccess{Lock<br/>Success?}
    LockSuccess -->|No| StartLoop
    LockSuccess -->|Yes| ParsePayload[Parse Task Payload]
    
    ParsePayload --> GetTaskType{Task<br/>Type?}
    
    GetTaskType -->|execute_node| ExecuteNode[Execute Node Task]
    GetTaskType -->|cleanup| CleanupTask[Cleanup Task]
    GetTaskType -->|notification| SendNotification[Send Notification]
    GetTaskType -->|other| UnknownTask[Log Unknown Task Type]
    
    ExecuteNode --> GetNodeData[Get Node Data from Payload]
    GetNodeData --> LoadPlugin[Load Plugin via PluginManager]
    LoadPlugin --> PluginLoaded{Plugin<br/>Loaded?}
    
    PluginLoaded -->|No| TaskError[Set Error: Plugin Not Found]
    TaskError --> UpdateFailed
    
    PluginLoaded -->|Yes| CheckDeps[Check Node Dependencies]
    CheckDeps --> DepsReady{Dependencies<br/>Complete?}
    
    DepsReady -->|No| RequeueTask[Requeue Task<br/>SET status = 'pending'<br/>locked_at = NULL]
    RequeueTask --> StartLoop
    
    DepsReady -->|Yes| GetInputs[Get Input Data<br/>from Connected Nodes]
    GetInputs --> ValidateInputs{Inputs<br/>Valid?}
    
    ValidateInputs -->|No| TaskError
    ValidateInputs -->|Yes| ExecutePlugin[PluginManager::executeNode]
    
    ExecutePlugin --> ExecResult{Execution<br/>Result?}
    
    ExecResult -->|Error| HandleRetry{Attempts <<br/>Max?}
    HandleRetry -->|Yes| IncrementAttempt[Increment attempts]
    IncrementAttempt --> RequeueTask
    HandleRetry -->|No| UpdateFailed[Update Task<br/>status = 'failed'<br/>error_message = error]
    
    ExecResult -->|Queued| UpdateQueued[Update Task<br/>status = 'pending'<br/>Note: Rate Limited]
    UpdateQueued --> StartLoop
    
    ExecResult -->|Success| StoreResult[Store Result Data]
    StoreResult --> UpdateNodeTask[Update node_tasks<br/>status = 'completed'<br/>result_url = url<br/>output_data = data]
    UpdateNodeTask --> UpdateSuccess[Update Task<br/>status = 'completed']
    
    CleanupTask --> PerformCleanup[Perform Cleanup Logic]
    PerformCleanup --> UpdateSuccess
    
    SendNotification --> SendEmail[Send Email/Notification]
    SendEmail --> UpdateSuccess
    
    UnknownTask --> UpdateFailed
    
    UpdateSuccess --> CheckWorkflow{All Nodes<br/>Complete?}
    CheckWorkflow -->|Yes| UpdateExecution[Update workflow_executions<br/>status = 'completed'<br/>completed_at = NOW]
    CheckWorkflow -->|No| KeepRunning[Keep Execution Running]
    UpdateExecution --> NotifyUser
    KeepRunning --> DeleteTask
    
    UpdateFailed --> CheckCritical{Critical<br/>Failure?}
    CheckCritical -->|Yes| FailWorkflow[Update workflow_executions<br/>status = 'failed']
    CheckCritical -->|No| DeleteTask
    FailWorkflow --> NotifyUser[Notify User of Completion]
    
    NotifyUser --> DeleteTask[Delete from task_queue]
    DeleteTask --> CheckShutdown2{Shutdown<br/>Signal?}
    CheckShutdown2 -->|Yes| Cleanup2[Cleanup & Exit]
    CheckShutdown2 -->|No| StartLoop
    Cleanup2 --> End

    style Start fill:#e1f5ff
    style End fill:#ffe1e1
```

---

## 11. User Journey Flow

```mermaid
journey
    title User Journey: From Registration to First Workflow Execution
    section Registration
      Visit Website: 5: User
      Fill Registration Form: 4: User
      Verify Email: 3: User
      Receive Welcome Credits: 5: User
    section First Login
      Login to Dashboard: 5: User
      View Empty Canvas: 3: User
      Read Onboarding Tooltip: 4: User
    section Workflow Creation
      Drag Start Flow Node: 4: User
      Add Image Input Node: 4: User
      Upload Image: 3: User
      Add Image-to-Video Node: 4: User
      Connect Nodes: 5: User
      Configure Properties: 3: User
    section Execution
      Click Run Button: 5: User
      See Progress Indicators: 4: User
      Wait for Completion: 2: User
      View Result: 5: User
    section Post-Execution
      Download Video: 5: User
      View in Gallery: 4: User
      Share Workflow: 4: User
      Check Credit Balance: 3: User
    section Growth
      Invite Friend: 4: User
      Receive Referral Credits: 5: User
      Purchase More Credits: 4: User
      Create Advanced Workflow: 5: User
```

---

## 12. Database Entity Relationship

```mermaid
erDiagram
    users ||--o{ workflows : creates
    users ||--o{ workflow_executions : executes
    users ||--o{ credit_ledger : has
    users ||--o{ credit_transactions : has
    users ||--o{ topup_requests : submits
    users ||--o{ media_assets : uploads
    users ||--o{ user_gallery : owns
    users ||--o{ user_settings : has
    users ||--o{ sessions : has
    users ||--o{ password_reset_tokens : has
    users ||--o{ enhancement_tasks : creates
    users ||--o{ workflow_shares : shares
    users ||--o{ credit_coupon_usage : uses
    users }o--o| users : refers

    workflows ||--o{ workflow_executions : executed_as
    workflows ||--o{ workflow_autosaves : autosaved
    workflows ||--o{ user_gallery : generates

    workflow_executions ||--o{ node_tasks : contains
    workflow_executions ||--o{ flow_executions : contains
    workflow_executions ||--o{ api_active_calls : tracks
    workflow_executions ||--o{ api_call_queue : queues

    credit_packages ||--o{ topup_requests : selected_in
    credit_coupons ||--o{ topup_requests : applied_to
    credit_coupons ||--o{ credit_coupon_usage : used_in

    topup_requests ||--o{ credit_coupon_usage : uses

    api_rate_limits ||--o{ api_active_calls : limits
    api_rate_limits ||--o{ api_call_queue : manages

    users {
        int id PK
        string email UK
        string username UK
        string password_hash
        string api_key UK
        boolean is_active
        enum role
        string language
        string invitation_code UK
        int referred_by FK
        timestamp created_at
        boolean is_verified
    }

    workflows {
        int id PK
        int user_id FK
        string name
        text description
        json json_data
        string thumbnail_url
        boolean is_public
        int version
        timestamp created_at
    }

    workflow_executions {
        int id PK
        int workflow_id FK
        int user_id FK
        enum status
        json input_data
        json output_data
        string result_url
        int repeat_count
        timestamp started_at
        timestamp completed_at
    }

    node_tasks {
        int id PK
        int execution_id FK
        string node_id
        string node_type
        enum status
        string external_task_id
        json output_data
        string result_url
        int attempts
        timestamp completed_at
    }

    credit_ledger {
        int id PK
        int user_id FK
        decimal credits
        decimal remaining
        enum source
        date expires_at
        timestamp created_at
    }

    credit_transactions {
        int id PK
        int user_id FK
        enum type
        decimal amount
        decimal balance_after
        string description
        string reference_id
        timestamp created_at
    }

    credit_packages {
        int id PK
        string name
        int credits
        decimal price
        int bonus_credits
        boolean is_active
    }

    topup_requests {
        int id PK
        int user_id FK
        int package_id FK
        int coupon_id FK
        decimal amount
        decimal final_amount
        int credits_requested
        string payment_proof
        enum status
        timestamp created_at
    }

    task_queue {
        int id PK
        string task_type
        json payload
        int priority
        enum status
        int attempts
        timestamp locked_at
        string locked_by
    }

    api_rate_limits {
        int id PK
        string provider UK
        string display_name
        int default_max_concurrent
        int queue_timeout
        boolean is_active
    }

    api_active_calls {
        int id PK
        string provider
        string api_key_hash
        string task_id
        int workflow_run_id FK
        timestamp started_at
        timestamp expires_at
    }

    site_settings {
        int id PK
        string setting_key UK
        text setting_value
        timestamp updated_at
    }
```

---

## Additional Diagrams

### 13. Content Lifecycle Flow

```mermaid
stateDiagram-v2
    [*] --> Created: User Uploads/Generates
    Created --> Stored: Save to Storage
    Stored --> InGallery: Add to Gallery
    InGallery --> Downloaded: User Downloads
    InGallery --> Shared: User Shares
    InGallery --> Expiring: Approaching Retention Limit
    Expiring --> Notified: Send Expiry Notice
    Notified --> Extended: User Purchases Extension
    Notified --> Expired: Retention Period Ends
    Extended --> InGallery
    Expired --> Deleted: Cron Job Cleanup
    InGallery --> ManualDelete: User Deletes
    ManualDelete --> Deleted
    Deleted --> [*]
```

### 14. Node Execution State Machine

```mermaid
stateDiagram-v2
    [*] --> Pending: Task Created
    Pending --> Queued: Added to Queue
    Queued --> Processing: Worker Picks Up
    Processing --> WaitingDependency: Dependencies Not Ready
    WaitingDependency --> Queued: Requeued
    Processing --> CallingAPI: API Node
    CallingAPI --> WaitingWebhook: API Call Success
    WaitingWebhook --> Completed: Webhook Received
    CallingAPI --> Failed: API Call Failed
    Processing --> Completed: Local Node Success
    Processing --> Failed: Execution Error
    Failed --> Queued: Retry Available
    Failed --> [*]: Max Retries Reached
    Completed --> [*]: Success
```

### 15. Credit Balance State

```mermaid
stateDiagram-v2
    [*] --> ZeroBalance: New User
    ZeroBalance --> HasCredits: Welcome Credits Added
    HasCredits --> Sufficient: Balance > Threshold
    Sufficient --> LowBalance: Usage Depletes
    LowBalance --> Sufficient: Top-Up
    LowBalance --> ZeroBalance: All Credits Used
    Sufficient --> ZeroBalance: Heavy Usage
    ZeroBalance --> HasCredits: Purchase Credits
    HasCredits --> Expiring: Approaching Expiry
    Expiring --> Expired: Expiry Date Reached
    Expired --> HasCredits: New Credits Added
    Expired --> ZeroBalance: All Expired
```

---

## Diagram Usage Guide

### Viewing Diagrams

These Mermaid diagrams can be viewed in:

1. **GitHub**: Automatically rendered in markdown files
2. **VS Code**: Install "Markdown Preview Mermaid Support" extension
3. **Online Editors**: 
   - https://mermaid.live/
   - https://mermaid.ink/
4. **Documentation Sites**: GitBook, Docusaurus, MkDocs with Mermaid plugin

### Diagram Types Used

- **Flowchart**: Process flows and decision trees
- **Sequence Diagram**: Interaction between components over time
- **Entity Relationship**: Database schema relationships
- **State Diagram**: State transitions and lifecycle
- **Journey**: User experience mapping

### Customization

To customize these diagrams:

1. Copy the Mermaid code block
2. Paste into https://mermaid.live/
3. Edit using Mermaid syntax
4. Export as PNG/SVG or copy updated code

---

**Document Version**: 1.0.0  
**Last Updated**: January 24, 2026  
**Maintained By**: AIKAFLOW Development Team
