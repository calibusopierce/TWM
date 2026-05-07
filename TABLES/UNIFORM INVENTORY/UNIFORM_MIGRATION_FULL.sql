-- ══════════════════════════════════════════════════════════════════
--  UNIFORM INVENTORY — FULL MIGRATION SCRIPT (FINAL v2)
--  Target Database : TradewellDatabase
--  Source Database : PIRS (backup tables prefixed with _bak_)
--
--  STEP ORDER:
--    SECTION 0 — Safety reset (clears any stuck IDENTITY_INSERT)
--    SECTION 1 — Create all tables (safe, skips if already exists)
--    SECTION 2 — Transfer data from PIRS → TradewellDatabase
--    SECTION 3 — Fixes & constraints
--    SECTION 4 — RBAC verification
--
--  Run this script AFTER restoring the prod TradewellDatabase backup.
-- ══════════════════════════════════════════════════════════════════

USE TradewellDatabase;
GO

-- ══════════════════════════════════════════════════════════════════
PRINT '══════════════════════════════════════════';
PRINT '  SECTION 0 — SAFETY RESET';
PRINT '══════════════════════════════════════════';
GO

-- Force OFF any stuck IDENTITY_INSERT from previous failed runs
-- Wrapped in IF EXISTS so fresh restores don't throw errors
IF EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME='po_categories')        SET IDENTITY_INSERT [dbo].[po_categories]        OFF;
IF EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME='purchase_orders')       SET IDENTITY_INSERT [dbo].[purchase_orders]       OFF;
IF EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME='po_items')              SET IDENTITY_INSERT [dbo].[po_items]              OFF;
IF EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME='UniformStock')          SET IDENTITY_INSERT [dbo].[UniformStock]          OFF;
IF EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME='UniformRequests')       SET IDENTITY_INSERT [dbo].[UniformRequests]       OFF;
IF EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME='UniformReleased')       SET IDENTITY_INSERT [dbo].[UniformReleased]       OFF;
IF EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME='UniformPO')             SET IDENTITY_INSERT [dbo].[UniformPO]             OFF;
IF EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME='UniformPOItems')        SET IDENTITY_INSERT [dbo].[UniformPOItems]        OFF;
IF EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME='UniformReceiving')      SET IDENTITY_INSERT [dbo].[UniformReceiving]      OFF;
IF EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME='UniformReceivingItems') SET IDENTITY_INSERT [dbo].[UniformReceivingItems] OFF;
IF EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME='UniformReturns')        SET IDENTITY_INSERT [dbo].[UniformReturns]        OFF;
PRINT 'IDENTITY_INSERT reset on all tables.';
GO

PRINT '';
PRINT 'SECTION 0 COMPLETE — Safety reset done.';
PRINT '';
GO

-- ══════════════════════════════════════════════════════════════════
PRINT '══════════════════════════════════════════';
PRINT '  SECTION 1 — CREATE TABLES';
PRINT '══════════════════════════════════════════';
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
        [ReturnStock]     INT           NOT NULL DEFAULT 0,
        [UpdatedAt]       DATETIME      NULL,
        [UpdatedBy]       NVARCHAR(100) NULL,
        CONSTRAINT [UQ_UniformStock_TypeSize] UNIQUE ([UniformType], [Size])
    );
    PRINT 'UniformStock created.';
END
ELSE
BEGIN
    -- Add ReturnStock if missing (hotfix for existing installs)
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_NAME = 'UniformStock' AND COLUMN_NAME = 'ReturnStock'
    )
    BEGIN
        ALTER TABLE [dbo].[UniformStock] ADD ReturnStock INT NOT NULL DEFAULT 0;
        PRINT 'UniformStock — ReturnStock column added.';
    END
    ELSE
        PRINT 'UniformStock already exists — skipped.';
END
GO

-- ── 5. UniformRequests ────────────────────────────────────────────
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

-- ── 6. UniformReleased ────────────────────────────────────────────
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

-- ── 7. UniformPO ──────────────────────────────────────────────────
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

-- ── 8. UniformPOItems ─────────────────────────────────────────────
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

-- ── 9. UniformReceiving ───────────────────────────────────────────
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
        [IsPosted]           BIT           NOT NULL DEFAULT 0,
        [PostedAt]           DATETIME      NULL,
        [PostedBy]           NVARCHAR(100) NULL,
        [CreatedBy]          NVARCHAR(100) NULL,
        [CreatedAt]          DATETIME      NOT NULL DEFAULT GETDATE()
    );
    PRINT 'UniformReceiving created.';
