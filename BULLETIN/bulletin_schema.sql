-- ============================================================
-- Bulletin Board / What's New — new tables
-- Run against the TradewellDatabase (same DB as TBL_HREmployeeList)
-- ============================================================

CREATE TABLE TBL_Bulletin (
    BulletinID      INT IDENTITY(1,1) PRIMARY KEY,
    Title           VARCHAR(255)    NOT NULL,
    Message         VARCHAR(MAX)    NOT NULL,
    StartDate       DATE            NOT NULL,   -- first day it's shown
    EndDate         DATE            NOT NULL,   -- last day it's shown
    CreatedByUserID INT             NOT NULL,   -- matches $_SESSION['UserID']
    CreatedByName   VARCHAR(150)    NULL,       -- denormalized display name, for the modal — convenience only, not load-bearing
    CreatedAt       DATETIME        NOT NULL DEFAULT GETDATE(),
    IsActive        BIT             NOT NULL DEFAULT 1
);

-- Tracks which user has clicked "Got it" on which post, so it never
-- shows again for that user (server-side, not localStorage — persists
-- across devices/browsers).
CREATE TABLE TBL_Bulletin_Dismissals (
    DismissalID     INT IDENTITY(1,1) PRIMARY KEY,
    BulletinID      INT      NOT NULL REFERENCES TBL_Bulletin(BulletinID),
    UserID          INT      NOT NULL,
    DismissedAt     DATETIME NOT NULL DEFAULT GETDATE(),
    CONSTRAINT UQ_Bulletin_Dismissal UNIQUE (BulletinID, UserID)
);

-- ============================================================
-- RBAC wiring — VERIFY COLUMN NAMES FIRST
-- Run these two SELECTs and compare against the INSERTs below,
-- since I don't have your exact rbac_modules/rbac_permissions
-- column list:
--   SELECT TOP 3 * FROM rbac_modules;
--   SELECT TOP 3 * FROM rbac_permissions;
-- ============================================================

-- Register the module (adjust columns to match what you see above).
-- If your RBAC admin UI (RBAC/index.php) can add a module, prefer that
-- over raw SQL — it likely also sets icon/category/route.
-- INSERT INTO rbac_modules (module_key, module_name, ...) VALUES ('bulletin', 'Bulletin Board', ...);

-- Grant creation rights to whichever roles should be able to post.
-- 'full' vs 'view_only' follows your existing three-tier model
-- (rbac_is_view_only('bulletin') is what bulletin_manage.php checks).
-- INSERT INTO rbac_permissions (role_name, module_key, permission_level) VALUES ('Admin', 'bulletin', 'full');
-- INSERT INTO rbac_permissions (role_name, module_key, permission_level) VALUES ('HR',    'bulletin', 'full');