-- ══════════════════════════════════════════════════════════════════
--  SCRIPT 2 OF 3 — Transfer Data from PIRS to TradewellDatabase
--  Run this AFTER 01_CREATE_TABLES.sql
--  Also run this every time you restore a fresh backup
-- ══════════════════════════════════════════════════════════════════

USE TradewellDatabase;
GO

-- ── Safety: Clear existing data in correct order (child → parent) ─
PRINT 'Clearing existing data...';
DELETE FROM [dbo].[UniformReceivingItems];
DELETE FROM [dbo].[UniformReceiving];
DELETE FROM [dbo].[UniformPOItems];
DELETE FROM [dbo].[UniformPO];
DELETE FROM [dbo].[UniformReleased];
DELETE FROM [dbo].[UniformRequests];
DELETE FROM [dbo].[UniformStock];
DELETE FROM [dbo].[po_items];
DELETE FROM [dbo].[purchase_orders];
DELETE FROM [dbo].[po_categories];
PRINT 'Existing data cleared.';
GO

-- ── 1. po_categories ──────────────────────────────────────────────
PRINT 'Transferring po_categories...';
SET IDENTITY_INSERT [dbo].[po_categories] ON;
INSERT INTO [dbo].[po_categories] (category_id, category_name, description, created_at)
SELECT category_id, category_name, description, created_at
FROM [PIRS].[dbo].[po_categories];
SET IDENTITY_INSERT [dbo].[po_categories] OFF;
PRINT 'po_categories done.';
GO

-- ── 2. purchase_orders ────────────────────────────────────────────
PRINT 'Transferring purchase_orders...';
SET IDENTITY_INSERT [dbo].[purchase_orders] ON;
INSERT INTO [dbo].[purchase_orders] (id, category_id, po_number, supplier, amount, status, remarks, created_by, created_at, updated_at)
SELECT id, category_id, po_number, supplier, amount, status, remarks, created_by, created_at, updated_at
FROM [PIRS].[dbo].[purchase_orders];
SET IDENTITY_INSERT [dbo].[purchase_orders] OFF;
PRINT 'purchase_orders done.';
GO

-- ── 3. po_items ───────────────────────────────────────────────────
PRINT 'Transferring po_items...';
SET IDENTITY_INSERT [dbo].[po_items] ON;
INSERT INTO [dbo].[po_items] (id, purchase_order_id, description, quantity, unit_price, total_price, remarks)
SELECT id, purchase_order_id, description, quantity, unit_price, total_price, remarks
FROM [PIRS].[dbo].[po_items];
SET IDENTITY_INSERT [dbo].[po_items] OFF;
PRINT 'po_items done.';
GO

-- ── 4. UniformStock ───────────────────────────────────────────────
PRINT 'Transferring UniformStock...';
SET IDENTITY_INSERT [dbo].[UniformStock] ON;
INSERT INTO [dbo].[UniformStock] (StockID, UniformType, Size, PreviousStock, AdditionalStock, LessStock, UpdatedAt, UpdatedBy)
SELECT StockID, UniformType, Size, PreviousStock, AdditionalStock, LessStock, UpdatedAt, UpdatedBy
FROM [PIRS].[dbo].[UniformStock];
SET IDENTITY_INSERT [dbo].[UniformStock] OFF;
PRINT 'UniformStock done.';
GO

-- ── 5. UniformRequests ────────────────────────────────────────────
PRINT 'Transferring UniformRequests...';
SET IDENTITY_INSERT [dbo].[UniformRequests] ON;
INSERT INTO [dbo].[UniformRequests] (RequestID, EmployeeName, RequestedBy, UniformType, UniformSize, Quantity, Department, DateRequested, Remarks, IsGiven, DateGiven, GivenBy, CreatedBy, CreatedAt)
SELECT RequestID, EmployeeName, RequestedBy, UniformType, UniformSize, Quantity, Department, DateRequested, Remarks, IsGiven, DateGiven, GivenBy, CreatedBy, CreatedAt
FROM [PIRS].[dbo].[UniformRequests];
SET IDENTITY_INSERT [dbo].[UniformRequests] OFF;
PRINT 'UniformRequests done.';
GO