END
ELSE
BEGIN
    -- Add IsPosted/PostedAt/PostedBy if missing
    IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='UniformReceiving' AND COLUMN_NAME='IsPosted')
    BEGIN
        ALTER TABLE [dbo].[UniformReceiving] ADD IsPosted BIT NOT NULL DEFAULT 0;
        ALTER TABLE [dbo].[UniformReceiving] ADD PostedAt DATETIME NULL;
        ALTER TABLE [dbo].[UniformReceiving] ADD PostedBy NVARCHAR(100) NULL;
        PRINT 'UniformReceiving — IsPosted/PostedAt/PostedBy columns added.';
    END
    ELSE
        PRINT 'UniformReceiving already exists — skipped.';
END
GO

-- ── 10. UniformReceivingItems ─────────────────────────────────────
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

-- ── 11. UniformReturns ────────────────────────────────────────────
IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'UniformReturns')
BEGIN
    CREATE TABLE [dbo].[UniformReturns] (
        [ReturnID]     INT           NOT NULL IDENTITY(1,1) PRIMARY KEY,
        [ReleasedID]   INT           NULL,
        [EmployeeName] NVARCHAR(255) NOT NULL,
        [UniformType]  NVARCHAR(50)  NOT NULL,
        [UniformSize]  NVARCHAR(20)  NOT NULL,
        [Quantity]     INT           NOT NULL DEFAULT 1,
        [Department]   NVARCHAR(100) NULL,
        [DateReturned] DATE          NOT NULL,
        [Condition]    NVARCHAR(20)  NOT NULL DEFAULT 'Good',
        [ReturnedTo]   NVARCHAR(255) NULL,
        [Remarks]      NVARCHAR(500) NULL,
        [CreatedBy]    NVARCHAR(255) NULL,
        [CreatedAt]    DATETIME      NOT NULL DEFAULT GETDATE()
    );
    PRINT 'UniformReturns created.';
END
ELSE
    PRINT 'UniformReturns already exists — skipped.';
GO

-- ── 12. vw_UniformStock (View) ────────────────────────────────────
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
    ReturnStock,
    (PreviousStock + AdditionalStock + ReturnStock - LessStock) AS CurrentStock,
    UpdatedAt,
    UpdatedBy
FROM [dbo].[UniformStock];
GO
PRINT 'vw_UniformStock view created.';
GO

PRINT '';
PRINT 'SECTION 1 COMPLETE — All tables ready.';
PRINT '';
GO

-- ══════════════════════════════════════════════════════════════════
PRINT '══════════════════════════════════════════';
PRINT '  SECTION 2 — TRANSFER DATA FROM PIRS';
PRINT '══════════════════════════════════════════';
GO

-- ── Clear existing uniform data (child → parent order) ────────────
PRINT 'Clearing existing uniform data...';
DELETE FROM [dbo].[UniformReturns];
DELETE FROM [dbo].[UniformReceivingItems];
DELETE FROM [dbo].[UniformReceiving];
DELETE FROM [dbo].[UniformPOItems];
DELETE FROM [dbo].[UniformPO];
DELETE FROM [dbo].[UniformReleased];
DELETE FROM [dbo].[UniformRequests];
DELETE FROM [dbo].[UniformStock];
PRINT 'Existing uniform data cleared.';
GO

-- ── 1. UniformStock ───────────────────────────────────────────────
PRINT 'Transferring UniformStock...';
SET IDENTITY_INSERT [dbo].[UniformStock] ON;
INSERT INTO [dbo].[UniformStock] (StockID, UniformType, Size, PreviousStock, AdditionalStock, LessStock, ReturnStock, UpdatedAt, UpdatedBy)
SELECT StockID, UniformType, Size, PreviousStock, AdditionalStock, LessStock,
       ISNULL(ReturnStock, 0), UpdatedAt, UpdatedBy
FROM [PIRS].[dbo].[_bak_UniformStock];
SET IDENTITY_INSERT [dbo].[UniformStock] OFF;
PRINT 'UniformStock done.';
GO

-- ── 2. UniformRequests ────────────────────────────────────────────
PRINT 'Transferring UniformRequests...';
SET IDENTITY_INSERT [dbo].[UniformRequests] ON;
INSERT INTO [dbo].[UniformRequests] (RequestID, EmployeeName, RequestedBy, UniformType, UniformSize, Quantity, Department, DateRequested, Remarks, IsGiven, DateGiven, GivenBy, CreatedBy, CreatedAt)
SELECT RequestID, EmployeeName, RequestedBy, UniformType, UniformSize, Quantity, Department, DateRequested, Remarks, IsGiven, DateGiven, GivenBy, CreatedBy, CreatedAt
FROM [PIRS].[dbo].[_bak_UniformRequests];
SET IDENTITY_INSERT [dbo].[UniformRequests] OFF;
PRINT 'UniformRequests done.';
GO

