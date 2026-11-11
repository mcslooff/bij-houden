<?php

declare(strict_types=1);

ini_set('zend.exception_ignore_args', '1');

class ConfigurationException extends RuntimeException
{
}

function private_configuration_path(): string
{
    $configured = getenv('BIJHOUDEN_CONFIG');
    return $configured !== false && $configured !== ''
        ? $configured : dirname(__DIR__) . '/.bijhouden/database.json';
}

function app_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }
    $values = [];
    $path = private_configuration_path();
    if (is_file($path)) {
        $resolved = realpath($path);
        $public = realpath(__DIR__ . '/public');
        if ($resolved !== false && $public !== false && str_starts_with($resolved, $public . DIRECTORY_SEPARATOR)) {
            throw new ConfigurationException('Database configuration must be outside the public directory.');
        }
        try {
            $values = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $error) {
            throw new ConfigurationException('Private database configuration could not be read.', 0, $error);
        }
        if (!is_array($values)) {
            throw new ConfigurationException('Private database configuration must be a JSON object.');
        }
    } elseif (getenv('BIJHOUDEN_CONFIG') !== false) {
        throw new ConfigurationException('The configured private database file does not exist.');
    }
    $defaults = ['environment' => 'development', 'db_host' => 'localhost', 'db_port' => 3306, 'db_socket' => ''];
    foreach (['environment' => 'BIJHOUDEN_ENV', 'db_host' => 'BIJHOUDEN_DB_HOST', 'db_port' => 'BIJHOUDEN_DB_PORT',
        'db_socket' => 'BIJHOUDEN_DB_SOCKET', 'db_name' => 'BIJHOUDEN_DB_NAME', 'db_user' => 'BIJHOUDEN_DB_USER',
        'db_password' => 'BIJHOUDEN_DB_PASSWORD'] as $key => $variable) {
        $environment = getenv($variable);
        $value = $environment === false ? ($values[$key] ?? $defaults[$key] ?? null) : $environment;
        if (!is_string($value) && !is_int($value)) {
            throw new ConfigurationException('Missing or invalid database configuration field: ' . $key);
        }
        $values[$key] = (string)$value;
    }
    if (!in_array($values['environment'], ['development', 'test', 'production'], true)) {
        throw new ConfigurationException('Invalid application environment.');
    }
    if (!preg_match('/^[a-zA-Z0-9_-]+$/D', $values['db_name']) || $values['db_user'] === '') {
        throw new ConfigurationException('Database name and user must be configured.');
    }
    if ($values['db_password'] === '' && $values['environment'] !== 'test') {
        throw new ConfigurationException('A database password is required.');
    }
    if (!ctype_digit($values['db_port']) || (int)$values['db_port'] < 1 || (int)$values['db_port'] > 65535) {
        throw new ConfigurationException('Invalid database port.');
    }
    foreach (['db_host', 'db_socket'] as $key) {
        if (preg_match('/[;\x00-\x1f]/', $values[$key])) {
            throw new ConfigurationException('Invalid database connection address.');
        }
    }
    if ($values['db_host'] === '' && $values['db_socket'] === '') {
        throw new ConfigurationException('A database host or socket is required.');
    }
    $values['db_port'] = (int)$values['db_port'];
    $test = $values['environment'] === 'test';
    $values['session_cookie_name'] = $values['session_cookie_name'] ?? 'BIJHOUDENSESSID';
    if (!is_string($values['session_cookie_name']) || !preg_match('/^BIJHOUDEN[A-Z0-9_]{1,24}$/D', $values['session_cookie_name'])) {
        throw new ConfigurationException('Invalid session cookie name.');
    }
    foreach ([
        'session_idle_seconds' => ['BIJHOUDEN_SESSION_IDLE_SECONDS', 1800, $test ? 1 : 60, 604800],
        'session_absolute_seconds' => ['BIJHOUDEN_SESSION_ABSOLUTE_SECONDS', 28800, $test ? 1 : 300, 604800],
        'password_cost' => ['BIJHOUDEN_PASSWORD_COST', 12, $test ? 4 : 10, 14],
        'login_pair_limit' => ['BIJHOUDEN_LOGIN_PAIR_LIMIT', 5, $test ? 1 : 3, 100],
        'login_ip_limit' => ['BIJHOUDEN_LOGIN_IP_LIMIT', 50, $test ? 1 : 10, 1000],
        'login_window_seconds' => ['BIJHOUDEN_LOGIN_WINDOW_SECONDS', 600, $test ? 1 : 60, 3600],
        'image_max_dimension' => ['BIJHOUDEN_IMAGE_MAX_DIMENSION', 4096, 128, 8192],
        'image_max_pixels' => ['BIJHOUDEN_IMAGE_MAX_PIXELS', 4000000, 16384, 8000000],
        'image_storage_quota_bytes' => ['BIJHOUDEN_IMAGE_STORAGE_QUOTA_BYTES', 104857600, $test ? 1 : 4194304, 2147483647],
        'image_storage_max_files' => ['BIJHOUDEN_IMAGE_STORAGE_MAX_FILES', 2048, $test ? 1 : 10, 100000],
        'diagnostic_log_max_bytes' => ['BIJHOUDEN_DIAGNOSTIC_LOG_MAX_BYTES', 1048576, $test ? 256 : 65536, 104857600],
        'diagnostic_log_retention' => ['BIJHOUDEN_DIAGNOSTIC_LOG_RETENTION', 5, 1, 30],
    ] as $key => [$variable, $default, $minimum, $maximum]) {
        $environment = getenv($variable);
        $value = $environment === false ? ($values[$key] ?? $default) : $environment;
        if ((!is_int($value) && !is_string($value)) || !ctype_digit((string)$value)
            || (int)$value < $minimum || (int)$value > $maximum) {
            throw new ConfigurationException('Invalid security configuration field: ' . $key);
        }
        $values[$key] = (int)$value;
    }
    if ($values['session_idle_seconds'] > $values['session_absolute_seconds']) {
        throw new ConfigurationException('The idle session timeout must not exceed the absolute timeout.');
    }
    $imageEnvironment = getenv('BIJHOUDEN_IMAGE_STORAGE_PATH');
    $imagePath = $imageEnvironment === false ? ($values['image_storage_path'] ?? null) : $imageEnvironment;
    if ($test && ($imagePath === null || $imagePath === '')) {
        throw new ConfigurationException('Test mode requires an explicitly isolated image storage path.');
    }
    $values['image_storage_path'] = validated_private_directory($imagePath ?? __DIR__ . '/uploads');
    $normalImages = realpath(__DIR__ . '/uploads') ?: __DIR__ . '/uploads';
    if ($test && ($values['image_storage_path'] === $normalImages || str_starts_with($values['image_storage_path'], $normalImages . '/'))) {
        throw new ConfigurationException('Tests cannot use the normal application image directory.');
    }
    $logEnvironment = getenv('BIJHOUDEN_DIAGNOSTIC_LOG_DIRECTORY');
    $logParent = is_dir(dirname($path)) ? dirname($path) : __DIR__;
    $logDirectory = $logEnvironment === false ? ($values['diagnostic_log_directory'] ?? $logParent . ($test ? '/test-logs' : '/logs')) : $logEnvironment;
    $values['diagnostic_log_path'] = validated_private_directory($logDirectory) . '/errors.jsonl';
    return $config = $values;
}

