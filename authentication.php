<?php

declare(strict_types=1);

function auth_start_session(): void
{
    if (PHP_SAPI === 'cli') {
        $_SESSION = [];
        return;
    }
    $config = app_config();
    $https = in_array(strtolower((string)($_SERVER['HTTPS'] ?? '')), ['on', '1'], true);
    if ($config['environment'] === 'production' && !$https) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: no-store');
        exit('HTTPS is required.');
    }
    // Forwarded headers are deliberately not trusted until hosting verification.
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_cookies', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.gc_maxlifetime', (string)$config['session_absolute_seconds']);
    session_name($config['session_cookie_name']);
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'domain' => '', 'secure' => $https || $config['environment'] === 'production',
        'httponly' => true, 'samesite' => 'Lax',
    ]);
    if (!session_start()) {
        throw new RuntimeException('Session storage is unavailable.');
    }
}

function auth_clear_session(string $message): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    $_SESSION['flash'] = ['message' => $message, 'type' => 'info'];
}

function auth_current_epoch(): string
{
    // Never enroll or accept sessions using a shared fallback during outages.
    $row = db()->query('SELECT session_epoch FROM application_state WHERE id = 1')->fetch();
    $epoch = $row === false ? null : $row['session_epoch'];
    if (!is_string($epoch) || preg_match('/^[a-f0-9]{32}$/D', $epoch) !== 1) {
        throw new RuntimeException('Application session state is unavailable.');
    }
    return $epoch;
}

function auth_current_user(): ?array
{
    $sessionUser = $_SESSION['user'] ?? null;
    if ($sessionUser === null) {
        return null;
    }
    $started = $_SESSION['auth_started_at'] ?? null;
    $last = $_SESSION['auth_last_activity'] ?? null;
    $now = time();
    $config = app_config();
    if (!is_array($sessionUser) || !is_int($sessionUser['id'] ?? null) || !is_int($sessionUser['auth_version'] ?? null)
        || !is_int($started) || !is_int($last) || $started <= 0 || $last < $started || $last > $now
        || $now - $last >= $config['session_idle_seconds'] || $now - $started >= $config['session_absolute_seconds']) {
        auth_clear_session('Your session has expired. Please log in again.');
        return null;
    }
    // Sessions are bound to the application epoch, so a database restore
    // (which rotates the epoch) invalidates every outstanding session.
    $epoch = $_SESSION['db_epoch'] ?? null;
    if (!is_string($epoch) || !hash_equals(auth_current_epoch(), $epoch)) {
        log_event(null, 'session_epoch_mismatch', '');
        auth_clear_session('The system was updated or restored. Please log in again.');
        return null;
    }
    static $cacheKey = null;
    static $cachedUser = null;
    $key = $sessionUser['id'] . ':' . $sessionUser['auth_version'] . ':' . $started;
    if ($key !== $cacheKey) {
        $stmt = db()->prepare('SELECT id, username, role, group_id, auth_version FROM users WHERE id = ?');
        $stmt->execute([$sessionUser['id']]);
        $row = $stmt->fetch();
        if ($row === false || (int)$row['auth_version'] !== $sessionUser['auth_version']) {
            auth_clear_session('Your account changed. Please log in again.');
            return null;
        }
        $cacheKey = $key;
        $cachedUser = $row;
    }
    $_SESSION['auth_last_activity'] = $now;
    return $cachedUser;
}

function auth_establish_session(array $user, bool $freshLogin = true): void
{
    $started = $freshLogin ? time() : ($_SESSION['auth_started_at'] ?? time());
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (!session_regenerate_id(true)) {
            $_SESSION = [];
            throw new RuntimeException('Could not rotate the authentication session.');
        }
    }
    $_SESSION = [
        'user' => ['id' => (int)$user['id'], 'username' => $user['username'], 'auth_version' => (int)$user['auth_version']],
        'auth_started_at' => $started, 'auth_last_activity' => time(), 'csrf' => bin2hex(random_bytes(32)),
        'db_epoch' => auth_current_epoch(),
    ];
}

function auth_refresh_own_session(int $userId, int $expectedVersion): void
{
    if ((int)($_SESSION['user']['id'] ?? 0) !== $userId) {
        return;
    }
    $stmt = db()->prepare('SELECT id, username, auth_version FROM users WHERE id = ? AND auth_version = ?');
    $stmt->execute([$userId, $expectedVersion]);
    $user = $stmt->fetch();
    if ($user !== false) {
        auth_establish_session($user, false);
    } else {
        auth_clear_session('Your account changed. Please log in again.');
    }
}

function new_password_error(mixed $password): ?string
{
    if (!is_string($password) || str_contains($password, "\0") || !mb_check_encoding($password, 'UTF-8')
        || mb_strlen($password, 'UTF-8') < 12 || strlen($password) > 72) {
        return 'Use at least 12 characters and at most 72 UTF-8 bytes, without NUL characters.';
    }
    return null;
}

function hash_user_password(string $password): string
{
    if (new_password_error($password) !== null) {
        throw new InvalidArgumentException(new_password_error($password));
    }
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => app_config()['password_cost']]);
}

