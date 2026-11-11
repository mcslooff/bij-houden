<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/layout.php';

require_login();

$user = current_user();
$formErrors = [];
$form = ['name' => '', 'hive_id' => '', 'queen_id' => '', 'date_of_birth' => '', 'notes' => '', 'group_id' => ''];
$editing = null;
$states = queen_states();
$groups = groups_for_dropdown();
$showArchived = isset($_GET['archived']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'update') {
        $form['name'] = trim((string)($_POST['name'] ?? ''));
        $form['hive_id'] = trim((string)($_POST['hive_id'] ?? ''));
        $form['queen_id'] = trim((string)($_POST['queen_id'] ?? ''));
        $form['date_of_birth'] = trim((string)($_POST['date_of_birth'] ?? ''));
        $form['notes'] = trim((string)($_POST['notes'] ?? ''));
        $form['group_id'] = trim((string)($_POST['group_id'] ?? ''));

        $hiveId = null;
        if ($form['hive_id'] === '') {
            $formErrors[] = 'A hive is required for every colony.';
        } elseif (scoped_entity_exists('hives', (int)$form['hive_id'])) {
            $hiveId = (int)$form['hive_id'];
        } else {
            $formErrors[] = 'Selected hive does not exist' . (is_admin() ? '' : ' in your group') . '.';
        }

        $queenId = null;
        if ($form['queen_id'] !== '') {
            if (scoped_entity_exists('queens', (int)$form['queen_id'])) {
                $queenId = (int)$form['queen_id'];
            } else {
                $formErrors[] = 'Selected queen does not exist' . (is_admin() ? '' : ' in your group') . '.';
            }
        }

        $dateOfBirth = null;
        if (!valid_date($form['date_of_birth'], 'Y-m-d')) {
            $formErrors[] = 'Date of birth must be a valid date.';
        } else {
            $today = (new DateTime('today'))->format('Y-m-d');
            if ($form['date_of_birth'] > $today) {
                $formErrors[] = 'Date of birth cannot be in the future.';
            } else {
                $dateOfBirth = $form['date_of_birth'];
            }
        }

        if ($form['name'] === '' || mb_strlen($form['name']) > 100) {
            $formErrors[] = 'Name is required (max 100 characters).';
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

        if ($formErrors === [] && $hiveId !== null) {
            $occupied = db()->prepare(
                'SELECT id FROM colonies WHERE archived_at IS NULL AND hive_id = ?' . ($action === 'update' ? ' AND id <> ?' : '')
            );
            $occupied->execute($action === 'update' ? [$hiveId, (int)($_POST['id'] ?? 0)] : [$hiveId]);

            if ($occupied->fetch() !== false) {
                $formErrors[] = 'That hive is already occupied by another colony.';
            }
        }

        $groupId = entity_group_id($chosenGroup);
        if ($action === 'update' && (!scoped_entity_exists('colonies', (int)($_POST['id'] ?? 0))
            || !entity_is_active('colonies', (int)($_POST['id'] ?? 0)))) {
            $formErrors[] = 'Only active colonies in your scope can be edited.';
        }
        if ($hiveId !== null && $formErrors === []) {
            validate_related_group('hives', $hiveId, $groupId, $formErrors);
        }
        if ($queenId !== null && $formErrors === []) {
            validate_related_group('queens', $queenId, $groupId, $formErrors);
            if (!entity_is_active('queens', $queenId)) {
                $formErrors[] = 'An archived queen cannot be assigned to a colony.';
            }
            $occupied = db()->prepare('SELECT id FROM colonies WHERE queen_id = ? AND archived_at IS NULL AND id <> ?');
            $occupied->execute([$queenId, $action === 'update' ? (int)$_POST['id'] : 0]);
            if ($occupied->fetch() !== false) {
                $formErrors[] = 'That queen is already assigned to another active colony.';
            }
        }
        if ($formErrors === []) {
            $groupId = entity_group_id($chosenGroup);

            if ($action === 'add') {
                db()->prepare(
                    'INSERT INTO colonies (name, hive_id, queen_id, date_of_birth, notes, group_id) VALUES (?, ?, ?, ?, ?, ?)'
                )->execute([$form['name'], $hiveId, $queenId, $dateOfBirth, $form['notes'], $groupId]);
                log_event((int)$user['id'], 'colony_added', $form['name']);
                set_flash('Colony added.', 'info');
            } else {
                $id = (int)($_POST['id'] ?? 0);
                if (!scoped_entity_exists('colonies', $id)) {
                    set_flash('You do not manage that colony.', 'error');
                } else {
                    db()->prepare(
                        'UPDATE colonies SET name = ?, hive_id = ?, queen_id = ?, date_of_birth = ?, notes = ?, group_id = ? WHERE id = ? AND group_id <=> ?'
                    )->execute([$form['name'], $hiveId, $queenId, $dateOfBirth, $form['notes'], $groupId, $id, mutation_original_group('colonies', $id)]);
                    log_event((int)$user['id'], 'colony_updated', $form['name']);
                    set_flash('Colony updated.', 'info');
                }
            }
            header('Location: colonies.php');
            exit;
        }

        if ($action === 'update') {
            $editing = ['id' => (int)($_POST['id'] ?? 0)];
        }
    } elseif ($action === 'archive') {
        $id = (int)($_POST['id'] ?? 0);

        if (!scoped_entity_exists('colonies', $id)) {
            set_flash('You do not manage that colony.', 'error');
        } else {
            $sel = db()->prepare('SELECT name FROM colonies WHERE id = ?');
            $sel->execute([$id]);
            $row = $sel->fetch();

            archive_entity('colonies', $id);
            set_flash('Colony archived. Its history remains available; its hive and queen can be reassigned.', 'warning');
        }
        header('Location: colonies.php');
        exit;
    }
}

