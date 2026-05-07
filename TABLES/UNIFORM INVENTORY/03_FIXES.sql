-- ══════════════════════════════════════════════════════════════════
--  SCRIPT 3 OF 3 — Fixes & Constraints
--  Run this AFTER 02_TRANSFER_DATA.sql
--  Only needs to be run ONCE on a fresh database
-- ══════════════════════════════════════════════════════════════════

USE TradewellDatabase;
GO

-- ── Fix 1: IsGiven default constraint ─────────────────────────────
-- Prevents "Cannot insert NULL into IsGiven" error when saving requests
IF NOT EXISTS (
    SELECT 1 FROM sys.default_constraints
    WHERE name = 'DF_UniformRequests_IsGiven'
)
BEGIN
    ALTER TABLE [dbo].[UniformRequests]
    ADD CONSTRAINT DF_UniformRequests_IsGiven DEFAULT 0 FOR IsGiven;
    PRINT 'Fix 1: IsGiven default constraint added.';
END
ELSE
    PRINT 'Fix 1: IsGiven default constraint already exists — skipped.';
GO

-- ── Fix 2: Recreate vw_UniformStock view ──────────────────────────
-- Ensures the stock display works correctly on the website
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
    (PreviousStock + AdditionalStock - LessStock) AS CurrentStock,
    UpdatedAt,
    UpdatedBy
FROM [dbo].[UniformStock];
GO
PRINT 'Fix 2: vw_UniformStock view recreated.';
GO

-- ── Verify view returns data ───────────────────────────────────────
PRINT 'Verifying vw_UniformStock...';
SELECT UniformType, Size, CurrentStock FROM [dbo].[vw_UniformStock] ORDER BY UniformType, Size;
GO

PRINT '';
PRINT '==========================================';
PRINT '  SCRIPT 3 COMPLETE — All fixes applied.';
PRINT '  Your system is ready to use!';
PRINT '==========================================';
