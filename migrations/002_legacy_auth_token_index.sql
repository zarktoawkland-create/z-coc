-- z-coc migration 002: index the legacy auth token lookup
-- The compatibility path in db_api.php queries
--   users WHERE user_uuid = ? AND (auth_token = ? OR auth_token = ?)
-- Without an index on auth_token this is a full table scan per request.
-- Safe to re-run: existing indexes make this a no-op.

-- MySQL 8.0: CREATE INDEX has no IF NOT EXISTS, so guard via a stored check.
SET @idx_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND INDEX_NAME = 'idx_users_auth_token'
);
SET @sql := IF(@idx_exists = 0,
    'CREATE INDEX idx_users_auth_token ON users (auth_token)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO schema_migrations (version) VALUES ('002_legacy_auth_token_index');