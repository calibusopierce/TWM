USE TradewellDatabase;
GO

-- ═══════════════════════════════════════════════════════════
-- VALE (Cash Advance) module — full production schema
-- Run once, in this order (FK dependency: Statement -> CashAdvance,
-- Statement -> Payment), all in this single script.
-- ═══════════════════════════════════════════════════════════

IF OBJECT_ID('dbo.TBL_CashAdvance_Statement', 'U') IS NOT NULL
    DROP TABLE dbo.TBL_CashAdvance_Statement;
GO

IF OBJECT_ID('dbo.TBL_CashAdvance_Payment', 'U') IS NOT NULL
    DROP TABLE dbo.TBL_CashAdvance_Payment;
GO

IF OBJECT_ID('dbo.TBL_CashAdvance', 'U') IS NOT NULL
    DROP TABLE dbo.TBL_CashAdvance;
GO


-- ── 1. TBL_CashAdvance ────────────────────────────────────────
CREATE TABLE dbo.TBL_CashAdvance
(
    -- Primary Key
    CashAdvanceID INT IDENTITY(1,1) NOT NULL,

    -- Employee
    EmployeeID VARCHAR(30) NOT NULL,

    -- Cash Advance Amount
    Amount DECIMAL(12,2) NOT NULL,

    -- Amount already paid/received
    PaidAmount DECIMAL(12,2) NOT NULL
        CONSTRAINT DF_TBL_CashAdvance_PaidAmount DEFAULT (0),

    -- Automatically calculated remaining balance
    -- NOTE: computed/persisted — never write to this column directly from PHP
    BalanceAmount AS (Amount - PaidAmount) PERSISTED,

    -- Request Details
    Reason VARCHAR(255) NULL,

    RequestDate DATETIME NOT NULL
        CONSTRAINT DF_TBL_CashAdvance_RequestDate DEFAULT (GETDATE()),


    -- Assigned Approver (chosen by requester at filing time, one of the fixed
    -- boss list — distinct from ApprovedByID, which records who actually acted)
    AssignedApproverID VARCHAR(30) NULL,


    -- Recommendation (optional — app allows filing without a recommender)
    RecommendByID VARCHAR(30) NULL,
    RecommendDate DATETIME NULL,
    RecommendRemarks VARCHAR(255) NULL,


    -- Approval
    ApprovedByID VARCHAR(30) NULL,
    ApprovedDate DATETIME NULL,


    -- Rejection
    RejectedByID VARCHAR(30) NULL,
    RejectedDate DATETIME NULL,
    RejectReason VARCHAR(255) NULL,


    -- Cash Release / Receipt
    ReceivedDate DATETIME NULL,


    -- Status
    Status VARCHAR(20) NOT NULL
        CONSTRAINT DF_TBL_CashAdvance_Status DEFAULT ('Requested'),


    -- Organization
    Department VARCHAR(100) NULL,
    Branch VARCHAR(100) NULL,


    -- Remarks
    Remarks VARCHAR(255) NULL,


    -- Audit Information
    CreatedBy VARCHAR(30) NOT NULL,

    CreatedDate DATETIME NOT NULL
        CONSTRAINT DF_TBL_CashAdvance_CreatedDate DEFAULT (GETDATE()),

    ModifiedBy VARCHAR(30) NULL,
    ModifiedDate DATETIME NULL,


    -- Primary Key
    CONSTRAINT PK_TBL_CashAdvance
        PRIMARY KEY CLUSTERED (CashAdvanceID),


    -- Amount must be greater than zero
    CONSTRAINT CK_TBL_CashAdvance_Amount
        CHECK (Amount > 0),


    -- Paid amount cannot be negative or exceed advance
    CONSTRAINT CK_TBL_CashAdvance_PaidAmount
        CHECK (PaidAmount >= 0 AND PaidAmount <= Amount),


    -- Allowed statuses
    CONSTRAINT CK_TBL_CashAdvance_Status
        CHECK
        (
            Status IN
            (
                'Requested',
                'Recommended',
                'Approved',
                'Rejected',
                'Released',
                'Received',
                'Partially Paid',
                'Paid',
                'Cancelled'
            )
        )
);
GO


-- ── 2. TBL_CashAdvance_Payment ─────────────────────────────────
-- (created before Statement since Statement FKs into it)
CREATE TABLE dbo.TBL_CashAdvance_Payment
(
    CashAdvancePaymentID INT IDENTITY(1,1) NOT NULL,

    PaymentDate      DATETIME       NOT NULL,
    PaymentAmount    DECIMAL(12,2)  NOT NULL,
    PaymentMethod    VARCHAR(30)    NULL,
    ReferenceNumber  VARCHAR(100)   NULL,

    UserInput        VARCHAR(30)    NOT NULL,
    InputDateTime    DATETIME       NOT NULL
        CONSTRAINT DF_TBL_CashAdvance_Payment_InputDateTime DEFAULT (GETDATE()),

    CONSTRAINT PK_TBL_CashAdvance_Payment
        PRIMARY KEY CLUSTERED (CashAdvancePaymentID),

    CONSTRAINT CK_TBL_CashAdvance_Payment_Amount
        CHECK (PaymentAmount > 0)
);
GO


-- ── 3. TBL_CashAdvance_Statement ───────────────────────────────
CREATE TABLE dbo.TBL_CashAdvance_Statement
(
    StatementID    INT IDENTITY(1,1) NOT NULL,

    CashAdvanceID  INT           NOT NULL,
    Due_Date       DATE          NOT NULL,
    Amortization_Amount DECIMAL(12,2) NOT NULL,

    -- NULL until a payment is recorded against this row
    PaymentID      INT NULL,

    CONSTRAINT PK_TBL_CashAdvance_Statement
        PRIMARY KEY CLUSTERED (StatementID),

    CONSTRAINT FK_TBL_CashAdvance_Statement_CashAdvance
        FOREIGN KEY (CashAdvanceID) REFERENCES dbo.TBL_CashAdvance (CashAdvanceID),

    CONSTRAINT FK_TBL_CashAdvance_Statement_Payment
        FOREIGN KEY (PaymentID) REFERENCES dbo.TBL_CashAdvance_Payment (CashAdvancePaymentID),

    CONSTRAINT CK_TBL_CashAdvance_Statement_Amount
        CHECK (Amortization_Amount > 0)
);
GO

CREATE INDEX IX_TBL_CashAdvance_Statement_CashAdvanceID
    ON dbo.TBL_CashAdvance_Statement (CashAdvanceID);
GO
