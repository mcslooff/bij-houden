<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/group_helpers.php';

require_login();

$user = current_user();
$formErrors = [];
$form = ['name' => '', 'apiary_id' => '', 'notes' => '', 'group_id' => ''];
$editing = null;
$groups = groups_for_dropdown();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'update') {
        $form['name'] = trim((string)($_POST['name'] ?? ''));
        $form['apiary_id'] = trim((string)($_POST['apiary_id'] ?? ''));
        $form['notes'] = trim((string)($_POST['notes'] ?? ''));
        $form['group_id'] = trim((string)($_POST['group_id'] ?? ''));

        if ($form['name'] === '' || mb_strlen($form['name']) > 100) {
            $formErrors[] = 'Name is required (max 100 characters).';
        }
        if (mb_strlen($form['notes']) > 2000) {
            $formErrors[] = 'Notes are limited to 2000 characters.';
        }

        $selectedApiary = null;
        if ($form['apiary_id'] !== '') {
            if (scoped_entity_exists('apiaries', (int)$form['apiary_id'])) {
                $selectedApiary = (int)$form['apiary_id'];
            } else {
                $formErrors[] = 'Selected apiary does not exist' . (is_admin() ? '' : ' in your group') . '.';
            }
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

        if ($action === 'update' && !scoped_entity_exists('hives', (int)($_POST['id'] ?? 0))) {
            $formErrors[] = 'You do not manage that hive.';
        }
        $groupId = entity_group_id($chosenGroup);
        if ($selectedApiary !== null && $formErrors === []) {
            validate_related_group('apiaries', $selectedApiary, $groupId, $formErrors);
        }
        if ($action === 'update' && $formErrors === []) {
            validate_group_change('hives', (int)$_POST['id'], $groupId, $formErrors);
        }
        if ($formErrors === []) {
            $groupId = entity_group_id($chosenGroup);

            if ($action === 'add') {
                db()->prepare('INSERT INTO hives (name, apiary_id, notes, group_id) VALUES (?, ?, ?, ?)')
                    ->execute([$form['name'], $selectedApiary, $form['notes'], $groupId]);
                log_event((int)$user['id'], 'hive_added', $form['name']);
                set_flash('Hive added.', 'info');
            } else {
                $id = (int)($_POST['id'] ?? 0);
                if (!scoped_entity_exists('hives', $id)) {
                    set_flash('You do not manage that hive.', 'error');
                } else {
                    db()->prepare('UPDATE hives SET name = ?, apiary_id = ?, notes = ?, group_id = ? WHERE id = ? AND group_id <=> ?')
                        ->execute([$form['name'], $selectedApiary, $form['notes'], $groupId, $id, mutation_original_group('hives', $id)]);
                    log_event((int)$user['id'], 'hive_updated', $form['name']);
                    set_flash('Hive updated.', 'info');
                }
            }
            header('Location: hives.php');
            exit;
        }

        if ($action === 'update') {
            $editing = ['id' => (int)($_POST['id'] ?? 0)];
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);

        if (!scoped_entity_exists('hives', $id)) {
            set_flash('You do not manage that hive.', 'error');
        } else {
            $sel = db()->prepare('SELECT name FROM hives WHERE id = ?');
            $sel->execute([$id]);
            $row = $sel->fetch();

            try {
                db()->prepare('DELETE FROM hives WHERE id = ? AND group_id <=> ?')->execute([$id, mutation_original_group('hives', $id)]);
                log_event((int)$user['id'], 'hive_deleted', $row['name']);
                set_flash('Hive removed.', 'warning');
            } catch (PDOException $error) {
                if (db()->inTransaction()) db()->rollBack();
                if ((int)($error->errorInfo[1] ?? 0) !== 1451) throw $error;
                set_flash('Hive not removed: it is referenced by a colony or its archived history. Keep it for those records.', 'error');
            }
        }
        header('Location: hives.php');
        exit;
    }
}

$editId = (int)($_GET['edit'] ?? 0);
if ($editId > 0 && $editing === null) {
    $row = scoped_entity('hives', $editId);
    if ($row === false) {
        set_flash('You do not manage that hive.', 'error');
    } else {
        $editing = $row;
        $form = [
            'name' => $row['name'],
            'apiary_id' => $row['apiary_id'] !== null ? (string)$row['apiary_id'] : '',
            'notes' => (string)$row['notes'],
            'group_id' => $row['group_id'] !== null ? (string)$row['group_id'] : '',
        ];
    }
}

