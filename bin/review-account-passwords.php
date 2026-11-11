<?php

declare(strict_types=1);

// Read-only review of stored application-account password hashes.
//
// Purpose: confirm the existing accounts do not use shared or well-known
// default/development credentials, WITHOUT asking for or displaying any
// password and WITHOUT changing anything:
//   - only SELECT statements are issued (the configured SQL account is
//     DML-only anyway);
//   - no password is requested on the terminal, and no hash is printed;
//   - no rehash, reset, INSERT, UPDATE, DELETE or schema change is performed;
//   - no session is started and no diagnostic log entry is written.
//
// Candidate passwords below are generic well-known defaults and
// application-name/username-derived guesses. On a match only the CATEGORY is
// reported, never the matching string, so the script output never reveals a
// live password.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if (getenv('BIJHOUDEN_ENV') !== false || getenv('BIJHOUDEN_DB_NAME') !== false) {
    fwrite(STDERR, "Refusing to review with an environment override; review the normal accounts without overrides.\n");
    exit(1);
}
$overridePath = getenv('BIJHOUDEN_CONFIG');
if ($overridePath !== false && $overridePath !== '') {
    // Only a properly protected private configuration may redirect this review
    // (used for isolated sandbox validation); every other override is refused.
    $override = @lstat($overridePath);
    $euid = function_exists('posix_geteuid') ? posix_geteuid() : (int)shell_exec('id -u');
    if ($override === false || ($override['mode'] & 0o170000) !== 0o100000
            || ($override['mode'] & 0o077) !== 0 || $override['uid'] !== $euid || $override['nlink'] !== 1) {
        fwrite(STDERR, "Refusing an unprotected BIJHOUDEN_CONFIG override; review the normal accounts instead.\n");
        exit(1);
    }
}

define('BIJHOUDEN_SESSIONLESS', true);

require_once dirname(__DIR__) . '/db.php';

/**
 * @param list<string> $candidates
 */
function match_category(string $hash, string $username, array $candidates, string $category): ?string
{
    foreach ($candidates as $candidate) {
        if ($candidate === '') {
            continue;
        }
        if (password_verify($candidate, $hash)) {
            return $category;
        }
    }
    // Password-verify on the empty-string candidate separately: an account
    // provisioned with an empty password is unacceptable, but the empty string
    // is also a legitimate dummy-verification input, so it is its own category.
    return password_verify('', $hash) ? 'empty password' : null;
}

try {
    $expectedCost = app_config()['password_cost'];
    $connection = db();
    $connection->beginTransaction();

    $statement = $connection->query(
        'SELECT id, username, password_hash, role, auth_version, created_at, last_login_at '
        . 'FROM users ORDER BY id'
    );
    if ($statement === false) {
        throw new RuntimeException('The users table could not be read.');
    }
    $users = $statement->fetchAll();
    $connection->commit();

    if (!$users) {
        throw new RuntimeException('No application accounts exist; review provisioning before launch.');
    }

    $genericDefaults = [
        'admin', 'admin1', 'admin12', 'admin123', 'admin1234', 'administrator',
        'password', 'password1', 'password12', 'password123', 'password1234',
        'passwort', 'wachtwoord', 'wachtwoord1', 'wachtwoord123', 'welkom', 'welkom1',
        'welkom123', 'welkom01', 'welkom2024', 'welkom2025', 'welkom2026',
        'changeme', 'change-me', 'change-me-please', 'changeme123', 'default',
        'letmein', 'welcome', 'welcome1', 'welcome123', 'qwerty123', 'abc123!!',
        '12345678', '123456789', '1234567890', '123456789012', 'aaaaaaaaaaaa',
        'test', 'test1', 'test123', 'test1234', 'dev', 'developer', 'development',
        'devpassword', 'secret', 'secret123', 'example', 'sample', 'demo',
        'geheim', 'geheim1', 'geheim123', 'raspberry', 'raspberry123', 'hostname', 'hostname123',
        'bijhouden', 'bijhouden1', 'bijhouden12', 'bijhouden123', 'bijhouden1234',
        'bij-houden', 'Bijhouden', 'Bijhouden123', 'BIJHOUDEN', 'bijhouden!',
        'imker', 'imker1', 'imker123', 'bijen', 'bijen1', 'bijen123', 'bijenkast',
        'bijenkorf', 'honing', 'honing1', 'honing123', 'honey', 'honey123',
        'imkers', 'beekeeping', 'beekeeper', 'bees123', '2024', '2025', '2026',
    ];

    $problems = [];
    $seen = [];
    $lines = [];
    foreach ($users as $user) {
        $hash = (string)$user['password_hash'];
        $username = (string)$user['username'];
        $role = (string)$user['role'];
        $cost = (int)substr($hash, 4, 2);
        $notes = [];

        if (!in_array($role, ['admin', 'user'], true)) {
            $problems[] = "{$username}: unexpected account role '{$role}'.";
        }
        if ((int)$user['auth_version'] < 1) {
            $problems[] = "{$username}: unexpected session/auth version.";
        }
        if (!preg_match('/^\$2y\$\d{2}\$[A-Za-z0-9.\/]{53}$/', $hash)) {
            $problems[] = "{$username}: password hash is not a well-formed bcrypt hash.";
        } else {
            if ($cost !== $expectedCost) {
                $problems[] = "{$username}: bcrypt cost {$cost} differs from the configured cost {$expectedCost}.";
            }
            if (password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => $expectedCost])) {
                $problems[] = "{$username}: password hash needs rehashing (legacy cost/format).";
            }
            if ($hash === dummy_password_hash()) {
                $problems[] = "{$username}: password hash equals the unknown-user placeholder hash.";
            }
            if (isset($seen[$hash])) {
                $problems[] = "{$username}: password hash is identical to {$seen[$hash]} (shared credential).";
            } else {
                $seen[$hash] = $username;
            }
            $category = match_category($hash, $username, $genericDefaults, 'well-known default credential');
            if ($category === null) {
                $category = match_category(
                    $hash,
                    $username,
                    [$username, $username . '1', $username . '12', $username . '123', $username . '1234',
                        $username . '!', $username . '123!', $username . '2024', $username . '2025',
                        $username . '2026', $username . 'home'],
                    'username-derived credential'
                );
            }
            if ($category !== null) {
                $problems[] = "{$username}: password matches a {$category}.";
            }
            $notes[] = "bcrypt cost {$cost}, no default/development credential matched, not the unknown-user placeholder";
        }

        $lastLogin = $user['last_login_at'] === null ? 'never signed in' : 'last signed in ' . $user['last_login_at'];
        $lines[] = sprintf('%s (role %s, created %s, %s): %s', $username, $role, $user['created_at'], $lastLogin, implode('; ', $notes));
    }

    foreach ($lines as $line) {
        print $line . "\n";
    }

    if ($problems) {
        foreach ($problems as $problem) {
            fwrite(STDERR, 'REVIEW PROBLEM: ' . $problem . "\n");
        }
        throw new RuntimeException('Account password review found problems; resolve them before launch.');
    }

    print 'Reviewed ' . count($users) . " application account(s): read-only SELECTs only; no password requested, printed, changed or recorded.\n";
    print "Owner-directed post-launch password changes for both current users remain pending; this review does not waive them.\n";
    print "PASSWORD REVIEW PASSED — NOT CHANGED\n";
} catch (Throwable $error) {
    fwrite(STDERR, "Password review stopped: " . $error->getMessage() . "\n");
    fwrite(STDERR, "Nothing was changed. Do not share passwords in chat; change credentials via the application if needed.\n");
    exit(1);
}
