# Tradewell Inventory System — UI Preview

This is the front-end scaffold for the inventory system, built from the
hand-drawn wireframe (Inventory: Dashboard / Stocks / Purchase Order /
Receiving, Maintenance: Supplier / Items). **No database is wired up yet** —
all data on screen is static PHP arrays so we can nail the UI first.

## Structure

```
tradewell-inventory/
├── index.php              Dashboard (placeholder)
├── stocks.php              Stocks (placeholder)
├── purchase_order.php      Purchase Order (placeholder)
├── receiving.php           Receiving (placeholder)
├── supplier.php            List of Supplier — fully built (table, search, Add New Supplier modal)
├── items.php                Items (placeholder)
├── includes/
│   ├── header.php          <head>, opens layout, topbar
│   ├── sidebar.php         Left nav (Inventory / Maintenance groups)
│   ├── footer.php          Closes layout, loads JS
│   └── config.php          App constants + commented-out SQL Server connection
└── assets/
    ├── css/style.css       Design tokens + all component styles
    └── js/app.js           Modal open/close, client-side search demo
```

## Running it locally

Any PHP 7.4+ server works, no extensions required yet:

```bash
cd tradewell-inventory
php -S localhost:8000
```

Then open `http://localhost:8000/supplier.php` — that's the fully-built screen
from the wireframe. The other nav items are placeholder pages so the
navigation is complete and clickable while we build out each screen.

## What's built vs. what's next

- **Built:** full app shell (sidebar + topbar), List of Supplier page with
  search box, data table, status badges, row actions, and the Add New
  Supplier modal (Name, Address, Contact Person, Contact #, Status).
- **Not built yet:** Dashboard, Stocks, Purchase Order, Receiving, and Items
  screens (currently placeholders), plus all backend logic.

## Backend (next phase)

`includes/config.php` has the SQL Server connection block commented out and
ready to go, pointing at a database named **TradewellDatabase**. When you're
ready, we'll:

1. Uncomment and configure the `sqlsrv`/PDO connection in `config.php`.
2. Design the `Suppliers` table (and others) in TradewellDatabase.
3. Replace the static `$suppliers` array in `supplier.php` with a real query.
4. Wire the "Save Supplier" form to an INSERT, and the edit icon to an UPDATE.




tables i used for our database;

TABLES NAME{

  Departments;
  TBL_Item_Brand;
  TBL_Item_Category;
  TBL_Item_Products;
  TBL_Item_Supplier;

}



CREATE TABLE [dbo].[Departments](
	[DepartmentID] [int] IDENTITY(1,1) NOT NULL,
	[DepartmentCode] [nvarchar](50) NULL,
	[Department] [nvarchar](50) NULL,
	[DepartmentName] [nvarchar](50) NULL,
	[Code] [nchar](1) NULL,
	[Status] [bit] NULL,
	[Public_Display] [bit] NULL,
	[Marker] [nvarchar](50) NULL,
	[ColorCode] [nvarchar](20) NULL,
 CONSTRAINT [PK_Departments_1] PRIMARY KEY CLUSTERED 


 CREATE TABLE [dbo].[TBL_Item_Brand](
	[ID] [int] IDENTITY(1,1) NOT NULL,
	[Department] [nvarchar](50) NULL,
	[SupplierID] [int] NULL,
	[SupplierCode] [nvarchar](50) NULL,
	[BrandCode] [nvarchar](50) NULL,
	[BrandName] [nvarchar](50) NULL,
	[Logo] [image] NULL,
	[Status] [bit] NULL,
	[UserInput] [nvarchar](50) NULL,
	[DateTimeInput] [datetime] NULL
) ON [PRIMARY] TEXTIMAGE_ON [PRIMARY]
GO


CREATE TABLE [dbo].[TBL_Item_Category](
	[ID] [int] IDENTITY(1,1) NOT NULL,
	[Department] [nvarchar](50) NULL,
	[SupplierID] [int] NULL,
	[SupplierCode] [nvarchar](50) NULL,
	[CategoryCode] [nvarchar](50) NULL,
	[CategoryName] [nvarchar](50) NULL,
	[Status] [bit] NULL,
	[UserInput] [nvarchar](50) NULL,
	[DateTimeInput] [datetime] NULL,
 CONSTRAINT [PK_TBL_Item_Category] PRIMARY KEY CLUSTERED 


 CREATE TABLE [dbo].[Tbl_Item_Products](
	[ItemID] [int] IDENTITY(1,1) NOT NULL,
	[ItemUpdateID] [int] NULL,
	[ItemCode] [nvarchar](50) NULL,
	[ItemDescription] [nvarchar](max) NULL,
	[Department] [nvarchar](50) NULL,
	[SupplierCode] [nvarchar](50) NULL,
	[BrandName] [nvarchar](50) NULL,
	[Category] [nvarchar](50) NULL,
	[QtyCs] [numeric](18, 0) NULL,
	[QtyBg] [numeric](18, 0) NULL,
	[QtyBdl] [numeric](18, 0) NULL,
	[QtyPc] [numeric](18, 0) NULL,
	[CsPrice] [numeric](18, 2) NULL,
	[BgPrice] [numeric](18, 2) NULL,
	[BdlPrice] [numeric](18, 2) NULL,
	[PcPrice] [numeric](18, 2) NULL,
	[CsPriceBO] [numeric](18, 2) NULL,
	[BgPriceBO] [numeric](18, 2) NULL,
	[BdlPriceBO] [numeric](18, 2) NULL,
	[PcPriceBO] [numeric](18, 2) NULL,
	[CsCostPrice] [numeric](18, 2) NULL,
	[BgCostPrice] [numeric](18, 2) NULL,
	[BdlCostPrice] [numeric](18, 2) NULL,
	[PcCostPrice] [numeric](18, 2) NULL,
	[Active] [bit] NULL,
	[Image] [image] NULL,
	[BarcodeCs] [nvarchar](50) NULL,
	[BarcodeBg] [nvarchar](50) NULL,
	[BarcodeBdl] [nvarchar](50) NULL,
	[BarcodePc] [nvarchar](50) NULL,
	[PcSize] [nvarchar](50) NULL,
	[PriceTagID] [int] NULL,
	[Status] [bit] NULL,
	[DateTimeInput] [datetime] NULL,
	[Picture] [nvarchar](max) NULL,
	[UOMCs] [nchar](10) NULL,
	[UOMBg] [nchar](10) NULL,
	[UOMPc] [nchar](10) NULL,
	[WeightPc] [numeric](18, 10) NULL,
	[DimensionCaseL] [int] NULL,
	[DimensionCaseW] [int] NULL,
	[DimensionCaseH] [int] NULL,
	[DimensionBagL] [int] NULL,
	[DimensionBagW] [int] NULL,
	[DimensionBagH] [int] NULL,
	[DimensionPieceL] [int] NULL,
	[DimensionPieceW] [int] NULL,
	[DimensionPieceH] [int] NULL,
	[SupplierDiscountID] [int] NULL,
	[ItemCategory] [nvarchar](50) NULL,
	[ItemSubCategory] [nvarchar](50) NULL,
	[Remarks] [nvarchar](max) NULL
) ON [PRIMARY] TEXTIMAGE_ON [PRIMARY]



CREATE TABLE [dbo].[TBL_Item_Supplier](
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
 CONSTRAINT [PK_TBL_Item_Supplier] PRIMARY KEY CLUSTERED 


 
