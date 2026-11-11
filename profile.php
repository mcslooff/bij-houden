<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/layout.php';

require_login();

$user = current_user();
$errors = [];

function move_photo(int $userId, array $file): array
{
    try {
        return [true, stage_image_upload($file, 'avatar', $userId)];
    } catch (InvalidArgumentException $error) {
        return [false, $error->getMessage()];
    } catch (Throwable $error) {
        report_error($error, 'avatar_upload');
        return [false, 'The photo could not be processed or stored. Please try again.'];
    }
}

function delete_photo_file(?string $path): void
{
    cleanup_image($path);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'identity') {
        $firstName = trim((string)($_POST['first_name'] ?? ''));
        $lastName = trim((string)($_POST['last_name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));

        if ($firstName === '' || $lastName === '') {
            $errors[] = 'First name and last name are required.';
        }
        if (mb_strlen($firstName) > 80 || mb_strlen($lastName) > 80) {
            $errors[] = 'Names are limited to 80 characters.';
        }
        if ($email === '' || strlen($email) > 200 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid e-mail address is required.';
        }

        if ($errors === []) {
            $dup = db()->prepare('SELECT id FROM users WHERE email = ? AND id <> ?');
            $dup->execute([$email, (int)$user['id']]);
            if ($dup->fetch() !== false) {
                $errors[] = 'That e-mail address is already in use.';
            }
        }

        if ($errors === []) {
            db()->beginTransaction();
            try {
                $stmt = db()->prepare('SELECT email, auth_version FROM users WHERE id = ? FOR UPDATE');
                $stmt->execute([(int)$user['id']]);
                $existing = $stmt->fetch();
                if ($existing === false || (int)$existing['auth_version'] !== (int)$user['auth_version']) {
                    throw new InvalidArgumentException('Your account changed. Please reload and log in again.');
                }
                $emailChanged = $existing['email'] !== $email;
                db()->prepare('UPDATE users SET first_name = ?, last_name = ?, email = ?, auth_version = auth_version + ? WHERE id = ? AND auth_version = ?')
                    ->execute([$firstName, $lastName, $email, (int)$emailChanged, (int)$user['id'], (int)$existing['auth_version']]);
                log_event((int)$user['id'], 'profile_updated', $user['username']);
                if ($emailChanged) {
                    log_event((int)$user['id'], 'sessions_revoked', 'E-mail changed');
                }
                db()->commit();
                if ($emailChanged) {
                    auth_refresh_own_session((int)$user['id'], (int)$existing['auth_version'] + 1);
                }
                set_flash('Profile updated.' . ($emailChanged ? ' Other sessions have been signed out.' : ''));
                header('Location: profile.php');
                exit;
            } catch (Throwable $error) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                if ($error instanceof InvalidArgumentException) {
                    $errors[] = $error->getMessage();
                } elseif ($error instanceof PDOException && (int)($error->errorInfo[1] ?? 0) === 1062) {
                    $errors[] = 'That e-mail address is already in use.';
                } else {
                    report_error($error, 'profile_identity');
                    $errors[] = 'Your profile could not be updated. Please try again.';
                }
            }
        }
    }

    if ($action === 'photo' && $errors === []) {
        $remove = input_checked($_POST, 'remove_photo');
        $current = user_profile();

        if ($remove) {
            if ($current['photo_path'] !== null) {
                image_enqueue_cleanup($current['photo_path']);
                db()->prepare('UPDATE users SET photo_path = NULL WHERE id = ? AND auth_version = ?')
                    ->execute([(int)$user['id'], (int)$user['auth_version']]);
                log_event((int)$user['id'], 'photo_removed', $user['username']);
                db()->commit();
                delete_photo_file($current['photo_path']);
                set_flash('Photo removed.', 'info');
            }
            header('Location: profile.php');
            exit;
        }

        if (isset($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
            [$ok, $result] = move_photo((int)$user['id'], $_FILES['photo']);
            if (!$ok) {
                $errors[] = $result;
            } else {
                try {
                    image_enqueue_cleanup($current['photo_path']);
                    db()->prepare('UPDATE users SET photo_path = ? WHERE id = ? AND auth_version = ?')
                        ->execute([$result, (int)$user['id'], (int)$user['auth_version']]);
                    log_event((int)$user['id'], 'photo_uploaded', $user['username']);
                    db()->commit();
                } catch (Throwable $error) {
                    if (db()->inTransaction()) db()->rollBack();
                    delete_photo_file($result);
                    throw $error;
                }
                delete_photo_file($current['photo_path']);
                set_flash('Photo updated.', 'info');
            }
        }

        if ($errors === []) {
            header('Location: profile.php');
            exit;
        }
    }

    if ($action === 'password' && $errors === []) {
        $currentPw = $_POST['current_password'] ?? null;
        $newPw = $_POST['new_password'] ?? null;
        $confirm = $_POST['confirm_password'] ?? null;
        if (new_password_error($newPw) !== null) {
            $errors[] = new_password_error($newPw);
        }
        if ($newPw !== $confirm) {
            $errors[] = 'Passwords do not match.';
        }
        if (is_string($currentPw) && $currentPw !== '' && $currentPw === $newPw) {
            $errors[] = 'The new password must be different from the current one.';
        }

        if ($errors === []) {
            $attempt = reserve_login_attempt('password-change:user:' . $user['id'], client_ip());
            if (!$attempt['allowed']) {
                http_response_code(429);
                header('Retry-After: ' . $attempt['retry_after']);
                $errors[] = 'Too many password-change attempts. Please try again later.';
            } else {
                db()->beginTransaction();
                try {
                    $stmt = db()->prepare('SELECT password_hash, auth_version FROM users WHERE id = ? FOR UPDATE');
                    $stmt->execute([(int)$user['id']]);
                    $existing = $stmt->fetch();
                    if ($existing === false || (int)$existing['auth_version'] !== (int)$user['auth_version']) {
                        throw new InvalidArgumentException('Your account changed. Please reload and log in again.');
                    }
                    if (!verify_user_password($currentPw, $existing['password_hash'])) {
                        throw new InvalidArgumentException('Current password is incorrect.');
                    }
                    db()->prepare('UPDATE users SET password_hash = ?, auth_version = auth_version + 1 WHERE id = ? AND auth_version = ?')
                        ->execute([hash_user_password($newPw), (int)$user['id'], (int)$existing['auth_version']]);
                    db()->prepare('DELETE FROM auth_login_limits WHERE rate_key = ?')->execute([$attempt['pair_key']]);
                    log_event((int)$user['id'], 'password_changed', $user['username']);
                    log_event((int)$user['id'], 'sessions_revoked', 'Password changed');
                    db()->commit();
                    auth_refresh_own_session((int)$user['id'], (int)$existing['auth_version'] + 1);
                    set_flash('Password changed. Other sessions have been signed out.');
                    header('Location: profile.php');
                    exit;
                } catch (Throwable $error) {
                    if (db()->inTransaction()) {
                        db()->rollBack();
                    }
                    if ($error instanceof InvalidArgumentException) {
                        $errors[] = $error->getMessage();
                    } else {
                        report_error($error, 'profile_password');
                        $errors[] = 'Your password could not be changed. Please try again.';
                    }
                }
            }
        }
    }
}

$profile = user_profile();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'identity') {
    $profile['first_name'] = $firstName;
    $profile['last_name'] = $lastName;
    $profile['email'] = $email;
}
release_failed_mutation($errors);
layout_start('Profile');
?>
    <?php if ($errors !== []): ?>
        <div class="flash error"><?php foreach ($errors as $err): ?><?= e($err) ?><br><?php endforeach; ?></div>
    <?php endif; ?>

    <div class="card">
        <h1>My profile</h1>
        <p><?= avatar_tag('avatar-large') ?></p>
        <p><strong><?= e($profile['username']) ?></strong>
        <span class="badge"><?= e(user_role()) ?></span>
        <?= user_group_id() !== null ? '<span class="meta">in group #' . (int)user_group_id() . '</span>' : '<span class="meta">no group (admin)</span>' ?>
        </p>
    </div>

    <div class="card">
        <h2>Identity</h2>
        <form method="post" action="profile.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="identity">
            <label for="first_name">First name</label>
            <input type="text" id="first_name" name="first_name" maxlength="80" required
                   value="<?= e($profile['first_name'] ?? '') ?>">
            <label for="last_name">Last name</label>
            <input type="text" id="last_name" name="last_name" maxlength="80" required
                   value="<?= e($profile['last_name'] ?? '') ?>">
            <label for="email">E-mail address</label>
            <input type="email" id="email" name="email" required maxlength="200"
                   value="<?= e($profile['email'] ?? '') ?>">
            <button type="submit">Save identity</button>
        </form>
    </div>

    <div class="card">
        <h2>Photo (max 2 MB &mdash; JPEG, PNG, WebP, GIF)</h2>
        <form method="post" action="profile.php" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="photo">
            <label for="photo">New photo</label>
            <input type="file" id="photo" name="photo" accept="image/jpeg,image/png,image/webp,image/gif">
            <?php if ($profile['photo_path'] !== null && $profile['photo_path'] !== ''): ?>
                <label>
                    <input type="checkbox" name="remove_photo" value="1">
                    Remove current photo
                </label>
            <?php endif; ?>
            <button type="submit">Save photo</button>
        </form>
    </div>

    <div class="card">
        <h2>Change password</h2>
        <form method="post" action="profile.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="password">
            <label for="current_password">Current password</label>
            <input type="password" id="current_password" name="current_password" maxlength="72" autocomplete="current-password" required>
            <label for="new_password">New password (at least 12 characters, up to 72 UTF-8 bytes)</label>
            <input type="password" id="new_password" name="new_password" minlength="12" maxlength="72" autocomplete="new-password" required>
            <label for="confirm_password">Confirm new password</label>
            <input type="password" id="confirm_password" name="confirm_password" maxlength="72" autocomplete="new-password" required>
            <button type="submit">Change password</button>
        </form>
    </div>
<?php
layout_end();
