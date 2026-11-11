<?php

declare(strict_types=1);

class RequestValidationException extends InvalidArgumentException
{
}

class RequestTooLargeException extends RequestValidationException
{
}

function input_text(array $source, string $key, int $maximum = 65535, bool $required = false): string
{
    $value = $source[$key] ?? '';
    if (!is_string($value) || str_contains($value, "\0") || !mb_check_encoding($value, 'UTF-8')) {
        throw new RequestValidationException('Invalid text field: ' . $key . '.');
    }
    $value = trim($value);
    if (mb_strlen($value, 'UTF-8') > $maximum || ($required && $value === '')) {
        throw new RequestValidationException('Invalid length for field: ' . $key . '.');
    }
    return $value;
}

function input_id(array $source, string $key, bool $optional = true): ?int
{
    if (!array_key_exists($key, $source) || $source[$key] === '') {
        if ($optional) {
            return null;
        }
        throw new RequestValidationException('A valid ID is required: ' . $key . '.');
    }
    $value = $source[$key];
    if ((!is_string($value) && !is_int($value)) || !preg_match('/^[1-9][0-9]{0,9}$/D', (string)$value)
        || (int)$value > 4294967295) {
        throw new RequestValidationException('Invalid ID field: ' . $key . '.');
    }
    return (int)$value;
}

function valid_choice(mixed $value, array $options): bool
{
    return is_string($value) && array_key_exists($value, $options);
}

function input_checked(array $source, string $key): bool
{
    return ($source[$key] ?? '0') === '1';
}

function validate_page_request(string $page): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 8388608) {
        throw new RequestTooLargeException('Request is too large. The maximum request size is 8 MB.');
    }
    foreach ($_GET as $key => $value) {
        if (!is_string($key) || !is_string($value) || str_contains($value, "\0") || !mb_check_encoding($value, 'UTF-8') || strlen($value) > 4096) {
            throw new RequestValidationException('Invalid query parameter.');
        }
    }
    foreach (['edit', 'id', 'colony', 'group_id', 'page'] as $key) {
        if (array_key_exists($key, $_GET)) {
            input_id($_GET, $key, $key === 'colony');
        }
    }
    if (isset($_GET['archived']) && !in_array($_GET['archived'], ['', '1'], true)) {
        throw new RequestValidationException('Invalid archive selection.');
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    // Login intentionally returns the same generic failure for malformed credentials.
    if ($page === 'login.php') {
        return;
    }
    $passwords = ['password', 'confirm', 'current_password', 'new_password', 'confirm_password'];
    foreach ($_POST as $key => $value) {
        if (!is_string($key) || !is_string($value)) {
            throw new RequestValidationException('Form fields must be scalar strings.');
        }
        if (!in_array($key, $passwords, true)) {
            input_text($_POST, $key);
            if (strlen($value) > 65535) {
                throw new RequestValidationException('Invalid form field: ' . $key . '.');
            }
        }
    }
    $actions = match ($page) {
        'apiaries.php', 'hives.php', 'groups.php', 'users.php' => ['add', 'update', 'delete'],
        'colonies.php', 'queens.php' => ['add', 'update', 'archive'],
        'tasks.php' => ['add', 'update', 'delete', 'complete', 'reopen'],
        'inspections.php' => ['add', 'update', 'delete'],
        'profile.php' => ['identity', 'photo', 'password'],
        default => [],
    };
    if ($actions !== [] && !in_array($_POST['action'] ?? '', $actions, true)) {
        throw new RequestValidationException('Invalid form action.');
    }
    foreach (['id', 'group_id', 'apiary_id', 'hive_id', 'queen_id', 'colony_id', 'assigned_to'] as $key) {
        if (array_key_exists($key, $_POST)) {
            input_id($_POST, $key, $key !== 'id');
        }
    }
    foreach (['date_of_birth', 'hatching_date', 'target_date'] as $key) {
        if (isset($_POST[$key]) && $_POST[$key] !== '' && !preg_match('/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}$/D', $_POST[$key])) {
            throw new RequestValidationException('Invalid date format: ' . $key . '.');
        }
    }
    if (in_array($_POST['action'] ?? '', ['update', 'delete', 'archive', 'complete', 'reopen'], true)) {
        input_id($_POST, 'id', false);
    }
    if ($page === 'inspections.php') {
        input_id($_POST, 'colony_id', false);
    }
    foreach (['queen_seen', 'queen_cells', 'swarmed', 'eggs', 'capped_brood', 'uncapped_brood', 'excessive_drones', 'remove_logo', 'remove_photo'] as $key) {
        if (isset($_POST[$key]) && !in_array($_POST[$key], ['0', '1'], true)) {
            throw new RequestValidationException('Invalid checkbox field: ' . $key . '.');
        }
    }
    foreach ($_FILES as $key => $file) {
        $allowed = ($page === 'groups.php' && $key === 'logo') || ($page === 'profile.php' && $key === 'photo');
        if (!$allowed || !is_array($file) || !is_int($file['error'] ?? null) || !is_int($file['size'] ?? null)
            || !is_string($file['tmp_name'] ?? null) || !is_string($file['name'] ?? null)) {
            throw new RequestValidationException('Invalid image upload structure.');
        }
    }
}
