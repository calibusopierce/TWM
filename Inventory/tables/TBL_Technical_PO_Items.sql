/*
  TBL_Technical_PO_Items
  Line items for a Technical PO. QtyReceived tracks running progress
  as receivings come in against this line (see save_receiving.php);
  once QtyReceived >= QtyOrdered for every line, the PO header status
  flips to Closed.

  Run this once in SSMS against TradewellDatabase, after TBL_Technical_PO.sql.
*/

CREATE TABLE [dbo].[TBL_Technical_PO_Items](
	[POItemID] [int] IDENTITY(1,1) NOT NULL,
	[POID] [int] NOT NULL,
	[Category] [nvarchar](50) NULL,
	[ItemDescription] [nvarchar](150) NULL,
	[Brand] [nvarchar](50) NULL,
	[Model] [nvarchar](50) NULL,
	[QtyOrdered] [numeric](18, 0) NULL,
	[QtyReceived] [numeric](18, 0) NULL,
	[UnitCost] [numeric](18, 2) NULL,
	[Remarks] [nvarchar](max) NULL,
 CONSTRAINT [PK_TBL_Technical_PO_Items] PRIMARY KEY CLUSTERED
(
	[POItemID] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY]
GO

CREATE NONCLUSTERED INDEX [IX_TBL_Technical_PO_Items_POID]
ON [dbo].[TBL_Technical_PO_Items] ([POID] ASC)
GO
