/*
  Adds TrackingMethod to TBL_Technical_PO_Items -- 'Individual Unit
  Tracking' or 'Quantity-Based', chosen per line on the Create PO
  screen. Determines whether save_po.php generates one barcode per
  physical unit (see TBL_Technical_PO_Item_Units.sql) or just tracks
  the line as a bulk quantity.

  Run this once in SSMS against TradewellDatabase.
*/

IF COL_LENGTH('TBL_Technical_PO_Items', 'TrackingMethod') IS NULL
BEGIN
    ALTER TABLE [dbo].[TBL_Technical_PO_Items] ADD [TrackingMethod] [nvarchar](30) NULL;
END
GO
