-- Grant test database for Doctrine `dbname_suffix: _test` (effective DB name: app_test).
CREATE DATABASE IF NOT EXISTS app_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON app_test.* TO `app`@`%`;
FLUSH PRIVILEGES;
