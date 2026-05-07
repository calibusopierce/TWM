-- ══════════════════════════════════════════════════════════════════
--  SCRIPT 1 OF 3 — Create All Tables
--  Target Database: TradewellDatabase
--  Run this FIRST on a fresh database
-- ══════════════════════════════════════════════════════════════════

USE TradewellDatabase;
GO

-- ── 1. po_categories ──────────────────────────────────────────────
IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'po_categories')
BEGIN
    CREATE TABLE [dbo].[po_categories] (
        [category_id]   INT            NOT NULL IDENTITY(1,1) PRIMARY KEY,
        [category_name] NVARCHAR(100)  NOT NULL,
        [description]   NVARCHAR(255)  NULL,
        [created_at]    DATETIME       NOT NULL DEFAULT GETDATE()
    );
    PRINT 'po_categories created.';
END
ELSE
    PRINT 'po_categories already exists — skipped.';
GO

-- ── 2. purchase_orders ────────────────────────────────────────────
IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'purchase_orders')
BEGIN
    CREATE TABLE [dbo].[purchase_orders] (
        [id]          INT            NOT NULL IDENTITY(1,1) PRIMARY KEY,
        [category_id] INT            NULL REFERENCES [dbo].[po_categories]([category_id]),
        [po_number]   NVARCHAR(50)   NOT NULL,
        [supplier]    NVARCHAR(200)  NULL,
        [amount]      DECIMAL(18,2)  NULL,
        [status]      NVARCHAR(50)   NULL DEFAULT 'Pending',
        [remarks]     NVARCHAR(MAX)  NULL,
        [created_by]  NVARCHAR(100)  NULL,
        [created_at]  DATETIME       NOT NULL DEFAULT GETDATE(),
        [updated_at]  DATETIME       NULL
    );
    PRINT 'purchase_orders created.';
END
ELSE
    PRINT 'purchase_orders already exists — skipped.';
GO

-- ── 3. po_items ───────────────────────────────────────────────────
IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'po_items')
BEGIN
    CREATE TABLE [dbo].[po_items] (
        [id]                INT            NOT NULL IDENTITY(1,1) PRIMARY KEY,
        [purchase_order_id] INT            NOT NULL REFERENCES [dbo].[purchase_orders]([id]) ON DELETE CASCADE,
        [description]       NVARCHAR(255)  NOT NULL,
        [quantity]          INT            NOT NULL DEFAULT 1,
        [unit_price]        DECIMAL(18,2)  NULL,
        [total_price]       DECIMAL(18,2)  NULL,
        [remarks]           NVARCHAR(MAX)  NULL
    );
    PRINT 'po_items created.';
END
ELSE
    PRINT 'po_items already exists — skipped.';
GO

-- ── 4. UniformStock ───────────────────────────────────────────────
IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'UniformStock')
BEGIN
    CREATE TABLE [dbo].[UniformStock] (
        [StockID]         INT           NOT NULL IDENTITY(1,1) PRIMARY KEY,
        [UniformType]     NVARCHAR(50)  NOT NULL,
        [Size]            NVARCHAR(10)  NOT NULL,
        [PreviousStock]   INT           NOT NULL DEFAULT 0,
        [AdditionalStock] INT           NOT NULL DEFAULT 0,
        [LessStock]       INT           NOT NULL DEFAULT 0,
        [UpdatedAt]       DATETIME      NULL,
        [UpdatedBy]       NVARCHAR(100) NULL,
        CONSTRAINT [UQ_UniformStock_TypeSize] UNIQUE ([UniformType], [Size])
    );
    PRINT 'UniformStock created.';
END
ELSE
    PRINT 'UniformStock already exists — skipped.';
GO

-- ── 5. vw_UniformStock (View) ─────────────────────────────────────
IF EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.VIEWS WHERE TABLE_NAME = 'vw_UniformStock')
BEGIN
    DROP VIEW [dbo].[vw_UniformStock];
    PRINT 'vw_UniformStock dropped for recreation.';
END
GO
CREATE VIEW [dbo].[vw_UniformStock] AS
SELECT
    StockID,
    UniformType,
    Size,
    PreviousStock,
    AdditionalStock,
    LessStock,
    (PreviousStock + AdditionalStock - LessStock) AS CurrentStock,
    UpdatedAt,
    UpdatedBy
FROM [dbo].[UniformStock];
GO
PRINT 'vw_UniformStock view created.';
GO

-- ── 6. UniformRequests ────────────────────────────────────────────
IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'UniformRequests')
BEGIN
    CREATE TABLE [dbo].[UniformRequests] (
        [RequestID]     INT           NOT NULL IDENTITY(1,1) PRIMARY KEY,
        [EmployeeName]  NVARCHAR(150) NULL,
        [RequestedBy]   NVARCHAR(150) NOT NULL,
        [UniformType]   NVARCHAR(50)  NOT NULL,
        [UniformSize]   NVARCHAR(10)  NOT NULL,
        [Quantity]      INT           NOT NULL DEFAULT 1,
        [Department]    NVARCHAR(100) NULL,
        [DateRequested] DATE          NULL DEFAULT CAST(GETDATE() AS DATE),
        [Remarks]       NVARCHAR(MAX) NULL,
        [IsGiven]       BIT           NOT NULL DEFAULT 0,
        [DateGiven]     DATE          NULL,
        [GivenBy]       NVARCHAR(100) NULL,
        [CreatedBy]     NVARCHAR(100) NULL,
        [CreatedAt]     DATETIME      NOT NULL DEFAULT GETDATE()
    );
    PRINT 'UniformRequests created.';
