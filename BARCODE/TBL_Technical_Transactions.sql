/*
  TBL_Technical_Transactions
  Movement log for Technical assets — one row per action taken against
  an item on the Stocks page (Assign/Transfer, Mark Under Repair,
  Return to Stock, Retire). This is the audit trail behind the
  barcode-driven "fast transaction" workflow.

  Run this once in SSMS against TradewellDatabase.
*/

CREATE TABLE [dbo].[TBL_Technical_Transactions](
	[TransactionID] [int] IDENTITY(1,1) NOT NULL,
	[ItemID] [int] NOT NULL,
	[Barcode] [nvarchar](50) NULL,
	[ActionType] [nvarchar](30) NULL,       -- Assign, Under Repair, Return to Stock, Retire
	[FromDepartment] [nvarchar](50) NULL,
	[ToDepartment] [nvarchar](50) NULL,
	[FromAssignedTo] [nvarchar](50) NULL,
	[ToAssignedTo] [nvarchar](50) NULL,
	[Remarks] [nvarchar](max) NULL,
	[UserInput] [nvarchar](50) NULL,
	[DateTimeInput] [datetime] NULL,
 CONSTRAINT [PK_TBL_Technical_Transactions] PRIMARY KEY CLUSTERED
(
	[TransactionID] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY]
GO

-- Speeds up "show me this item's history" lookups later.
CREATE NONCLUSTERED INDEX [IX_TBL_Technical_Transactions_ItemID]
ON [dbo].[TBL_Technical_Transactions] ([ItemID] ASC)
GO
