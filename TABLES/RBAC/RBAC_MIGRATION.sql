-- ══════════════════════════════════════════════════════════════════
--  RBAC Migration (SQL Server Version)
--  Target Database : TradewellDatabase
--  Safe to re-run — all inserts check for duplicates first
-- ══════════════════════════════════════════════════════════════════

USE TradewellDatabase;
GO

-- ── 1. rbac_roles ─────────────────────────────────────────────────
IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='rbac_roles' AND xtype='U')
BEGIN
    CREATE TABLE rbac_roles (
        id         INT           IDENTITY(1,1) PRIMARY KEY,
        role_name  NVARCHAR(50)  NOT NULL UNIQUE,
        created_by NVARCHAR(100) NULL,
        created_at DATETIME      DEFAULT GETDATE()
    );
    PRINT 'rbac_roles created.';
END
ELSE
    PRINT 'rbac_roles already exists — skipped.';
GO

-- ── 2. rbac_modules ───────────────────────────────────────────────
IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='rbac_modules' AND xtype='U')
BEGIN
    CREATE TABLE rbac_modules (
        id          INT          IDENTITY(1,1) PRIMARY KEY,
        module_key  VARCHAR(80)  NOT NULL UNIQUE,
        module_name VARCHAR(100) NOT NULL,
        category    VARCHAR(50)  NOT NULL,
        icon        VARCHAR(60)  NOT NULL DEFAULT 'bi-grid',
        color       VARCHAR(20)  NOT NULL DEFAULT 'blue',
        description VARCHAR(255) NULL,
        sort_order  INT          NOT NULL DEFAULT 0,
        created_at  DATETIME     DEFAULT GETDATE()
    );
    PRINT 'rbac_modules created.';
END
ELSE
    PRINT 'rbac_modules already exists — skipped.';
GO

-- ── 3. rbac_permissions ───────────────────────────────────────────
IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='rbac_permissions' AND xtype='U')
BEGIN
    CREATE TABLE rbac_permissions (
        id         INT          IDENTITY(1,1) PRIMARY KEY,
        role_name  VARCHAR(50)  NOT NULL,
        module_key VARCHAR(80)  NOT NULL,
        can_access BIT          NOT NULL DEFAULT 1,
        granted_by VARCHAR(100) NULL,
        granted_at DATETIME     DEFAULT GETDATE(),
        CONSTRAINT uq_role_module UNIQUE (role_name, module_key),
        CONSTRAINT fk_module FOREIGN KEY (module_key)
            REFERENCES rbac_modules(module_key) ON DELETE CASCADE
    );
    PRINT 'rbac_permissions created.';
END
ELSE
    PRINT 'rbac_permissions already exists — skipped.';
GO

-- ══════════════════════════════════════════════════════════════════
PRINT 'Seeding rbac_roles...';
GO

INSERT INTO rbac_roles (role_name, created_by)
SELECT src.role_name, 'system'
FROM (VALUES
    ('Admin'),
    ('Administrator'),
    ('HR'),
    ('Tester'),
    ('Finance'),
    ('Fleet')
) AS src(role_name)
WHERE NOT EXISTS (
    SELECT 1 FROM rbac_roles r WHERE r.role_name = src.role_name
);
PRINT 'rbac_roles seeded.';
GO

-- ══════════════════════════════════════════════════════════════════
PRINT 'Seeding rbac_modules...';
GO

INSERT INTO rbac_modules (module_key, module_name, category, icon, color, description, sort_order)
SELECT src.module_key, src.module_name, src.category, src.icon, src.color, src.description, src.sort_order
FROM (VALUES
    ('careers_admin',    'Careers Admin',     'hr',      'bi-bag',                      'green',  'Manage job postings and applicant records',              10),
    ('view_applications','Applications',      'hr',      'bi-file-earmark-person-fill', 'purple', 'View and process job applications',                      20),
    ('uniform_inventory','Uniform Inventory', 'hr',      'bi-bag-fill',                 'green',  'Manage uniform stock, issuances, and returns',           30),
    ('employee_list',    'Employee List',     'hr',      'bi-people-fill',              'blue',   'View and manage employee records',                       40),
    ('fuel_dashboard',   'Fuel Dashboard',    'fleet',   'bi-fuel-pump-fill',           'green',  'Monitor fleet fuel usage and refuel records',            50),
    ('graphs',           'Fuel Graphs',       'fleet',   'bi-bar-chart-fill',           'amber',  'Visual analytics and charts for fleet fuel data',        60),
    ('po_index',         'Purchase Orders',   'finance', 'bi-receipt-cutoff',           'purple', 'Create, manage and print company POs by category',       70),
    ('help',             'Help Manual',       'general', 'bi-book-fill',                'blue',   'Learn how to use the dashboard and all features',        80),
    ('RBAC',             'RBAC Management',   'admin',   'bi-shield-lock-fill',         'red',    'Manage roles and module access permissions',             90)
) AS src(module_key, module_name, category, icon, color, description, sort_order)
WHERE NOT EXISTS (
    SELECT 1 FROM rbac_modules m WHERE m.module_key = src.module_key
);
PRINT 'rbac_modules seeded.';
GO