function validated_private_directory(mixed $path): string
{
    if (!is_string($path) || !str_starts_with($path, '/') || preg_match('/[\x00-\x1f]/', $path)) {
        throw new ConfigurationException('Storage/log directories must be absolute private paths.');
    }
    $path = rtrim($path, '/');
    $resolved = realpath($path);
    if ($resolved === false) {
        $parent = realpath(dirname($path));
        if ($parent === false || in_array(basename($path), ['.', '..', ''], true)) {
            throw new ConfigurationException('Create the private parent directory before configuring storage/log paths.');
        }
        $resolved = $parent . '/' . basename($path);
    } elseif (!is_dir($resolved)) {
        throw new ConfigurationException('Storage/log paths must identify directories.');
    }
    $public = realpath(__DIR__ . '/public');
    if ($resolved === '/' || ($public !== false && ($resolved === $public || str_starts_with($resolved, $public . '/')))) {
        throw new ConfigurationException('Storage/log directories must be outside the public document root.');
    }
    return $resolved;
}

function app_storage_fingerprint(): string
{
    return hash('sha256', app_config()['image_storage_path']);
}

function app_database_fingerprint(): string
{
    $config = app_config();
    return hash('sha256', json_encode([$config['db_host'], $config['db_port'], $config['db_socket'], $config['db_name']], JSON_THROW_ON_ERROR));
}

function database_dsn(array $config): string
{
    $connection = $config['db_socket'] !== ''
        ? 'unix_socket=' . $config['db_socket']
        : 'host=' . $config['db_host'] . ';port=' . $config['db_port'];
    return 'mysql:' . $connection . ';dbname=' . $config['db_name'] . ';charset=utf8mb4';
}