if (!is_admin() && user_group_id() === null) {
    $hives = ['rows' => [], 'page' => 1, 'pages' => 1, 'total' => 0, 'per_page' => 50];
    $apiaries = [];
} else {
    $gid = (int)user_group_id();
    $apiaries = db()->query(
        'SELECT id, name FROM apiaries' . (is_admin() ? '' : ' WHERE group_id = ' . $gid) . ' ORDER BY name, id'
    )->fetchAll();
    $hives = list_page_query(
        'SELECT h.id, h.name, h.apiary_id, h.notes, h.group_id, h.updated_at, a.name AS apiary_name, g.name AS group_name
         FROM hives h LEFT JOIN apiaries a ON a.id = h.apiary_id AND a.group_id <=> h.group_id LEFT JOIN `groups` g ON g.id = h.group_id'
        . (is_admin() ? '' : ' WHERE h.group_id = ' . $gid),
        'ORDER BY h.name, h.id'
    );
}

release_failed_mutation($formErrors);
layout_start('Hives', 'hives');
?>
    <?php if ($formErrors !== []): ?>
        <div class="flash error"><?php foreach ($formErrors as $err): ?><?= e($err) ?><br><?php endforeach; ?></div>
    <?php endif; ?>

    <div class="card">
        <h1><?= $editing !== null ? 'Edit hive' : 'Add a hive' ?></h1>
        <form method="post" action="hives.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= $editing !== null ? 'update' : 'add' ?>">
            <?php if ($editing !== null): ?>
                <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
                <?= edit_revision_field('hives', $editing) ?>
            <?php endif; ?>
            <label for="name">Name</label>
            <input type="text" id="name" name="name" maxlength="100" required
                   value="<?= e($form['name']) ?>">
            <label for="apiary_id">Apiary</label>
            <select id="apiary_id" name="apiary_id">
                <option value=""<?= $form['apiary_id'] === '' ? ' selected' : '' ?>>In storage</option>
                <?php foreach ($apiaries as $apiary): ?>
                    <option value="<?= (int)$apiary['id'] ?>"<?= (string)$apiary['id'] === (string)$form['apiary_id'] ? ' selected' : '' ?>>
                        <?= e($apiary['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
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
            <button type="submit"><?= $editing !== null ? 'Save changes' : 'Add hive' ?></button>
            <?php if ($editing !== null): ?>
                <a class="button ghost" href="hives.php">Cancel</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="card">
        <h1>Hives</h1>
        <?php if ($hives['rows'] === []): ?>
            <p class="meta">No hives <?= is_admin() ? 'yet' : 'assigned to your group' ?>.</p>
        <?php else: ?>
            <table>
                <thead>
                <tr>
                    <th>Name</th>
                    <th>Apiary / Storage</th>
                    <th>Notes</th>
                    <th>Group</th>
                    <th>Updated</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($hives['rows'] as $row): ?>
                    <tr>
                        <td><strong><?= e($row['name']) ?></strong></td>
                        <td>
                            <?php if ($row['apiary_name'] !== null): ?>
                                <?= e($row['apiary_name']) ?>
                            <?php else: ?>
                                <span class="badge"><?= $row['apiary_id'] === null ? 'In storage' : 'Unavailable apiary' ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="notes">
                            <?= mb_strlen((string)$row['notes']) > 60
                                ? e(mb_substr((string)$row['notes'], 0, 60)) . '&hellip;'
                                : e((string)$row['notes']) ?>
                        </td>
                        <td><?= $row['group_name'] !== null ? e($row['group_name']) : '<span class="meta">&mdash;</span>' ?></td>
                        <td class="meta"><?= e($row['updated_at']) ?></td>
                        <td>
                            <?php if ($row['group_id'] !== null): ?>
                                <a class="button ghost" href="<?= e(owner_label_link((int)$row['group_id'])) ?>" target="_blank" rel="noopener">Print owner label</a>
                            <?php else: ?>
                                <span class="meta">Assign a group for an owner label.</span>
                            <?php endif; ?>
                            <a class="button ghost"
                               href="hives.php?edit=<?= (int)$row['id'] ?>">Edit</a>
                            <form class="inline" method="post" action="hives.php"
                                  data-confirm="Remove this hive?">
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
        <?php render_pager($hives); ?>
    </div>
<?php
layout_end();
