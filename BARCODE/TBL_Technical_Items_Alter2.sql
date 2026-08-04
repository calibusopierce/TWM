/*
  Adds PO/Receiving traceability columns to TBL_Technical_Items.
  Run this once in SSMS against TradewellDatabase, after
  TBL_Technical_PO.sql and TBL_Technical_Receiving.sql have both
  been run.

  These let you trace any registered asset back to the PO and
  receiving event it came in on — nullable, since items registered
  directly through technical/items.php (no PO involved) won't have
  either.
*/

ALTER TABLE [dbo].[TBL_Technical_Items]
ADD [POID] [int] NULL,
    [ReceivingID] [int] NULL;
GO
