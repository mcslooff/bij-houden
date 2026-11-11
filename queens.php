<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/layout.php';

require_login();

$user = current_user();
$formErrors = [];
$form = ['name' => '', 'hatching_date' => '', 'state' => 'unknown', 'notes' => '', 'group_id' => ''];
$editing = null;
$states = queen_states();
$groups = groups_for_dropdown();
$showArchived = isset($_GET['archived']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'update') {
        $form['name'] = trim((string)($_POST['name'] ?? ''));
        $form['hatching_date'] = trim((string)($_POST['hatching_date'] ?? ''));
        $form['state'] = (string)($_POST['state'] ?? 'unknown');
        $form['notes'] = trim((string)($_POST['notes'] ?? ''));
        $form['group_id'] = trim((string)($_POST['group_id'] ?? ''));

        if (mb_strlen($form['name']) > 100) {
            $formErrors[] = 'Name is limited to 100 characters.';
        }
        $hatchingDate = null;
        if ($form['hatching_date'] !== '') {
            if (!valid_date($form['hatching_date'], 'Y-m-d')) {
                $formErrors[] = 'Hatching date must be a valid date.';
            } else {
                $hatchingDate = $form['hatching_date'];
            }
        }
        if (!valid_choice($form['state'], $states)) {
            $formErrors[] = 'Invalid queen state.';
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

        if ($action === 'update' && (!scoped_entity_exists('queens', (int)($_POST['id'] ?? 0))
            || !entity_is_active('queens', (int)($_POST['id'] ?? 0)))) {
            $formErrors[] = 'Only active queens in your scope can be edited.';
        }
        if ($action === 'update' && $formErrors === []) {
            validate_group_change('queens', (int)$_POST['id'], entity_group_id($chosenGroup), $formErrors);
        }
        if ($formErrors === []) {
            $groupId = entity_group_id($chosenGroup);

            $queenName = $form['name'] !== '' ? $form['name'] : null;
            if ($action === 'add') {
                db()->prepare('INSERT INTO queens (name, hatching_date, state, notes, group_id) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$queenName, $hatchingDate, $form['state'], $form['notes'], $groupId]);
                log_event((int)$user['id'], 'queen_added', $queenName ?? 'unnamed queen');
                set_flash('Queen added.', 'info');
            } else {
                $id = (int)($_POST['id'] ?? 0);
                if (!scoped_entity_exists('queens', $id)) {
                    set_flash('You do not manage that queen.', 'error');
                } else {
                    db()->prepare('UPDATE queens SET name = ?, hatching_date = ?, state = ?, notes = ?, group_id = ? WHERE id = ? AND group_id <=> ?')
                        ->execute([$queenName, $hatchingDate, $form['state'], $form['notes'], $groupId, $id, mutation_original_group('queens', $id)]);
                    log_event((int)$user['id'], 'queen_updated', $queenName ?? 'unnamed queen');
                    set_flash('Queen updated.', 'info');
                }
            }
            header('Location: queens.php');
            exit;
        }

        if ($action === 'update') {
            $editing = ['id' => (int)($_POST['id'] ?? 0)];
        }
    } elseif ($action === 'archive') {
        $id = (int)($_POST['id'] ?? 0);

        if (!scoped_entity_exists('queens', $id)) {
            set_flash('You do not manage that queen.', 'error');
        } else {
            $sel = db()->prepare('SELECT COALESCE(name, "unnamed queen") AS label FROM queens WHERE id = ?');
            $sel->execute([$id]);
            $row = $sel->fetch();

            archive_entity('queens', $id);
            set_flash('Queen archived. Any active colony assigned to her is now queenless; the queen remains available for viewing.', 'warning');
        }
        header('Location: queens.php');
        exit;
    }
}

$editId = (int)($_GET['edit'] ?? 0);
if ($editId > 0 && $editing === null) {
    $row = scoped_entity('queens', $editId);
    if ($row === false || $row['archived_at'] !== null) {
        set_flash('You do not manage that queen.', 'error');
    } else {
        $editing = $row;
        $form = [
            'name' => (string)$row['name'],
            'hatching_date' => (string)$row['hatching_date'],
            'state' => $row['state'],
            'notes' => (string)$row['notes'],
            'group_id' => $row['group_id'] !== null ? (string)$row['group_id'] : '',
        ];
    }
}

if (!is_admin() && user_group_id() === null) {
    $queens = ['rows' => [], 'page' => 1, 'pages' => 1, 'total' => 0, 'per_page' => 50];
} else {
    $gid = (int)user_group_id();
    $queens = list_page_query(
        'SELECT q.id, q.name, q.hatching_date, q.state, q.notes, q.group_id, q.updated_at, g.name AS group_name,
                (SELECT COUNT(*) FROM colonies c WHERE c.queen_id = q.id AND c.archived_at IS NULL AND c.group_id <=> q.group_id) AS colony_count
         FROM queens q LEFT JOIN `groups` g ON g.id = q.group_id WHERE q.archived_at IS ' . ($showArchived ? 'NOT NULL' : 'NULL')
         . (is_admin() ? '' : ' AND q.group_id = ' . $gid),
        'ORDER BY q.name IS NULL, q.name, q.id'
    );
}

release_failed_mutation($formErrors);
layout_start('Queens', 'queens');
?>
    <?php if ($formErrors !== []): ?>
        <div class="flash error"><?php foreach ($formErrors as $err): ?><?= e($err) ?><br><?php endforeach; ?></div>
    <?php endif; ?>

    <div class="card">
        <h1><?= $editing !== null ? 'Edit queen' : 'Add a queen' ?></h1>
        <form method="post" action="queens.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= $editing !== null ? 'update' : 'add' ?>">
            <?php if ($editing !== null): ?>
                <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
                <?= edit_revision_field('queens', $editing) ?>
            <?php endif; ?>
            <label for="name">Name / line (optional)</label>
            <input type="text" id="name" name="name" maxlength="100"
                   value="<?= e($form['name']) ?>">
            <label for="hatching_date">Hatching date</label>
            <input type="date" id="hatching_date" name="hatching_date"
                   value="<?= e($form['hatching_date']) ?>">
            <label for="state">State</label>
            <select id="state" name="state">
                <?php foreach ($states as $value => $label): ?>
                    <option value="<?= e($value) ?>"<?= $form['state'] === $value ? ' selected' : '' ?>>
                        <?= e($label) ?>
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
            <button type="submit"><?= $editing !== null ? 'Save changes' : 'Add queen' ?></button>
            <?php if ($editing !== null): ?>
                <a class="button ghost" href="queens.php">Cancel</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="card">
        <h1><?= $showArchived ? 'Archived queens' : 'Queens' ?></h1>
        <p><a href="queens.php<?= $showArchived ? '' : '?archived=1' ?>"><?= $showArchived ? 'Active queens' : 'View archived queens' ?></a></p>
        <?php if ($queens['rows'] === []): ?>
            <p class="meta">No queens <?= is_admin() ? 'registered yet' : 'assigned to your group' ?>.</p>
        <?php else: ?>
            <table>
                <thead>
                <tr>
                    <th>Name / line</th>
                    <th>Hatched</th>
                    <th>State</th>
                    <th>Colonies</th>
                    <th>Group</th>
                    <th>Updated</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($queens['rows'] as $row): ?>
                    <tr>
                        <td><strong><?= $row['name'] !== null ? e($row['name']) : '<span class="badge">unnamed</span>' ?></strong></td>
                        <td class="meta"><?= $row['hatching_date'] !== null ? e($row['hatching_date']) : '&mdash;' ?></td>
                        <td><?= e($states[$row['state']] ?? $row['state']) ?></td>
                        <td><?= (int)$row['colony_count'] ?></td>
                        <td><?= $row['group_name'] !== null ? e($row['group_name']) : '<span class="meta">&mdash;</span>' ?></td>
                        <td class="meta"><?= e($row['updated_at']) ?></td>
                        <td>
                            <a class="button ghost" href="record.php?type=queens&id=<?= (int)$row['id'] ?>">View</a>
                            <?php if (!$showArchived): ?>
                            <a class="button ghost"
                               href="queens.php?edit=<?= (int)$row['id'] ?>">Edit</a>
                            <form class="inline" method="post" action="queens.php"
                                  data-confirm="Archive this queen? Her active colony will be marked queenless.">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="archive">
                                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                <button type="submit" class="danger">Archive</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php render_pager($queens); ?>
    </div>
<?php
layout_end();
