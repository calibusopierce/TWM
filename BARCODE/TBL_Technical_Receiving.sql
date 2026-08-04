/*
  TBL_Technical_Receiving
  Receiving header for Technical inventory — one row per receiving
  event against a PO. Each receiving event creates its own line rows
  (TBL_Technical_Receiving_Items) AND registers the actual physical
  items in TBL_Technical_Items with real barcodes.

  Run this once in SSMS against TradewellDatabase.
*/

CREATE TABLE [dbo].[TBL_Technical_Receiving](
	[ReceivingID] [int] IDENTITY(1,1) NOT NULL,
	[ReceivingNumber] [nvarchar](30) NULL,
	[POID] [int] NULL,
	[SupplierCode] [nvarchar](50) NULL,
	[DateReceived] [datetime] NULL,
	[Remarks] [nvarchar](max) NULL,
	[UserInput] [nvarchar](50) NULL,
	[DateTimeInput] [datetime] NULL,
 CONSTRAINT [PK_TBL_Technical_Receiving] PRIMARY KEY CLUSTERED
(
	[ReceivingID] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY]
GO
