USE TradewellDatabase;
GO

-- ═══════════════════════════════════════════════════════════
-- Reconstructed from every query in the VALE module that
-- references these two tables (create.php, edit.php, view.php,
-- payments.php). No original DDL was available — these were
-- wiped along with the rest of the project files.
-- ═══════════════════════════════════════════════════════════

IF OBJECT_ID('dbo.TBL_CashAdvance_Statement', 'U') IS NOT NULL
    DROP TABLE dbo.TBL_CashAdvance_Statement;
GO

IF OBJECT_ID('dbo.TBL_CashAdvance_Payment', 'U') IS NOT NULL
    DROP TABLE dbo.TBL_CashAdvance_Payment;
GO


-- Payment table first (Statement references it via PaymentID)
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
