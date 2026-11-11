# Bij-Houden

PHP/MariaDB application for group-owned apiaries, hives, colonies, queens, colony inspections, and tasks.

The current deployment target is the Raspberry Pi on the **home LAN**, over Ethernet or Wi-Fi, at the intended **https://pai01.home** address—not one.com or a public internet endpoint. nginx/PHP-FPM and private-CA HTTPS preparation are documented in [deployment/pi/README.md](deployment/pi/README.md). No live cutover has taken place; see `PLAN.md` for the current checkpoint and deferred operations.

## Running locally

Required PHP extensions: PDO MySQL, mbstring, fileinfo, and GD with JPEG/PNG/GIF/WebP support. EXIF support is recommended for camera-photo orientation. From the project directory:

```sh
php -d expose_php=0 -S 0.0.0.0:8080 -t public public/router.php
```

Use this public-root/router command, not a development server serving the project root. Existing page and asset URLs stay the same. The public entry point serves only approved pages, assets, and image filenames; configuration, SQL, tests, documentation, and CLI tools are not web-accessible. Root `.htaccess` denies access to the private project directory; `public/.htaccess` supplies an Apache front-controller configuration that still needs verification on the production host.

### Private database configuration

The default configuration file is **outside the application directory**, at `../.bijhouden/database.json` relative to the project. For this workstation it is `/home/pixy/.bijhouden/database.json`. Keep its directory private (0700) and the file readable only by the application owner (0600). Store backups of this file securely, outside the repository and web root.

For a new installation, use `config/database.example.json` as the structure for that private file and fill in the actual database connection details. `BIJHOUDEN_CONFIG` can point to a different private JSON file. Files inside `public/` are rejected as configuration sources.

Supported environment overrides are `BIJHOUDEN_ENV` (`development`, `test`, or `production`), `BIJHOUDEN_DB_HOST`, `BIJHOUDEN_DB_PORT`, `BIJHOUDEN_DB_SOCKET`, `BIJHOUDEN_DB_NAME`, `BIJHOUDEN_DB_USER`, and `BIJHOUDEN_DB_PASSWORD`. There is no default database password or fallback development account. Missing configuration fails closed with a generic response.

### Database installation and first administrator

Provision the database and its account separately using database administration tools or the hosting control panel. The runtime account needs application DML permissions; schema installation/migrations need an appropriately privileged operator. The SQL files no longer create users, set passwords, grant privileges, or select a hardcoded database.

Import into the explicitly selected database, for example on a local MariaDB installation:

```sh
sudo mariadb bijhouden < schema.sql
```

For shared hosting, select the provisioned database before importing `schema.sql` through the provider's database tools.

Public registration is disabled, including when the database is empty. Provision the first administrator from a trusted terminal:

```sh
php bin/create-admin.php --username=your_admin_name
```

The password is entered twice without terminal echo and must have at least 12 characters and at most 72 UTF-8 bytes. Automation can supply one line through standard input with `--password-stdin`; never put passwords in command-line arguments or shell history. A database lock prevents simultaneous first-admin creation. Once an administrator exists, the command refuses to create another or modify accounts. Use the authenticated **Users** page for subsequent accounts. If the hosting package lacks CLI access, controlled provisioning must be resolved with the provider during the final deployment phase; do not re-enable anonymous registration.

For a MariaDB account whose password is stored in the private JSON file, `php bin/rotate-db-password.php` can rotate that account's own password, verify the new connection and rejection of the old password, and atomically update the private file. It prints no password. Environment-based or provider-controlled credentials must be rotated through their configuration provider instead. The exposed local development database password has already been rotated.

## Updating an existing database

For installations predating the detailed inspection fields and archiving:

```sh
sudo mariadb bijhouden < migrations/001_complete_inspections_and_archiving.sql
```

This migration can be rerun. It preserves existing observations and adds “Not assessed” defaults for new observations. If historical data already assigns the same queen to multiple active colonies, resolve those assignments before installing the unique index.

To add task management to an existing installation:

