<?php

declare(strict_types=1);

// Operator-only coordinated backup of the database and the private media
// storage. Run as root (`sudo php bin/backup.php`). The database snapshot and
// the media copy are taken under the application's existing media
// `.image-storage.lock`, so no image can be retired or normalized while the
// snapshot is being written and the restore point stays internally consistent.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/configuration.php';
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/image_storage.php';
require_once __DIR__ . '/operations_common.php';

ini_set('display_errors', 'stderr');
error_reporting(E_ALL);
umask(077);

$acceptedOptions = ['--yes'];
$options = array_slice($_SERVER['argv'] ?? [], 1);
foreach ($options as $option) {
    if (!in_array($option, $acceptedOptions, true)) {
        fwrite(STDERR, "Unknown option: {$option}\nUsage: sudo php bin/backup.php [--yes]\n");
        exit(2);
    }
}
$yes = in_array('--yes', $options, true);

function backup_dir(): string
{
    $configured = getenv('BIJHOUDEN_BACKUP_DIRECTORY');
    if ($configured === false || $configured === '') {
        $configured = dirname(private_configuration_path()) . '/backups';
    }
    if (!str_starts_with($configured, '/')) {
        throw new RuntimeException('The backup directory must be an absolute private path.');
    }
    $public = realpath(__DIR__ . '/../public');
    $root = app_config()['image_storage_path'];
    $resolvedParent = realpath(dirname($configured)) ?: dirname($configured);
    if ($configured === $root || $resolvedParent === $root || str_starts_with($resolvedParent, $root . '/')) {
        throw new RuntimeException('Backups must not be stored inside the image storage root.');
    }
    if ($public !== false && ($configured === $public || $resolvedParent === $public || str_starts_with($resolvedParent, $public . '/'))) {
        throw new RuntimeException('Backups must not be stored below the public web directory.');
    }
    if (!is_dir($configured) && !mkdir($configured, 0700, true) && !is_dir($configured)) {
        throw new RuntimeException('Backup directory could not be created.');
    }
    if (realpath($configured) !== $configured || !chmod($configured, 0700)) {
        throw new RuntimeException('Backup directory permissions are unavailable.');
    }
    return $configured;
}

function acquire_media_lock(int $attempts = 240): mixed
{
    $last = null;
    for ($attempt = 0; $attempt < $attempts; $attempt++) {
        try {
            return image_storage_lock(true);
        } catch (RuntimeException $error) {
            $last = $error;
            usleep(250000);
        }
    }
    throw new RuntimeException('The image storage stayed busy: ' . ($last?->getMessage() ?? 'unknown error'));
}

function mysql_dump_snapshot(string $target): int
{
    $config = app_config();
    $command = array_filter([
        ...operator_client_command('mariadb-dump'), '--single-transaction', '--skip-lock-tables', '--quick',
        '--default-character-set=utf8mb4', '--add-drop-database', '--databases', $config['db_name'],
    ], fn(string $element): bool => $element !== '');
    $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['file', $target, 'w'], 2 => ['pipe', 'w']];
    $handle = proc_open($command, $descriptors, $pipes);
    if (!is_resource($handle)) {
        throw new RuntimeException('mysqldump could not be started.');
    }
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $status = proc_close($handle);
    if ($status !== 0 || trim($stderr) !== '') {
        @unlink($target);
        throw new RuntimeException('mysqldump failed: ' . trim($stderr));
    }
    clearstatcache(true, $target);
    return (int)filesize($target);
}

