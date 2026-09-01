/*
  TBL_Technical_PO_Item_Units
  Individual unit barcodes generated at PO-creation time for lines
  where TrackingMethod = 'Individual Unit Tracking'. E.g. a line
  ordering 5 units generates 5 rows here (UTC{POItemID}-01 through
  -05), one per physical unit expected.

  Lines using 'Quantity-Based' tracking get NO rows here at all --
  that's the whole point of the distinction: bulk stock, no per-unit
  barcode records.

  Run this once in SSMS against TradewellDatabase, after
  TBL_Technical_PO_Items_Alter3.sql.
*/

CREATE TABLE [dbo].[TBL_Technical_PO_Item_Units](
	[POItemUnitID] [int] IDENTITY(1,1) NOT NULL,
	[POItemID] [int] NOT NULL,
	[UnitBarcode] [nvarchar](50) NOT NULL,
	[DateTimeInput] [datetime] NULL,
 CONSTRAINT [PK_TBL_Technical_PO_Item_Units] PRIMARY KEY CLUSTERED
(
	[POItemUnitID] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY]
GO

CREATE UNIQUE NONCLUSTERED INDEX [UX_TBL_Technical_PO_Item_Units_UnitBarcode]
ON [dbo].[TBL_Technical_PO_Item_Units] ([UnitBarcode] ASC)
GO

CREATE NONCLUSTERED INDEX [IX_TBL_Technical_PO_Item_Units_POItemID]
ON [dbo].[TBL_Technical_PO_Item_Units] ([POItemID] ASC)
GO
