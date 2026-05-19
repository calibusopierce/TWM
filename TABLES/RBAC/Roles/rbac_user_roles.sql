
CREATE TABLE rbac_user_access (
    id          INT IDENTITY PRIMARY KEY,
    user_id     INT          NOT NULL,
    module_key  VARCHAR(100) NOT NULL,  -- from rbac_modules.module_key
    granted_by  VARCHAR(100) NULL,
    granted_at  DATETIME     DEFAULT GETDATE(),
    is_active   BIT          DEFAULT 1,

    CONSTRAINT uq_user_module UNIQUE (user_id, module_key)
)