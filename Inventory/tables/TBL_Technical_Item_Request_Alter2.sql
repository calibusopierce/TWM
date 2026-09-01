/*
  TBL_Technical_Item_Request_Alter2
  Adds the "requester confirms receipt" step to Item Request, per the
  workflow: Pending -> Approved -> (requester clicks "I've received
  this") -> Fulfilled. Repair Request is untouched -- this step only
  applies to Item Request for now.

    ReceivedByUserAt -- NULL until the requester (the row's own
                         RequestedBy) clicks "I've received this" on
                         their My Requests view. Once set, the
                         superadmin's "Mark Fulfilled" button becomes
                         available for that request; before that, it's
                         hidden even though Status is already Approved.

  Run this once in SSMS against TradewellDatabase, after
  TBL_Technical_Item_Request_Alter1.sql.
*/

ALTER TABLE [dbo].[TBL_Technical_Item_Request] ADD
	[ReceivedByUserAt] [datetime] NULL
GO
