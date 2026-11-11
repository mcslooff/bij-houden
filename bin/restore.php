<?php

declare(strict_types=1);

// Operator-only restore from a backup created by bin/backup.php. Run as root
// (`sudo php bin/restore.php <backup-directory> [--apply] [--yes]`).
// Without --apply the tool performs a dry-run that verifies the backup and
// reports exactly what would change. The runtime PHP server must be stopped
// while applying a restore. The restored snapshot rolls application_state back,
// and this tool rotates the session epoch afterwards so every outstanding
// session is invalidated. Restoring never touches the private configuration
// file or its credentials.

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

$arguments = array_slice($_SERVER['argv'] ?? [], 1);
$options = array_values(array_filter($arguments, fn(string $value): bool => str_starts_with($value, '--')));
$positional = array_values(array_filter($arguments, fn(string $value): bool => !str_starts_with($value, '--')));
$apply = in_array('--apply', $options, true);
$yes = in_array('--yes', $options, true);
foreach ($options as $option) {
    if (!in_array($option, ['--apply', '--yes', '--allow-storage-change'], true)) {
        fwrite(STDERR, "Unknown option: {$option}\nUsage: sudo php bin/restore.php <backup-directory> [--apply] [--yes]\n");
        exit(2);
    }
}

function confirm(string $prompt, string $expected): bool
{
    print $prompt;
    $reply = trim((string)fgets(STDIN));
    return $reply === $expected;
}

function run_import(array $command, ?string $stdinFile): int
{
    $descriptors = [0 => ['file', $stdinFile ?? '/dev/null', 'r'], 1 => STDOUT, 2 => ['pipe', 'w']];
    $handle = proc_open($command, $descriptors, $pipes);
    if (!is_resource($handle)) {
        throw new RuntimeException('The mariadb CLI could not be started.');
    }
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $status = proc_close($handle);
    if (trim($stderr) !== '') {
        fwrite(STDERR, trim($stderr) . "\n");
    }
    return $status;
}

function acquire_media_lock(int $attempts = 240)
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

function clear_directory_files(string $directory): int
{
    $removed = 0;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $item) {
        /** @var SplFileInfo $item */
        if ($item->isLink()) {
            throw new RuntimeException('Symlink in restore target; restore refused.');
        }
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } elseif ($item->getFilename() !== '.image-storage.lock') {
            if (!unlink($item->getPathname())) throw new RuntimeException('Could not remove superseded media.');
            $removed++;
        }
    }
    return $removed;
}

function restore_media(string $sourceRoot, string $targetRoot): array
{
    $count = 0;
    $owner = fileowner($targetRoot);
    $group = filegroup($targetRoot);
    $runtimeOwnership = function (string $path) use ($owner, $group): void {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            if ($owner === false || $group === false || !chown($path, $owner) || !chgrp($path, $group)) {
                throw new RuntimeException('Could not preserve runtime media ownership.');
            }
        }
    };
    foreach (['avatars', 'group-logos'] as $directory) {
        $source = $sourceRoot . '/' . $directory;
        $targetDirectory = $targetRoot . '/' . $directory;
        if (is_link($targetDirectory)) throw new RuntimeException('Symlink in restore target.');
        if (is_dir($targetDirectory)) {
            clear_directory_files($targetDirectory);
        } elseif (!mkdir($targetDirectory, 0700, true) && !is_dir($targetDirectory)) {
            throw new RuntimeException("The media directory {$directory} could not be created.");
        }
        chmod($targetDirectory, 0700);
        $runtimeOwnership($targetDirectory);
        if (!is_dir($source)) {
            continue; // the backup had no files for this directory
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if (!$file->isFile() || $file->isLink()) {
                continue;
            }
            $relative = str_replace($sourceRoot . '/', '', $file->getPathname());
            $target = $targetRoot . '/' . $relative;
            if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0700, true)) {
                throw new RuntimeException("The media directory for {$relative} could not be created.");
            }
            chmod(dirname($target), 0700);
            $input = fopen($file->getPathname(), 'rb');
            if ($input === false) {
                throw new RuntimeException("The backup media file {$relative} could not be opened.");
            }
            $copy = fopen($target, 'wb');
            if ($copy === false) {
                fclose($input);
                throw new RuntimeException("The media file {$relative} could not be written.");
            }
            try {
                $copied = stream_copy_to_stream($input, $copy);
                if ($copied === false || $copied !== $file->getSize()) {
                    throw new RuntimeException("Media copy failed for {$relative}.");
                }
            } finally {
                fclose($input);
                fclose($copy);
            }
            chmod($target, 0600);
            $runtimeOwnership($target);
            $count++;
        }
    }
    return ['files_restored' => $count];
}

