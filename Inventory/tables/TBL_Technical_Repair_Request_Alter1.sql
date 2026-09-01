/*
  TBL_Technical_Repair_Request_Alter1
  Same addition as TBL_Technical_Item_Request_Alter1.sql, mirrored on
  the Repair Request table -- see that file for the full explanation.

  Run this once in SSMS against TradewellDatabase, after
  TBL_Technical_Repair_Request.sql.
*/

ALTER TABLE [dbo].[TBL_Technical_Repair_Request] ADD
	[StatusUpdatedAt] [datetime] NULL,
	[RequesterLastSeenAt] [datetime] NULL
GO
