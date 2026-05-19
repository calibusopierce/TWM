CREATE TABLE rbac_audit_log (
    id           INT IDENTITY PRIMARY KEY,
    action_type  VARCHAR(50)  NOT NULL,  -- 'grant', 'revoke', 'assign_access'
    target_user  VARCHAR(100) NULL,      -- username affected
    target_uid   INT          NULL,      -- user_id affected
    module_key   VARCHAR(100) NULL,      -- module involved
    role_name    VARCHAR(100) NULL,      -- role involved (if any)
    performed_by VARCHAR(100) NOT NULL,  -- $_SESSION['Username']
    performed_at DATETIME     DEFAULT GETDATE(),
    ip_address   VARCHAR(45)  NULL,      -- REMOTE_ADDR
    notes        NVARCHAR(500) NULL      -- e.g. "removed 3 modules, added 2"
)