/*
  TBL_Technical_Supplier
  Supplier master for the Technical inventory (IT/computer parts,
  furniture, printers, etc). Structured the same way as
  dbo.TBL_Item_Supplier (Warehouse's supplier table), but kept as its
  own table with its own IDENTITY column — Technical and Warehouse
  suppliers are separate records even if the same vendor happens to
  supply both.

  Run this once in SSMS against TradewellDatabase.
*/

CREATE TABLE [dbo].[TBL_Technical_Supplier](
	[ID] [int] IDENTITY(1,1) NOT NULL,
	[Department] [nvarchar](50) NULL,
	[SupplierCode] [nvarchar](50) NULL,
	[SupplierName] [nvarchar](50) NULL,
	[Description] [nvarchar](max) NULL,
	[AccountName] [nvarchar](50) NULL,
	[AccountNo] [nvarchar](50) NULL,
	[DepositSlip] [nvarchar](50) NULL,
	[Address] [nvarchar](max) NULL,
	[ContactNo] [nvarchar](50) NULL,
	[ContactPerson] [nvarchar](50) NULL,
	[TIN] [nvarchar](50) NULL,
	[Business_Style] [nvarchar](max) NULL,
	[Image] [image] NULL,
	[Category] [nvarchar](50) NULL,
	[Bank] [nvarchar](50) NULL,
	[Branch] [nvarchar](50) NULL,
	[WithTax] [int] NULL,
	[UpdateUserID] [nvarchar](50) NULL,
	[UpdateDateTime] [datetime] NULL,
	[Status] [bit] NULL,
	[Partner] [bit] NULL,
	[PartnerTop] [int] NULL,
 CONSTRAINT [PK_TBL_Technical_Supplier] PRIMARY KEY CLUSTERED
(
	[ID] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY] TEXTIMAGE_ON [PRIMARY]
GO
