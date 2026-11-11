<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/layout.php';

require_login();

$user = current_user();
$formErrors = [];
$form = ['name' => '', 'location' => '', 'notes' => '', 'group_id' => ''];
$editing = null;
$groups = groups_for_dropdown();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'update') {
        $form['name'] = trim((string)($_POST['name'] ?? ''));
        $form['location'] = trim((string)($_POST['location'] ?? ''));
        $form['notes'] = trim((string)($_POST['notes'] ?? ''));
        $form['group_id'] = trim((string)($_POST['group_id'] ?? ''));

        if ($form['name'] === '' || mb_strlen($form['name']) > 100) {
            $formErrors[] = 'Name is required (max 100 characters).';
        }
        if ($form['location'] === '' || mb_strlen($form['location']) > 200) {
            $formErrors[] = 'Location is required (max 200 characters).';
        }
        if (mb_strlen($form['notes']) > 2000) {
            $formErrors[] = 'Notes are limited to 2000 characters.';
        }

        $chosenGroup = null;
        if (is_admin()) {
            if ($form['group_id'] !== '') {
                $sel = db()->prepare('SELECT id FROM `groups` WHERE id = ?');
                $sel->execute([(int)$form['group_id']]);
                if ($sel->fetch() === false) {
                    $formErrors[] = 'Selected group does not exist.';
                } else {
                    $chosenGroup = (int)$form['group_id'];
                }
            }
        } elseif (user_group_id() === null) {
            $formErrors[] = 'You are not a member of any group yet. Ask an administrator to assign you.';
        }

        if ($action === 'update' && !scoped_entity_exists('apiaries', (int)($_POST['id'] ?? 0))) {
            $formErrors[] = 'You do not manage that apiary.';
        }
        if ($action === 'update' && $formErrors === []) {
            validate_group_change('apiaries', (int)$_POST['id'], entity_group_id($chosenGroup), $formErrors);
        }
        if ($formErrors === []) {
            $groupId = entity_group_id($chosenGroup);

            if ($action === 'add') {
                db()->prepare('INSERT INTO apiaries (name, location, notes, group_id) VALUES (?, ?, ?, ?)')
                    ->execute([$form['name'], $form['location'], $form['notes'], $groupId]);
                log_event((int)$user['id'], 'apiary_added', $form['name']);
                set_flash('Apiary added.', 'info');
            } else {
                $id = (int)($_POST['id'] ?? 0);
                if (!scoped_entity_exists('apiaries', $id)) {
                    set_flash('You do not manage that apiary.', 'error');
                } else {
                    db()->prepare('UPDATE apiaries SET name = ?, location = ?, notes = ?, group_id = ? WHERE id = ? AND group_id <=> ?')
                        ->execute([$form['name'], $form['location'], $form['notes'], $groupId, $id, mutation_original_group('apiaries', $id)]);
                    log_event((int)$user['id'], 'apiary_updated', $form['name']);
                    set_flash('Apiary updated.', 'info');
                }
            }
            header('Location: apiaries.php');
            exit;
        }

        if ($action === 'update') {
            $editing = ['id' => (int)($_POST['id'] ?? 0)];
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);

        if (!scoped_entity_exists('apiaries', $id)) {
            set_flash('You do not manage that apiary.', 'error');
        } else {
            $sel = db()->prepare('SELECT name FROM apiaries WHERE id = ?');
            $sel->execute([$id]);
            $row = $sel->fetch();

            try {
                db()->prepare('DELETE FROM apiaries WHERE id = ? AND group_id <=> ?')->execute([$id, mutation_original_group('apiaries', $id)]);
                log_event((int)$user['id'], 'apiary_deleted', $row['name']);
                set_flash('Apiary removed.', 'warning');
            } catch (PDOException $error) {
                if (db()->inTransaction()) db()->rollBack();
                if ((int)($error->errorInfo[1] ?? 0) !== 1451) throw $error;
                set_flash('Apiary not removed. Untangle it from hives or colonies first.', 'error');
            }
        }
        header('Location: apiaries.php');
        exit;
    }
}