-- ── 3. UniformReleased ────────────────────────────────────────────
PRINT 'Transferring UniformReleased...';
SET IDENTITY_INSERT [dbo].[UniformReleased] ON;
INSERT INTO [dbo].[UniformReleased] (ReleasedID, EmployeeName, UniformType, UniformSize, Quantity, Department, DateGiven, RequestedBy, Remarks, CreatedBy, CreatedAt, RequestID)
SELECT ReleasedID, EmployeeName, UniformType, UniformSize, Quantity, Department, DateGiven, RequestedBy, Remarks, CreatedBy, CreatedAt, RequestID
FROM [PIRS].[dbo].[_bak_UniformReleased];
SET IDENTITY_INSERT [dbo].[UniformReleased] OFF;
PRINT 'UniformReleased done.';
GO

-- ── 4. UniformPO ──────────────────────────────────────────────────
PRINT 'Transferring UniformPO...';
SET IDENTITY_INSERT [dbo].[UniformPO] ON;
INSERT INTO [dbo].[UniformPO] (POID, PONumber, PODate, Supplier, Remarks, CreatedBy, CreatedAt)
SELECT POID, PONumber, PODate, Supplier, Remarks, CreatedBy, CreatedAt
FROM [PIRS].[dbo].[_bak_UniformPO];
SET IDENTITY_INSERT [dbo].[UniformPO] OFF;
PRINT 'UniformPO done.';
GO

-- ── 5. UniformPOItems ─────────────────────────────────────────────
PRINT 'Transferring UniformPOItems...';
SET IDENTITY_INSERT [dbo].[UniformPOItems] ON;
INSERT INTO [dbo].[UniformPOItems] (POItemID, POID, UniformType, Size, Requested, Additional)
SELECT POItemID, POID, UniformType, Size, Requested, Additional
FROM [PIRS].[dbo].[_bak_UniformPOItems];
SET IDENTITY_INSERT [dbo].[UniformPOItems] OFF;
PRINT 'UniformPOItems done.';
GO

-- ── 6. UniformReceiving ───────────────────────────────────────────
PRINT 'Transferring UniformReceiving...';
SET IDENTITY_INSERT [dbo].[UniformReceiving] ON;
INSERT INTO [dbo].[UniformReceiving] (RFID, POID, RFNumber, RFDate, DateReceived, PrintingShop, PrintShop, RepresentativeThem, RepresentativeUs, UniformType, IsPosted, PostedAt, PostedBy, CreatedBy, CreatedAt)
SELECT RFID, POID, RFNumber, RFDate, DateReceived, PrintingShop, PrintShop, RepresentativeThem, RepresentativeUs, UniformType,
       ISNULL(IsPosted, 0), PostedAt, PostedBy, CreatedBy, CreatedAt
FROM [PIRS].[dbo].[_bak_UniformReceiving];
SET IDENTITY_INSERT [dbo].[UniformReceiving] OFF;
PRINT 'UniformReceiving done.';
GO

-- ── 7. UniformReceivingItems ──────────────────────────────────────
PRINT 'Transferring UniformReceivingItems...';
SET IDENTITY_INSERT [dbo].[UniformReceivingItems] ON;
INSERT INTO [dbo].[UniformReceivingItems] (RFItemID, RFID, UniformType, Size, Quantity)
SELECT RFItemID, RFID, UniformType, Size, Quantity
FROM [PIRS].[dbo].[_bak_UniformReceivingItems];
SET IDENTITY_INSERT [dbo].[UniformReceivingItems] OFF;
PRINT 'UniformReceivingItems done.';
GO

-- ── 8. UniformReturns ─────────────────────────────────────────────
PRINT 'Transferring UniformReturns...';
SET IDENTITY_INSERT [dbo].[UniformReturns] ON;
INSERT INTO [dbo].[UniformReturns] (ReturnID, ReleasedID, EmployeeName, UniformType, UniformSize, Quantity, Department, DateReturned, Condition, ReturnedTo, Remarks, CreatedBy, CreatedAt)
SELECT ReturnID, ReleasedID, EmployeeName, UniformType, UniformSize, Quantity, Department, DateReturned, Condition, ReturnedTo, Remarks, CreatedBy, CreatedAt
FROM [PIRS].[dbo].[_bak_UniformReturns];
SET IDENTITY_INSERT [dbo].[UniformReturns] OFF;
PRINT 'UniformReturns done.';
GO

