/*
  TBL_Technical_Release
  Release header — one row per release event issued against
  available stock. Items come from TBL_Technical_Release_Items.

  Status:
    Open     — release is active, items are out
    Partial  — some items have been returned, not all
    Returned — all items fully returned to stock

  Run this once in SSMS against TradewellDatabase.
*/

CREATE TABLE [dbo].[TBL_Technical_Release](
    [ReleaseID]     [int] IDENTITY(1,1) NOT NULL,
    [ReleaseNumber] [nvarchar](30)  NULL,
    [Department]    [nvarchar](50)  NULL,
    [ReleasedTo]    [nvarchar](100) NULL,
    [Remarks]       [nvarchar](max) NULL,
    [Status]        [nvarchar](20)  NULL,  -- Open, Partial, Returned
    [UserInput]     [nvarchar](50)  NULL,
    [DateTimeInput] [datetime]      NULL,
 CONSTRAINT [PK_TBL_Technical_Release] PRIMARY KEY CLUSTERED ([ReleaseID] ASC)
) ON [PRIMARY]
GO
