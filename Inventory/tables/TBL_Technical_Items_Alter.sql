/*
  Adds asset-tracking columns to TBL_Technical_Items.
  Run this once in SSMS against TradewellDatabase, AFTER
  TBL_Technical_Items.sql has already been run.

  - AssignedTo: the person currently holding this item (nullable —
    empty means it's sitting unassigned in stock).
  - ItemStatus: lifecycle state, separate from [Condition] (which
    describes physical condition at registration time, e.g. New/Used).
    ItemStatus tracks where the item is *right now*: In Stock,
    Assigned, Under Repair, or Retired.
*/

ALTER TABLE [dbo].[TBL_Technical_Items]
ADD [AssignedTo] [nvarchar](50) NULL,
    [ItemStatus] [nvarchar](30) NULL;
GO

-- Backfill existing rows so nothing is left blank in the new column.
UPDATE [dbo].[TBL_Technical_Items]
SET [ItemStatus] = 'In Stock'
WHERE [ItemStatus] IS NULL;
GO
