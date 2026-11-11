<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/layout.php';

require_login();
require_admin();

$me = current_user();
$formErrors = [];
$form = ['username' => '', 'password' => '', 'confirm' => '', 'role' => 'user', 'group_id' => ''];
$editing = null;
$groups = groups_for_dropdown();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'update') {
        $form['username'] = trim((string)($_POST['username'] ?? ''));
        $form['password'] = $_POST['password'] ?? '';
        $form['confirm'] = $_POST['confirm'] ?? '';
        $form['role'] = (string)($_POST['role'] ?? 'user');
        $form['group_id'] = trim((string)($_POST['group_id'] ?? ''));

        if (!valid_choice($form['role'], ['admin' => 'Administrator', 'user' => 'User'])) {
            $formErrors[] = 'Invalid role.';
        }

        $groupId = null;
        if ($form['group_id'] !== '') {
            $sel = db()->prepare('SELECT id FROM `groups` WHERE id = ?');
            $sel->execute([(int)$form['group_id']]);
            if ($sel->fetch() === false) {
                $formErrors[] = 'Selected group does not exist.';
            } else {
                $groupId = (int)$form['group_id'];
            }
        }

        if ($action === 'add') {
            if (!preg_match('/^[a-zA-Z0-9_.-]{3,50}$/', $form['username'])) {
                $formErrors[] = 'Username must be 3-50 characters (letters, digits, dot, dash, underscore).';
            }
            if (new_password_error($form['password']) !== null) {
                $formErrors[] = new_password_error($form['password']);
            }
            if ($form['password'] !== $form['confirm']) {
                $formErrors[] = 'Passwords do not match.';
            }
        }

        if ($action === 'add' || $action === 'update') {
            if ($form['role'] === 'user' && $groupId === null) {
                $formErrors[] = 'Non-admin users must be a member of a group.';
            }
            if ($action === 'update') {
                $id = (int)($_POST['id'] ?? 0);
                if ($id === (int)$me['id'] && $form['role'] !== 'admin') {
                    $formErrors[] = 'You cannot remove your own admin rights.';
                }
            }
        }

        if ($action === 'update' && $form['password'] !== '') {
            if (new_password_error($form['password']) !== null) {
                $formErrors[] = new_password_error($form['password']);
            }
            if ($form['password'] !== $form['confirm']) {
                $formErrors[] = 'Passwords do not match.';
            }
        }

        if ($formErrors === []) {
            if ($action === 'add') {
                $dup = db()->prepare('SELECT id FROM users WHERE username = ?');
                $dup->execute([$form['username']]);

                if ($dup->fetch() !== false) {
                    $formErrors[] = 'That username is already taken.';
                } else {
                    db()->prepare('INSERT INTO users (username, password_hash, role, group_id) VALUES (?, ?, ?, ?)')
                        ->execute([$form['username'], hash_user_password($form['password']), $form['role'], $groupId]);
                    log_event((int)$me['id'], 'user_added', $form['username']);
                    set_flash('User added.', 'info');
                    header('Location: users.php');
                    exit;
                }
            } else {
                $id = (int)($_POST['id'] ?? 0);
                $sel = db()->prepare('SELECT id FROM users WHERE id = ?');
                $sel->execute([$id]);

                if ($sel->fetch() === false) {
                    set_flash('That user no longer exists.', 'error');
                } else {
                    begin_if_needed();
                    try {
                        $sel = db()->prepare('SELECT role, group_id, auth_version FROM users WHERE id = ? FOR UPDATE');
                        $sel->execute([$id]);
                        $existing = $sel->fetch();
                        if ($existing === false) {
                            throw new RuntimeException('User disappeared during update.');
                        }
                        $securityChanged = $form['password'] !== '' || $existing['role'] !== $form['role']
                            || !same_group($groupId, $existing['group_id']);
                        if ($form['password'] !== '') {
                            db()->prepare('UPDATE users SET role = ?, group_id = ?, password_hash = ?, auth_version = auth_version + ? WHERE id = ? AND auth_version = ? AND group_id <=> ?')
                                ->execute([$form['role'], $groupId, hash_user_password($form['password']), (int)$securityChanged, $id, (int)$existing['auth_version'], $existing['group_id']]);
                        } else {
                            db()->prepare('UPDATE users SET role = ?, group_id = ?, auth_version = auth_version + ? WHERE id = ? AND auth_version = ? AND group_id <=> ?')
                                ->execute([$form['role'], $groupId, (int)$securityChanged, $id, (int)$existing['auth_version'], $existing['group_id']]);
                        }
                        $unassign = db()->prepare('UPDATE tasks SET assigned_to = NULL WHERE assigned_to = ? AND NOT (group_id <=> ?)');
                        $unassign->execute([$id, $groupId]);
                        if ($unassign->rowCount() > 0) {
                            log_event((int)$me['id'], 'tasks_unassigned', 'user #' . $id . ' left the task group');
                        }
                        log_event((int)$me['id'], 'user_updated', $form['username'] !== '' ? $form['username'] : ('user #' . $id));
                        if ($securityChanged) {
                            log_event((int)$me['id'], 'sessions_revoked', 'user #' . $id);
                        }
                        db()->commit();
                        if ($securityChanged) {
                            auth_refresh_own_session($id, (int)$existing['auth_version'] + 1);
                        }
                        set_flash('User updated. Tasks belonging to a previous group have been unassigned.');
                        header('Location: users.php');
                        exit;
                    } catch (Throwable $error) {
                        if (db()->inTransaction()) {
                            db()->rollBack();
                        }
                        report_error($error, 'user_update');
                        $formErrors[] = 'The user could not be updated. Please try again.';
                    }
                }
            }
        }

        if ($action === 'update') {
            $editing = ['id' => (int)($_POST['id'] ?? 0)];
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $sel = db()->prepare('SELECT username, role FROM users WHERE id = ?');
        $sel->execute([$id]);
        $row = $sel->fetch();

        if ($row === false) {
            set_flash('That user does not exist.', 'error');
        } elseif ($id === (int)$me['id']) {
            set_flash('You cannot delete your own account.', 'error');
        } elseif ($row['role'] === 'admin') {
            $admins = db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
            if ((int)$admins <= 1) {
                set_flash('Cannot delete the last administrator.', 'error');
            } else {
                $photo = db()->prepare('SELECT photo_path FROM users WHERE id = ?');
                $photo->execute([$id]);
                image_enqueue_cleanup($photo->fetchColumn() ?: null);
                db()->prepare('DELETE FROM users WHERE id = ? AND auth_version = ? AND group_id <=> ?')
                    ->execute([$id, (int)$GLOBALS['mutation_context']['users'][$id]['auth_version'], $GLOBALS['mutation_context']['users'][$id]['group_id']]);
                log_event((int)$me['id'], 'user_deleted', $row['username']);
                set_flash('User removed.', 'warning');
            }
        } else {
            $photo = db()->prepare('SELECT photo_path FROM users WHERE id = ?');
            $photo->execute([$id]);
            image_enqueue_cleanup($photo->fetchColumn() ?: null);
            db()->prepare('DELETE FROM users WHERE id = ? AND auth_version = ? AND group_id <=> ?')
                ->execute([$id, (int)$GLOBALS['mutation_context']['users'][$id]['auth_version'], $GLOBALS['mutation_context']['users'][$id]['group_id']]);
            log_event((int)$me['id'], 'user_deleted', $row['username']);
            set_flash('User removed.', 'warning');
        }
        header('Location: users.php');
        exit;
    }
}

