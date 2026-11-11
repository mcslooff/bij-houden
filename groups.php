<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/group_helpers.php';

require_login();
require_admin();

$formErrors = [];
$form = ['name' => '', 'owner_name' => '', 'owner_address' => '', 'owner_phone' => '', 'owner_email' => '', 'logo_path' => null];
$editing = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'update') {
        $form['name'] = trim((string)($_POST['name'] ?? ''));
        $contactLimits = ['owner_name' => 150, 'owner_address' => 500, 'owner_phone' => 50, 'owner_email' => 200];
        foreach ($contactLimits as $key => $limit) {
            $form[$key] = trim((string)($_POST[$key] ?? ''));
            if (mb_strlen($form[$key]) > $limit) {
                $formErrors[] = str_replace('_', ' ', ucfirst($key)) . ' is limited to ' . $limit . ' characters.';
            }
        }
        if ($form['owner_email'] !== '' && !filter_var($form['owner_email'], FILTER_VALIDATE_EMAIL)) {
            $formErrors[] = 'Enter a valid owner/contact e-mail address, or leave it blank.';
        }
        $existing = null;
        if ($action === 'update') {
            $stmt = db()->prepare('SELECT id, logo_path FROM `groups` WHERE id = ?');
            $stmt->execute([(int)($_POST['id'] ?? 0)]);
            $existing = $stmt->fetch();
            if ($existing === false) {
                $formErrors[] = 'That group no longer exists.';
            } else {
                $form['logo_path'] = $existing['logo_path'];
            }
        }

        if ($form['name'] === '' || mb_strlen($form['name']) > 100) {
            $formErrors[] = 'Name is required (max 100 characters).';
        }
        $duplicate = db()->prepare('SELECT id FROM `groups` WHERE name = ? AND id <> ?');
        $duplicate->execute([$form['name'], $action === 'update' ? (int)($_POST['id'] ?? 0) : 0]);
        if ($duplicate->fetch() !== false) {
            $formErrors[] = 'A group with that name already exists.';
        }

        if ($formErrors === []) {
            $uploadedLogo = null;
            try {
                $logoPath = input_checked($_POST, 'remove_logo') ? null : $form['logo_path'];
                if (isset($_FILES['logo']) && $_FILES['logo']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $uploadedLogo = upload_group_logo($_FILES['logo']);
                    $logoPath = $uploadedLogo;
                }
                $values = [$form['name']];
                foreach ($contactLimits as $key => $limit) {
                    $values[] = $form[$key] === '' ? null : $form[$key];
                }
                $values[] = $logoPath;
                begin_if_needed();
                if ($action === 'add') {
                    db()->prepare('INSERT INTO `groups` (name, owner_name, owner_address, owner_phone, owner_email, logo_path, owner_label_token)
                        VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([...$values, bin2hex(random_bytes(16))]);
                    log_event((int)current_user()['id'], 'group_added', $form['name']);
                } else {
                    $id = (int)$_POST['id'];
                    db()->prepare('UPDATE `groups` SET name = ?, owner_name = ?, owner_address = ?, owner_phone = ?, owner_email = ?, logo_path = ? WHERE id = ?')
                        ->execute([...$values, $id]);
                    log_event((int)current_user()['id'], 'group_updated', $form['name']);
                }
                if ($form['logo_path'] !== $logoPath) image_enqueue_cleanup($form['logo_path']);
                db()->commit();
                if ($form['logo_path'] !== $logoPath) {
                    delete_group_logo($form['logo_path']);
                }
                set_flash($action === 'add' ? 'Group added.' : 'Group updated.');
                header('Location: groups.php');
                exit;
            } catch (Throwable $error) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                delete_group_logo($uploadedLogo);
                if ($error instanceof PDOException && (int)($error->errorInfo[1] ?? 0) === 1062) {
                    $formErrors[] = 'A group with that name already exists.';
                } elseif ($error instanceof InvalidArgumentException) {
                    $formErrors[] = $error->getMessage();
                } else {
                    report_error($error, 'group_update');
                    $formErrors[] = 'The group could not be saved. Please try again.';
                }
            }
        }

        if ($action === 'update') {
            $editing = ['id' => (int)($_POST['id'] ?? 0)];
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $sel = db()->prepare('SELECT name, logo_path FROM `groups` WHERE id = ?');
        $sel->execute([$id]);
        $row = $sel->fetch();

        if ($row === false) {
            set_flash('That group does not exist.', 'error');
        } else {
            $members = db()->prepare('SELECT COUNT(*) FROM users WHERE group_id = ?');
            $members->execute([$id]);
            $entities = db()->prepare('SELECT
                (SELECT COUNT(*) FROM apiaries WHERE group_id = ?) +
                (SELECT COUNT(*) FROM hives WHERE group_id = ?) +
                (SELECT COUNT(*) FROM colonies WHERE group_id = ?) +
                (SELECT COUNT(*) FROM queens WHERE group_id = ?) +
                (SELECT COUNT(*) FROM tasks WHERE group_id = ?)');
            $entities->execute([$id, $id, $id, $id, $id]);

            if ((int)$members->fetchColumn() > 0) {
                set_flash('Group not removed: it still has members. Reassign or remove the users first.', 'error');
            } elseif ((int)$entities->fetchColumn() > 0) {
                set_flash('Group not removed: it owns active or archived records. Keep the group to preserve ownership.', 'error');
            } else {
                image_enqueue_cleanup($row['logo_path']);
                db()->prepare('DELETE FROM `groups` WHERE id = ?')->execute([$id]);
                log_event((int)current_user()['id'], 'group_deleted', $row['name']);
                db()->commit();
                delete_group_logo($row['logo_path']);
                set_flash('Empty group removed.', 'warning');
            }
        }
        header('Location: groups.php');
        exit;
    }
}

$editId = (int)($_GET['edit'] ?? 0);
if ($editId > 0 && $editing === null) {
    $sel = db()->prepare('SELECT * FROM `groups` WHERE id = ?');
    $sel->execute([$editId]);
    $row = $sel->fetch();

    if ($row === false) {
        set_flash('That group does not exist.', 'error');
    } else {
        $editing = $row;
        foreach ($form as $key => $value) {
            $form[$key] = $key === 'logo_path' ? $row[$key] : (string)($row[$key] ?? '');
        }
    }
}

$groupPage = list_page_query(
    "SELECT g.id, g.name, g.created_at, g.owner_name, g.owner_email, g.logo_path,
            (SELECT COUNT(*) FROM users u WHERE u.group_id = g.id) AS member_count,
            (SELECT COUNT(*) FROM apiaries a WHERE a.group_id = g.id) AS apiary_count,
            (SELECT COUNT(*) FROM hives h WHERE h.group_id = g.id) AS hive_count,
            (SELECT COUNT(*) FROM colonies c WHERE c.group_id = g.id) AS colony_count,
            (SELECT COUNT(*) FROM queens q WHERE q.group_id = g.id) AS queen_count,
            (SELECT COUNT(*) FROM tasks t WHERE t.group_id = g.id) AS task_count
     FROM `groups` g", 'ORDER BY g.name, g.id'
);
$groups = $groupPage['rows'];

release_failed_mutation($formErrors);
layout_start('Groups', 'groups');
?>
    <?php if ($formErrors !== []): ?>
        <div class="flash error"><?php foreach ($formErrors as $err): ?><?= e($err) ?><br><?php endforeach; ?></div>
    <?php endif; ?>

    <div class="card">
        <h1><?= $editing !== null ? 'Edit group' : 'Add a group' ?></h1>
        <form method="post" action="groups.php" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= $editing !== null ? 'update' : 'add' ?>">
            <?php if ($editing !== null): ?>
                <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
                <?= edit_revision_field('groups', $editing) ?>
            <?php endif; ?>
            <label for="name">Name</label>
            <input type="text" id="name" name="name" maxlength="100" required
                   value="<?= e($form['name']) ?>">
            <h2 class="form-section-heading">Owner and contact details (optional)</h2>
            <p class="meta">These details and the group logo appear on the public, printable hive owner label.</p>
            <label for="owner_name">Owner / contact name</label>
            <input type="text" id="owner_name" name="owner_name" maxlength="150" value="<?= e($form['owner_name']) ?>">
            <label for="owner_address">Address</label>
            <textarea id="owner_address" name="owner_address" maxlength="500"><?= e($form['owner_address']) ?></textarea>
            <label for="owner_phone">Phone number</label>
            <input type="tel" id="owner_phone" name="owner_phone" maxlength="50" value="<?= e($form['owner_phone']) ?>">
            <label for="owner_email">E-mail address</label>
            <input type="email" id="owner_email" name="owner_email" maxlength="200" value="<?= e($form['owner_email']) ?>">
            <h2 class="form-section-heading">Group logo / brand</h2>
            <p><?= group_avatar($form, 'group-logo-preview') ?></p>
            <label for="logo">Upload a logo (optional, maximum 2 MB)</label>
            <input type="file" id="logo" name="logo" accept="image/jpeg,image/png,image/webp,image/gif">
            <?php if ($form['logo_path'] !== null): ?>
                <label><input type="checkbox" name="remove_logo" value="1"> Remove current logo</label>
            <?php endif; ?>
            <button type="submit"><?= $editing !== null ? 'Save changes' : 'Add group' ?></button>
            <?php if ($editing !== null): ?>
                <a class="button ghost" href="groups.php">Cancel</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="card">
        <h1>Groups</h1>
        <?php if ($groups === []): ?>
            <p class="meta">No groups yet. Groups let you assign apiaries, hives, colonies and queens to teams.</p>
        <?php else: ?>
            <table>
                <thead>
                <tr>
                    <th>Name</th>
                    <th>Owner / contact</th>
                    <th>Members</th>
                    <th>Apiaries</th>
                    <th>Hives</th>
                    <th>Colonies</th>
                    <th>Queens</th>
                    <th>Tasks</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($groups as $row): ?>
                    <tr>
                        <td><div class="group-name-with-avatar"><?= group_avatar($row) ?><strong><?= e($row['name']) ?></strong></div></td>
                        <td><?= e($row['owner_name'] ?? 'Not provided') ?><?php if ($row['owner_email'] !== null): ?><br><span class="meta"><?= e($row['owner_email']) ?></span><?php endif; ?></td>
                        <td><?= (int)$row['member_count'] ?></td>
                        <td><?= (int)$row['apiary_count'] ?></td>
                        <td><?= (int)$row['hive_count'] ?></td>
                        <td><?= (int)$row['colony_count'] ?></td>
                        <td><?= (int)$row['queen_count'] ?></td>
                        <td><?= (int)$row['task_count'] ?></td>
                        <td>
                            <a class="button ghost"
                                href="groups.php?edit=<?= (int)$row['id'] ?>">Edit</a>
                            <a class="button ghost" href="<?= e(owner_label_link((int)$row['id'])) ?>" target="_blank" rel="noopener">Owner label</a>
                            <form class="inline" method="post" action="groups.php"
                                  data-confirm="Remove this empty group?">
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
        <?php endif; ?>
        <?php render_pager($groupPage); ?>
    </div>
<?php
layout_end();