```sh
sudo mariadb bijhouden < migrations/002_tasks.sql
```

This creates the tasks table without modifying existing beekeeping records and can also be rerun.

To add group logos and public owner labels:

```sh
sudo mariadb bijhouden < migrations/003_group_owner_labels.sql
```

The migration preserves existing groups and generates stable, random owner-label links. It can be rerun without changing existing links.

To add session revocation and login rate limits:

```sh
sudo mariadb bijhouden < migrations/004_authentication_hardening.sql
```

This adds `users.auth_version` and `auth_login_limits` without changing existing passwords. It can be rerun without resetting session versions or rate counters. Deploy the migration before the authentication code. Existing pre-hardening sessions require a fresh login once.

To enable coordinated, transactional mutations:

```sh
sudo mariadb bijhouden < migrations/005_consistent_mutations.sql
```

This idempotently adds the `consistency_locks` table and its `unassigned` and `administrators` rows. It does not modify existing records, credentials, or foreign-key/uniqueness constraints. The runtime database account needs row-locking read permissions on this table (SELECT/UPDATE when using table-specific grants).

For durable image retirement:

```sh
sudo mariadb bijhouden < migrations/006_image_cleanup.sql
```

This idempotently adds `image_cleanup_queue` without altering existing image references. The runtime account needs SELECT/INSERT/UPDATE/DELETE on this queue. Install PHP GD before accepting image uploads; there is no MIME-only fallback when decoding/re-encoding is unavailable.

## Migration ledger and backups (Phase 5)

After migration `007_operations_performance.sql` creates the `schema_migrations` ledger, all later migrations are applied through the runner instead of ad-hoc SQL:

```sh
sudo php bin/migrate.php --status   # list ledger state and pending files
sudo php bin/migrate.php            # interactive apply of pending migrations
sudo php bin/migrate.php --baseline # one-off: record the pre-007 history for an existing database
```

The runner connects through the operator's MariaDB unix socket (`BIJHOUDEN_DB_SOCKET` if the socket is not `/run/mysqld/mysqld.sock`), verifies each recorded file's SHA-256 against the ledger, applies migrations strictly in file order, and records `running`, `applied`, or `failed` status with timestamps. Failed runs keep the row as `failed`; inspect the database state, fix the problem, then rerun with `--force-retry`. Never edit a migration file after it was applied: checksum drift is refused, and corrections go into a new numbered migration.

Coordinated backups cover the database and the private media tree together. A backup holds the application's `.image-storage.lock` for the whole capture, so no image can be retired or re-encrypted while the snapshot is taken, and the dump references only files present on disk:

```sh
sudo php bin/backup.php                 # snapshot into ~/.bijhouden/backups/backup-<UTC>/...
sudo php bin/restore.php <backup-dir>   # verify a backup; report the restore plan (dry run)
sudo php bin/restore.php <backup-dir> --apply   # stop the runtime server first!
```

Backups are private (directories 0700, files 0600), contain a `meta.json` manifest with the dump hash, row counts, active session epoch, and migration history, and default retention keeps the newest 14 (`BIJHOUDEN_BACKUP_RETENTION`). The default backup directory `~/.bijhouden/backups` must never be moved under the web root. Restoring drops and recreates the target database from the dump, mirrors the media tree, and rotates `application_state.session_epoch` so every session from before the restore is invalidated automatically. Restores require the backup's database name to match the configured `db_name` (use a separate private configuration file with `BIJHOUDEN_CONFIG` to restore into a staging database).

## Phase 5 operational controls

