ALTER TABLE `groups`
  ADD COLUMN IF NOT EXISTS owner_name VARCHAR(150) NULL,
  ADD COLUMN IF NOT EXISTS owner_address VARCHAR(500) NULL,
  ADD COLUMN IF NOT EXISTS owner_phone VARCHAR(50) NULL,
  ADD COLUMN IF NOT EXISTS owner_email VARCHAR(200) NULL,
  ADD COLUMN IF NOT EXISTS logo_path VARCHAR(200) NULL,
  ADD COLUMN IF NOT EXISTS owner_label_token CHAR(32) NULL;

UPDATE `groups` SET owner_label_token = LOWER(HEX(RANDOM_BYTES(16)))
  WHERE owner_label_token IS NULL;

ALTER TABLE `groups` ADD UNIQUE INDEX IF NOT EXISTS uq_groups_owner_label_token (owner_label_token);
