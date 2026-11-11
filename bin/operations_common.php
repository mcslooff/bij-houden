<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function operator_client_command(string $program): array
{
    $config = app_config();
    $command = [$program, '--no-defaults', '--user=root'];
    if ($config['db_socket'] !== '' || $config['db_host'] === 'localhost') {
        $socket = $config['db_socket'] !== '' ? $config['db_socket']
            : (ini_get('pdo_mysql.default_socket') ?: '/run/mysqld/mysqld.sock');
        return [...$command, '--protocol=SOCKET', '--socket=' . $socket];
    }
    return [...$command, '--protocol=TCP', '--host=' . $config['db_host'], '--port=' . $config['db_port']];
}

function inspect_database_dump(string $path): array
{
    $handle = fopen($path, 'rb');
    if ($handle === false) throw new RuntimeException('Database dump could not be opened.');
    $tables = [];
    $database = false;
    try {
        // Bounded reads even when extended INSERT statements are very large.
        while (($line = fgets($handle, 65536)) !== false) {
            if (str_starts_with($line, 'CREATE DATABASE')) $database = true;
            if (preg_match('/^CREATE TABLE `([^`]+)`/', $line, $match)) $tables[] = $match[1];
        }
    } finally {
        fclose($handle);
    }
    return ['has_database' => $database, 'tables' => $tables];
}
