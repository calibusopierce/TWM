/*
  TBL_Technical_Receiving_Items
  Line items for a receiving event. Barcodes is a newline-separated
  record of exactly which barcodes were entered for this line, kept
  here for a quick audit trail — the individual physical items
  themselves live in TBL_Technical_Items (one row each, linked back
  via ReceivingID/POID).

  Run this once in SSMS against TradewellDatabase, after TBL_Technical_Receiving.sql.
*/

CREATE TABLE [dbo].[TBL_Technical_Receiving_Items](
	[ReceivingItemID] [int] IDENTITY(1,1) NOT NULL,
	[ReceivingID] [int] NOT NULL,
	[POItemID] [int] NULL,
	[QtyReceived] [numeric](18, 0) NULL,
	[Barcodes] [nvarchar](max) NULL,
	[Remarks] [nvarchar](max) NULL,
 CONSTRAINT [PK_TBL_Technical_Receiving_Items] PRIMARY KEY CLUSTERED
(
	[ReceivingItemID] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY]
GO

CREATE NONCLUSTERED INDEX [IX_TBL_Technical_Receiving_Items_ReceivingID]
ON [dbo].[TBL_Technical_Receiving_Items] ([ReceivingID] ASC)
GO
