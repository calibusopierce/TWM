/*
  Adds Unit (e.g. PCS, SET, BOX) to TBL_Technical_PO_Items, needed for
  the redesigned Create PO screen's Item Form (Item / Unit / Qty).

  Run this once in SSMS against TradewellDatabase.
*/

ALTER TABLE [dbo].[TBL_Technical_PO_Items]
ADD [Unit] [nvarchar](20) NULL;
GO
