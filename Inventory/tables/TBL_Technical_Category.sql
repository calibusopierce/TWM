/*
  TBL_Technical_Category
  Standalone category lookup for Technical inventory assets (Computer,
  Printer, Monitor, Table, Chair, etc). Unlike TBL_Item_Category
  (Warehouse), this is NOT scoped per-supplier — a category describes
  what the asset *is*, and more than one supplier can sell the same
  category of item.

  Run this once in SSMS against TradewellDatabase.
*/

CREATE TABLE [dbo].[TBL_Technical_Category](
	[ID] [int] IDENTITY(1,1) NOT NULL,
	[CategoryCode] [nvarchar](20) NULL,
	[CategoryName] [nvarchar](50) NULL,
	[Status] [bit] NULL,
	[DateTimeInput] [datetime] NULL,
 CONSTRAINT [PK_TBL_Technical_Category] PRIMARY KEY CLUSTERED
(
	[ID] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY]
GO

-- Starter set covering common IT + office/furniture assets.
-- Add more any time with the same INSERT pattern, or ask to have a
-- proper Category Maintenance page built later.
INSERT INTO [dbo].[TBL_Technical_Category] (CategoryCode, CategoryName, Status, DateTimeInput) VALUES
('COMP', 'Computer',             1, GETDATE()),
('LAPT', 'Laptop',               1, GETDATE()),
('PRIN', 'Printer',              1, GETDATE()),
('MONI', 'Monitor',              1, GETDATE()),
('KEYB', 'Keyboard',             1, GETDATE()),
('MOUS', 'Mouse',                1, GETDATE()),
('NETW', 'Networking Equipment', 1, GETDATE()),
('SERV', 'Server',               1, GETDATE()),
('UPS',  'UPS / Power Supply',   1, GETDATE()),
('SCAN', 'Scanner',              1, GETDATE()),
('PROJ', 'Projector',            1, GETDATE()),
('TABL', 'Table',                1, GETDATE()),
('CHAI', 'Chair',                1, GETDATE()),
('CABI', 'Cabinet',              1, GETDATE());
GO
