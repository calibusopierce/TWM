/*
  Renames TBL_Technical_PO_Items.ItemBarcode to UnitBarcode.
  Uses sp_rename so existing barcode values are preserved, not lost.

  Safe to run even if you're not sure of the current name -- it
  checks first and does nothing if UnitBarcode already exists or
  ItemBarcode doesn't.

  Run this once in SSMS against TradewellDatabase.
*/

IF COL_LENGTH('TBL_Technical_PO_Items', 'ItemBarcode') IS NOT NULL
   AND COL_LENGTH('TBL_Technical_PO_Items', 'UnitBarcode') IS NULL
BEGIN
    EXEC sp_rename 'TBL_Technical_PO_Items.ItemBarcode', 'UnitBarcode', 'COLUMN';
END
GO