function copy_media_tight(string $sourceRoot, string $targetRoot): array
{
    $manifest = ['files' => 0, 'bytes' => 0, 'sha256' => []];
    foreach (['avatars', 'group-logos'] as $directory) {
        $source = $sourceRoot . '/' . $directory;
        if (!is_dir($source)) {
            continue;
        }
        if (!is_dir($targetRoot . '/' . $directory) && !mkdir($targetRoot . '/' . $directory, 0700, true)) {
            throw new RuntimeException("The {$directory} media directory could not be created.");
        }
        chmod($targetRoot . '/' . $directory, 0700);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if (!$file->isFile() || $file->isLink() || $file->getFilename() === '.image-storage.lock') {
                continue;
            }
            $relative = str_replace($sourceRoot . '/', '', $file->getPathname());
            if (managed_image_kind('uploads/' . $relative) === null) {
                throw new RuntimeException('Unsupported file in managed media; inspect storage before backing up.');
            }
            $target = $targetRoot . '/' . $relative;
            $parent = dirname($target);
            if (!is_dir($parent) && !mkdir($parent, 0700, true)) {
                throw new RuntimeException("Media directory {$parent} could not be created.");
            }
            $input = fopen($file->getPathname(), 'rb');
            if ($input === false) {
                throw new RuntimeException("Media file {$relative} could not be opened.");
            }
            try {
                if (!flock($input, LOCK_SH | LOCK_NB)) {
                    throw new RuntimeException("Media file {$relative} is exclusively locked by a writer.");
                }
                $copy = fopen($target, 'wb');
                if ($copy === false) {
                    throw new RuntimeException("Media copy target {$relative} could not be opened.");
                }
                try {
                    $copied = stream_copy_to_stream($input, $copy);
                    if ($copied === false || $copied !== $file->getSize()) {
                        throw new RuntimeException("Media file {$relative} changed or could not be copied completely.");
                    }
                } finally {
                    fclose($copy);
                }
            } finally {
                fclose($input);
            }
            chmod($target, 0600);
            $manifest['files']++;
            $manifest['bytes'] += (int)$file->getSize();
            $manifest['sha256'][$relative] = hash_file('sha256', $target);
        }
    }
    return $manifest;
}

function row_overview(): array
{
    $counts = [];
    $tables = app_config();
    $db = db();
    $rows = $db->query('SELECT TABLE_NAME AS name FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = "BASE TABLE" ORDER BY TABLE_NAME')->fetchAll();
    foreach ($rows as $row) {
        $name = '`' . str_replace('`', '', (string)$row['name']) . '`';
        $counts[$row['name']] = (int)$db->query("SELECT COUNT(*) FROM {$name}")->fetchColumn();
    }
    return $counts;
}

