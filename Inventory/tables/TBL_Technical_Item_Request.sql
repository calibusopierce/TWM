/*
  TBL_Technical_Item_Request
  Request Management — "Item Request" side. A department asks for an
  item (existing stock or something to be procured); this is a request
  ticket, not a stock movement on its own -- fulfilling one is a
  manual step done elsewhere (e.g. a Release), same way an approved PO
  line doesn't auto-receive itself.

  Status lifecycle: Pending -> Approved -> Fulfilled
                              -> Rejected

  Approval is admin-gated: only a session with user_type = 'admin' can
  move a request out of Pending (enforced in save_request_status.php,
  not just hidden in the UI).

  Run this once in SSMS against TradewellDatabase.
*/

CREATE TABLE [dbo].[TBL_Technical_Item_Request](
	[RequestID] [int] IDENTITY(1,1) NOT NULL,
	[RequestNumber] [nvarchar](30) NULL,
	[RequestDate] [datetime] NULL,
	[Department] [nvarchar](50) NULL,
	[RequestedBy] [nvarchar](100) NULL,
	[Area] [nvarchar](100) NULL,
	[Facilities] [nvarchar](100) NULL,
	[ItemName] [nvarchar](150) NULL,
	[Quantity] [numeric](18, 0) NULL,
	[Purpose] [nvarchar](max) NULL,
	[Status] [nvarchar](20) NULL,          -- Pending, Approved, Rejected, Fulfilled
	[ApprovedBy] [nvarchar](100) NULL,
	[ApprovedDateTime] [datetime] NULL,
	[RejectReason] [nvarchar](max) NULL,
	[UserInput] [nvarchar](50) NULL,
	[DateTimeInput] [datetime] NULL,
 CONSTRAINT [PK_TBL_Technical_Item_Request] PRIMARY KEY CLUSTERED
(
	[RequestID] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY]
GO

CREATE NONCLUSTERED INDEX [IX_TBL_Technical_Item_Request_Status]
ON [dbo].[TBL_Technical_Item_Request] ([Status] ASC)
GO