- Lists use stable paged ordering (50 records; recent inspections 12). Paging preserves scope/archive/colony filters. Dropdown choices remain complete; these are not silently truncated.
- The eight entity/admin edit forms carry session-signed row snapshots. Updates compare them against the locked current row, rejecting stale, missing, or modified tokens with HTTP 409. Reload/review before resubmitting. Add/delete/archive/complete actions are separate operations; profile identity/password workflows retain their existing account-version locking policy.
- New inspection visits and task completions use `Europe/Amsterdam`, with UTC companions. Archives record the Amsterdam wall time and zone. DST gaps are rejected; repeated autumn hours require an explicit offset. Editing an unchanged historical inspection time preserves its original value (including seconds) and unknown timezone. Existing records are **not** backfilled or silently reinterpreted; historical task-time annotation remains a separately reviewed operation. Created/updated/audit database timestamps retain their existing database basis for now, rather than assuming their historical zone.
- `php bin/health.php` is a **CLI-only** JSON check (exit 0 healthy, 1 warning, 2 failure). It checks database/session state, migration hashes/status, dependencies, private writable media/log paths, disk headroom (100 MiB and 5%), 90% media quota warnings, and repeatedly failing image cleanup. Run as the runtime user; arrange an operator-managed scheduled check/alert rather than adding a public diagnostic endpoint. Versions are an inventory, not a vulnerability scan: review vendor/security updates regularly. Scheduling and external alert delivery have not been installed by these changes.
- Private diagnostics rotate automatically at 1 MiB, retaining five archives plus the active file under a stable cross-process lock. Override with `BIJHOUDEN_DIAGNOSTIC_LOG_MAX_BYTES` and `BIJHOUDEN_DIAGNOSTIC_LOG_RETENTION`. This bounds log volume; it does not impose an age-based deletion policy on the application audit history.
- Fresh backups now include per-media SHA-256 checksums. Restore verifies all expected files, totals and the absence of untracked media **before import**. Older snapshots without media hashes remain valuable for manual recovery, but the stricter tool refuses them; take a new verified snapshot rather than deleting the old one.
- Dump/import subprocesses explicitly select the configured host/port or socket and use the local operator/root account, ignoring unrelated client defaults. The migration runner is local-socket-only and now supports ledger bootstrap plus explicit retry of failed/running migrations. Hosting-specific operator authentication is still a Phase 7 capability check.
- Application database connections hold a shared application lock; backup, migration writes and restore acquire the exclusive lock, briefly draining current database work and returning 503 for new database-backed HTTP requests. Backup/restore acquire the media lock second. Backups verify every database image reference is captured, and manifest row counts describe the quiescent snapshot. A `.restore-incomplete` marker keeps runtime database access blocked after a partial failure. Re-run the verified restore to recover; **do not simply delete the marker and resume a partially restored database**. Direct SQL clients bypass the application lock and must remain stopped during recovery. The tool preserves the media-root owner's ownership on restored files. Restore also requires a matching storage fingerprint by default; only use `--allow-storage-change` after explicitly verifying a relocated private target.
- Secure configuration recovery is separate: keep an encrypted/offline copy of the private configuration and the operator's reprovisioning instructions outside the web tree, with its decryption key stored separately. Database/media snapshots intentionally do not copy plaintext credentials. Reprovision accounts and use current/rotated credentials after recovery; never restore a previously exposed password.
- `public/.user.ini` supplies 128 MiB memory, 30-second execution/input, 8 MiB POST, 2 MiB upload, one-file and 200-input-variable limits for compatible CGI/FPM hosts. The built-in development server does not load `.user.ini`; its startup flags/server configuration must supply equivalent parsing limits. The existing application validation remains enforced independently. Hosting support/effective values must be checked in Phase 7.
- Local private runtime directories are 0700 and credentials/media/logs 0600. Code and runtime currently share the local `pixy` owner; true immutable deployment ownership requires a separate deployment/runtime identity and is deferred to the hosting-capability/release phase. Do not make the public directory writable to fix media errors; media is stored privately.

For the upgraded privileged sandbox rehearsal (ledger bootstrap/retry plus nonempty-media restore), run:

```sh
sudo bash /home/pixy/bij-houden/phase5-verify-operations.sh
```

It only modifies the isolated test database/media, then runs a read-only normal-database health check. Requires the existing isolated test server on port 8081. No new live migration is required for these controls.

## Authentication and session policy