$editId = (int)($_GET['edit'] ?? 0);
if ($editId > 0 && $editing === null) {
    $row = scoped_entity('colonies', $editId);
    if ($row === false || $row['archived_at'] !== null) {
        set_flash('You do not manage that colony.', 'error');
    } else {
        $editing = $row;
        $form = [
            'name' => $row['name'],
            'hive_id' => (string)$row['hive_id'],
            'queen_id' => $row['queen_id'] !== null ? (string)$row['queen_id'] : '',
            'date_of_birth' => (string)$row['date_of_birth'],
            'notes' => (string)$row['notes'],
            'group_id' => $row['group_id'] !== null ? (string)$row['group_id'] : '',
        ];
    }
}

if (!is_admin() && user_group_id() === null) {
    $hives = [];
    $queens = [];
    $colonies = ['rows' => [], 'page' => 1, 'pages' => 1, 'total' => 0, 'per_page' => 50];
} else {
    $gid = (int)user_group_id();
    $hives = db()->query(
        'SELECT h.id, h.name, a.name AS apiary_name
         FROM hives h LEFT JOIN apiaries a ON a.id = h.apiary_id AND a.group_id <=> h.group_id' . (is_admin() ? '' : ' WHERE h.group_id = ' . $gid) . '
         ORDER BY h.name'
    )->fetchAll();
    $queens = db()->query(
        'SELECT id, name, state, hatching_date FROM queens WHERE archived_at IS NULL' . (is_admin() ? '' : ' AND group_id = ' . $gid) . '
         ORDER BY name IS NULL, name, id'
    )->fetchAll();
    if (is_admin() || user_group_id() !== null) {
        $gid = (int)user_group_id();
        $colonies = list_page_query(
            'SELECT c.id, c.name, c.date_of_birth, c.notes, c.group_id, c.updated_at,
                h.name AS hive_name, a.name AS apiary_name,
                q.name AS queen_name, q.state AS queen_state,
                g.name AS group_name
         FROM colonies c
           LEFT JOIN hives h ON h.id = c.hive_id AND h.group_id <=> c.group_id
           LEFT JOIN apiaries a ON a.id = h.apiary_id AND a.group_id <=> c.group_id
           LEFT JOIN queens q ON q.id = c.queen_id AND q.group_id <=> c.group_id
           LEFT JOIN `groups` g ON g.id = c.group_id WHERE c.archived_at IS ' . ($showArchived ? 'NOT NULL' : 'NULL')
           . (is_admin() ? '' : ' AND c.group_id = ' . $gid),
            'ORDER BY c.name, c.id'
        );
    }
}