function current_tables(): array
{
    $db = db();
    return array_column($db->query('SELECT TABLE_NAME AS name FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = "BASE TABLE" ORDER BY TABLE_NAME')->fetchAll(), 'name');
}

try {
    if (count($positional) !== 1) {
        fwrite(STDERR, "Usage: sudo php bin/restore.php <backup-directory> [--apply] [--yes]\n");
        exit(2);
    }
    $source = realpath($positional[0]);
    if ($source === false || !is_dir($source)) throw new RuntimeException('Backup directory does not exist.');
    if (!is_file($source . '/meta.json') || !is_file($source . '/database.sql')) {
        throw new RuntimeException("{$source} is not a bijhouden backup directory (meta.json and database.sql required).");
    }
    $meta = json_decode((string)file_get_contents($source . '/meta.json'), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($meta) || !isset($meta['database'], $meta['dump_sha256'], $meta['created_at'])) {
        throw new RuntimeException('The backup meta.json is not a valid bijhouden backup manifest.');
    }
    $config = app_config();
    if ($meta['database'] !== $config['db_name']) {
        throw new RuntimeException("The backup belongs to database {$meta['database']} but the configured target database is {$config['db_name']}. Point BIJHOUDEN_CONFIG at a private configuration for the exact target name.");
    }
    if (($meta['storage_fingerprint'] ?? null) !== app_storage_fingerprint()
        && !in_array('--allow-storage-change', $options, true)) {
        throw new RuntimeException('Backup storage target differs. Verify the private target path explicitly before using --allow-storage-change.');
    }
    if (!hash_equals((string)$meta['dump_sha256'], (string)hash_file('sha256', $source . '/database.sql'))) {
        throw new RuntimeException('The database dump hash does not match the manifest; the backup is corrupted.');
    }
    if (!isset($meta['media_sha256']) || !is_array($meta['media_sha256'])) {
        throw new RuntimeException('This older backup has no media checksums. Retain it for manual recovery; create a new verified backup for this restore tool.');
    }
    $mediaBytes = 0;
    foreach ($meta['media_sha256'] as $relative => $checksum) {
        if (!is_string($relative) || managed_image_kind('uploads/' . $relative) === null || !is_string($checksum)) {
            throw new RuntimeException('Invalid media manifest entry.');
        }
        $file = $source . '/media/' . $relative;
        if (is_link($file) || realpath($file) !== $file || !is_file($file)
            || !hash_equals($checksum, (string)hash_file('sha256', $file))) {
            throw new RuntimeException('Media checksum verification failed; nothing was restored.');
        }
        $mediaBytes += filesize($file);
    }
    if (count($meta['media_sha256']) !== (int)$meta['media_files'] || $mediaBytes !== (int)$meta['media_bytes']) {
        throw new RuntimeException('Media manifest totals do not match.');
    }
    $actualFiles = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source . '/media', FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isLink()) throw new RuntimeException('Symlink in backup media.');
        if ($file->isFile()) $actualFiles[] = substr($file->getPathname(), strlen($source . '/media/'));
    }
    if (array_diff($actualFiles, array_keys($meta['media_sha256'])) !== []) {
        throw new RuntimeException('Backup contains media not covered by the manifest.');
    }
    $dump = inspect_database_dump($source . '/database.sql');
    if (!$dump['has_database']) {
        throw new RuntimeException('The dump is missing its CREATE DATABASE section; incompatible backup layout.');
    }
    $dumpTables = $dump['tables'];

    print "Restore plan for backup created " . $meta['created_at'] . "\n";
    print "  target database: " . $config['db_name'] . " (dropped and recreated from the dump)\n";
    print "  dump tables: " . count($dumpTables) . "\n";
    print "  media files recorded: " . ($meta['media_files'] ?? 0) . "\n";
    print "  storage fingerprint at capture: " . ($meta['storage_fingerprint'] ?? 'unknown') . "\n";
    try {
        $tablesBefore = current_tables();
        $removedTables = array_diff($tablesBefore, $dumpTables);
        $newTables = array_diff($dumpTables, $tablesBefore);
        if ($removedTables !== []) {
            print "  tables removed by the restore: " . implode(', ', $removedTables) . "\n";
        }
        if ($newTables !== []) {
            print "  tables added by the restore: " . implode(', ', $newTables) . "\n";
        }
    } catch (Throwable $skipped) {
        print "  (table comparison skipped: " . $skipped->getMessage() . ")\n";
    }
    print "  all active sessions will be invalidated by a session-epoch rotation\n";

    if (!$apply) {
        print "Dry run only. Stop the runtime server, then add --apply to restore.\n";
        exit(0);
    }
    if (!$yes && !confirm("Stop the runtime server before continuing. Type 'restore' to proceed: ", 'restore')) {
        print "Aborted.\n";
        exit(2);
    }

    // The dry-run table comparison opened a shared runtime lock. Drop it
    // before acquiring the exclusive barrier. All normal db() users are then
    // drained/refused, including login, CLI mutations and media requests.
    if (is_resource($GLOBALS['application_lock'] ?? null)) fclose($GLOBALS['application_lock']);
    $GLOBALS['application_lock'] = application_maintenance_lock(true);
    $lock = acquire_media_lock();
    $result = [];
    $incomplete = $config['image_storage_path'] . '/.restore-incomplete';
    if (is_link($incomplete) || file_put_contents($incomplete, "Restore in progress; rerun the verified restore to recover.\n", LOCK_EX) === false) {
        throw new RuntimeException('Could not create the recovery safety marker.');
    }
    try {
        $status = run_import(operator_client_command('mariadb'), $source . '/database.sql');
        if ($status !== 0) {
            throw new RuntimeException("The database import failed (exit {$status}); the database state may be partial. Re-import the dump to recover before using the application.");
        }
        $result['media'] = restore_media($source . '/media', $config['image_storage_path']);
        if ($result['media']['files_restored'] !== (int)$meta['media_files']) {
            throw new RuntimeException('Restored media count does not match the manifest.');
        }
        // Rotate the application session epoch so sessions captured before the
        // restore can never be reused against the restored snapshot.
        $rotation = run_import([
            ...operator_client_command('mariadb'), '-NBe', 'UPDATE application_state SET session_epoch = LOWER(HEX(RANDOM_BYTES(16))) WHERE id = 1',
            $config['db_name'],
        ], null);
        if ($rotation !== 0) {
            throw new RuntimeException('The database imported and the media restored, but the session epoch rotation failed. Run the rotation manually before restarting the application.');
        }
        $epoch = auth_current_epoch();
        if (isset($meta['session_epoch']) && hash_equals((string)$meta['session_epoch'], $epoch)) {
            throw new RuntimeException('Restored session epoch did not change.');
        }
        $result['session_epoch_rotated'] = true;
        if (!unlink($incomplete)) throw new RuntimeException('Could not clear the recovery safety marker.');
    } finally {
        if (is_resource($lock)) {
            fclose($lock);
        }
    }

    print json_encode($result, JSON_PRETTY_PRINT) . "\n";
    print "Next steps: start the runtime server, then run `sudo php bin/cleanup-images.php` and `sudo php bin/check-integrity.php`.\n";
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'restore: ' . $error->getMessage() . "\n");
    exit(1);
}
