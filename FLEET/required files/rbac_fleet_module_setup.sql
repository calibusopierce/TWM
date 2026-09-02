-- Register the module
INSERT INTO rbac_modules (module_key, module_name, category, description)
VALUES ('fleet_tracking', 'Fleet Tracking (Cartrack)', 'Fleet', 'Vehicle status, trips, and fuel data via Cartrack Fleet API');

-- Grant access to a role (adjust role_name + permission_level as needed)
-- permission_level: 'none' | 'view_only' | 'full'
INSERT INTO rbac_permissions (role_name, module_key, permission_level)
VALUES ('Logistics', 'fleet_tracking', 'full');

-- Optional: per-user override (individual wins over role grant)
-- INSERT INTO rbac_user_access (UserID, module_key, permission_level)
-- VALUES (123, 'fleet_tracking', 'view_only');
