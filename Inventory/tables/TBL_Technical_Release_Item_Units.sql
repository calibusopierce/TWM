/*
  TBL_Technical_Release_Item_Units
  Records exactly which pre-generated unit barcodes (from
  TBL_Technical_PO_Item_Units) were included in a given release line,
  for items using Individual Unit Tracking. Quantity-Based lines have
  no rows here at all -- they're tracked purely by QtyReleased on
  TBL_Technical_Release_Items, same as before.

  ReturnedFlag stays 0 for now (Return isn't unit-aware yet -- that's
  a planned follow-up), but the column is here so it's ready when
  that gets built, without another schema change.

  Run this once in SSMS against TradewellDatabase, after
  TBL_Technical_PO_Item_Units.sql and TBL_Technical_Release_Items.sql.
*/

CREATE TABLE [dbo].[TBL_Technical_Release_Item_Units](
	[ReleaseItemUnitID] [int] IDENTITY(1,1) NOT NULL,
	[ReleaseItemID] [int] NOT NULL,
	[POItemUnitID] [int] NOT NULL,
	[UnitBarcode] [nvarchar](50) NOT NULL,
	[ReturnedFlag] [bit] NOT NULL DEFAULT 0,
	[DateTimeInput] [datetime] NULL,
 CONSTRAINT [PK_TBL_Technical_Release_Item_Units] PRIMARY KEY CLUSTERED
(
	[ReleaseItemUnitID] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY]
GO

CREATE NONCLUSTERED INDEX [IX_TBL_Technical_Release_Item_Units_ReleaseItemID]
ON [dbo].[TBL_Technical_Release_Item_Units] ([ReleaseItemID] ASC)
GO

CREATE NONCLUSTERED INDEX [IX_TBL_Technical_Release_Item_Units_POItemUnitID]
ON [dbo].[TBL_Technical_Release_Item_Units] ([POItemUnitID] ASC)
GO
