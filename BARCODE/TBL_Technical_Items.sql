/*
  TBL_Technical_Items
  Asset registry for Technical inventory — one row per physical item
  (a specific computer, printer, chair, etc), not a per-SKU stock
  count like Warehouse's Tbl_Item_Products.

  Barcode is UNIQUE and NOT NULL on purpose: this is meant to be the
  key that future fast-transaction features (issuing, transferring,
  returning, auditing) will scan against, so every registered item
  must have one from the start, and no two items can share one.

  Run this once in SSMS against TradewellDatabase, after
  TBL_Technical_Category.sql and TBL_Technical_Supplier.sql.
*/

CREATE TABLE [dbo].[TBL_Technical_Items](
	[ItemID] [int] IDENTITY(1,1) NOT NULL,
	[Barcode] [nvarchar](50) NOT NULL,
	[ItemName] [nvarchar](150) NOT NULL,
	[Category] [nvarchar](50) NULL,
	[Brand] [nvarchar](50) NULL,
	[Model] [nvarchar](50) NULL,
	[SerialNumber] [nvarchar](100) NULL,
	[SupplierCode] [nvarchar](50) NULL,
	[Department] [nvarchar](50) NULL,
	[Condition] [nvarchar](30) NULL,
	[Cost] [numeric](18, 2) NULL,
	[DateAcquired] [datetime] NULL,
	[Image] [image] NULL,
	[Remarks] [nvarchar](max) NULL,
	[Active] [bit] NULL,
	[Status] [bit] NULL,
	[UserInput] [nvarchar](50) NULL,
	[DateTimeInput] [datetime] NULL,
 CONSTRAINT [PK_TBL_Technical_Items] PRIMARY KEY CLUSTERED
(
	[ItemID] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY] TEXTIMAGE_ON [PRIMARY]
GO

-- Enforces "one barcode = one item" at the database level, and makes
-- barcode-scan lookups fast once that feature gets built.
CREATE UNIQUE NONCLUSTERED INDEX [UX_TBL_Technical_Items_Barcode]
ON [dbo].[TBL_Technical_Items] ([Barcode] ASC)
GO