- Sessions default to the host-only `BIJHOUDENSESSID` cookie, HttpOnly, SameSite=Lax, strict session IDs, and cookies-only transport. Private configuration may set a validated `session_cookie_name` (prefix `BIJHOUDEN`, uppercase letters/digits/underscores only) for an isolated instance; Pi staging uses `BIJHOUDENSTAGE` so it does not overwrite the normal cookie. Use private browsing for staging because cookies are not port-scoped.
- Idle expiry defaults to **30 minutes**, and absolute expiry to **8 hours**. Login and security-sensitive changes rotate the session ID and CSRF token. Keeping the current session after a password/e-mail change does not restart the absolute lifetime.
- Password changes, admin password resets, e-mail changes, role changes, and group changes increment the user's session version. Old sessions are rejected on their next request. The current session is retained for a user's own successful security change, unless another concurrent security update has superseded it.
- New passwords require **at least 12 characters and at most 72 UTF-8 bytes**, without NUL characters. Valid existing shorter passwords continue to work; successful login rehashes them at the configured bcrypt cost. Bcrypt cost defaults to **12**. No existing user password is automatically reset.
- Login reservations are atomic. Defaults are **5 attempts per account/IP pair** and **50 per IP** in **10 minutes**. A successful login resets the pair counter but not the shared IP budget. There is no account-wide lockout across all IPs. Rate-limited requests return HTTP 429 with Retry-After. Password changes also have bounded current-password verification attempts.
- Unknown accounts use a valid dummy bcrypt hash at the configured cost. Malformed password values, NUL bytes, and inputs over 72 bytes are rejected without silently accepting bcrypt-truncated input.
- Expired rate buckets are pruned in bounded batches during login activity. Failed-login audit entries do not contain passwords.

Optional JSON settings (and environment overrides) are:

| Setting | Override | Default |
| --- | --- | --- |
| `session_idle_seconds` | `BIJHOUDEN_SESSION_IDLE_SECONDS` | 1800 |
| `session_absolute_seconds` | `BIJHOUDEN_SESSION_ABSOLUTE_SECONDS` | 28800 |
| `password_cost` | `BIJHOUDEN_PASSWORD_COST` | 12 |
| `login_pair_limit` | `BIJHOUDEN_LOGIN_PAIR_LIMIT` | 5 |
| `login_ip_limit` | `BIJHOUDEN_LOGIN_IP_LIMIT` | 50 |
| `login_window_seconds` | `BIJHOUDEN_LOGIN_WINDOW_SECONDS` | 600 |

`BIJHOUDEN_ENV=production` requires server-confirmed HTTPS and sets Secure cookies. Plain HTTP requests to application pages receive 403 instead of a redirect to an unverified hostname. Forwarded headers are **not trusted**: one.com's TLS/proxy behavior still needs verification during final deployment. Local development/test HTTP continues to work. Do not enable production mode or change the public URL until the final deployment phase.

Direct operator changes to passwords/roles/groups outside these application paths must also increment `auth_version` to revoke sessions.

## Ownership and archives

- Related apiaries, hives, colonies, and queens must belong to the same group. Unassigned records are accessible only to administrators.
- Group changes are blocked when existing links, including archived records, would cross group boundaries.
- An active colony requires one hive. A hive and queen can each be assigned to at most one active colony.
- Archiving a colony preserves its records and inspections, and releases its hive and queen for new assignments.
- Archiving a queen preserves her record and marks any active colony assigned to her as queenless. Archived colony links remain intact.
- Use **View archived colonies/queens** to browse archived records. Inspection histories for archived colonies are read-only.
- Groups with records cannot be removed. Hives referenced by archived colonies are retained to preserve history.

### Request validation and transaction rules