function dummy_password_hash(): string
{
    return sprintf('$2y$%02d$', app_config()['password_cost']) . 'Es3G5mZmHp6YGCcOtC9wHOHjpbiY6tvk4/3chUXxntTL1qE7DfUsu';
}

function verify_user_password(mixed $password, ?string $hash): bool
{
    $valid = is_string($password) && $password !== '' && strlen($password) <= 72 && !str_contains($password, "\0");
    $verified = password_verify($valid ? $password : 'invalid-password-input', $valid && $hash !== null ? $hash : dummy_password_hash());
    return $valid && $hash !== null && $verified;
}

function reserve_login_attempt(string $account, ?string $ip): array
{
    $config = app_config();
    $now = time();
    $window = $config['login_window_seconds'];
    $address = $ip !== null && filter_var($ip, FILTER_VALIDATE_IP) ? bin2hex(inet_pton($ip)) : 'unknown';
    $pairKey = hash('sha256', 'pair|' . $account . '|' . $address);
    // Always lock/check the IP bucket first, before allocating a new pair bucket.
    $limits = [hash('sha256', 'ip|' . $address) => $config['login_ip_limit'], $pairKey => $config['login_pair_limit']];
    db()->prepare('DELETE FROM auth_login_limits WHERE expires_at <= ? ORDER BY expires_at LIMIT 100')->execute([$now]);
    db()->beginTransaction();
    try {
        $retry = 0;
        foreach ($limits as $key => $limit) {
            db()->prepare('INSERT INTO auth_login_limits (rate_key, window_started, expires_at, attempts)
                VALUES (?, ?, ?, 0) ON DUPLICATE KEY UPDATE rate_key = VALUES(rate_key)')->execute([$key, $now, $now + $window]);
            $stmt = db()->prepare('SELECT attempts, expires_at FROM auth_login_limits WHERE rate_key = ? FOR UPDATE');
            $stmt->execute([$key]);
            $row = $stmt->fetch();
            if ((int)$row['expires_at'] <= $now) {
                db()->prepare('UPDATE auth_login_limits SET window_started = ?, expires_at = ?, attempts = 0 WHERE rate_key = ?')
                    ->execute([$now, $now + $window, $key]);
            } elseif ((int)$row['attempts'] >= $limit) {
                $retry = max($retry, (int)$row['expires_at'] - $now);
                break;
            }
        }
        if ($retry === 0) {
            foreach ($limits as $key => $limit) {
                db()->prepare('UPDATE auth_login_limits SET attempts = attempts + 1 WHERE rate_key = ?')->execute([$key]);
            }
        }
        db()->commit();
        return ['allowed' => $retry === 0, 'retry_after' => $retry, 'pair_key' => $pairKey];
    } catch (Throwable $error) {
        db()->rollBack();
        throw $error;
    }
}

function attempt_login(mixed $username, mixed $password): array
{
    $username = is_string($username) ? trim($username) : '';
    $validUsername = preg_match('/^[a-zA-Z0-9_.-]{3,50}$/D', $username) === 1;
    $user = false;
    if ($validUsername) {
        $stmt = db()->prepare('SELECT id, username, password_hash, auth_version FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $user = $stmt->fetch();
    }
    $account = $user !== false ? 'user:' . $user['id'] : 'unknown:' . ($validUsername ? strtolower($username) : 'invalid');
    $attempt = reserve_login_attempt($account, client_ip());
    if (!$attempt['allowed']) {
        return ['success' => false, 'retry_after' => $attempt['retry_after'], 'error' => 'Too many login attempts. Please try again later.'];
    }
    $verified = verify_user_password($password, $user === false ? null : $user['password_hash']);
    if (!$verified) {
        log_event(null, 'login_failed', $validUsername ? $username : '(invalid username)');
        return ['success' => false, 'retry_after' => 0, 'error' => 'Invalid username or password.'];
    }
    db()->beginTransaction();
    try {
        $stmt = db()->prepare('SELECT id, username, password_hash, auth_version FROM users WHERE id = ? FOR UPDATE');
        $stmt->execute([(int)$user['id']]);
        $fresh = $stmt->fetch();
        if ($fresh === false || !hash_equals($user['password_hash'], $fresh['password_hash'])
            || (int)$fresh['auth_version'] !== (int)$user['auth_version']) {
            db()->rollBack();
            return ['success' => false, 'retry_after' => 0, 'error' => 'Invalid username or password.'];
        }
        if (password_needs_rehash($fresh['password_hash'], PASSWORD_BCRYPT, ['cost' => app_config()['password_cost']])) {
            // Rehash legacy valid passwords without imposing the new-password minimum.
            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => app_config()['password_cost']]);
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, (int)$user['id']]);
        }
        db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([(int)$user['id']]);
        db()->prepare('DELETE FROM auth_login_limits WHERE rate_key = ?')->execute([$attempt['pair_key']]);
        log_event((int)$user['id'], 'login_success', $fresh['username']);
        db()->commit();
        auth_establish_session($fresh);
        return ['success' => true, 'retry_after' => 0, 'error' => ''];
    } catch (Throwable $error) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $error;
    }
}
