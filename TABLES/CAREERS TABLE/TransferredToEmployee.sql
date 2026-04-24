USE [TradewellDatabase];
GO

-- 1. Track if applicant has been transferred (shows badge on Hired tab)
IF NOT EXISTS (
    SELECT 1 FROM sys.columns
    WHERE object_id = OBJECT_ID(N'[dbo].[JobApplications]')
      AND name = N'TransferredToEmployee'
)
BEGIN
    ALTER TABLE [dbo].[JobApplications]
        ADD [TransferredToEmployee] BIT NOT NULL DEFAULT 0;
END
GO

-- 2. Link employee record back to the original application
IF NOT EXISTS (
    SELECT 1 FROM sys.columns
    WHERE object_id = OBJECT_ID(N'[dbo].[TBL_HREmployeeList]')
      AND name = N'ApplicationID'
)
BEGIN
    ALTER TABLE [dbo].[TBL_HREmployeeList]
        ADD [ApplicationID] INT NULL;
END
GO