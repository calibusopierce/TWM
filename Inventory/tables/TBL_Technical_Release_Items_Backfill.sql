/*
  One-time repair for release rows created BEFORE save_release.php was
  fixed to save POItemID. Those rows have POItemID = NULL, so they can
  never be subtracted from "Available" no matter how many times the
  page reloads.

  This backfills POItemID by matching ItemDescription + ItemCondition
  to a PO line — but ONLY when there's exactly one matching PO line
  overall, so it won't guess wrong on an ambiguous case (e.g. the same
  item name ordered on two different POs). Ambiguous rows are left
  alone and listed at the end so you can fix them by hand if needed.

  Safe to run more than once -- it only touches rows where POItemID
  is still NULL.

  Run this once in SSMS against TradewellDatabase, AFTER
  TBL_Technical_Release_Items_Alter2.sql.
*/

UPDATE ri
SET ri.POItemID = matched.POItemID
FROM TBL_Technical_Release_Items ri
CROSS APPLY (
    SELECT pi.POItemID
    FROM TBL_Technical_PO_Items pi
    WHERE pi.ItemDescription = ri.ItemDescription
      AND COALESCE(pi.Condition, 'Brand New') = COALESCE(ri.ItemCondition, 'Brand New')
) matched
WHERE ri.POItemID IS NULL
  AND (
        SELECT COUNT(*)
        FROM TBL_Technical_PO_Items pi2
        WHERE pi2.ItemDescription = ri.ItemDescription
          AND COALESCE(pi2.Condition, 'Brand New') = COALESCE(ri.ItemCondition, 'Brand New')
      ) = 1;

-- Anything still NULL after the update above was ambiguous (matched
-- more than one PO line, or matched none) and needs a manual look.
SELECT ReleaseItemID, ItemDescription, ItemCondition, QtyReleased, QtyReturned
FROM TBL_Technical_Release_Items
WHERE POItemID IS NULL;