release_failed_mutation($formErrors);
layout_start('Colonies', 'colonies');
?>
    <?php if ($formErrors !== []): ?>
        <div class="flash error"><?php foreach ($formErrors as $err): ?><?= e($err) ?><br><?php endforeach; ?></div>
    <?php endif; ?>

    <div class="card">
        <h1><?= $editing !== null ? 'Edit colony' : 'Add a colony' ?></h1>
        <form method="post" action="colonies.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= $editing !== null ? 'update' : 'add' ?>">
            <?php if ($editing !== null): ?>
                <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
                <?= edit_revision_field('colonies', $editing) ?>
            <?php endif; ?>
            <label for="name">Name</label>
            <input type="text" id="name" name="name" maxlength="100" required
                   value="<?= e($form['name']) ?>">
            <label for="hive_id">Hive (required)</label>
            <select id="hive_id" name="hive_id" required>
                <option value="" disabled<?= $form['hive_id'] === '' ? ' selected' : '' ?>>Choose a hive&hellip;</option>
                <?php foreach ($hives as $hive): ?>
                    <option value="<?= (int)$hive['id'] ?>"<?= (string)$hive['id'] === (string)$form['hive_id'] ? ' selected' : '' ?>>
                        <?= e($hive['name']) ?>
                        (<?= $hive['apiary_name'] !== null ? e($hive['apiary_name']) : 'in storage' ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <label for="date_of_birth">Date of birth</label>
            <input type="date" id="date_of_birth" name="date_of_birth" required
                   value="<?= e($form['date_of_birth']) ?>">
            <label for="queen_id">Queen (optional)</label>
            <select id="queen_id" name="queen_id">
                <option value=""<?= $form['queen_id'] === '' ? ' selected' : '' ?>>No queen (hopefully temporarily)</option>
                <?php foreach ($queens as $queen): ?>
                    <option value="<?= (int)$queen['id'] ?>"<?= (string)$queen['id'] === (string)$form['queen_id'] ? ' selected' : '' ?>>
                        <?= e($queen['name'] ?? 'unnamed') ?>
                        &mdash; <?= e($states[$queen['state']] ?? $queen['state']) ?>
                        (hatched <?= $queen['hatching_date'] !== null ? e($queen['hatching_date']) : '?' ?>)
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
            <button type="submit"><?= $editing !== null ? 'Save changes' : 'Add colony' ?></button>
            <?php if ($editing !== null): ?>
                <a class="button ghost" href="colonies.php">Cancel</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="card">
        <h1><?= $showArchived ? 'Archived colonies' : 'Colonies' ?></h1>
        <p><a href="colonies.php<?= $showArchived ? '' : '?archived=1' ?>"><?= $showArchived ? 'Active colonies' : 'View archived colonies' ?></a></p>
        <?php if ($colonies['rows'] === []): ?>
            <p class="meta">No colonies <?= is_admin() ? 'registered yet' : 'assigned to your group' ?>.</p>
        <?php else: ?>
            <table>
                <thead>
                <tr>
                    <th>Name</th>
                    <th>Hive</th>
                    <th>Queen</th>
                    <th>Born</th>
                    <th>Group</th>
                    <th>Updated</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($colonies['rows'] as $row): ?>
                    <tr>
                        <td><strong><?= e($row['name']) ?></strong></td>
                        <td>
                            <?= e($row['hive_name'] ?? 'Unavailable hive') ?>
                            <br><span class="meta"><?= $row['apiary_name'] !== null ? e($row['apiary_name']) : 'in storage' ?></span>
                        </td>
                        <td>
                            <?php if ($row['queen_state'] !== null): ?>
                                <?= $row['queen_name'] !== null ? e($row['queen_name']) : '<span class="badge">unnamed</span>' ?>
                                <br><span class="meta">
                                    <?= e($states[$row['queen_state']] ?? $row['queen_state']) ?>
                                </span>
                            <?php else: ?>
                                <span class="badge">No queen</span>
                            <?php endif; ?>
                        </td>
                        <td class="meta"><?= e($row['date_of_birth']) ?></td>
                        <td><?= $row['group_name'] !== null ? e($row['group_name']) : '<span class="meta">&mdash;</span>' ?></td>
                        <td class="meta"><?= e($row['updated_at']) ?></td>
                        <td>
                            <a class="button ghost" href="record.php?type=colonies&id=<?= (int)$row['id'] ?>">View</a>
                            <a class="button ghost"
                               href="inspections.php?colony=<?= (int)$row['id'] ?>">History</a>
                            <?php if (!$showArchived): ?>
                            <a class="button ghost"
                               href="colonies.php?edit=<?= (int)$row['id'] ?>">Edit</a>
                            <form class="inline" method="post" action="colonies.php"
                                  data-confirm="Archive this colony? Its history will be preserved.">
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
        <?php render_pager($colonies); ?>
    </div>
<?php
layout_end();