-- ══════════════════════════════════════════════════════════════════
PRINT 'Seeding rbac_permissions...';
GO

-- ── Admin — full access to everything ─────────────────────────────
INSERT INTO rbac_permissions (role_name, module_key, can_access, granted_by)
SELECT 'Admin', module_key, 1, 'system'
FROM rbac_modules m
WHERE NOT EXISTS (
    SELECT 1 FROM rbac_permissions p
    WHERE p.role_name = 'Admin' AND p.module_key = m.module_key
);

-- ── Administrator — same as Admin ─────────────────────────────────
INSERT INTO rbac_permissions (role_name, module_key, can_access, granted_by)
SELECT 'Administrator', module_key, 1, 'system'
FROM rbac_modules m
WHERE NOT EXISTS (
    SELECT 1 FROM rbac_permissions p
    WHERE p.role_name = 'Administrator' AND p.module_key = m.module_key
);

-- ── HR — hr modules only ──────────────────────────────────────────
INSERT INTO rbac_permissions (role_name, module_key, can_access, granted_by)
SELECT 'HR', module_key, 1, 'system'
FROM rbac_modules m
WHERE m.category = 'hr'
AND NOT EXISTS (
    SELECT 1 FROM rbac_permissions p
    WHERE p.role_name = 'HR' AND p.module_key = m.module_key
);

-- ── Finance — finance + help ───────────────────────────────────────
INSERT INTO rbac_permissions (role_name, module_key, can_access, granted_by)
SELECT 'Finance', module_key, 1, 'system'
FROM rbac_modules m
WHERE m.category IN ('finance', 'general')
AND NOT EXISTS (
    SELECT 1 FROM rbac_permissions p
    WHERE p.role_name = 'Finance' AND p.module_key = m.module_key
);

-- ── Fleet — fleet + help ───────────────────────────────────────────
INSERT INTO rbac_permissions (role_name, module_key, can_access, granted_by)
SELECT 'Fleet', module_key, 1, 'system'
FROM rbac_modules m
WHERE m.category IN ('fleet', 'general')
AND NOT EXISTS (
    SELECT 1 FROM rbac_permissions p
    WHERE p.role_name = 'Fleet' AND p.module_key = m.module_key
);

-- ── Tester — full access to everything ────────────────────────────
INSERT INTO rbac_permissions (role_name, module_key, can_access, granted_by)
SELECT 'Tester', module_key, 1, 'system'
FROM rbac_modules m
WHERE NOT EXISTS (
    SELECT 1 FROM rbac_permissions p
    WHERE p.role_name = 'Tester' AND p.module_key = m.module_key
);

PRINT 'rbac_permissions seeded.';
GO

-- ══════════════════════════════════════════════════════════════════
PRINT 'Verifying...';
GO

SELECT 'rbac_roles'       AS TableName, COUNT(*) AS TotalRows FROM rbac_roles       UNION ALL
SELECT 'rbac_modules',                  COUNT(*)               FROM rbac_modules     UNION ALL
SELECT 'rbac_permissions',              COUNT(*)               FROM rbac_permissions;
GO

SELECT r.role_name, p.module_key, p.can_access, p.granted_by
FROM rbac_permissions p
JOIN rbac_modules m ON m.module_key = p.module_key
JOIN rbac_roles r ON r.role_name = p.role_name
ORDER BY r.role_name, m.sort_order;
GO

PRINT '';
PRINT '══════════════════════════════════════════';
PRINT '  RBAC MIGRATION COMPLETE!';
PRINT '══════════════════════════════════════════';
GO
