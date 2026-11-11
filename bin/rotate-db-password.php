<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/db.php';

$staged = null;
$changed = false;
try {
    foreach (['HOST', 'PORT', 'SOCKET', 'NAME', 'USER', 'PASSWORD'] as $key) {
        if (getenv('BIJHOUDEN_DB_' . $key) !== false) {
            throw new InvalidArgumentException('Environment-based credentials must be rotated through their configuration provider.');
        }
    }
    $config = app_config();
    $path = private_configuration_path();
    if (!is_file($path) || is_link($path) || !is_writable(dirname($path))) {
        throw new InvalidArgumentException('A writable private configuration directory and regular configuration file are required.');
    }
    $values = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $newPassword = bin2hex(random_bytes(32));
    $values['db_password'] = $newPassword;
    umask(0077);
    $staged = tempnam(dirname($path), '.database-new-');
    if ($staged === false || file_put_contents($staged, json_encode($values, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n") === false) {
        throw new RuntimeException('Could not stage private configuration.');
    }
    chmod($staged, 0600);
    $pdo = db();
    // MariaDB does not accept a parameter marker in SET PASSWORD's grammar.
    $pdo->exec('SET PASSWORD = PASSWORD(' . $pdo->quote($newPassword) . ')');
    $changed = true;
    $verify = new PDO(database_dsn($config), $config['db_user'], $newPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $verify->query('SELECT 1');
    $rejected = false;
    try {
        new PDO(database_dsn($config), $config['db_user'], $config['db_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (PDOException $error) {
        $rejected = ($error->errorInfo[1] ?? null) === 1045;
    }
    if (!$rejected) {
        throw new RuntimeException('The previous password was not rejected.');
    }
    if (!rename($staged, $path)) {
        throw new RuntimeException('Could not activate private configuration.');
    }
    $staged = null;
    print "Database password rotated; new connection verified and previous password rejected. Private configuration updated.\n";
} catch (Throwable $error) {
    $rollbackConfirmed = !$changed;
    if ($changed) {
        try {
            $pdo->exec('SET PASSWORD = PASSWORD(' . $pdo->quote($config['db_password']) . ')');
            $rollbackConfirmed = true;
        } catch (Throwable $rollbackError) {
            fwrite(STDERR, "Database password rollback could not be confirmed. Retaining the staged private configuration for recovery.\n");
        }
    }
    if ($rollbackConfirmed && is_string($staged) && is_file($staged)) {
        unlink($staged);
    }
    fwrite(STDERR, $error instanceof InvalidArgumentException ? $error->getMessage() . "\n"
        : "Database credential rotation failed; existing configuration has been retained. Use the database administrator if needed.\n");
    if ($error instanceof PDOException) {
        fwrite(STDERR, 'SQLSTATE ' . $error->getCode() . '; database error ' . ($error->errorInfo[1] ?? 'unknown') . ".\n");
    }
    exit(1);
}
