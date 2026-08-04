<?php
/**
 * api/expenses.php
 * Returns View_Maintenance_Service + View_Maintenance_Parts combined into
 * one row-per-record JSON array, tagged with RecordType ('Service'/'Parts')
 * so the dashboard can filter by type alongside every other column.
 */

require_once __DIR__ . '/../config.php';
header('Content-Type: application/json; charset=utf-8');

$conn = getConnection();

$sql = "
SELECT
  [MID], [DID], [Department], [PlateNumber], [DateRequest], [JobPerformed],
  [UserID], [DateTimeInput], [Date], [Amount],
  'Service' AS RecordType,
  CAST([SID] AS NVARCHAR(255))            AS SID,
  CAST([Type] AS NVARCHAR(255))           AS Type,
  CAST([Description] AS NVARCHAR(255))    AS Description,
  CAST([UserIDD] AS NVARCHAR(255))        AS UserIDD,
  CAST([DateTimeInputD] AS NVARCHAR(255)) AS DateTimeInputD,
  CAST([MID1] AS NVARCHAR(255))           AS MID1,
  CAST(NULL AS NVARCHAR(255))             AS PID,
  CAST(NULL AS NVARCHAR(255))             AS PONo,
  CAST(NULL AS NVARCHAR(255))             AS ORNo,
  CAST(NULL AS NVARCHAR(255))             AS QTY,
  CAST(NULL AS NVARCHAR(255))             AS Items,
  CAST(NULL AS NVARCHAR(255))             AS Supplier,
  CAST(NULL AS NVARCHAR(255))             AS Remarks,
  CAST(NULL AS NVARCHAR(255))             AS Expr1,
  CAST(NULL AS NVARCHAR(255))             AS Expr2
FROM dbo.View_Maintenance_Service

UNION ALL

SELECT
  [MID], [DID], [Department], [PlateNumber], [DateRequest], [JobPerformed],
  [UserID], [DateTimeInput], [Date], [Amount],
  'Parts' AS RecordType,
  CAST(NULL AS NVARCHAR(255))             AS SID,
  CAST(NULL AS NVARCHAR(255))             AS Type,
  CAST(NULL AS NVARCHAR(255))             AS Description,
  CAST(NULL AS NVARCHAR(255))             AS UserIDD,
  CAST(NULL AS NVARCHAR(255))             AS DateTimeInputD,
  CAST(NULL AS NVARCHAR(255))             AS MID1,
  CAST([PID] AS NVARCHAR(255))            AS PID,
  CAST([PONo] AS NVARCHAR(255))           AS PONo,
  CAST([ORNo] AS NVARCHAR(255))           AS ORNo,
  CAST([QTY] AS NVARCHAR(255))            AS QTY,
  CAST([Items] AS NVARCHAR(255))          AS Items,
  CAST([Supplier] AS NVARCHAR(255))       AS Supplier,
  CAST([Remarks] AS NVARCHAR(255))        AS Remarks,
  CAST([Expr1] AS NVARCHAR(255))          AS Expr1,
  CAST([Expr2] AS NVARCHAR(255))          AS Expr2
FROM dbo.View_Maintenance_Parts

ORDER BY [DateRequest] DESC
";

$stmt = sqlsrv_query($conn, $sql);

if ($stmt === false) {
    http_response_code(500);
    echo json_encode([
        'error'   => true,
        'message' => 'Query failed: ' . print_r(sqlsrv_errors(), true)
    ]);
    closeConnection($conn);
    exit;
}

$rows = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    // sqlsrv returns datetime columns as PHP DateTime objects; convert
    // them to plain strings so json_encode can output them.
    foreach ($row as $key => $value) {
        if ($value instanceof DateTime) {
            $row[$key] = $value->format('Y-m-d H:i:s');
        }
    }
    $rows[] = $row;
}

sqlsrv_free_stmt($stmt);
closeConnection($conn);

echo json_encode($rows);
