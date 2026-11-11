<?php

declare(strict_types=1);

function application_maintenance_lock(bool $exclusive = false)
{
    $root = app_config()['image_storage_path'];
    if (!$exclusive && file_exists($root . '/.restore-incomplete')) {
        throw new ConfigurationException('Recovery must be completed before using the application.');
    }
    if (!is_dir($root) && !@mkdir($root, 0700, true) && !is_dir($root)) {
        throw new ConfigurationException('Runtime storage is unavailable.');
    }
    $path = $root . '/.application.lock';
    if (is_link($path)) throw new ConfigurationException('Unsafe maintenance lock.');
    $handle = @fopen($path, 'c+b');
    if ($handle === false) throw new ConfigurationException('Maintenance lock is unavailable.');
    // Operator-created locks must remain usable by the runtime storage owner.
    $owner = fileowner($root);
    if (function_exists('posix_geteuid') && posix_geteuid() === 0 && $owner !== false) @chown($path, $owner);
    if (!@chmod($path, 0600)) {
        fclose($handle);
        throw new ConfigurationException('Maintenance lock permissions unavailable.');
    }
    $deadline = microtime(true) + ($exclusive ? 15 : 0);
    do {
        if (flock($handle, ($exclusive ? LOCK_EX : LOCK_SH) | LOCK_NB)) {
            clearstatcache(true, $root . '/.restore-incomplete');
            if (!$exclusive && file_exists($root . '/.restore-incomplete')) {
                fclose($handle);
                throw new ConfigurationException('Recovery must be completed before using the application.');
            }
            return $handle;
        }
        if (!$exclusive) break;
        usleep(10000);
    } while (microtime(true) < $deadline);
    fclose($handle);
    throw new ConfigurationException('Application maintenance is in progress.');
}
