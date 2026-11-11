CREATE TABLE IF NOT EXISTS `groups` (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  owner_name VARCHAR(150) NULL,
  owner_address VARCHAR(500) NULL,
  owner_phone VARCHAR(50) NULL,
  owner_email VARCHAR(200) NULL,
  logo_path VARCHAR(200) NULL,
  owner_label_token CHAR(32) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT uq_groups_owner_label_token UNIQUE (owner_label_token)
  , INDEX idx_groups_logo_path (logo_path)
) ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  first_name VARCHAR(80) NULL,
  last_name VARCHAR(80) NULL,
  email VARCHAR(200) NULL UNIQUE,
  photo_path VARCHAR(200) NULL,
  password_hash VARCHAR(255) NOT NULL,
  auth_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  role VARCHAR(20) NOT NULL DEFAULT 'user',
  group_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  last_login_at TIMESTAMP NULL,
  CONSTRAINT fk_users_group FOREIGN KEY (group_id) REFERENCES `groups` (id) ON DELETE SET NULL
  , INDEX idx_users_photo_path (photo_path)
) ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS auth_login_limits (
  rate_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  window_started BIGINT UNSIGNED NOT NULL,
  expires_at BIGINT UNSIGNED NOT NULL,
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  INDEX idx_auth_limits_expiry (expires_at)
) ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS consistency_locks (
  lock_name VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY
) ENGINE = InnoDB;
INSERT IGNORE INTO consistency_locks (lock_name) VALUES ('unassigned'), ('administrators');

CREATE TABLE IF NOT EXISTS image_cleanup_queue (
  path VARCHAR(200) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  queued_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  last_attempt DATETIME NULL
  , INDEX idx_image_cleanup_order (attempts, queued_at, path)
) ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS activity_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  event VARCHAR(30) NOT NULL,
  detail VARCHAR(255) NULL,
  ip VARCHAR(45) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_activity_user (user_id, created_at),
  INDEX idx_activity_event (event, detail, ip, created_at)
  , INDEX idx_activity_actor_id (user_id, id)
) ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS apiaries (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  location VARCHAR(200) NOT NULL,
  notes TEXT NULL,
  group_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_apiaries_group FOREIGN KEY (group_id) REFERENCES `groups` (id) ON DELETE SET NULL,
  INDEX idx_apiaries_group (group_id)
  , INDEX idx_apiaries_group_name (group_id, name, id)
) ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS hives (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  apiary_id INT UNSIGNED NULL,
  notes TEXT NULL,
  group_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_hives_apiary
    FOREIGN KEY (apiary_id) REFERENCES apiaries (id)
    ON DELETE SET NULL,
  CONSTRAINT fk_hives_group FOREIGN KEY (group_id) REFERENCES `groups` (id) ON DELETE SET NULL,
  INDEX idx_hives_apiary (apiary_id),
  INDEX idx_hives_group (group_id)
  , INDEX idx_hives_group_name (group_id, name, id)
) ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS queens (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NULL,
  hatching_date DATE NULL,
  state ENUM('unknown','capped_cell','emerged','mated','mature','old') NOT NULL DEFAULT 'unknown',
  notes TEXT NULL,
  group_id INT UNSIGNED NULL,
  archived_at DATETIME NULL,
  archived_timezone VARCHAR(64) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_queens_group FOREIGN KEY (group_id) REFERENCES `groups` (id) ON DELETE SET NULL,
  INDEX idx_queens_group (group_id)
  , INDEX idx_queens_group_archive (group_id, archived_at, name, id)
) ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS colonies (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  hive_id INT UNSIGNED NOT NULL,
  queen_id INT UNSIGNED NULL,
  date_of_birth DATE NOT NULL,
  notes TEXT NULL,
  group_id INT UNSIGNED NULL,
  archived_at DATETIME NULL,
  archived_timezone VARCHAR(64) NULL,
  active_hive_id INT UNSIGNED GENERATED ALWAYS AS (IF(archived_at IS NULL, hive_id, NULL)) PERSISTENT,
  active_queen_id INT UNSIGNED GENERATED ALWAYS AS (IF(archived_at IS NULL, queen_id, NULL)) PERSISTENT,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT uq_active_hive UNIQUE (active_hive_id),
  CONSTRAINT uq_active_queen UNIQUE (active_queen_id),
  CONSTRAINT fk_colonies_hive
    FOREIGN KEY (hive_id) REFERENCES hives (id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_colonies_queen
    FOREIGN KEY (queen_id) REFERENCES queens (id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_colonies_group FOREIGN KEY (group_id) REFERENCES `groups` (id) ON DELETE SET NULL,
  INDEX idx_colonies_queen (queen_id),
  INDEX idx_colonies_group (group_id)
  , INDEX idx_colonies_group_archive (group_id, archived_at, name, id)
) ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS inspections (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  colony_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  visited_at DATETIME NOT NULL,
  visited_at_utc DATETIME NULL,
  visited_timezone VARCHAR(64) NULL,
  visited_offset VARCHAR(6) NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'unknown',
  strength VARCHAR(20) NOT NULL DEFAULT 'not_assessed',
  temperament VARCHAR(20) NOT NULL DEFAULT 'not_assessed',
  supers SMALLINT UNSIGNED NULL,
  queen_seen TINYINT(1) NOT NULL DEFAULT 0,
  queen_cells TINYINT(1) NOT NULL DEFAULT 0,
  swarmed TINYINT(1) NOT NULL DEFAULT 0,
  eggs TINYINT(1) NOT NULL DEFAULT 0,
  capped_brood TINYINT(1) NOT NULL DEFAULT 0,
  uncapped_brood TINYINT(1) NOT NULL DEFAULT 0,
  excessive_drones TINYINT(1) NOT NULL DEFAULT 0,
  laying_pattern VARCHAR(20) NOT NULL DEFAULT 'not_assessed',
  population VARCHAR(20) NOT NULL DEFAULT 'not_assessed',
  action VARCHAR(200) NULL,
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_inspections_colony FOREIGN KEY (colony_id) REFERENCES colonies (id) ON DELETE RESTRICT,
  CONSTRAINT fk_inspections_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
  INDEX idx_inspections_colony (colony_id, visited_at),
  INDEX idx_inspections_user (user_id)
  , INDEX idx_inspections_visit (visited_at, id)
) ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS tasks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  group_id INT UNSIGNED NOT NULL,
  title VARCHAR(150) NOT NULL,
  description TEXT NULL,
  target_date DATE NULL,
  priority ENUM('none', 'high', 'medium', 'low') NOT NULL DEFAULT 'none',
  assigned_to INT UNSIGNED NULL,
  created_by INT UNSIGNED NULL,
  completed_at DATETIME NULL,
  completed_at_utc DATETIME NULL,
  completed_timezone VARCHAR(64) NULL,
  completed_by INT UNSIGNED NULL,
  completed_by_name VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_tasks_group FOREIGN KEY (group_id) REFERENCES `groups` (id) ON DELETE RESTRICT,
  CONSTRAINT fk_tasks_assignee FOREIGN KEY (assigned_to) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_tasks_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_tasks_completer FOREIGN KEY (completed_by) REFERENCES users (id) ON DELETE SET NULL,
  INDEX idx_tasks_group_status (group_id, completed_at),
  INDEX idx_tasks_assignee (assigned_to),
  INDEX idx_tasks_target (target_date)
  , INDEX idx_tasks_overview (group_id, completed_at, assigned_to, target_date, priority, id)
) ENGINE = InnoDB;

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