- `request_validation.php` checks request structure, UTF-8/NUL handling, canonical positive unsigned-INT IDs, date formats, action choices, checkbox values, and upload structure before dispatch. Business validation keeps validly structured form values available for correction. Login preserves generic authentication failures for malformed credentials.
- Malformed input returns 400; forbidden mutations return 403; changed ownership, busy records, and unhandled duplicate/foreign-key conflicts return a safe 409. Read-only pages reject POST with 405. Expected form validation and some handled conflicts use the existing HTML/redirect flow.
- `mutation_guard.php` starts a READ COMMITTED transaction for protected form mutations, revalidates and locks the actor, then locks affected groups and target records. Ordinary inventory writes in separate groups remain independent; linked writes in the same group are serialized briefly. Administrator changes and nullable/unassigned ownership have dedicated coordination rows.
- Lock order is: administrator coordination where required, affected user rows in numeric order, unassigned coordination where required, group rows in numeric order, then target records. User changes also account for groups affected by task assignments and historical foreign-key references.
- Entity/task UPDATE and DELETE statements include original-ownership predicates. Inspection mutations include their colony/ownership/active-state predicates. Read payloads are fetched with their scope predicates rather than relying on a separate existence check; related-name joins also check matching ownership.
- These locks protect authorization and linked-record invariants. Ordinary authorized field edits still use last-writer-wins semantics; optimistic browser revision tokens and automatic merging are not implemented.
- Record writes and audit inserts share a transaction. Existing handlers either explicitly commit after auditing or redirect with 302/303 after an audited successful operation; the guard finalizes the latter before response output. Uncommitted validation responses, errors, unfinished handlers, and fatal errors roll back. `release_failed_mutation()` releases failed-form locks before rendering. Use `begin_if_needed()` instead of opening a nested PDO transaction in a guarded handler.
- New write routes must participate in the guard's route/user/group mapping, or implement equivalent explicit authorization and locking. Guarded entity helpers require that context; do not call them as standalone unguarded writers. Profile identity/password changes retain their own actor-version-checked transactions.
- Existing foreign keys and active hive/queen unique indexes remain enforced. Broader composite ownership foreign keys were assessed but not introduced because nullable administrator-only ownership and existing storage/archive deletion semantics need a separate compatible design. Group locks protect application writes; direct imports/operator SQL must respect these invariants and be checked before/after changes.

Run the read-only ownership/reference preflight with the configured database account:

```sh
php bin/check-integrity.php
```

It outputs counts, not private contact data, and exits nonzero on missing links, mismatched ownership, archived-queen assignments, or duplicate active allocations. It does not repair or delete records. Inconsistent legacy references are hidden in related-name displays and guarded against unsafe mutation until resolved.

## Inspections

Strength: Not assessed, Weak, Medium, Strong (legacy Unknown values remain readable).
Temperament: Not assessed, Calm, Normal, Nervous, Aggressive.
Laying pattern: Not assessed, None, Not uniformly, Mostly uniformly, Uniform.
Population: Not assessed, Low, Medium, High.
Supers: blank means not assessed; zero means no supers.
Observation checkboxes record whether the observation was made; unchecked values are false.

The dashboard displays the latest inspection visible to the user, ordered by visit date, with observations and a link to its full history entry. Inspection follow-up actions remain free text; tasks are managed separately.

## Tasks

- Every task belongs to one group. Any member of that group can add, edit, remove, complete, reopen, or reassign its tasks. Administrators can manage tasks across groups.
- Title is required; description, assignee, target completion date, and priority are optional. Default priority is **No priority**, with **High**, **Medium**, and **Low** also available.
- Assignees must belong to the task's group. If a user leaves the group or is deleted, their tasks become unassigned.
- The overview orders open tasks assigned to the current user first, then unassigned tasks, then tasks assigned to other group members. Within each section, dated tasks appear earliest target date first; undated tasks follow in High/Medium/Low/No priority order. Equal target dates use priority as a tie-breaker.
- Completed tasks appear last, ordered by completion date descending. Completion records the actual acting user, the date/time, and a display-name snapshot retained even if the account is later deleted.
- Completing an already completed task preserves its original completion record. Reopening clears the current completion record; completion/reopening events remain in the activity log.
- The dashboard shows open/completed totals, tasks assigned to the current user, and unassigned tasks within their scope.

