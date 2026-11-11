<?php

declare(strict_types=1);

require_once __DIR__ . '/configuration.php';
require_once __DIR__ . '/diagnostics.php';
require_once __DIR__ . '/image_storage.php';
require_once __DIR__ . '/authentication.php';
require_once __DIR__ . '/request_validation.php';
require_once __DIR__ . '/mutation_guard.php';
require_once __DIR__ . '/pagination.php';
require_once __DIR__ . '/time_policy.php';
require_once __DIR__ . '/maintenance.php';

ini_set('display_errors', '0');
error_reporting(E_ALL);

if (!defined('BIJHOUDEN_SESSIONLESS') || !BIJHOUDEN_SESSIONLESS) {
    auth_start_session();
} else {
    $_SESSION = [];
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        if (!is_resource($GLOBALS['application_lock'] ?? null)) {
            $GLOBALS['application_lock'] = application_maintenance_lock();
        }
        $config = app_config();
        $pdo = new PDO(
            database_dsn($config),
            $config['db_user'],
            $config['db_password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        $GLOBALS['db_connection'] = $pdo;
    }
    return $pdo;
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function current_user(): ?array
{
    return auth_current_user();
}

function user_role(): string
{
    return current_user()['role'] ?? '';
}

function user_group_id(): ?int
{
    $group = current_user()['group_id'] ?? null;
    return $group === null ? null : (int)$group;
}

function is_admin(): bool
{
    return user_role() === 'admin';
}

function require_admin(): void
{
    if (!is_admin()) {
        set_flash('Only administrators can access that page.', 'error');
        header('Location: index.php');
        exit;
    }
}

function require_login(): void
{
    if (current_user() === null) {
        header('Location: login.php');
        exit;
    }
}

function group_sql(string $alias = '', string $connector = ' WHERE '): string
{
    if (($alias !== '' && !preg_match('/^[a-z][a-z0-9_]*$/iD', $alias)) || !in_array($connector, [' WHERE ', ' AND '], true)) {
        throw new LogicException('Invalid SQL scope identifier or connector.');
    }
    if (is_admin()) {
        return '';
    }
    $col = ($alias !== '' ? $alias . '.' : '') . 'group_id';
    $gid = user_group_id();
    return $connector . ($gid === null ? '1 = 0' : $col . ' = ' . $gid);
}

function same_group(?int $left, mixed $right): bool
{
    return $left === ($right === null ? null : (int)$right);
}

function validate_entity_group(array $form, array &$errors): ?int
{
    if (!is_admin()) {
        $id = user_group_id();
        if ($id === null) {
            $errors[] = 'Ask an administrator to assign you to a group.';
        }
        return $id;
    }
    $value = input_id($form, 'group_id');
    if ($value === null) {
        return null;
    }
    $stmt = db()->prepare('SELECT id FROM `groups` WHERE id = ?');
    $stmt->execute([$value]);
    $id = $stmt->fetchColumn();
    if ($id === false) {
        $errors[] = 'Selected group does not exist.';
        return null;
    }
    return (int)$id;
}

function validate_related_group(string $table, int $id, ?int $groupId, array &$errors): void
{
    if (!in_array($table, ['apiaries', 'hives', 'queens'], true)) {
        throw new InvalidArgumentException('Invalid related entity.');
    }
    $stmt = db()->prepare("SELECT group_id FROM `$table` WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if ($row === false || !same_group($groupId, $row['group_id'])) {
        $errors[] = 'The selected ' . $table . ' record must belong to the same group.';
    }
}

function validate_group_change(string $table, int $id, ?int $groupId, array &$errors): void
{
    $queries = match ($table) {
        'apiaries' => ['SELECT group_id FROM hives WHERE apiary_id = ?'],
        'hives' => [
            'SELECT group_id FROM colonies WHERE hive_id = ?',
        ],
        'queens' => ['SELECT group_id FROM colonies WHERE queen_id = ?'],
        default => [],
    };
    foreach ($queries as $query) {
        $stmt = db()->prepare($query);
        $stmt->execute([$id]);
        foreach ($stmt->fetchAll() as $row) {
            if (!same_group($groupId, $row['group_id'])) {
                $errors[] = 'Group change blocked: linked records must stay in the same group. Reassign or unlink them first.';
                return;
            }
        }
    }
}

function valid_date(string $value, string $format): bool
{
    if (strlen($value) > 32 || str_contains($value, "\0") || !mb_check_encoding($value, 'UTF-8')
        || !preg_match('/^[1-9][0-9]{3}-/', $value)) {
        return false;
    }
    try {
        $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
        return $date !== false && $date->format($format) === $value;
    } catch (ValueError) {
        return false;
    }
}

function entity_is_active(string $table, int $id): bool
{
    if (!in_array($table, ['colonies', 'queens'], true)) {
        return false;
    }
    $stmt = db()->prepare("SELECT COUNT(*) FROM `$table` WHERE id = ? AND archived_at IS NULL");
    $stmt->execute([$id]);
    return (int)$stmt->fetchColumn() === 1;
}

function archive_entity(string $table, int $id): void
{
    if (!in_array($table, ['colonies', 'queens'], true) || !scoped_entity_exists($table, $id)) {
        throw new RuntimeException('You do not manage that record.');
    }
    begin_if_needed();
    try {
        if ($table === 'queens') {
            db()->prepare('UPDATE colonies SET queen_id = NULL WHERE queen_id = ? AND archived_at IS NULL AND group_id <=> ?')
                ->execute([$id, mutation_original_group($table, $id)]);
        }
        db()->prepare("UPDATE `$table` SET archived_timezone = ?, archived_at = ? WHERE id = ? AND group_id <=> ? AND archived_at IS NULL")
            ->execute(['Europe/Amsterdam', app_now()->format('Y-m-d H:i:s'), $id, mutation_original_group($table, $id)]);
        log_event((int)current_user()['id'], $table === 'colonies' ? 'colony_archived' : 'queen_archived', 'record #' . $id);
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
}

function scoped_entity_exists(string $table, int $id): bool
{
    $allowed = ['apiaries', 'hives', 'colonies', 'queens'];
    if (!in_array($table, $allowed, true)) {
        return false;
    }
    $sql = "SELECT COUNT(*) FROM `$table` WHERE id = ?";
    $params = [$id];
    if (!is_admin()) {
        $sql .= ' AND group_id = ?';
        $params[] = user_group_id();
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return ((int)$stmt->fetchColumn()) > 0;
}

function scoped_entity(string $table, int $id): array|false
{
    if (!in_array($table, ['apiaries', 'hives', 'colonies', 'queens'], true)) {
        throw new LogicException('Invalid entity table.');
    }
    $stmt = db()->prepare("SELECT * FROM `$table` WHERE id = ?" . group_sql('', ' AND '));
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function entity_group_id(?int $adminChoice = null): ?int
{
    if (is_admin()) {
        return $adminChoice;
    }
    return user_group_id();
}

function groups_for_dropdown(): array
{
    if (!is_admin()) {
        return [];
    }
    return db()->query('SELECT id, name FROM `groups` ORDER BY name')->fetchAll();
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    if (!is_string($_POST['csrf'] ?? null) || !hash_equals(csrf_token(), $_POST['csrf'])) {
        http_response_code(400);
        exit('Invalid CSRF token.');
    }
}

function set_flash(string $message, string $type = 'info'): void
{
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function take_flash(): ?array
{
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

function client_ip(): ?string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) ? inet_ntop(inet_pton($ip)) : null;
}

function log_event(?int $userId, string $event, string $detail = ''): void
{
    $stmt = db()->prepare(
        'INSERT INTO activity_log (user_id, event, detail, ip) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([
        $userId,
        $event,
        $detail !== '' ? mb_substr($detail, 0, 255) : null,
        client_ip(),
    ]);
}
