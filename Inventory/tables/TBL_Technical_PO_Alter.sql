/*
  Adds Discount/Tax/SubTotal/Total to TBL_Technical_PO, needed for the
  redesigned Create PO screen's totals footer (Sub Total, Discount %,
  Tax %, Total).

  Run this once in SSMS against TradewellDatabase.
*/

ALTER TABLE [dbo].[TBL_Technical_PO]
ADD [Discount] [numeric](5, 2) NULL,
    [Tax] [numeric](5, 2) NULL,
    [SubTotal] [numeric](18, 2) NULL,
    [Total] [numeric](18, 2) NULL;
GO
