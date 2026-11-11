<?php

require_once __DIR__ . '/layout.php';
require_login();

$statuses = inspection_statuses();
$optionSets = [
    'strength' => ['Colony strength', inspection_strength_options()],
    'temperament' => ['Temperament', inspection_temperament_options()],
    'laying_pattern' => ['Laying pattern', inspection_laying_pattern_options()],
    'population' => ['Population', inspection_population_options()],
];
$flags = inspection_flags();
$errors = [];
$form = [
    'visited_at' => app_now()->format('Y-m-d\TH:i'), 'visited_offset' => '', 'status' => 'good', 'supers' => '',
    'strength' => 'not_assessed', 'temperament' => 'not_assessed',
    'laying_pattern' => 'not_assessed', 'population' => 'not_assessed',
    'todo' => '', 'notes' => '',
];
foreach ($flags as $column => $label) {
    $form[$column] = false;
}

function accessible_inspection(int $id): array|false
{
    $stmt = db()->prepare(
        'SELECT i.* FROM inspections i JOIN colonies c ON c.id = i.colony_id
         WHERE i.id = ?' . group_sql('c', ' AND ')
    );
    $stmt->execute([$id]);
    return $stmt->fetch();
}

$editing = null;
$colonyId = (int)($_POST['colony_id'] ?? $_GET['colony'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['edit'])) {
    $editing = accessible_inspection((int)$_GET['edit']);
    if ($editing === false) {
        $editing = null;
        $errors[] = 'You do not manage that inspection.';
    } else {
        $colonyId = (int)$editing['colony_id'];
        foreach ($form as $key => $value) {
            $form[$key] = $key === 'todo' ? (string)$editing['action'] : ($editing[$key] ?? '');
        }
        $form['visited_at'] = (new DateTimeImmutable($editing['visited_at']))->format('Y-m-d\TH:i');
    }
}