END
ELSE
    PRINT 'UniformRequests already exists — skipped.';
GO

-- ── 7. UniformReleased ────────────────────────────────────────────
IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'UniformReleased')
BEGIN
    CREATE TABLE [dbo].[UniformReleased] (
        [ReleasedID]   INT           NOT NULL IDENTITY(1,1) PRIMARY KEY,
        [EmployeeName] NVARCHAR(150) NOT NULL,
        [UniformType]  NVARCHAR(50)  NOT NULL,
        [UniformSize]  NVARCHAR(10)  NOT NULL,
        [Quantity]     INT           NOT NULL DEFAULT 1,
        [Department]   NVARCHAR(100) NULL,
        [DateGiven]    DATE          NULL,
        [RequestedBy]  NVARCHAR(150) NULL,
        [Remarks]      NVARCHAR(MAX) NULL,
        [CreatedBy]    NVARCHAR(100) NULL,
        [CreatedAt]    DATETIME      NOT NULL DEFAULT GETDATE(),
        [RequestID]    INT           NULL REFERENCES [dbo].[UniformRequests]([RequestID])
    );
    PRINT 'UniformReleased created.';
END
ELSE
    PRINT 'UniformReleased already exists — skipped.';
GO

-- ── 8. UniformPO ──────────────────────────────────────────────────
IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'UniformPO')
BEGIN
    CREATE TABLE [dbo].[UniformPO] (
        [POID]      INT           NOT NULL IDENTITY(1,1) PRIMARY KEY,
        [PONumber]  NVARCHAR(50)  NOT NULL UNIQUE,
        [PODate]    DATE          NULL DEFAULT CAST(GETDATE() AS DATE),
        [Supplier]  NVARCHAR(200) NULL,
        [Remarks]   NVARCHAR(MAX) NULL,
        [CreatedBy] NVARCHAR(100) NULL,
        [CreatedAt] DATETIME      NOT NULL DEFAULT GETDATE()
    );
    PRINT 'UniformPO created.';
END
ELSE
    PRINT 'UniformPO already exists — skipped.';
GO

-- ── 9. UniformPOItems ─────────────────────────────────────────────
IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'UniformPOItems')
BEGIN
    CREATE TABLE [dbo].[UniformPOItems] (
        [POItemID]    INT          NOT NULL IDENTITY(1,1) PRIMARY KEY,
        [POID]        INT          NOT NULL REFERENCES [dbo].[UniformPO]([POID]) ON DELETE CASCADE,
        [UniformType] NVARCHAR(50) NOT NULL,
        [Size]        NVARCHAR(10) NOT NULL,
        [Requested]   INT          NOT NULL DEFAULT 0,
        [Additional]  INT          NOT NULL DEFAULT 0
    );
    PRINT 'UniformPOItems created.';
END
ELSE
    PRINT 'UniformPOItems already exists — skipped.';
GO

-- ── 10. UniformReceiving ──────────────────────────────────────────
IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'UniformReceiving')
BEGIN
    CREATE TABLE [dbo].[UniformReceiving] (
        [RFID]               INT           NOT NULL IDENTITY(1,1) PRIMARY KEY,
        [POID]               INT           NOT NULL REFERENCES [dbo].[UniformPO]([POID]),
        [RFNumber]           NVARCHAR(100) NULL,
        [RFDate]             DATE          NULL,
        [DateReceived]       DATE          NULL,
        [PrintingShop]       NVARCHAR(200) NULL,
        [PrintShop]          NVARCHAR(200) NULL,
        [RepresentativeThem] NVARCHAR(150) NULL,
        [RepresentativeUs]   NVARCHAR(150) NULL,
        [UniformType]        NVARCHAR(50)  NULL,
        [CreatedBy]          NVARCHAR(100) NULL,
        [CreatedAt]          DATETIME      NOT NULL DEFAULT GETDATE()
    );
    PRINT 'UniformReceiving created.';
END
ELSE
    PRINT 'UniformReceiving already exists — skipped.';
GO

-- ── 11. UniformReceivingItems ─────────────────────────────────────
IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'UniformReceivingItems')
BEGIN
    CREATE TABLE [dbo].[UniformReceivingItems] (
        [RFItemID]    INT          NOT NULL IDENTITY(1,1) PRIMARY KEY,
        [RFID]        INT          NOT NULL REFERENCES [dbo].[UniformReceiving]([RFID]) ON DELETE CASCADE,
        [UniformType] NVARCHAR(50) NOT NULL,
        [Size]        NVARCHAR(10) NOT NULL,
        [Quantity]    INT          NOT NULL DEFAULT 0
    );
    PRINT 'UniformReceivingItems created.';
END
ELSE
    PRINT 'UniformReceivingItems already exists — skipped.';
GO

PRINT '';
PRINT '==========================================';
PRINT '  SCRIPT 1 COMPLETE — All tables ready.';
PRINT '  Run 02_TRANSFER_DATA.sql next.';
PRINT '==========================================';
