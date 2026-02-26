-- Map Feature Permissions
-- Run: sqlite3 database/tiknix.db < sql/map_permissions.sql
-- Or via MySQL: mysql -u user -p database < sql/map_permissions.sql

-- Map USA page - public access
INSERT OR REPLACE INTO authcontrol (control, method, level, description, created_at)
VALUES ('map', 'usa', 101, 'USA Map - Public access', CURRENT_TIMESTAMP);

-- State details AJAX endpoint - public access
INSERT OR REPLACE INTO authcontrol (control, method, level, description, created_at)
VALUES ('map', 'statedetails', 101, 'State Details API - Public access', CURRENT_TIMESTAMP);