$editId = (int)($_GET['edit'] ?? 0);
if ($editId > 0 && $editing === null) {
    $row = scoped_entity('apiaries', $editId);
    if ($row === false) {
        set_flash('You do not manage that apiary.', 'error');
    } else {
        $editing = $row;
        $form = [
            'name' => $row['name'],
            'location' => $row['location'],
            'notes' => (string)$row['notes'],
            'group_id' => $row['group_id'] !== null ? (string)$row['group_id'] : '',
        ];
    }
}

$params = [];
if (!is_admin() && user_group_id() === null) {
    $apiaries = ['rows' => [], 'page' => 1, 'pages' => 1, 'total' => 0, 'per_page' => 50];
} else {
    $apiaries = list_page_query(
        'SELECT a.id, a.name, a.location, a.notes, a.group_id, a.created_at, a.updated_at, g.name AS group_name
        FROM apiaries a LEFT JOIN `groups` g ON g.id = a.group_id' . (is_admin() ? '' : ' WHERE a.group_id = ' . (int)user_group_id()),
        'ORDER BY a.name, a.id'
    );
}

release_failed_mutation($formErrors);
layout_start('Apiaries', 'apiaries');
?>
    <?php if ($formErrors !== []): ?>
        <div class="flash error"><?php foreach ($formErrors as $err): ?><?= e($err) ?><br><?php endforeach; ?></div>
    <?php endif; ?>

    <div class="card">
        <h1><?= $editing !== null ? 'Edit apiary' : 'Add an apiary' ?></h1>
        <form method="post" action="apiaries.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= $editing !== null ? 'update' : 'add' ?>">
            <?php if ($editing !== null): ?>
                <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
                <?= edit_revision_field('apiaries', $editing) ?>
            <?php endif; ?>
            <label for="name">Name</label>
            <input type="text" id="name" name="name" maxlength="100" required
                   value="<?= e($form['name']) ?>">
            <label for="location">Location</label>
            <input type="text" id="location" name="location" maxlength="200" required
                   value="<?= e($form['location']) ?>">
            <label for="notes">Notes (optional)</label>
            <textarea id="notes" name="notes" maxlength="2000"><?= e($form['notes']) ?></textarea>
            <?php if (is_admin()): ?>
                <label for="group_id">Group</label>
                <select id="group_id" name="group_id">
                    <option value=""<?= $form['group_id'] === '' ? ' selected' : '' ?>>Unassigned (admins only)</option>
                    <?php foreach ($groups as $group): ?>
                        <option value="<?= (int)$group['id'] ?>"<?= (string)$group['id'] === (string)$form['group_id'] ? ' selected' : '' ?>>
                            <?= e($group['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
            <button type="submit"><?= $editing !== null ? 'Save changes' : 'Add apiary' ?></button>
            <?php if ($editing !== null): ?>
                <a class="button ghost" href="apiaries.php">Cancel</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="card">
        <h1>Apiaries</h1>
        <?php if ($apiaries['rows'] === []): ?>
            <p class="meta">No apiaries <?= is_admin() ? 'yet' : 'assigned to your group' ?>.</p>
        <?php else: ?>
            <table>
                <thead>
                <tr>
                    <th>Name</th>
                    <th>Location</th>
                    <th>Notes</th>
                    <th>Group</th>
                    <th>Updated</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($apiaries['rows'] as $row): ?>
                    <tr>
                        <td><strong><?= e($row['name']) ?></strong></td>
                        <td><?= e($row['location']) ?></td>
                        <td class="notes">
                            <?= mb_strlen((string)$row['notes']) > 60
                                ? e(mb_substr((string)$row['notes'], 0, 60)) . '&hellip;'
                                : e((string)$row['notes']) ?>
                        </td>
                        <td><?= $row['group_name'] !== null ? e($row['group_name']) : '<span class="meta">&mdash;</span>' ?></td>
                        <td class="meta"><?= e($row['updated_at']) ?></td>
                        <td>
                            <a class="button ghost"
                               href="apiaries.php?edit=<?= (int)$row['id'] ?>">Edit</a>
                            <form class="inline" method="post" action="apiaries.php"
                                  data-confirm="Remove this apiary? Its hives will be put in storage.">
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
        <?php render_pager($apiaries); ?>
    </div>
<?php
layout_end();
