<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/db.php';

// CLI-only: no public database/storage/version information endpoint.
try {
    $config = app_config();
    $checks = ['database' => (int)db()->query('SELECT 1')->fetchColumn() === 1];
    auth_current_epoch();
    $checks['session_state'] = true;
    $ledger = db()->query('SELECT migration, checksum, status FROM schema_migrations')->fetchAll();
    $installed = array_column($ledger, null, 'migration');
    $checks['migrations'] = true;
    foreach (glob(dirname(__DIR__) . '/migrations/*.sql') ?: [] as $file) {
        $entry = $installed[basename($file)] ?? null;
        if ($entry === null || $entry['status'] !== 'applied' || !hash_equals($entry['checksum'], hash_file('sha256', $file))) {
            $checks['migrations'] = false;
        }
        unset($installed[basename($file)]);
    }
    if ($installed !== []) $checks['migrations'] = false;
    $usage = image_storage_usage();
    $checks['media_capacity'] = $usage['bytes'] < $config['image_storage_quota_bytes'] * .9
        && $usage['files'] < $config['image_storage_max_files'] * .9;
    $directories = ['media' => $config['image_storage_path'], 'logs' => dirname($config['diagnostic_log_path'])];
    foreach ($directories as $name => $directory) {
        $checks[$name . '_private_writable'] = is_dir($directory) && !is_link($directory)
            && is_writable($directory) && (fileperms($directory) & 0077) === 0;
        $free = disk_free_space($directory);
        $total = disk_total_space($directory);
        $checks[$name . '_disk_space'] = $free !== false && $total !== false
            && $free >= 104857600 && $free >= $total * .05;
    }
    $extensions = ['pdo_mysql', 'mbstring', 'gd', 'exif', 'fileinfo'];
    foreach ($extensions as $extension) $checks['extension_' . $extension] = extension_loaded($extension);
    $checks['cleanup_queue'] = (int)db()->query('SELECT COUNT(*) FROM image_cleanup_queue WHERE attempts >= 10')->fetchColumn() === 0;
    $healthy = !in_array(false, $checks, true);
    print json_encode(['healthy' => $healthy, 'checks' => $checks, 'media' => $usage,
        'dependencies' => ['php' => PHP_VERSION, 'database' => db()->query('SELECT VERSION()')->fetchColumn(),
            'gd' => extension_loaded('gd') ? gd_info()['GD Version'] : null]], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
    exit($healthy ? 0 : 1);
} catch (Throwable $error) {
    report_error($error, 'health_check');
    fwrite(STDERR, "Health check failed. Inspect the private diagnostic log.\n");
    exit(2);
}
