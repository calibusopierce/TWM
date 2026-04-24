-- ============================================================
--  RBAC Migration (SQL Server Version)
-- ============================================================

-- 1. Modules table
IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='rbac_modules' AND xtype='U')
BEGIN
    CREATE TABLE rbac_modules (
        id INT IDENTITY(1,1) PRIMARY KEY,
        module_key VARCHAR(80) NOT NULL UNIQUE,
        module_name VARCHAR(100) NOT NULL,
        category VARCHAR(50) NOT NULL,
        icon VARCHAR(60) NOT NULL DEFAULT 'bi-grid',
        color VARCHAR(20) NOT NULL DEFAULT 'blue',
        description VARCHAR(255) NULL,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT GETDATE()
    );
END

-- 2. Permissions table
IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='rbac_permissions' AND xtype='U')
BEGIN
    CREATE TABLE rbac_permissions (
        id INT IDENTITY(1,1) PRIMARY KEY,
        role_name VARCHAR(50) NOT NULL,
        module_key VARCHAR(80) NOT NULL,
        can_access BIT NOT NULL DEFAULT 1,
        granted_by VARCHAR(100) NULL,
        granted_at DATETIME DEFAULT GETDATE(),
        CONSTRAINT uq_role_module UNIQUE (role_name, module_key),
        CONSTRAINT fk_module FOREIGN KEY (module_key)
            REFERENCES rbac_modules(module_key) ON DELETE CASCADE
    );
END

-- ── Seed modules (avoid duplicates)
INSERT INTO rbac_modules (module_key, module_name, category, icon, color, description, sort_order)
SELECT * FROM (VALUES
('careers_admin','Careers Admin','hr','bi-bag','green','Manage job postings and applicant records',10),
('view_applications','Applications','hr','bi-file-earmark-person-fill','purple','View and process job applications',20),
('uniform_inventory','Uniform Inventory','hr','bi-bag-fill','green','Manage uniform stock, issuances, and returns',30),
('employee_list','Employee List','hr','bi-people-fill','blue','View and manage employee records',40),
('fuel_dashboard','Fuel Dashboard','fleet','bi-fuel-pump-fill','green','Monitor fleet fuel usage and refuel records',50),
('graphs','Fuel Graphs','fleet','bi-bar-chart-fill','amber','Visual analytics and charts for fleet fuel data',60),
('po_index','Purchase Orders','finance','bi-receipt-cutoff','purple','Create, manage and print company POs by category',70),
('help','Help Manual','general','bi-book-fill','blue','Learn how to use the dashboard and all features',80)
) AS src(module_key,module_name,category,icon,color,description,sort_order)
WHERE NOT EXISTS (
    SELECT 1 FROM rbac_modules m WHERE m.module_key = src.module_key
);

-- ── Seed permissions (example: Admin full access)
INSERT INTO rbac_permissions (role_name, module_key, granted_by)
SELECT 'Admin', module_key, 'system'
FROM rbac_modules m
WHERE NOT EXISTS (
    SELECT 1 FROM rbac_permissions p
    WHERE p.role_name = 'Admin' AND p.module_key = m.module_key
);