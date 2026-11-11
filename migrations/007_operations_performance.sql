CREATE TABLE IF NOT EXISTS schema_migrations (
  migration VARCHAR(150) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  status ENUM('running','applied','failed') NOT NULL,
  baseline TINYINT(1) NOT NULL DEFAULT 0,
  started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  applied_at TIMESTAMP NULL,
  error_code VARCHAR(32) NULL
) ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS application_state (
  id TINYINT UNSIGNED PRIMARY KEY,
  session_epoch CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  legacy_database_timezone VARCHAR(64) NULL
) ENGINE = InnoDB;
INSERT IGNORE INTO application_state (id, session_epoch) VALUES (1, LOWER(HEX(RANDOM_BYTES(16))));

ALTER TABLE inspections
  ADD COLUMN IF NOT EXISTS visited_at_utc DATETIME NULL,
  ADD COLUMN IF NOT EXISTS visited_timezone VARCHAR(64) NULL,
  ADD COLUMN IF NOT EXISTS visited_offset VARCHAR(6) NULL;
ALTER TABLE tasks
  ADD COLUMN IF NOT EXISTS completed_at_utc DATETIME NULL,
  ADD COLUMN IF NOT EXISTS completed_timezone VARCHAR(64) NULL;
ALTER TABLE colonies ADD COLUMN IF NOT EXISTS archived_timezone VARCHAR(64) NULL;
ALTER TABLE queens ADD COLUMN IF NOT EXISTS archived_timezone VARCHAR(64) NULL;

ALTER TABLE users ADD INDEX IF NOT EXISTS idx_users_photo_path (photo_path);
ALTER TABLE `groups` ADD INDEX IF NOT EXISTS idx_groups_logo_path (logo_path);
ALTER TABLE apiaries ADD INDEX IF NOT EXISTS idx_apiaries_group_name (group_id, name, id);
ALTER TABLE hives ADD INDEX IF NOT EXISTS idx_hives_group_name (group_id, name, id);
ALTER TABLE queens ADD INDEX IF NOT EXISTS idx_queens_group_archive (group_id, archived_at, name, id);
ALTER TABLE colonies ADD INDEX IF NOT EXISTS idx_colonies_group_archive (group_id, archived_at, name, id);
ALTER TABLE tasks ADD INDEX IF NOT EXISTS idx_tasks_overview (group_id, completed_at, assigned_to, target_date, priority, id);
ALTER TABLE activity_log ADD INDEX IF NOT EXISTS idx_activity_actor_id (user_id, id);
ALTER TABLE inspections ADD INDEX IF NOT EXISTS idx_inspections_visit (visited_at, id);
ALTER TABLE image_cleanup_queue ADD INDEX IF NOT EXISTS idx_image_cleanup_order (attempts, queued_at, path);
