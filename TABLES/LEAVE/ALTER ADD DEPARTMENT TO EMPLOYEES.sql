/* =====================================================================
   001_alter_add_department_to_employees.sql

   Adds a DepartmentID column to Tbl_HREmployeeList, referencing the
   existing dbo.Tbl_Department master table (DepartmentID, DepartmentName,
   CreatedAt, Status [bit], Color).

   Per TWM convention, no enforced FOREIGN KEY constraint is added —
   DepartmentID is a plain nullable INT that logically references
   Tbl_Department.DepartmentID, same pattern as other cross-table
   references in this codebase.

   IMPORTANT: This only adds the column. Existing employee rows will have
   DepartmentID = NULL until backfilled — that data mapping (which
   employee belongs to which department) has to come from you / HR data,
   it can't be inferred. Until backfilled, HR's department-scoped
   approval view will show nothing for employees with a NULL DepartmentID.
   ===================================================================== */

USE [TradewellDatabase]
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.Tbl_HREmployeeList')
      AND name = 'DepartmentID'
)
BEGIN
    ALTER TABLE dbo.Tbl_HREmployeeList
    ADD DepartmentID INT NULL;
END
GO

-- Optional but recommended: speeds up the department-scoped approval
-- queries (WHERE DepartmentID = ... joins happen on every HR list load).
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = 'IX_HREmployeeList_DepartmentID'
      AND object_id = OBJECT_ID('dbo.Tbl_HREmployeeList')
)
BEGIN
    CREATE INDEX IX_HREmployeeList_DepartmentID
    ON dbo.Tbl_HREmployeeList (DepartmentID);
END
GO

/* ---------------------------------------------------------------------
   Sanity checks to run after applying, before backfilling data:

   -- Confirm the column exists and is empty:
   SELECT COUNT(*) AS TotalEmployees,
          SUM(CASE WHEN DepartmentID IS NULL THEN 1 ELSE 0 END) AS NullDept
   FROM dbo.Tbl_HREmployeeList;

   -- Once backfilled, spot-check a join:
   SELECT e.EmployeeID, e.EmployeeName, d.DepartmentName
   FROM dbo.Tbl_HREmployeeList e
   LEFT JOIN dbo.Tbl_Department d ON d.DepartmentID = e.DepartmentID
   WHERE e.DepartmentID IS NOT NULL;
   --------------------------------------------------------------------- */