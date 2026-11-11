CREATE TABLE IF NOT EXISTS consistency_locks (
  lock_name VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY
) ENGINE = InnoDB;

INSERT IGNORE INTO consistency_locks (lock_name) VALUES ('unassigned'), ('administrators');
