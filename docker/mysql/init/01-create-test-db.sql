-- Separate database for the automated test suite.
-- Tests must run on MySQL (not SQLite) so row locks behave as in production.
CREATE DATABASE IF NOT EXISTS auction_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON auction_test.* TO 'auction'@'%';
FLUSH PRIVILEGES;