$colony = null;
if ($colonyId > 0 && scoped_entity_exists('colonies', $colonyId)) {
    $stmt = db()->prepare(
        'SELECT c.*, h.name AS hive_name, a.name AS apiary_name
         FROM colonies c JOIN hives h ON h.id = c.hive_id AND h.group_id <=> c.group_id
         LEFT JOIN apiaries a ON a.id = h.apiary_id AND a.group_id <=> c.group_id WHERE c.id = ?' . group_sql('c', ' AND ')
    );
    $stmt->execute([$colonyId]);
    $row = $stmt->fetch();
    $colony = $row === false ? null : $row;
} elseif ($colonyId > 0) {
    $errors[] = 'That colony does not exist or is not managed by your group.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $existing = null;
    if (in_array($action, ['update', 'delete'], true)) {
        $existing = accessible_inspection((int)($_POST['id'] ?? 0));
        if ($existing === false || $colony === null || (int)$existing['colony_id'] !== $colonyId) {
            $errors[] = 'You do not manage that inspection.';
        }
    }
    if ($colony === null || $colony['archived_at'] !== null) {
        $errors[] = 'Inspections can only be changed for an active colony in your group.';
    }
    if (!in_array($action, ['add', 'update', 'delete'], true)) {
        $errors[] = 'Invalid inspection action.';
    }
    if ($action === 'delete' && $errors === []) {
        db()->prepare('DELETE FROM inspections WHERE id = ? AND colony_id = ? AND EXISTS
            (SELECT 1 FROM colonies c WHERE c.id = inspections.colony_id AND c.group_id <=> ? AND c.archived_at IS NULL)')
            ->execute([(int)$existing['id'], $colonyId, mutation_original_group('colonies', $colonyId)]);
        log_event((int)current_user()['id'], 'inspection_deleted', 'inspection #' . $existing['id']);
        set_flash('Inspection removed.');
        header('Location: inspections.php?colony=' . $colonyId);
        exit;
    }
    if (in_array($action, ['add', 'update'], true)) {
        foreach ($form as $key => $value) {
            $form[$key] = isset($flags[$key]) ? input_checked($_POST, $key) : trim((string)($_POST[$key] ?? ''));
        }
        if ($action === 'update' && $existing !== false) {
            $editing = $existing;
        }
        if (!valid_date($form['visited_at'], 'Y-m-d\TH:i')) {
            $errors[] = 'Enter a valid visit date and time.';
        }
        if (!valid_choice($form['status'], $statuses)) {
            $errors[] = 'Invalid inspection status.';
        }
        foreach ($optionSets as $key => [$label, $options]) {
            if (!valid_choice($form[$key], $options)) {
                $errors[] = 'Select a valid value for ' . strtolower($label) . '.';
            }
        }
        if ($form['supers'] !== '' && (!ctype_digit($form['supers']) || (int)$form['supers'] > 999)) {
            $errors[] = 'Number of supers must be a whole number from 0 to 999, or blank if not assessed.';
        }
        if (mb_strlen($form['todo']) > 200 || mb_strlen($form['notes']) > 2000) {
            $errors[] = 'Actions are limited to 200 characters and notes to 2000 characters.';
        }
        $visit = null;
        if ($errors === []) {
            if ($existing && substr($existing['visited_at'], 0, 16) === str_replace('T', ' ', $form['visited_at'])
                && $form['visited_offset'] === (string)($existing['visited_offset'] ?? '')) {
                // An ordinary edit must not silently assign a timezone to history.
                $visit = array_intersect_key($existing, array_flip(['visited_at', 'visited_at_utc', 'visited_timezone', 'visited_offset']));
            } else {
                try {
                    $visit = inspection_visit_time($form['visited_at'], $form['visited_offset']);
                } catch (InvalidArgumentException $error) {
                    $errors[] = $error->getMessage();
                }
            }
        }
        if ($errors === []) {
            $values = [
                'colony_id' => $colonyId,
                'visited_at' => (new DateTimeImmutable($form['visited_at']))->format('Y-m-d H:i:s'),
                'status' => $form['status'], 'strength' => $form['strength'],
                'temperament' => $form['temperament'],
                'supers' => $form['supers'] === '' ? null : (int)$form['supers'],
                'laying_pattern' => $form['laying_pattern'], 'population' => $form['population'],
                'action' => $form['todo'] === '' ? null : $form['todo'], 'notes' => $form['notes'],
            ];
            $values = array_replace($values, $visit);
            foreach ($flags as $key => $label) {
                $values[$key] = (int)$form[$key];
            }
            if ($action === 'add') {
                $values['user_id'] = (int)current_user()['id'];
                $columns = array_keys($values);
                $stmt = db()->prepare('INSERT INTO inspections (`' . implode('`, `', $columns) . '`) SELECT '
                    . implode(', ', array_fill(0, count($values), '?')) . ' WHERE EXISTS
                    (SELECT 1 FROM colonies c WHERE c.id = ? AND c.group_id <=> ? AND c.archived_at IS NULL)');
                $stmt->execute([...array_values($values), $colonyId, mutation_original_group('colonies', $colonyId)]);
                if ($stmt->rowCount() !== 1) {
                    throw new MutationConflictException('The colony changed. Reload and try again.');
                }
                $id = (int)db()->lastInsertId();
            } else {
                $id = (int)$existing['id'];
                $assignments = array_map(fn($column) => '`' . $column . '` = ?', array_keys($values));
                db()->prepare('UPDATE inspections SET ' . implode(', ', $assignments) . ' WHERE id = ? AND colony_id = ? AND EXISTS
                    (SELECT 1 FROM colonies c WHERE c.id = inspections.colony_id AND c.group_id <=> ? AND c.archived_at IS NULL)')
                    ->execute([...array_values($values), $id, $colonyId, mutation_original_group('colonies', $colonyId)]);
            }
            log_event((int)current_user()['id'], $action === 'add' ? 'inspection_added' : 'inspection_updated', 'inspection #' . $id);
            set_flash('Inspection saved.');
            header('Location: inspections.php?colony=' . $colonyId);
            exit;
        }
    }
}

$colonies = db()->query('SELECT c.id, c.name, c.archived_at FROM colonies c' . group_sql('c')
    . ' ORDER BY c.archived_at IS NOT NULL, c.name')->fetchAll();
$sql = 'SELECT i.*, c.name AS colony_name, u.username FROM inspections i
        JOIN colonies c ON c.id = i.colony_id LEFT JOIN users u ON u.id = i.user_id';
if ($colony !== null) {
    $inspectionPage = list_page_query($sql . ' WHERE i.colony_id = ? AND c.group_id <=> ?' . group_sql('c', ' AND '),
        'ORDER BY i.visited_at DESC, i.id DESC', [$colonyId, $colony['group_id']]);
} else {
    $inspectionPage = list_page_query($sql . group_sql('c'), 'ORDER BY i.visited_at DESC, i.id DESC', [], 12);
}
$entries = $inspectionPage['rows'];
release_failed_mutation($errors);
layout_start('Inspections', 'inspections');
?>
<?php foreach ($errors as $error): ?>
    <div class="flash error"><?= e($error) ?></div>
<?php endforeach; ?>
<div class="card">
    <h1>Inspections</h1>
    <form method="get" action="inspections.php">
        <label for="colony">Colony</label>
        <select id="colony" name="colony">
            <option value="">Recent inspections</option>
            <?php foreach ($colonies as $row): ?>
                <option value="<?= (int)$row['id'] ?>"<?= (int)$row['id'] === $colonyId ? ' selected' : '' ?>>
                    <?= e($row['name']) ?><?= $row['archived_at'] !== null ? ' (archived)' : '' ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit">View inspections</button>
    </form>
</div>
<?php if ($colony !== null): ?>
<div class="card">
    <h2><?= e($colony['name']) ?></h2>
    <p class="meta">Hive: <?= e($colony['hive_name']) ?> · <?= e($colony['apiary_name'] ?? 'In storage') ?></p>
    <?php if ($colony['archived_at'] !== null): ?>
        <p class="flash warning">Archived colony — inspection history is read-only.</p>
    <?php else: ?>
    <h2><?= $editing !== null ? 'Edit inspection' : 'Log inspection' ?></h2>
    <form method="post" action="inspections.php?colony=<?= $colonyId ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="<?= $editing !== null ? 'update' : 'add' ?>">
        <input type="hidden" name="colony_id" value="<?= $colonyId ?>">
        <?php if ($editing !== null): ?><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>"><?php endif; ?>
        <?php if ($editing !== null): ?><?= edit_revision_field('inspections', $editing) ?><?php endif; ?>
        <label for="visited_at">Visit date &amp; time</label>
        <input type="datetime-local" id="visited_at" name="visited_at" required value="<?= e($form['visited_at']) ?>">
        <p class="meta">New or changed visit times use Europe/Amsterdam. Unchanged historical times retain their recorded values and timezone status.</p>
        <label for="visited_offset">UTC offset (needed when the autumn clock change repeats an hour)</label>
        <select id="visited_offset" name="visited_offset">
            <option value="">Automatic for unambiguous times</option>
            <?php foreach (['+01:00' => 'Winter (+01:00)', '+02:00' => 'Summer (+02:00)'] as $offset => $label): ?>
                <option value="<?= e($offset) ?>"<?= $form['visited_offset'] === $offset ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <div class="form-grid">
            <div>
                <label for="status">Status</label>
                <select id="status" name="status">
                    <?php foreach ($statuses as $value => $label): ?>
                        <option value="<?= e($value) ?>"<?= $form['status'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php foreach ($optionSets as $key => [$label, $options]): ?>
            <div>
                <label for="<?= e($key) ?>"><?= e($label) ?></label>
                <select id="<?= e($key) ?>" name="<?= e($key) ?>">
                    <?php foreach ($options as $value => $option): ?>
                        <option value="<?= e($value) ?>"<?= $form[$key] === $value ? ' selected' : '' ?>><?= e($option) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endforeach; ?>
            <div>
                <label for="supers">Number of supers</label>
                <input type="number" id="supers" name="supers" min="0" max="999" step="1" placeholder="Not assessed" value="<?= e((string)$form['supers']) ?>">
            </div>
        </div>
        <fieldset class="flags">
            <legend>Observations (checked = observed)</legend>
            <?php foreach ($flags as $key => $label): ?>
                <label><input type="checkbox" name="<?= e($key) ?>" value="1"<?= !empty($form[$key]) ? ' checked' : '' ?>> <?= e($label) ?></label>
            <?php endforeach; ?>
        </fieldset>
        <label for="todo">Action to do (optional)</label>
        <input type="text" id="todo" name="todo" maxlength="200" value="<?= e($form['todo']) ?>">
        <label for="notes">Notes (optional)</label>
        <textarea id="notes" name="notes" maxlength="2000"><?= e($form['notes']) ?></textarea>
        <button type="submit">Save inspection</button>
        <?php if ($editing !== null): ?><a class="button ghost" href="inspections.php?colony=<?= $colonyId ?>">Cancel</a><?php endif; ?>
    </form>
    <?php endif; ?>
</div>
<?php endif; ?>
<div class="card">
    <h2><?= $colony !== null ? 'Inspection history' : 'Recent inspections' ?></h2>
    <?php if ($entries === []): ?><p class="meta">No inspections recorded.</p><?php endif; ?>
    <?php foreach ($entries as $entry): ?>
    <article class="inspection-entry" id="inspection-<?= (int)$entry['id'] ?>">
        <h3><a href="inspections.php?colony=<?= (int)$entry['colony_id'] ?>"><?= e($entry['colony_name']) ?></a> — <?= e(display_record_time($entry['visited_at'], $entry['visited_at_utc'], $entry['visited_timezone'])) ?></h3>
        <p>Status: <strong><?= e($statuses[$entry['status']] ?? $entry['status']) ?></strong> · By <?= e($entry['username'] ?? 'Deleted user') ?></p>
        <dl class="inspection-summary">
            <?php foreach ($optionSets as $key => [$label, $options]): ?>
                <div><dt><?= e($label) ?></dt><dd><?= e($options[$entry[$key]] ?? 'Not assessed') ?></dd></div>
            <?php endforeach; ?>
            <div><dt>Supers</dt><dd><?= $entry['supers'] === null ? 'Not assessed' : (int)$entry['supers'] ?></dd></div>
        </dl>
        <p><?= inspection_flag_badges($entry) ?: '<span class="meta">No observations checked</span>' ?></p>
        <?php if ($entry['action'] !== null): ?><p><strong>Action:</strong> <?= e($entry['action']) ?></p><?php endif; ?>
        <?php if ($entry['notes'] !== ''): ?><p class="notes"><?= nl2br(e((string)$entry['notes'])) ?></p><?php endif; ?>
        <?php if ($colony !== null && $colony['archived_at'] === null): ?>
            <a class="button ghost" href="inspections.php?edit=<?= (int)$entry['id'] ?>">Edit</a>
            <form class="inline" method="post" action="inspections.php?colony=<?= $colonyId ?>" data-confirm="Remove this inspection?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$entry['id'] ?>">
                <input type="hidden" name="colony_id" value="<?= $colonyId ?>">
                <button type="submit" class="danger">Remove</button>
            </form>
        <?php endif; ?>
    </article>
    <?php endforeach; ?>
    <?php render_pager($inspectionPage); ?>
</div>
<?php layout_end(); ?>