-- ── 6. UniformReleased ────────────────────────────────────────────
PRINT 'Transferring UniformReleased...';
SET IDENTITY_INSERT [dbo].[UniformReleased] ON;
INSERT INTO [dbo].[UniformReleased] (ReleasedID, EmployeeName, UniformType, UniformSize, Quantity, Department, DateGiven, RequestedBy, Remarks, CreatedBy, CreatedAt, RequestID)
SELECT ReleasedID, EmployeeName, UniformType, UniformSize, Quantity, Department, DateGiven, RequestedBy, Remarks, CreatedBy, CreatedAt, RequestID
FROM [PIRS].[dbo].[UniformReleased];
SET IDENTITY_INSERT [dbo].[UniformReleased] OFF;
PRINT 'UniformReleased done.';
GO

-- ── 7. UniformPO ──────────────────────────────────────────────────
PRINT 'Transferring UniformPO...';
SET IDENTITY_INSERT [dbo].[UniformPO] ON;
INSERT INTO [dbo].[UniformPO] (POID, PONumber, PODate, Supplier, Remarks, CreatedBy, CreatedAt)
SELECT POID, PONumber, PODate, Supplier, Remarks, CreatedBy, CreatedAt
FROM [PIRS].[dbo].[UniformPO];
SET IDENTITY_INSERT [dbo].[UniformPO] OFF;
PRINT 'UniformPO done.';
GO

-- ── 8. UniformPOItems ─────────────────────────────────────────────
PRINT 'Transferring UniformPOItems...';
SET IDENTITY_INSERT [dbo].[UniformPOItems] ON;
INSERT INTO [dbo].[UniformPOItems] (POItemID, POID, UniformType, Size, Requested, Additional)
SELECT POItemID, POID, UniformType, Size, Requested, Additional
FROM [PIRS].[dbo].[UniformPOItems];
SET IDENTITY_INSERT [dbo].[UniformPOItems] OFF;
PRINT 'UniformPOItems done.';
GO

-- ── 9. UniformReceiving ───────────────────────────────────────────
PRINT 'Transferring UniformReceiving...';
SET IDENTITY_INSERT [dbo].[UniformReceiving] ON;
INSERT INTO [dbo].[UniformReceiving] (RFID, POID, RFNumber, RFDate, DateReceived, PrintingShop, PrintShop, RepresentativeThem, RepresentativeUs, UniformType, CreatedBy, CreatedAt)
SELECT RFID, POID, RFNumber, RFDate, DateReceived, PrintingShop, PrintShop, RepresentativeThem, RepresentativeUs, UniformType, CreatedBy, CreatedAt
FROM [PIRS].[dbo].[UniformReceiving];
SET IDENTITY_INSERT [dbo].[UniformReceiving] OFF;
PRINT 'UniformReceiving done.';
GO

-- ── 10. UniformReceivingItems ─────────────────────────────────────
PRINT 'Transferring UniformReceivingItems...';
SET IDENTITY_INSERT [dbo].[UniformReceivingItems] ON;
INSERT INTO [dbo].[UniformReceivingItems] (RFItemID, RFID, UniformType, Size, Quantity)
SELECT RFItemID, RFID, UniformType, Size, Quantity
FROM [PIRS].[dbo].[UniformReceivingItems];
SET IDENTITY_INSERT [dbo].[UniformReceivingItems] OFF;
PRINT 'UniformReceivingItems done.';
GO

-- ── Verify Row Counts ─────────────────────────────────────────────
PRINT '';
PRINT 'Verifying row counts...';
SELECT 'po_categories'        AS TableName, COUNT(*) AS TotalRows FROM [dbo].[po_categories]        UNION ALL
SELECT 'purchase_orders',                   COUNT(*)               FROM [dbo].[purchase_orders]      UNION ALL
SELECT 'po_items',                          COUNT(*)               FROM [dbo].[po_items]             UNION ALL
SELECT 'UniformStock',                      COUNT(*)               FROM [dbo].[UniformStock]         UNION ALL
SELECT 'UniformRequests',                   COUNT(*)               FROM [dbo].[UniformRequests]      UNION ALL
SELECT 'UniformReleased',                   COUNT(*)               FROM [dbo].[UniformReleased]      UNION ALL
SELECT 'UniformPO',                         COUNT(*)               FROM [dbo].[UniformPO]            UNION ALL
SELECT 'UniformPOItems',                    COUNT(*)               FROM [dbo].[UniformPOItems]       UNION ALL
SELECT 'UniformReceiving',                  COUNT(*)               FROM [dbo].[UniformReceiving]     UNION ALL
SELECT 'UniformReceivingItems',             COUNT(*)               FROM [dbo].[UniformReceivingItems]

PRINT '';
PRINT '==========================================';
PRINT '  SCRIPT 2 COMPLETE — Data transferred.';
PRINT '  Run 03_FIXES.sql next.';
PRINT '==========================================';
