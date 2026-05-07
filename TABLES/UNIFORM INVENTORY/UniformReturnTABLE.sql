CREATE TABLE [dbo].[UniformReturns] (
    ReturnID       INT IDENTITY(1,1) PRIMARY KEY,
    ReleasedID     INT NULL,           -- optional link back to UniformReleased
    EmployeeName   NVARCHAR(255) NOT NULL,
    UniformType    NVARCHAR(50)  NOT NULL,
    UniformSize    NVARCHAR(20)  NOT NULL,
    Quantity       INT           NOT NULL DEFAULT 1,
    Department     NVARCHAR(100) NULL,
    DateReturned   DATE          NOT NULL,
    Condition      NVARCHAR(20)  NOT NULL DEFAULT 'Good', -- 'Good' | 'Damaged'
    ReturnedTo     NVARCHAR(255) NULL,   -- UTC staff who received it
    Remarks        NVARCHAR(500) NULL,
    CreatedBy      NVARCHAR(255) NULL,
    CreatedAt      DATETIME      DEFAULT GETDATE()
);