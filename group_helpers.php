<?php

require_once __DIR__ . '/db.php';

function group_logo_path(?string $path): ?string
{
    return managed_image_kind($path) === 'logo' ? $path : null;
}

function group_avatar(array $group, string $class = 'group-avatar'): string
{
    $path = group_logo_path($group['logo_path'] ?? null);
    if ($path !== null && managed_image_file($path) !== null) {
        return '<img class="' . e($class) . '" src="' . e($path) . '" alt="' . e($group['name'] . ' logo') . '">';
    }
    return '<span class="' . e($class) . ' group-avatar-placeholder" aria-hidden="true">'
        . e(mb_strtoupper(mb_substr($group['name'], 0, 1))) . '</span>';
}

function upload_group_logo(array $file): string
{
    return stage_image_upload($file, 'logo');
}

function delete_group_logo(?string $path): void
{
    cleanup_image($path);
}

function owner_label_token(int $groupId): string
{
    if (!is_admin() && user_group_id() !== $groupId) {
        throw new RuntimeException('You do not manage that group.');
    }
    static $tokens = [];
    if (isset($tokens[$groupId])) {
        return $tokens[$groupId];
    }
    $stmt = db()->prepare('SELECT owner_label_token FROM `groups` WHERE id = ?');
    $stmt->execute([$groupId]);
    $value = $stmt->fetch();
    if ($value === false) {
        throw new RuntimeException('Group not found.');
    }
    if ($value['owner_label_token'] === null) {
        $ownTransaction = !db()->inTransaction();
        begin_if_needed();
        try {
            $snapshot = current_user();
            $actor = db()->prepare('SELECT role, group_id, auth_version FROM users WHERE id = ? FOR UPDATE');
            $actor->execute([(int)($snapshot['id'] ?? 0)]);
            $user = $actor->fetch();
            if ($user === false || (int)$user['auth_version'] !== (int)$snapshot['auth_version']
                || ($user['role'] !== 'admin' && !same_group($groupId, $user['group_id']))) {
                throw new MutationDeniedException('You do not manage that group.');
            }
            $locked = db()->prepare('SELECT owner_label_token FROM `groups` WHERE id = ? FOR UPDATE');
            $locked->execute([$groupId]);
            $record = $locked->fetch();
            if ($record === false) throw new MutationConflictException('Group no longer exists.');
            $token = $record['owner_label_token'];
            if ($token === null) {
                $token = bin2hex(random_bytes(16));
                db()->prepare('UPDATE `groups` SET owner_label_token = ? WHERE id = ? AND owner_label_token IS NULL')->execute([$token, $groupId]);
                log_event((int)$snapshot['id'], 'owner_label_created', 'group #' . $groupId);
            }
            if ($ownTransaction) db()->commit();
            return $tokens[$groupId] = $token;
        } catch (Throwable $error) {
            if (db()->inTransaction()) db()->rollBack();
            throw $error;
        }
    }
    return $tokens[$groupId] = $value['owner_label_token'];
}

function owner_label_link(int $groupId): string
{
    return 'owner_label.php?token=' . rawurlencode(owner_label_token($groupId));
}

function owner_label_absolute_url(string $token): string
{
    $base = getenv('BIJHOUDEN_PUBLIC_URL');
    if ($base !== false && $base !== '') {
        $parts = parse_url($base);
        if ($parts === false || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('BIJHOUDEN_PUBLIC_URL must be an HTTP(S) base URL without query parameters.');
        }
    } else {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8080';
        if (!preg_match('/^(?:[a-z0-9.-]+|\[[a-f0-9:]+\])(?::[0-9]{1,5})?$/iD', $host)) {
            $host = 'localhost:8080';
        }
        $scheme = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
        $directory = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/owner_label.php'));
        $base = $scheme . '://' . $host . ($directory === '/' || $directory === '.' ? '' : $directory);
    }
    return rtrim($base, '/') . '/owner_label.php?token=' . rawurlencode($token);
}
