ALTER TABLE users ADD COLUMN IF NOT EXISTS auth_version BIGINT UNSIGNED NOT NULL DEFAULT 1;

CREATE TABLE IF NOT EXISTS auth_login_limits (
  rate_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  window_started BIGINT UNSIGNED NOT NULL,
  expires_at BIGINT UNSIGNED NOT NULL,
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  INDEX idx_auth_limits_expiry (expires_at)
) ENGINE = InnoDB;
