<?php

declare(strict_types=1);

// Operator-only migration runner. Requires DDL privileges, so it must be
// run as root (for example `sudo php bin/migrate.php`) and connects to MariaDB
// through the local unix socket. The runtime application account stays DML-only.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/configuration.php';
require_once dirname(__DIR__) . '/maintenance.php';

ini_set('display_errors', 'stderr');
error_reporting(E_ALL);

$acceptedOptions = ['--status', '--check', '--baseline', '--force-retry', '--yes'];
$options = array_slice($_SERVER['argv'] ?? [], 1);
foreach ($options as $option) {
    if (!in_array($option, $acceptedOptions, true)) {
        fwrite(STDERR, "Unknown option: {$option}\nUsage: php bin/migrate.php [--status] [--check] [--baseline] [--force-retry] [--yes]\n");
        exit(2);
    }
}
$apply = !in_array('--status', $options, true) && !in_array('--check', $options, true);
$baselineWrite = in_array('--baseline', $options, true);
$forceRetry = in_array('--force-retry', $options, true);
$yes = in_array('--yes', $options, true);

function fail_fast(string $message, bool $condition = true): void
{
    if ($condition) {
        fwrite(STDERR, "migrate: {$message}\n");
        exit(2);
    }
}

function operator_pdo(): PDO
{
    $config = app_config();
    fail_fast('This migration runner supports a local operator socket only; refusing a remote configured database.',
        $config['db_socket'] === '' && !in_array($config['db_host'], ['localhost', '127.0.0.1', '::1'], true));
    $candidates = array_values(array_filter([
        $config['db_socket'] !== '' ? $config['db_socket'] : null,
        getenv('BIJHOUDEN_DB_SOCKET') ?: null,
        '/run/mysqld/mysqld.sock',
        '/var/run/mysqld/mysqld.sock',
        '/tmp/mysql.sock',
    ]));
    if ($config['db_socket'] !== '') $candidates = [$config['db_socket']];
    foreach ($candidates as $candidate) {
        // file_exists, not is_file: unix sockets are not regular files.
        if (!file_exists($candidate)) {
            continue;
        }
        try {
            $pdo = new PDO("mysql:unix_socket={$candidate};dbname={$config['db_name']};charset=utf8mb4", 'root', '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            return $pdo;
        } catch (PDOException $error) {
            fail_fast("Root socket connection to {$candidate} failed: " . $error->getMessage());
        }
    }
    fail_fast('No usable MariaDB unix socket was found. Set BIJHOUDEN_DB_SOCKET for the operator run.');
    exit(2); // unreachable, keeps static analysers happy
}

function migration_files(): array
{
    $directory = dirname(__DIR__) . '/migrations';
    $files = glob($directory . '/*.sql') ?: [];
    sort($files, SORT_STRING);
    $entries = [];
    foreach ($files as $file) {
        $name = basename($file);
        fail_fast("Unexpected migration file name: {$name}",
            !preg_match('~^(\d{3})_[a-z0-9_]+\.sql$~D', $name));
        $checksum = hash_file('sha256', $file);
        fail_fast("Migration {$name} could not be hashed.", $checksum === false);
        $entries[] = ['name' => basename($file), 'path' => $file, 'number' => (int)substr($name, 0, 3),
            'checksum' => $checksum];
    }
    fail_fast('No migration files were found.', count($entries) === 0);
    return $entries;
}

function ledger_rows(PDO $pdo): array
{
    try {
        return $pdo->query('SELECT migration, checksum, status, baseline, started_at, applied_at, error_code
            FROM schema_migrations ORDER BY migration')->fetchAll();
    } catch (PDOException $error) {
        if ((int)($error->errorInfo[1] ?? 0) !== 1146) throw $error;
        return [];
    }
}

function marker_evidence(): array
{
    return [
        '001_complete_inspections_and_archiving.sql' => "SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'colonies' AND COLUMN_NAME = 'archived_at'",
        '002_tasks.sql' => "SELECT 1 FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tasks'",
        '003_group_owner_labels.sql' => 'SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'groups\' AND COLUMN_NAME = \'owner_label_token\'',
        '004_authentication_hardening.sql' => "SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'auth_version'",
        '005_consistent_mutations.sql' => "SELECT 1 FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'consistency_locks'",
        '006_image_cleanup.sql' => "SELECT 1 FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'image_cleanup_queue'",
    ];
}

function confirm(string $prompt, string $expected): bool
{
    print $prompt;
    $reply = trim((string)fgets(STDIN));
    return $reply === $expected;
}

function baseline_structure_complete(PDO $pdo, string $migration): bool
{
    $number = (int)substr($migration, 0, 3);
    $requirements = [
        1 => ['queens' => ['archived_at'], 'colonies' => ['archived_at', 'active_hive_id', 'active_queen_id'],
            'inspections' => ['strength', 'temperament', 'supers', 'queen_seen', 'queen_cells', 'swarmed', 'eggs',
                'capped_brood', 'uncapped_brood', 'excessive_drones', 'laying_pattern', 'population']],
        2 => ['tasks' => ['id', 'group_id', 'title', 'description', 'target_date', 'priority', 'assigned_to',
            'created_by', 'completed_at', 'completed_by', 'completed_by_name', 'created_at', 'updated_at']],
        3 => ['groups' => ['owner_name', 'owner_address', 'owner_phone', 'owner_email', 'logo_path', 'owner_label_token']],
        4 => ['users' => ['auth_version'], 'auth_login_limits' => ['rate_key', 'window_started', 'expires_at', 'attempts']],
        5 => ['consistency_locks' => ['lock_name']],
        6 => ['image_cleanup_queue' => ['path', 'queued_at', 'attempts', 'last_attempt']],
    ];
    $columns = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    foreach ($requirements[$number] ?? [] as $table => $required) {
        $columns->execute([$table]);
        if (array_diff($required, $columns->fetchAll(PDO::FETCH_COLUMN)) !== []) return false;
    }
    $indexes = match ($number) {
        1 => ['colonies' => ['uq_active_hive', 'uq_active_queen']],
        2 => ['tasks' => ['idx_tasks_group_status', 'idx_tasks_assignee', 'idx_tasks_target']],
        3 => ['groups' => ['uq_groups_owner_label_token']],
        4 => ['auth_login_limits' => ['PRIMARY', 'idx_auth_limits_expiry']],
        5 => ['consistency_locks' => ['PRIMARY']], 6 => ['image_cleanup_queue' => ['PRIMARY']], default => [],
    };
    $query = $pdo->prepare('SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    foreach ($indexes as $table => $required) {
        $query->execute([$table]);
        if (array_diff($required, $query->fetchAll(PDO::FETCH_COLUMN)) !== []) return false;
    }
    if ($number === 5 && (int)$pdo->query("SELECT COUNT(*) FROM consistency_locks WHERE lock_name IN ('unassigned','administrators')")->fetchColumn() !== 2) return false;
    return true;
}

$pdo = operator_pdo();
$version = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
fail_fast("MariaDB is required for the IF NOT EXISTS migration syntax; the server reports {$version}",
    !str_contains($version, 'MariaDB'));
$config = app_config();
fail_fast('Required random-byte database function is unavailable.',
    (int)$pdo->query('SELECT LENGTH(RANDOM_BYTES(16))')->fetchColumn() !== 16);
$nonTransactional = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND ENGINE <> 'InnoDB'")->fetchColumn();
fail_fast('All application database tables must use InnoDB before migration.', $nonTransactional !== 0);
print "bijhouden migrations — database: {$config['db_name']}, MariaDB {$version}\n";

// Single advisory lock over the whole run; exiting (including fail_fast)
// releases it when the connection closes.
$acquired = (int)$pdo->query("SELECT GET_LOCK('bijhouden_migrations', 0) AS acquired")->fetch()['acquired'];
fail_fast('Another migration run is active (bijhouden_migrations advisory lock held).', $acquired !== 1);

$entries = migration_files();
$rows = ledger_rows($pdo);

// Recorded checksums must match the current files: a mismatch means a file was
// changed after it was applied, or the repository state does not match.
foreach ($rows as $row) {
    fail_fast("Ledger row {$row['migration']} has the unrecognised status {$row['status']}.",
        !in_array($row['status'], ['running', 'applied', 'failed', 'baseline'], true));
    $entry = array_find($entries, fn(array $candidate): bool => $candidate['name'] === $row['migration']);
    fail_fast("Ledger contains migration {$row['migration']} that is missing from the repository.", $entry === null);
    fail_fast("Checksum drift for {$row['migration']}: ledger has {$row['checksum']} but the file hashes to {$entry['checksum']}.",
        $row['checksum'] !== $entry['checksum']);
}

if ($rows === []) {
    // Empty ledger. Either a fresh database just received the schema by hand, or
    // an existing database predates the ledger. Confirm against concrete schema
    // evidence; never record a history that is not visible in the database.
    print "The migration ledger is empty. Baseline evidence:\n";
    $supported = [];
    foreach (marker_evidence() as $migration => $sql) {
        try {
            $present = (bool)$pdo->query($sql)->fetchColumn() && baseline_structure_complete($pdo, $migration);
        } catch (PDOException) {
            $present = false;
        }
        printf("%s %-45s %s\n", $present ? '[x]' : '[ ]', $migration, $present ? 'present' : 'absent');
        if ($present) {
            $supported[] = $migration;
        }
    }
    if (!$baselineWrite || $apply === false) {
        print "Run again with --baseline to record these migrations as historical baselines.\n";
        exit(0);
    }
    fail_fast('Historical schema evidence is incomplete. Repair/verify migrations 001–006 before baselining.', count($supported) !== 6);
    if (!$yes && !confirm("Record all six historical migrations as baselines? [type 'baseline'] ", 'baseline')) {
        print "Aborted.\n";
        exit(2);
    }
    // Bootstrap the ledger before 007 itself can be tracked. Use its exact
    // first statement, leaving the immutable migration file unchanged.
    $bootstrap = explode(';', (string)file_get_contents(dirname(__DIR__) . '/migrations/007_operations_performance.sql'), 2)[0];
    fail_fast('Invalid ledger bootstrap statement.', !str_starts_with($bootstrap, 'CREATE TABLE IF NOT EXISTS schema_migrations'));
    $GLOBALS['application_lock'] = application_maintenance_lock(true);
    $pdo->exec($bootstrap);
    $pdo->beginTransaction();
    $insert = $pdo->prepare("INSERT INTO schema_migrations (migration, checksum, status, baseline, applied_at)
        VALUES (?, ?, 'applied', 1, NULL)");
    foreach ($supported as $migration) {
        $entry = array_find($entries, fn(array $candidate): bool => $candidate['name'] === $migration);
        $insert->execute([$entry['name'], $entry['checksum']]);
        printf("%-45s baseline recorded\n", $entry['name']);
    }
    $pdo->commit();
    $rows = ledger_rows($pdo);
    if (!$apply) {
        exit(0);
    }
}

// Applied migrations in the ledger must precede any pending file, so the ledger
// order can never claim history that came later than reality.
$pending = [];
$unfinished = [];
foreach ($entries as $entry) {
    $recorded = array_find($rows, fn(array $row): bool => $row['migration'] === $entry['name']);
    if ($recorded !== null && in_array($recorded['status'], ['running', 'failed'], true)) {
        $unfinished[] = $entry['name'];
        $pending[] = $entry;
        continue;
    }
    if ($recorded === null) {
        $pending[] = $entry;
        continue;
    }
}

if ($unfinished !== [] && !$forceRetry) {
    fail_fast('Unfinished migration records (inspect the database, then rerun with --force-retry): ' . implode(', ', $unfinished));
}

$appliedNames = array_column(array_filter($rows, fn(array $row): bool => $row['status'] === 'applied'), 'migration');
foreach ($pending as $entry) {
    $later = array_find($appliedNames, fn(string $name): bool => $name > $entry['name']);
    fail_fast("Applying {$entry['name']} would skip ordering: {$later} is already applied.", $later !== null);
}

$checkOnly = !$apply;
if ($checkOnly) {
    foreach ($entries as $entry) {
        $recorded = array_find($rows, fn(array $row): bool => $row['migration'] === $entry['name']);
        printf("%-45s %s\n", $entry['name'], $recorded !== null ? $recorded['status'] : 'pending');
    }
    printf("%d pending\n", count($pending));
    exit(0);
}

if ($pending === []) {
    print "Nothing to apply.\n";
    exit(0);
}

if (!$yes) {
    print "About to apply the following to {$config['db_name']}:\n";
    foreach ($pending as $entry) {
        printf("  %s\n", $entry['name']);
    }
    if (!confirm("Continue? [type 'apply'] ", 'apply')) {
        print "Aborted.\n";
        exit(2);
    }
}

if (!is_resource($GLOBALS['application_lock'] ?? null)) {
    $GLOBALS['application_lock'] = application_maintenance_lock(true);
}
$insertRunning = $pdo->prepare("INSERT INTO schema_migrations (migration, checksum, status)
    VALUES (?, ?, 'running') ON DUPLICATE KEY UPDATE checksum = VALUES(checksum), status = 'running',
    started_at = CURRENT_TIMESTAMP, error_code = NULL");
$markApplied = $pdo->prepare("UPDATE schema_migrations SET status = 'applied', checksum = ?,
    applied_at = CURRENT_TIMESTAMP WHERE migration = ?");
$markFailed = $pdo->prepare("UPDATE schema_migrations SET status = 'failed', error_code = ? WHERE migration = ?");

$failure = null;
foreach ($pending as $entry) {
    try {
        $insertRunning->execute([$entry['name'], $entry['checksum']]);
        $sql = file_get_contents($entry['path']);
        if ($sql === false || trim($sql) === '') {
            throw new RuntimeException('Migration file is empty or unreadable.');
        }
        $pdo->exec($sql);
        $markApplied->execute([$entry['checksum'], $entry['name']]);
        printf("%-45s applied\n", $entry['name']);
    } catch (Throwable $error) {
        $failure = $error;
        try {
            $markFailed->execute([substr(get_class($error), 0, 32), $entry['name']]);
        } catch (Throwable $nested) {
            fwrite(STDERR, 'Could not record the failed migration: ' . $nested->getMessage() . "\n");
        }
        fwrite(STDERR, "{$entry['name']} failed: {$error->getMessage()}\n");
        break;
    }
}

if ($failure !== null) {
    fwrite(STDERR, "Run aborted. Inspect the failing statement and database state, then rerun with --force-retry once the state is resolved.\n");
    exit(1);
}

$rows = ledger_rows($pdo);
foreach ($rows as $row) {
    fail_fast("Ledger row {$row['migration']} remains {$row['status']} after the run.", $row['status'] !== 'applied');
}
print "All migrations are applied. Verify with sudo php bin/check-integrity.php\n";
exit(0);
