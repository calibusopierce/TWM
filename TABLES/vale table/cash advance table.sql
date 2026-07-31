CREATE TABLE TBL_CashAdvance (
    CashAdvanceID       INT IDENTITY(1,1) PRIMARY KEY,
    EmployeeID           INT NOT NULL,
    Amount                DECIMAL(12,2) NOT NULL,
    Reason                VARCHAR(255) NULL,
    RequestDate           DATETIME NOT NULL DEFAULT GETDATE(),

    -- Recommendation (now REQUIRED)
    RecommendByID         INT NOT NULL,        -- FK to employee/user table
    RecommendDate         DATETIME NULL,
    RecommendRemarks      VARCHAR(255) NULL,   -- keeping this optional, just context notes

    -- Approval / Receiving (same approver handles both, gated by cash_advance_record RBAC)
    ApprovedByID           INT NULL,
    ApprovedDate            DATETIME NULL,
    ReceivedDate            DATETIME NULL,

    Status                 VARCHAR(20) NOT NULL DEFAULT 'Requested',  -- Requested / Approved / Received
    Department              VARCHAR(100) NULL,
    Branch                   VARCHAR(100) NULL,
    Remarks                 VARCHAR(255) NULL,

    CreatedBy               INT NOT NULL,
    CreatedDate              DATETIME NOT NULL DEFAULT GETDATE(),
    ModifiedBy               INT NULL,
    ModifiedDate              DATETIME NULL
);