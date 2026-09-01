/*
  TBL_Technical_Item_Request_Alter1
  Adds the two columns needed to tell a requester "your request has an
  update you haven't seen yet":

    StatusUpdatedAt     -- stamped every time Status changes
                            (save_request_status.php), NULL while
                            still Pending since nothing's happened yet
    RequesterLastSeenAt -- stamped when the requester's own
                            "My Requests" view loads and shows them
                            the current status (technical/requests.php)

  A request has an unseen update when:
    Status <> 'Pending' AND (RequesterLastSeenAt IS NULL
                              OR RequesterLastSeenAt < StatusUpdatedAt)

  Run this once in SSMS against TradewellDatabase, after
  TBL_Technical_Item_Request.sql.
*/

ALTER TABLE [dbo].[TBL_Technical_Item_Request] ADD
	[StatusUpdatedAt] [datetime] NULL,
	[RequesterLastSeenAt] [datetime] NULL
GO
