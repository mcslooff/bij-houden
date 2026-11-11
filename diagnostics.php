<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('zend.exception_ignore_args', '1');
ini_set('log_errors', '0');
error_reporting(E_ALL);
$GLOBALS['diagnostic_memory_reserve'] = str_repeat('x', 65536);

function request_error_id(): string
{
    static $id = null;
    return $id ??= bin2hex(random_bytes(8));
}

function report_error(Throwable $error, string $context): void
{
    $entry = ['time' => gmdate('c'), 'request_id' => request_error_id(), 'context' => $context,
        'type' => get_class($error), 'code' => (string)$error->getCode(),
        'source' => basename($error->getFile()), 'line' => $error->getLine()];
    if ($error instanceof PDOException) {
        $entry['database_code'] = (int)($error->errorInfo[1] ?? 0);
    }
    // Never record exception messages, SQL parameters, query strings, cookies, or form values.
    $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    try {
        $config = app_config();
        $path = $config['diagnostic_log_path'];
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Diagnostic directory unavailable.');
        }
        if (!@chmod($directory, 0700)) throw new RuntimeException('Diagnostic directory permissions unavailable.');
        // Lock a stable inode before opening the log, including during rename.
        $lockPath = $directory . '/.diagnostic.lock';
        if (is_link($lockPath) || is_link($path)) throw new RuntimeException('Unsafe diagnostic path.');
        $lock = @fopen($lockPath, 'c+b');
        if ($lock === false) throw new RuntimeException('Diagnostic lock unavailable.');
        try {
            if (!private_diagnostic_permissions($lockPath) || !@flock($lock, LOCK_EX)) throw new RuntimeException('Diagnostic lock failed.');
            clearstatcache(true, $path);
            if (is_file($path) && filesize($path) + strlen($line) > $config['diagnostic_log_max_bytes']) {
                $keep = $config['diagnostic_log_retention'];
                for ($i = $keep; $i >= 1; $i--) {
                    $old = $path . '.' . $i;
                    if (is_link($old)) throw new RuntimeException('Unsafe rotated diagnostic path.');
                    if (!is_file($old)) continue;
                    if ($i === $keep) {
                        if (!@unlink($old)) throw new RuntimeException('Diagnostic retention failed.');
                    } elseif (!@rename($old, $path . '.' . ($i + 1))) {
                        throw new RuntimeException('Diagnostic rotation failed.');
                    }
                }
                if (!@rename($path, $path . '.1')) throw new RuntimeException('Diagnostic rotation failed.');
            }
            append_diagnostic_line($path, $line);
        } finally {
            fclose($lock);
        }
    } catch (Throwable) {
        error_log(rtrim($line));
    }
}

function append_diagnostic_line(string $path, string $line): void
{
    $handle = @fopen($path, 'ab');
    if ($handle === false) throw new RuntimeException('Diagnostic log unavailable.');
    if (!private_diagnostic_permissions($path)) { fclose($handle); throw new RuntimeException('Diagnostic log permissions unavailable.'); }
    try {
        if (!@flock($handle, LOCK_EX) || @fwrite($handle, $line) !== strlen($line)) {
            throw new RuntimeException('Diagnostic write failed.');
        }
    } finally {
        fclose($handle);
    }
}

function private_diagnostic_permissions(string $path): bool
{
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $owner = fileowner(dirname($path));
        $group = filegroup(dirname($path));
        if ($owner === false || $group === false || !@chown($path, $owner) || !@chgrp($path, $group)) return false;
    }
    return @chmod($path, 0600);
}

set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) return false;
    report_error(new ErrorException('', 0, $severity, $file, $line), 'php_warning');
    return true;
});

register_shutdown_function(function (): void {
    $error = error_get_last();
    if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) return;
    unset($GLOBALS['diagnostic_memory_reserve']);
    report_error(new ErrorException('', 0, $error['type'], $error['file'], $error['line']), 'php_fatal');
    if (PHP_SAPI === 'cli') return;
    if (!headers_sent()) {
        if (ob_get_level() > 0) ob_clean();
        header_remove('Location');
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: no-store');
        print 'The application is temporarily unavailable. Please try again later.';
    }
});