-- ── Row Count Verification ────────────────────────────────────────
PRINT '';
PRINT 'Verifying row counts...';
SELECT 'UniformStock'          AS TableName, COUNT(*) AS TotalRows FROM [dbo].[UniformStock]          UNION ALL
SELECT 'UniformRequests',                    COUNT(*)               FROM [dbo].[UniformRequests]       UNION ALL
SELECT 'UniformReleased',                    COUNT(*)               FROM [dbo].[UniformReleased]       UNION ALL
SELECT 'UniformPO',                          COUNT(*)               FROM [dbo].[UniformPO]             UNION ALL
SELECT 'UniformPOItems',                     COUNT(*)               FROM [dbo].[UniformPOItems]        UNION ALL
SELECT 'UniformReceiving',                   COUNT(*)               FROM [dbo].[UniformReceiving]      UNION ALL
SELECT 'UniformReceivingItems',              COUNT(*)               FROM [dbo].[UniformReceivingItems] UNION ALL
SELECT 'UniformReturns',                     COUNT(*)               FROM [dbo].[UniformReturns];
GO

PRINT '';
PRINT 'SECTION 2 COMPLETE — Data transferred.';
PRINT '';
GO

-- ══════════════════════════════════════════════════════════════════
PRINT '══════════════════════════════════════════';
PRINT '  SECTION 3 — FIXES & CONSTRAINTS';
PRINT '══════════════════════════════════════════';
GO

-- ── Fix 1: IsGiven default constraint ─────────────────────────────
IF NOT EXISTS (
    SELECT 1 FROM sys.default_constraints dc
    JOIN sys.columns c ON dc.parent_object_id = c.object_id
                      AND dc.parent_column_id  = c.column_id
    WHERE dc.parent_object_id = OBJECT_ID('dbo.UniformRequests')
    AND   c.name = 'IsGiven'
)
BEGIN
    ALTER TABLE [dbo].[UniformRequests]
    ADD CONSTRAINT DF_UniformRequests_IsGiven DEFAULT 0 FOR IsGiven;
    PRINT 'Fix 1: IsGiven default constraint added.';
END
ELSE
    PRINT 'Fix 1: IsGiven default constraint already exists — skipped.';
GO

-- ── Fix 2: Recreate vw_UniformStock ───────────────────────────────
IF EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.VIEWS WHERE TABLE_NAME = 'vw_UniformStock')
BEGIN
    DROP VIEW [dbo].[vw_UniformStock];
    PRINT 'Fix 2: vw_UniformStock dropped for recreation.';
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
    ReturnStock,
    (PreviousStock + AdditionalStock + ReturnStock - LessStock) AS CurrentStock,
    UpdatedAt,
    UpdatedBy
FROM [dbo].[UniformStock];
GO
PRINT 'Fix 2: vw_UniformStock view recreated.';
GO

-- ── Verify view returns data ───────────────────────────────────────
PRINT 'Verifying vw_UniformStock...';
SELECT UniformType, Size, ReturnStock, CurrentStock
FROM [dbo].[vw_UniformStock]
ORDER BY UniformType, Size;
GO

PRINT '';
PRINT 'SECTION 3 COMPLETE — Fixes applied.';
PRINT '';
GO

-- ══════════════════════════════════════════════════════════════════
PRINT '══════════════════════════════════════════';
PRINT '  SECTION 4 — RBAC VERIFICATION';
PRINT '══════════════════════════════════════════';
GO

IF EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'rbac_modules')
BEGIN
    PRINT 'Checking rbac_modules...';
    SELECT * FROM [dbo].[rbac_modules] WHERE module_key = 'uniform_inventory';
END
ELSE
    PRINT 'rbac_modules not found — skipped. Insert RBAC entries manually if needed.';
GO

IF EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'rbac_permissions')
BEGIN
    PRINT 'Checking rbac_permissions...';
    SELECT * FROM [dbo].[rbac_permissions] WHERE module_key = 'uniform_inventory';
END
ELSE
    PRINT 'rbac_permissions not found — skipped. Insert RBAC entries manually if needed.';
GO

-- ── If either query above returns 0 rows, uncomment and run below ─
/*
INSERT INTO [dbo].[rbac_modules]
    (module_key, module_name, category, icon, color, description, sort_order, created_at)
VALUES
    ('uniform_inventory', 'Uniform Inventory', 'hr', 'bi-bag-fill', 'green',
     'Manage uniform stock, issuances, and returns', 30, '2026-04-27 11:11:19.767');

INSERT INTO [dbo].[rbac_permissions]
    (role_name, module_key, can_access, granted_by, granted_at)
VALUES
    ('Admin',         'uniform_inventory', 1, 'system',   '2026-04-27 11:11:19.777'),
    ('Tester',        'uniform_inventory', 1, 'pierce16', '2026-04-27 11:18:53.903'),
    ('Administrator', 'uniform_inventory', 1, 'pierce16', '2026-04-27 14:00:37.880'),
    ('HR',            'uniform_inventory', 1, 'pierce16', '2026-04-27 14:00:57.920');
*/

PRINT '';
PRINT '══════════════════════════════════════════';
PRINT '  MIGRATION COMPLETE!';
PRINT '  Uniform Inventory is ready to use.';
PRINT '══════════════════════════════════════════';
GO