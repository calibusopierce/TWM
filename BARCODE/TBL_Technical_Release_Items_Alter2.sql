/*
  Fixes the root cause of "Available" never decreasing after a
  Release: TBL_Technical_Release_Items never actually had a column
  to record which PO line (POItemID) a release came from, even
  though the app's queries assumed it did. Without that link,
  release.php and get_po_item_by_barcode.php can never subtract what
  was released from what was received.

  Both ALTERs are written to be safe to run even if the column
  already exists (e.g. if it was added manually before), so running
  this twice or on a database that's partially set up won't error.

  Run this once in SSMS against TradewellDatabase.
*/

IF COL_LENGTH('TBL_Technical_Release_Items', 'POItemID') IS NULL
BEGIN
    ALTER TABLE [dbo].[TBL_Technical_Release_Items] ADD [POItemID] [int] NULL;
END
GO

IF COL_LENGTH('TBL_Technical_PO_Items', 'ItemBarcode') IS NULL
BEGIN
    ALTER TABLE [dbo].[TBL_Technical_PO_Items] ADD [ItemBarcode] [nvarchar](50) NULL;
END
GO

-- Speeds up the "how much is still available for this PO line" lookups
-- that release.php and get_po_item_by_barcode.php run constantly.
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_TBL_Technical_Release_Items_POItemID')
BEGIN
    CREATE NONCLUSTERED INDEX [IX_TBL_Technical_Release_Items_POItemID]
    ON [dbo].[TBL_Technical_Release_Items] ([POItemID] ASC);
END
GO