try {
    $config = app_config();
    $baseDir = backup_dir();
    $directory = $baseDir . '/backup-' . gmdate('Ymd\THis\Z');
    if (is_dir($directory)) {
        throw new RuntimeException('The backup directory already exists.');
    }
    if (!mkdir($directory, 0700) || !mkdir($directory . '/media', 0700)) {
        throw new RuntimeException('The backup target directory could not be created.');
    }
    chmod($directory, 0700);
    chmod($directory . '/media', 0700);

    // Briefly drain app writers so dump, file hashes and manifest row counts
    // describe the same quiescent state. Direct SQL clients must be stopped.
    if (file_exists($config['image_storage_path'] . '/.restore-incomplete')) {
        throw new RuntimeException('Finish the interrupted restore before taking another backup.');
    }
    $GLOBALS['application_lock'] = application_maintenance_lock(true);
    clearstatcache(true, $config['image_storage_path'] . '/.restore-incomplete');
    if (file_exists($config['image_storage_path'] . '/.restore-incomplete')) {
        throw new RuntimeException('Finish the interrupted restore before taking another backup.');
    }
    db();
    $lock = acquire_media_lock();
    $released = false;
    try {
        $dumpPath = $directory . '/database.sql';
        $dumpBytes = mysql_dump_snapshot($dumpPath);
        $dump = inspect_database_dump($dumpPath);
        if ($dumpBytes < 512 || !$dump['has_database'] || $dump['tables'] === []) {
            throw new RuntimeException('The database dump is unexpectedly small or incomplete.');
        }
        $manifest = copy_media_tight($config['image_storage_path'], $directory . '/media');
        $usage = image_storage_usage();
        if ($manifest['files'] !== $usage['files'] || $manifest['bytes'] !== $usage['bytes']) {
            throw new RuntimeException('Media storage changed during the backup; every item is re-copied on the next run.');
        }
        $db = db();
        $references = $db->query('SELECT photo_path AS path FROM users WHERE photo_path IS NOT NULL
            UNION SELECT logo_path AS path FROM `groups` WHERE logo_path IS NOT NULL');
        while ($reference = $references->fetch()) {
            $path = $reference['path'];
            if (managed_image_kind($path) === null || !isset($manifest['sha256'][substr($path, strlen('uploads/'))])) {
                throw new RuntimeException('A database image reference is missing from the snapshot; backup refused.');
            }
        }
        $state = $db->query('SELECT session_epoch, legacy_database_timezone FROM application_state WHERE id = 1')->fetch();
        $migrations = $db->query("SELECT migration, status, applied_at FROM schema_migrations ORDER BY migration")->fetchAll();
        $epoch = $state === false || $state === null ? 'no-application-state-row' : (string)$state['session_epoch'];
        $timezone = $state['legacy_database_timezone'] ?? null;
        $meta = [
            'created_at' => gmdate('c'),
            'application' => 'bijhouden',
            'environment' => $config['environment'],
            'database' => $config['db_name'],
            'database_host' => $config['db_host'],
            'storage_fingerprint' => app_storage_fingerprint(),
            'database_fingerprint' => app_database_fingerprint(),
            'dump_sha256' => hash_file('sha256', $dumpPath),
            'dump_bytes' => $dumpBytes,
            'media_files' => $manifest['files'],
            'media_bytes' => $manifest['bytes'],
            'media_sha256' => $manifest['sha256'],
            'session_epoch' => $epoch,
            'legacy_database_timezone' => $timezone,
            'migrations' => $migrations,
            'table_rows' => row_overview(),
        ];
        $metadata = json_encode($meta, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if (file_put_contents($directory . '/meta.json', $metadata) !== strlen($metadata)
            || !chmod($directory . '/meta.json', 0600) || !chmod($directory . '/database.sql', 0600)) {
            throw new RuntimeException('Backup manifest or private permissions could not be finalized.');
        }
    } finally {
        if (is_resource($lock)) {
            fclose($lock);
            $released = true;
        }
    }

    $retention = max(3, min(3650, (int)(getenv('BIJHOUDEN_BACKUP_RETENTION') ?: 14)));
    $directories = glob($baseDir . '/backup-*', GLOB_ONLYDIR) ?: [];
    // Failed/incomplete captures must not evict the last good restore points.
    $directories = array_values(array_filter($directories, function (string $path): bool {
        if (is_link($path) || realpath($path) !== $path || is_link($path . '/meta.json')
            || !is_file($path . '/meta.json') || !is_file($path . '/database.sql')) return false;
        $manifest = json_decode((string)file_get_contents($path . '/meta.json'), true);
        return is_array($manifest) && ($manifest['application'] ?? null) === 'bijhouden'
            && ($manifest['database'] ?? null) === app_config()['db_name'];
    }));
    sort($directories, SORT_STRING);
    $removed = [];
    if (count($directories) > $retention) {
        $removeOldest = function (string $path) use (&$removed): void {
            if (is_link($path) || realpath($path) !== $path || is_link($path . '/meta.json') || !is_file($path . '/meta.json')) {
                return; // never prune a directory we did not create
            }
            $oldMeta = json_decode((string)file_get_contents($path . '/meta.json'), true);
            if (!is_array($oldMeta) || ($oldMeta['application'] ?? null) !== 'bijhouden'
                || ($oldMeta['database'] ?? null) !== app_config()['db_name']) return;
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $item) {
                /** @var SplFileInfo $item */
                if ($item->isLink()) continue;
                if ($item->isDir()) {
                    @rmdir($item->getPathname());
                } else {
                    @unlink($item->getPathname());
                }
            }
            @rmdir($path);
            if (!is_dir($path)) {
                $removed[] = basename($path);
            }
        };
        while (count($directories) > $retention) {
            $removeOldest(array_shift($directories));
        }
    }

    print json_encode([
        'backup' => $directory,
        'dump_bytes' => $dumpBytes,
        'media_files' => $manifest['files'],
        'media_bytes' => $manifest['bytes'],
        'tables' => $meta['table_rows'],
        'pruned' => $removed,
        'media_lock_released' => $released,
    ], JSON_PRETTY_PRINT) . "\n";

    if (($meta['environment'] ?? '') === 'production') {
        print "This backup contains production data. Keep the directory private (0700/0600).\n";
    }
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'backup: ' . $error->getMessage() . "\n");
    exit(1);
}
