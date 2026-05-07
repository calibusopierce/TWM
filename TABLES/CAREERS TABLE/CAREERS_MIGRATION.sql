-- ══════════════════════════════════════════════════════════════════
--  CAREERS — FULL MIGRATION SCRIPT
--  Target Database : TradewellDatabase
--  Safe to re-run — all ALTER TABLE checks if column exists first
--
--  Covers:
--    SECTION 1 — JobApplications column additions
--    SECTION 2 — TBL_HREmployeeList ApplicationID column
--    SECTION 3 — TransferredToEmployee flag
--    SECTION 4 — Verification
-- ══════════════════════════════════════════════════════════════════

USE TradewellDatabase;
GO

PRINT '══════════════════════════════════════════';
PRINT '  SECTION 1 — JobApplications ALTER TABLE';
PRINT '══════════════════════════════════════════';
GO

-- ── Personal Name Fields ───────────────────────────────────────────
IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='JobApplications' AND COLUMN_NAME='FirstName')
BEGIN
    ALTER TABLE [dbo].[JobApplications] ADD [FirstName] NVARCHAR(50) NULL;
    PRINT 'FirstName added.';
END ELSE PRINT 'FirstName already exists — skipped.';
GO

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='JobApplications' AND COLUMN_NAME='MiddleName')
BEGIN
    ALTER TABLE [dbo].[JobApplications] ADD [MiddleName] NVARCHAR(50) NULL;
    PRINT 'MiddleName added.';
END ELSE PRINT 'MiddleName already exists — skipped.';
GO

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='JobApplications' AND COLUMN_NAME='LastName')
BEGIN
    ALTER TABLE [dbo].[JobApplications] ADD [LastName] NVARCHAR(50) NULL;
    PRINT 'LastName added.';
END ELSE PRINT 'LastName already exists — skipped.';
GO

-- ── Contact ────────────────────────────────────────────────────────
IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='JobApplications' AND COLUMN_NAME='Mobile_Number')
BEGIN
    ALTER TABLE [dbo].[JobApplications] ADD [Mobile_Number] NVARCHAR(50) NULL;
    PRINT 'Mobile_Number added.';
END ELSE PRINT 'Mobile_Number already exists — skipped.';
GO

-- ── Personal Info ──────────────────────────────────────────────────
IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='JobApplications' AND COLUMN_NAME='Birth_date')
BEGIN
    ALTER TABLE [dbo].[JobApplications] ADD [Birth_date] DATE NULL;
    PRINT 'Birth_date added.';
END ELSE PRINT 'Birth_date already exists — skipped.';
GO

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='JobApplications' AND COLUMN_NAME='Birth_Place')
BEGIN
    ALTER TABLE [dbo].[JobApplications] ADD [Birth_Place] NVARCHAR(50) NULL;
    PRINT 'Birth_Place added.';
END ELSE PRINT 'Birth_Place already exists — skipped.';
GO

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='JobApplications' AND COLUMN_NAME='Gender')
BEGIN
    ALTER TABLE [dbo].[JobApplications] ADD [Gender] NVARCHAR(30) NULL;
    PRINT 'Gender added.';
END ELSE PRINT 'Gender already exists — skipped.';
GO

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='JobApplications' AND COLUMN_NAME='Civil_Status')
BEGIN
    ALTER TABLE [dbo].[JobApplications] ADD [Civil_Status] NVARCHAR(30) NULL;
    PRINT 'Civil_Status added.';
END ELSE PRINT 'Civil_Status already exists — skipped.';
GO

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='JobApplications' AND COLUMN_NAME='Nationality')
BEGIN
    ALTER TABLE [dbo].[JobApplications] ADD [Nationality] NVARCHAR(50) NULL;
    PRINT 'Nationality added.';
END ELSE PRINT 'Nationality already exists — skipped.';
GO

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='JobApplications' AND COLUMN_NAME='Religion')
BEGIN
    ALTER TABLE [dbo].[JobApplications] ADD [Religion] NVARCHAR(50) NULL;
    PRINT 'Religion added.';
END ELSE PRINT 'Religion already exists — skipped.';
GO

-- ── Addresses ─────────────────────────────────────────────────────
IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='JobApplications' AND COLUMN_NAME='Present_Address')
BEGIN
    ALTER TABLE [dbo].[JobApplications] ADD [Present_Address] NVARCHAR(MAX) NULL;
    PRINT 'Present_Address added.';
END ELSE PRINT 'Present_Address already exists — skipped.';
GO

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='JobApplications' AND COLUMN_NAME='Permanent_Address')
BEGIN
    ALTER TABLE [dbo].[JobApplications] ADD [Permanent_Address] NVARCHAR(MAX) NULL;
    PRINT 'Permanent_Address added.';
END ELSE PRINT 'Permanent_Address already exists — skipped.';
GO

-- ── Government IDs ─────────────────────────────────────────────────
IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='JobApplications' AND COLUMN_NAME='SSS_Number')
BEGIN
    ALTER TABLE [dbo].[JobApplications] ADD [SSS_Number] NVARCHAR(50) NULL;
    PRINT 'SSS_Number added.';
