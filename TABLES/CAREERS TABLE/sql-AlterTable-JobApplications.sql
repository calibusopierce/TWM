-- ══════════════════════════════════════════════════════════════════════
--  JobApplications — ALTER TABLE
--  Adds all fields needed to promote a hired applicant directly into
--  TBL_HREmployeeList with zero manual re-entry.
--
--  Run this ONCE on TradewellDatabase before deploying job-application.php
-- ══════════════════════════════════════════════════════════════════════
USE [TradewellDatabase];
GO

-- ── Personal Name Fields ────────────────────────────────────────────
ALTER TABLE [dbo].[JobApplications]
    ADD [FirstName]   NVARCHAR(50) NULL,
        [MiddleName]  NVARCHAR(50) NULL,
        [LastName]    NVARCHAR(50) NULL;
GO

-- ── Contact ─────────────────────────────────────────────────────────
ALTER TABLE [dbo].[JobApplications]
    ADD [Mobile_Number] NVARCHAR(50) NULL;
GO

-- ── Personal Info ────────────────────────────────────────────────────
ALTER TABLE [dbo].[JobApplications]
    ADD [Birth_date]   DATE          NULL,
        [Birth_Place]  NVARCHAR(50) NULL,
        [Gender]       NVARCHAR(30)  NULL,
        [Civil_Status] NVARCHAR(30)  NULL,
        [Nationality]  NVARCHAR(50) NULL,
        [Religion]     NVARCHAR(50) NULL;
GO

-- ── Addresses ────────────────────────────────────────────────────────
ALTER TABLE [dbo].[JobApplications]
    ADD [Present_Address]   NVARCHAR(max) NULL,
        [Permanent_Address] NVARCHAR(max) NULL;
GO

-- ── Government IDs ───────────────────────────────────────────────────
ALTER TABLE [dbo].[JobApplications]
    ADD [SSS_Number]        NVARCHAR(50) NULL,
        [TIN_Number]        NVARCHAR(50) NULL,
        [Philhealth_Number] NVARCHAR(50) NULL,
        [HDMF]              NVARCHAR(50) NULL;
GO

-- ── Emergency Contact ────────────────────────────────────────────────
ALTER TABLE [dbo].[JobApplications]
    ADD [Contact_Person]           NVARCHAR(50) NULL,
        [Relationship]             NVARCHAR(50) NULL,
        [Contact_Number_Emergency] NVARCHAR(50)  NULL;
GO

-- ── Education & Notes ────────────────────────────────────────────────
ALTER TABLE [dbo].[JobApplications]
    ADD [Educational_Background] NVARCHAR(MAX) NULL,
        [Notes]                  NVARCHAR(MAX) NULL;
GO


-- ══════════════════════════════════════════════════════════════════════
--  VERIFY — should show all original + new columns
-- ══════════════════════════════════════════════════════════════════════
SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE
FROM   INFORMATION_SCHEMA.COLUMNS
WHERE  TABLE_NAME = 'JobApplications'
ORDER  BY ORDINAL_POSITION;
GO
