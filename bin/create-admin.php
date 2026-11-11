<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/db.php';

$options = getopt('', ['username:', 'password-stdin', 'help']);
if (isset($options['help'])) {
    print "Usage: php bin/create-admin.php --username=NAME [--password-stdin]\n";
    print "Creates the first administrator only. Without --password-stdin, enter and confirm the password interactively.\n";
    exit;
}

function read_admin_password(string $prompt): string
{
    fwrite(STDOUT, $prompt);
    $state = shell_exec('stty -g');
    if ($state === null || $state === '') {
        throw new RuntimeException('Cannot disable terminal echo. Use --password-stdin instead.');
    }
    exec('stty -echo');
    try {
        $line = fgets(STDIN);
    } finally {
        exec('stty ' . escapeshellarg(trim($state)));
        fwrite(STDOUT, "\n");
    }
    if ($line === false) {
        throw new RuntimeException('No password was supplied.');
    }
    return rtrim($line, "\r\n");
}

$locked = false;
try {
    $username = $options['username'] ?? '';
    if (!is_string($username) || !preg_match('/^[a-zA-Z0-9_.-]{3,50}$/D', $username)) {
        throw new InvalidArgumentException('Supply --username with 3–50 letters, digits, dots, dashes or underscores.');
    }
    if (isset($options['password-stdin'])) {
        $line = fgets(STDIN, 1024);
        if ($line === false) {
            throw new InvalidArgumentException('No password was supplied on standard input.');
        }
        $password = rtrim($line, "\r\n");
    } else {
        if (!stream_isatty(STDIN)) {
            throw new InvalidArgumentException('Interactive terminal required; use --password-stdin for piped input.');
        }
        $password = read_admin_password('Administrator password: ');
        if ($password !== read_admin_password('Confirm password: ')) {
            throw new InvalidArgumentException('Passwords do not match.');
        }
    }
    if (new_password_error($password) !== null) {
        throw new InvalidArgumentException(new_password_error($password));
    }
    $connection = db();
    $lockName = 'bijhouden-bootstrap-' . substr(app_database_fingerprint(), 0, 32);
    $stmt = $connection->prepare('SELECT GET_LOCK(?, 10)');
    $stmt->execute([$lockName]);
    $locked = (int)$stmt->fetchColumn() === 1;
    if (!$locked) {
        throw new RuntimeException('Another administrator bootstrap is running; try again later.');
    }
    db()->beginTransaction();
    if ((int)db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() > 0) {
        throw new InvalidArgumentException('An administrator already exists. Use the authenticated Users page.');
    }
    $stmt = db()->prepare('SELECT id FROM users WHERE username = ?');
    $stmt->execute([$username]);
    if ($stmt->fetch() !== false) {
        throw new InvalidArgumentException('That username already exists. No account was changed.');
    }
    db()->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, 'admin')")
        ->execute([$username, hash_user_password($password)]);
    $id = (int)db()->lastInsertId();
    log_event($id, 'admin_bootstrapped', $username);
    db()->commit();
    print "First administrator created.\n";
} catch (Throwable $error) {
    if (isset($connection) && $connection->inTransaction()) {
        $connection->rollBack();
    }
    $message = $error instanceof InvalidArgumentException || $error instanceof ConfigurationException
        ? $error->getMessage() : 'Administrator provisioning failed. Check database access and schema installation.';
    fwrite(STDERR, $message . "\n");
    $failed = true;
} finally {
    if ($locked) {
        $stmt = $connection->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->execute([$lockName]);
    }
}
exit(isset($failed) ? 1 : 0);