## Verification

Integration suites require a **separate database whose name contains a `test` component**, a private test configuration, an isolated image directory, and a dedicated test HTTP server. The private file should use `"environment": "test"` and an explicit absolute `image_storage_path` such as `$HOME/.bijhouden/test-images` (expand the home path in JSON). A `diagnostic_log_directory` such as `$HOME/.bijhouden/test-logs` can also be set. The parent directories must exist. Test mode rejects the normal project upload directory and paths inside it; if normal media uses a custom external directory, explicitly select a different test directory as well. Never reuse the development/production database or its media directory. Install `schema.sql` into the selected test database first.

Start the test server in another terminal, for example:

```sh
mkdir -p "$HOME/.bijhouden/test-sessions"
chmod 700 "$HOME/.bijhouden/test-sessions"
BIJHOUDEN_ENV=test BIJHOUDEN_CONFIG="$HOME/.bijhouden/test.json" BIJHOUDEN_PASSWORD_COST=4 \
  php -d expose_php=0 -d session.save_path="$HOME/.bijhouden/test-sessions" -S 127.0.0.1:8081 -t public public/router.php
```

Then run the checks with the same configuration:

```sh
for file in *.php public/*.php bin/*.php; do php -l "$file"; done
export BIJHOUDEN_ENV=test
export BIJHOUDEN_CONFIG="$HOME/.bijhouden/test.json"
export BIJHOUDEN_TEST_URL=http://127.0.0.1:8081
export BIJHOUDEN_TEST_SESSION_PATH="$HOME/.bijhouden/test-sessions"
export BIJHOUDEN_PASSWORD_COST=4
python3 -B tests/p0_hardening.py
python3 -B tests/integration.py
python3 -B tests/tasks_integration.py
python3 -B tests/group_owner_labels_integration.py
python3 -B tests/authentication_integration.py
python3 -B tests/consistency_integration.py
python3 -B tests/media_security_integration.py
python3 -B tests/browser_security.py
```

The suites check the HTTP server's test-only database **and image-storage fingerprints** before creating fixtures. A production/development target, a server using a different database, or mismatched media storage is rejected. Fixtures and their managed image files are cleaned up after each suite. `p0_hardening.py` additionally requires a test database without an existing administrator so it can verify first-admin provisioning. It also checks that the normal server on port 8080 rejects test targeting; keep that local development server available for that check.

Use a separate session directory for the test server. The authentication suite modifies only its fixture session timestamps there to test expiry without waiting hours. Cost 4 and shortened security settings are allowed only in test mode; production/development bcrypt cost must be at least 10. Run the suites sequentially against a dedicated database because fixture cleanup resets the test rate-limit buckets.

`BIJHOUDEN_TEST_TEMP_DIRECTORY` can select an existing writable absolute private temporary base for a dedicated runtime identity; it defaults to `/tmp/opencode`. The Pi staged verifier uses private runtime paths and explicit CA trust. HTTPS tests assert Secure cookies rather than assuming HTTP, and the staged Chromium trust store is scoped to the isolated account. No certificate verification is disabled. See `deployment/pi/README.md` for the actual-stack verification commands and the separate loopback quota subtest.

`consistency_integration.py` requires a **privileged isolated MariaDB sandbox**, including CREATE TRIGGER and lock/process introspection access. It starts a temporary multi-worker loopback server, deliberately fails/delays audit inserts, and runs parallel requests to test transaction rollback and ordering. It removes its triggers/fixtures and stops its worker group afterward. Do not grant these test-only privileges to the production runtime account or run this suite on production.

The extended media/browser suites require Pillow, OpenCV/numpy, Chromium, and Poppler (`pdftotext`, `pdftoppm`). Browser automation uses a small local DevTools client rather than an external automation package. The media suite also uses the privileged isolated sandbox for audit fault/delay injection and concurrent quota checks. These are development test dependencies, not production application dependencies.

### Full Phase 6 verification report

With the same explicitly isolated environment and both required local servers available, run:

