/*
  TBL_Technical_Release_Items
  Line items for a release. QtyReturned tracks how many units
  have come back — once QtyReturned >= QtyReleased for every
  line, the Release header flips to "Returned".

  Run this once in SSMS against TradewellDatabase,
  after TBL_Technical_Release.sql.
*/

CREATE TABLE [dbo].[TBL_Technical_Release_Items](
    [ReleaseItemID] [int] IDENTITY(1,1) NOT NULL,
    [ReleaseID]     [int]           NOT NULL,
    [ItemDescription] [nvarchar](150) NULL,
    [QtyReleased]   [numeric](18,0) NULL,
    [QtyReturned]   [numeric](18,0) NULL DEFAULT 0,
    [Remarks]       [nvarchar](max) NULL,
 CONSTRAINT [PK_TBL_Technical_Release_Items] PRIMARY KEY CLUSTERED ([ReleaseItemID] ASC)
) ON [PRIMARY]
GO

CREATE NONCLUSTERED INDEX [IX_TBL_Technical_Release_Items_ReleaseID]
ON [dbo].[TBL_Technical_Release_Items] ([ReleaseID] ASC)
GO
