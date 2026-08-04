/*
  TBL_Technical_PO
  Purchase Order header for Technical inventory. A PO here is an
  intent to buy — line items describe what's being ordered (category,
  description, brand/model, qty, unit cost), but individual barcodes
  aren't known yet; those only exist once physical units are received
  (see TBL_Technical_Receiving / TBL_Technical_Items).

  Run this once in SSMS against TradewellDatabase.
*/

CREATE TABLE [dbo].[TBL_Technical_PO](
	[POID] [int] IDENTITY(1,1) NOT NULL,
	[PONumber] [nvarchar](30) NULL,
	[SupplierCode] [nvarchar](50) NULL,
	[Department] [nvarchar](50) NULL,
	[Status] [nvarchar](20) NULL,       -- Open, Partially Received, Closed, Cancelled
	[Remarks] [nvarchar](max) NULL,
	[UserInput] [nvarchar](50) NULL,
	[DateTimeInput] [datetime] NULL,
 CONSTRAINT [PK_TBL_Technical_PO] PRIMARY KEY CLUSTERED
(
	[POID] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY]
GO