```sh
python3 tests/run_phase6.py --include-scalability \
  --report-directory "$HOME/.bijhouden/phase6-$(date -u +%Y%m%dT%H%M%SZ)"
```

The runner checks the database/storage/HTTP isolation contract before starting, runs suites sequentially, and writes private per-suite logs plus `results.json` with exit codes, elapsed times, target fingerprints and source hashes. Use a new report directory for every run; existing reports are not overwritten. Logs may contain test-failure details: keep them private and redact before sharing. A timed-out/failed suite stops the run; inspect its fixtures/processes before retrying.

The default battery has 14 suites. `--include-scalability` adds a fifteenth check with 13,000 scoped apiary/hive/queen/task/inspection records, first/last-page bounds and task ordering across an assignment boundary. Its fixtures are removed in `finally`. It checks local response times against a five-second per-request ceiling; results from the PHP development server are not production load-test guarantees. Complete relationship dropdowns can still produce larger forms even when result tables are paged.

Chromium verification covers desktop/mobile layouts, anonymous and authenticated owner labels, software decoding of screenshot and printed-PDF QR codes, visible printed logos and CSP-safe printing. A physical phone scan/printer check and the actual hosting runtime remain separate release checks. Keep the deferred offline GPG recovery and scheduled email-monitoring setup explicitly pending before production release.

## Image processing, privacy, and cleanup

- JPEG, PNG, GIF, and WebP inputs are limited to **2 MB**, **4096 pixels per side**, and **4 million total pixels** by default. The decoder is called only after these header/size checks and a conservative memory-budget check. Requests over 8 MB receive 413.
- Images are fully decoded, resized without upscaling, and re-encoded to new JPEG or PNG files. Avatar maximum edge: **512 px**; group logo maximum edge: **1600 px**. Transparency is retained. GIF animation becomes its first frame. JPEG EXIF orientation is applied when EXIF is available, then EXIF/XMP/comments and other unnecessary metadata are discarded. Re-encoded files also have a 2 MB cap.
- Normalized file names have a reserved `v1-` component. Client-supplied file names are never used for storage. New files are created exclusively, with private permissions and a lease held until their metadata commit/rollback is confirmed.
- Default managed-image budget: **100 MiB and 2048 files**, including pending/retired files. A shared filesystem lock makes quota checks and allocation atomic across workers. Replacement requires temporary headroom for both the old and new file; a full/busy store preserves the current image.
- Files live outside `public/`, defaulting to the project's private `uploads` directory. Set absolute `image_storage_path` / `BIJHOUDEN_IMAGE_STORAGE_PATH` to use another private directory. Storage directories are 0700 and files 0600. Image responses are served only through the reference-aware application handler, never as executable files or symlink targets.
- **Personal avatars are accessible only to their owner or an administrator.** Current group logos remain public for owner labels and do not require a session. Unreferenced/retired paths return 404 even if a file is waiting for cleanup. Image reads use a short shared lease and bounded read before sending the response, so slow clients do not pin retired files.
- Retirement is queued in the same SQL transaction as metadata replacement/removal. After commit, cleanup runs best-effort without delaying the user on a busy storage lock. Failed cleanup stays queued for retry. Active references and active upload leases protect files from deletion.
- Test configuration must use separate media storage; test orphan cleanup must never see ordinary application images.

Optional JSON settings/environment overrides:

| Setting | Override | Default |
| --- | --- | --- |
| `image_storage_path` | `BIJHOUDEN_IMAGE_STORAGE_PATH` | private project `uploads` |
| `image_max_dimension` | `BIJHOUDEN_IMAGE_MAX_DIMENSION` | 4096 |
| `image_max_pixels` | `BIJHOUDEN_IMAGE_MAX_PIXELS` | 4000000 |
| `image_storage_quota_bytes` | `BIJHOUDEN_IMAGE_STORAGE_QUOTA_BYTES` | 104857600 |
| `image_storage_max_files` | `BIJHOUDEN_IMAGE_STORAGE_MAX_FILES` | 2048 |