END ELSE PRINT 'SSS_Number already exists — skipped.';
GO

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='JobApplications' AND COLUMN_NAME='TIN_Number')
BEGIN
    ALTER TABLE [dbo].[JobApplications] ADD [TIN_Number] NVARCHAR(50) NULL;
    PRINT 'TIN_Number added.';
END ELSE PRINT 'TIN_Number already exists — skipped.';
GO

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='JobApplications' AND COLUMN_NAME='Philhealth_Number')
BEGIN
    ALTER TABLE [dbo].[JobApplications] ADD [Philhealth_Number] NVARCHAR(50) NULL;
    PRINT 'Philhealth_Number added.';
END ELSE PRINT 'Philhealth_Number already exists — skipped.';
GO

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='JobApplications' AND COLUMN_NAME='HDMF')
BEGIN
    ALTER TABLE [dbo].[JobApplications] ADD [HDMF] NVARCHAR(50) NULL;
    PRINT 'HDMF added.';
END ELSE PRINT 'HDMF already exists — skipped.';
GO

-- ── Emergency Contact ──────────────────────────────────────────────
IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='JobApplications' AND COLUMN_NAME='Contact_Person')
BEGIN
    ALTER TABLE [dbo].[JobApplications] ADD [Contact_Person] NVARCHAR(50) NULL;
    PRINT 'Contact_Person added.';
END ELSE PRINT 'Contact_Person already exists — skipped.';
GO

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='JobApplications' AND COLUMN_NAME='Relationship')
BEGIN
    ALTER TABLE [dbo].[JobApplications] ADD [Relationship] NVARCHAR(50) NULL;
    PRINT 'Relationship added.';
END ELSE PRINT 'Relationship already exists — skipped.';
GO

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='JobApplications' AND COLUMN_NAME='Contact_Number_Emergency')
BEGIN
    ALTER TABLE [dbo].[JobApplications] ADD [Contact_Number_Emergency] NVARCHAR(50) NULL;
    PRINT 'Contact_Number_Emergency added.';
END ELSE PRINT 'Contact_Number_Emergency already exists — skipped.';
GO

-- ── Education & Notes ──────────────────────────────────────────────
IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='JobApplications' AND COLUMN_NAME='Educational_Background')
BEGIN
    ALTER TABLE [dbo].[JobApplications] ADD [Educational_Background] NVARCHAR(MAX) NULL;
    PRINT 'Educational_Background added.';
END ELSE PRINT 'Educational_Background already exists — skipped.';
GO

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='JobApplications' AND COLUMN_NAME='Notes')
BEGIN
    ALTER TABLE [dbo].[JobApplications] ADD [Notes] NVARCHAR(MAX) NULL;
    PRINT 'Notes added.';
END ELSE PRINT 'Notes already exists — skipped.';
GO

-- ── TransferredToEmployee flag ─────────────────────────────────────
IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='JobApplications' AND COLUMN_NAME='TransferredToEmployee')
BEGIN
    ALTER TABLE [dbo].[JobApplications] ADD [TransferredToEmployee] BIT NOT NULL DEFAULT 0;
    PRINT 'TransferredToEmployee added.';
END ELSE PRINT 'TransferredToEmployee already exists — skipped.';
GO

PRINT '';
PRINT 'SECTION 1 COMPLETE — JobApplications updated.';
PRINT '';
GO

-- ══════════════════════════════════════════════════════════════════
PRINT '══════════════════════════════════════════';
PRINT '  SECTION 2 — TBL_HREmployeeList ALTER';
PRINT '══════════════════════════════════════════';
GO

-- ── ApplicationID link back to JobApplications ─────────────────────
IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='TBL_HREmployeeList' AND COLUMN_NAME='ApplicationID')
BEGIN
    ALTER TABLE [dbo].[TBL_HREmployeeList] ADD [ApplicationID] INT NULL;
    PRINT 'ApplicationID added to TBL_HREmployeeList.';
END ELSE PRINT 'ApplicationID already exists — skipped.';
GO

PRINT '';
PRINT 'SECTION 2 COMPLETE — TBL_HREmployeeList updated.';
PRINT '';
GO

-- ══════════════════════════════════════════════════════════════════
PRINT '══════════════════════════════════════════';
PRINT '  SECTION 3 — VERIFICATION';
PRINT '══════════════════════════════════════════';
GO

PRINT 'JobApplications columns:';
SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_NAME = 'JobApplications'
ORDER BY ORDINAL_POSITION;
GO

PRINT 'TBL_HREmployeeList — ApplicationID check:';
SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_NAME = 'TBL_HREmployeeList'
AND COLUMN_NAME = 'ApplicationID';
GO

PRINT '';
PRINT '══════════════════════════════════════════';
PRINT '  CAREERS MIGRATION COMPLETE!';
PRINT '══════════════════════════════════════════';
GO
