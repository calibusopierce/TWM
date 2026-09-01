/*
  Adds Condition to TBL_Technical_PO_Items.
  Captures whether each ordered item is Brand New, Used, Old, or
  Refurbished at the PO stage, so receiving and asset registration
  can inherit the value rather than having to re-enter it.

  Run this once in SSMS against TradewellDatabase.
*/

ALTER TABLE [dbo].[TBL_Technical_PO_Items]
ADD [Condition] [nvarchar](30) NULL;
GO