Maintain retired files from a trusted terminal (schedule this during the final hosting setup):

```sh
php bin/cleanup-images.php
php bin/cleanup-images.php --orphans
```

The first command retries queued retirement. `--orphans` also considers recognized, unreferenced images older than 24 hours; live file leases are skipped. It does not delete unknown filenames, symlink targets, or currently referenced files. Deferred cleanup returns a nonzero status for monitoring/retry. The grace period protects interrupted/uncertain commits.

For legacy image references:

```sh
php bin/normalize-images.php
php bin/normalize-images.php --apply
```

The default is a read-only inventory. `--apply` locks each current reference, creates a normalized replacement, updates metadata/audit transactionally, and queues the old file. A changed/missing/invalid reference is not overwritten or repaired automatically. Referenced legacy images are re-encoded on read until migrated, so original metadata is not served. Back up image storage and the database together before operator maintenance.

## Browser headers and diagnostics

The dispatcher sends an enforced same-origin CSP without unsafe-inline/eval, blocks inline script/style attributes and framing, disables unused browser capabilities, applies no-referrer/CORP/COOP/nosniff, and removes X-Powered-By. Scripts, including sidebar initialization and confirmation handling, are local external files. SVG menu icons and QR rendering work under this policy. Dynamic responses/private avatars use no-store; public logos and static assets use no-cache. HSTS is prepared for production HTTPS application pages; local HTTP is not pinned or redirected.

`diagnostics.php` records redacted JSON lines in a private 0700 directory with a 0600 log file. The default is `logs/errors.jsonl` alongside the private configuration directory, with an isolated test default. Configure `diagnostic_log_directory` / `BIJHOUDEN_DIAGNOSTIC_LOG_DIRECTORY` to choose another private location. Responses include an X-Request-ID for correlation. Messages, SQL parameters, form bodies, cookies, query strings, and exception arguments are not logged. Startup/fatal details remain hidden from clients, and sanitized fallback diagnostics go to the server logger if private logging is unavailable. Log rotation and monitoring remain Phase 5 work.

`public/.user.ini` prepares hidden startup errors and exception-argument suppression for hosts that honor per-directory settings. `expose_php` is a system setting: disable it in provider PHP settings or CLI startup (`-d expose_php=0`) during hosting verification, rather than relying on a runtime ini change. The application also strips its response version header. Actual one.com behavior still needs final verification.

## Group logos and printable hive owner labels

Administrators can edit optional owner/contact name, address, phone number, e-mail address, and group logo under **Groups**. Logos use the shared image policy above and are stored in the managed private `group-logos` subdirectory while being served publicly only when referenced by a group.

Each hive assigned to a group has a **Print owner label** button. All hives in the group share a read-only owner-contact label containing the logo, supplied contact details, and a QR code pointing back to that label. The label can be opened without login using its random link. It does not expose hive notes, colonies, tasks, or group membership. Editing the group updates the contact page without changing its QR link.

Open the app using a LAN/public address reachable by the phone before printing. A QR code containing `localhost` points to the scanning device itself. For a stable canonical address, set the public base URL when starting PHP, for example:

```sh
BIJHOUDEN_PUBLIC_URL="http://pai01.local:8080" php -d expose_php=0 -S 0.0.0.0:8080 -t public public/router.php
```

Production URL changes and deployment to one.com are deferred to the final phase in `PLAN.md`; no production hostname is activated by the local hardening changes. The current public dispatcher targets deployment at the subdomain root; verify host-specific routing during that final phase. The print button waits for the logo to load and the QR code to be generated. The print stylesheet preserves both the logo and QR code and hides the print controls.

QR generation uses a local vendored copy of `qrcode-generator` 1.4.4 (MIT, Kazuhiko Arase), so no external QR service is contacted. Source: `https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js`. Vendored SHA-256: `bb2365e4902f4f84852cf4025e6f6a60325a682aeafa43fb63b7fc8f098d1ef2`. License: `vendor/qrcode-generator/LICENSE.txt`.
