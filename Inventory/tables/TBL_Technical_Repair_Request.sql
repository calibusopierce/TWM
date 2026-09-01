/*
  TBL_Technical_Repair_Request
  Request Management — "Repair Request" side. A department reports a
  problem with a specific registered asset (scanned by its
  TBL_Technical_Items.Barcode). Kept as a standalone request log for
  now -- approving/fulfilling a repair request here does NOT itself
  touch TBL_Technical_Transactions or flip the item's ItemStatus; that
  stays a separate, manual step on Asset Transactions until that
  linkage gets built later.

  Status lifecycle: Pending -> Approved -> Fulfilled
                              -> Rejected

  Photo is stored the same way TBL_Technical_Items.Image is (binary
  in-row, streamed back out by repair_photo.php) so it follows the
  same backup/restore story as every other image in this system.

  Run this once in SSMS against TradewellDatabase.
*/

CREATE TABLE [dbo].[TBL_Technical_Repair_Request](
	[RequestID] [int] IDENTITY(1,1) NOT NULL,
	[RequestNumber] [nvarchar](30) NULL,
	[RequestDate] [datetime] NULL,
	[Department] [nvarchar](50) NULL,
	[RequestedBy] [nvarchar](100) NULL,
	[Area] [nvarchar](100) NULL,
	[Facilities] [nvarchar](100) NULL,
	[UnitBarcode] [nvarchar](50) NULL,
	[ItemName] [nvarchar](150) NULL,
	[Problem] [nvarchar](max) NULL,
	[Photo] [image] NULL,
	[Status] [nvarchar](20) NULL,          -- Pending, Approved, Rejected, Fulfilled
	[ApprovedBy] [nvarchar](100) NULL,
	[ApprovedDateTime] [datetime] NULL,
	[RejectReason] [nvarchar](max) NULL,
	[UserInput] [nvarchar](50) NULL,
	[DateTimeInput] [datetime] NULL,
 CONSTRAINT [PK_TBL_Technical_Repair_Request] PRIMARY KEY CLUSTERED
(
	[RequestID] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY] TEXTIMAGE_ON [PRIMARY]
GO

CREATE NONCLUSTERED INDEX [IX_TBL_Technical_Repair_Request_Status]
ON [dbo].[TBL_Technical_Repair_Request] ([Status] ASC)
GO

CREATE NONCLUSTERED INDEX [IX_TBL_Technical_Repair_Request_UnitBarcode]
ON [dbo].[TBL_Technical_Repair_Request] ([UnitBarcode] ASC)
GO
