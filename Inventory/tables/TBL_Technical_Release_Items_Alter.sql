/*
  Adds ItemCondition to TBL_Technical_Release_Items so releases
  track which condition of stock was issued (Brand New, Old, Used, etc.)
  matching the grouping used in the Stocks page (Option A).

  Run this once in SSMS against TradewellDatabase,
  after TBL_Technical_Release_Items already exists.
*/

ALTER TABLE [dbo].[TBL_Technical_Release_Items]
ADD [ItemCondition] [nvarchar](30) NULL;
GO