$editId = (int)($_GET['edit'] ?? 0);
if ($editId > 0 && $editing === null) {
    $sel = db()->prepare('SELECT * FROM users WHERE id = ?');
    $sel->execute([$editId]);
    $row = $sel->fetch();

    if ($row === false) {
        set_flash('That user no longer exists.', 'error');
    } else {
        $editing = $row;
        $form = [
            'username' => $row['username'],
            'password' => '',
            'confirm' => '',
            'role' => $row['role'],
            'group_id' => $row['group_id'] !== null ? (string)$row['group_id'] : '',
        ];
    }
}

$userPage = list_page_query(
    "SELECT u.id, u.username, u.role, u.group_id, u.created_at, u.last_login_at,
            u.first_name, u.last_name, u.email, g.name AS group_name
     FROM users u LEFT JOIN `groups` g ON g.id = u.group_id",
    "ORDER BY u.role = 'admin' DESC, u.username, u.id"
);
$users = $userPage['rows'];

release_failed_mutation($formErrors);
layout_start('Users', 'users');
?>
    <?php if ($formErrors !== []): ?>
        <div class="flash error"><?php foreach ($formErrors as $err): ?><?= e($err) ?><br><?php endforeach; ?></div>
    <?php endif; ?>

    <div class="card">
        <h1><?= $editing !== null ? 'Edit user: ' . e($form['username']) : 'Add a user' ?></h1>
        <form method="post" action="users.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= $editing !== null ? 'update' : 'add' ?>">
            <?php if ($editing !== null): ?>
                <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
                <?= edit_revision_field('users', $editing) ?>
            <?php endif; ?>
            <label for="username">Username<?= $editing !== null ? ' (fixed)' : '' ?></label>
            <input type="text" id="username" name="username" maxlength="50" required
                   <?= $editing !== null ? 'disabled' : '' ?>
                   value="<?= e($form['username']) ?>">
            <?php if ($editing !== null): ?>
                <input type="hidden" name="username" value="<?= e($form['username']) ?>">
            <?php endif; ?>
            <label for="role">Role</label>
            <select id="role" name="role">
                <option value="user"<?= $form['role'] === 'user' ? ' selected' : '' ?>>User (manages own group only)</option>
                <option value="admin"<?= $form['role'] === 'admin' ? ' selected' : '' ?>>Administrator</option>
            </select>
            <label for="group_id">Group<?= $form['role'] !== 'admin' ? ' (required for users)' : ' (optional for admins)' ?></label>
            <select id="group_id" name="group_id">
                <option value=""<?= $form['group_id'] === '' ? ' selected' : '' ?>>No group</option>
                <?php foreach ($groups as $group): ?>
                    <option value="<?= (int)$group['id'] ?>"<?= (string)$group['id'] === (string)$form['group_id'] ? ' selected' : '' ?>>
                        <?= e($group['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <label for="password"><?= $editing !== null ? 'New password (leave blank to keep current)' : 'Password' ?></label>
            <p class="meta">Use at least 12 characters, up to 72 UTF-8 bytes.</p>
            <input type="password" id="password" name="password" minlength="12" maxlength="72" autocomplete="new-password" <?= $editing !== null ? '' : 'required' ?>>
            <label for="confirm">Confirm password</label>
            <input type="password" id="confirm" name="confirm" maxlength="72" autocomplete="new-password">
            <button type="submit"><?= $editing !== null ? 'Save changes' : 'Add user' ?></button>
            <?php if ($editing !== null): ?>
                <a class="button ghost" href="users.php">Cancel</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="card">
        <h1>Users</h1>
        <table>
            <thead>
            <tr>
                <th>Username</th>
                <th>Name</th>
                <th>E-mail</th>
                <th>Role</th>
                <th>Group</th>
                <th>Last login</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $row): ?>
                <tr>
                    <td><strong><?= e($row['username']) ?></strong>
                    <?= (int)$row['id'] === (int)$me['id'] ? '<span class="badge">you</span>' : '' ?>
                    </td>
                    <td><?= e(trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''))) ?: '<span class="meta">&mdash;</span>' ?></td>
                    <td class="meta"><?= $row['email'] !== null ? e($row['email']) : '&mdash;' ?></td>
                    <td><?= e($row['role']) ?></td>
                    <td><?= $row['group_name'] !== null ? e($row['group_name']) : '<span class="meta">&mdash;</span>' ?></td>
                    <td class="meta"><?= $row['last_login_at'] !== null ? e($row['last_login_at']) : 'never' ?></td>
                    <td>
                        <a class="button ghost" href="users.php?edit=<?= (int)$row['id'] ?>">Edit</a>
                        <form class="inline" method="post" action="users.php"
                              data-confirm="Remove this user?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                            <button type="submit" class="danger">Remove</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php render_pager($userPage); ?>
    </div>
<?php
layout_end();
