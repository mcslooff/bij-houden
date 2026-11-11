<?php

require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/task_helpers.php';
require_login();

$user = current_user();
$priorities = task_priorities();
$groups = groups_for_dropdown();
$errors = [];
$editing = null;
$form = [
    'title' => '', 'description' => '', 'target_date' => '', 'priority' => 'none',
    'assigned_to' => '', 'group_id' => user_group_id() === null ? '' : (string)user_group_id(),
];

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['edit'])) {
    $editing = accessible_task((int)$_GET['edit']);
    if ($editing === false) {
        $editing = null;
        $errors[] = 'You do not manage that task.';
    } else {
        foreach ($form as $key => $value) {
            $form[$key] = (string)($editing[$key] ?? '');
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    begin_if_needed();
    try {
        if (!in_array($action, ['add', 'update', 'delete', 'complete', 'reopen'], true)) {
            throw new InvalidArgumentException('Invalid task action.');
        }
        $task = null;
        if ($action !== 'add') {
            $task = accessible_task((int)($_POST['id'] ?? 0), true);
            if ($task === false) {
                throw new InvalidArgumentException('You do not manage that task.');
            }
        }
        if (in_array($action, ['add', 'update'], true)) {
            $editing = $action === 'update' ? $task : null;
            foreach ($form as $key => $value) {
                $form[$key] = trim((string)($_POST[$key] ?? ($key === 'priority' ? 'none' : '')));
            }
            $groupId = validate_entity_group($form, $errors);
            if ($groupId === null) {
                $errors[] = 'A task must belong to a group.';
            }
            if ($form['title'] === '' || mb_strlen($form['title']) > 150) {
                $errors[] = 'A title is required (maximum 150 characters).';
            }
            if (mb_strlen($form['description']) > 5000) {
                $errors[] = 'Description is limited to 5000 characters.';
            }
            if ($form['target_date'] !== '' && !valid_date($form['target_date'], 'Y-m-d')) {
                $errors[] = 'Enter a valid target completion date, or leave it blank.';
            }
            if (!valid_choice($form['priority'], $priorities)) {
                $errors[] = 'Select a valid priority.';
            }
            $assigneeId = null;
            if ($form['assigned_to'] !== '') {
                $stmt = db()->prepare('SELECT id FROM users WHERE id = ? AND group_id = ? FOR UPDATE');
                $stmt->execute([$form['assigned_to'], $groupId]);
                $id = $stmt->fetchColumn();
                if ($id === false) {
                    $errors[] = 'The assignee must be a member of the task’s group.';
                } else {
                    $assigneeId = (int)$id;
                }
            }
            if ($errors !== []) {
                db()->rollBack();
            } else {
                $values = [$groupId, $form['title'], $form['description'],
                    $form['target_date'] === '' ? null : $form['target_date'], $form['priority'], $assigneeId];
                if ($action === 'add') {
                    db()->prepare('INSERT INTO tasks (group_id, title, description, target_date, priority, assigned_to, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([...$values, (int)$user['id']]);
                    $id = (int)db()->lastInsertId();
                } else {
                    $id = (int)$task['id'];
                    db()->prepare('UPDATE tasks SET group_id = ?, title = ?, description = ?, target_date = ?, priority = ?, assigned_to = ?
                        WHERE id = ? AND group_id <=> ?')->execute([...$values, $id, mutation_original_group('tasks', $id)]);
                }
                log_event((int)$user['id'], $action === 'add' ? 'task_added' : 'task_updated', 'task #' . $id . ': ' . $form['title']);
                if ($task !== null && $task['assigned_to'] !== $assigneeId) {
                    log_event((int)$user['id'], 'task_reassigned', 'task #' . $id . ' to ' . ($assigneeId ?? 'unassigned'));
                }
                db()->commit();
                set_flash('Task saved.');
                header('Location: tasks.php');
                exit;
            }
        } else {
            $id = (int)$task['id'];
            if ($action === 'delete') {
                db()->prepare('DELETE FROM tasks WHERE id = ? AND group_id <=> ?')->execute([$id, mutation_original_group('tasks', $id)]);
                log_event((int)$user['id'], 'task_deleted', 'task #' . $id . ': ' . $task['title']);
                $message = 'Task removed.';
            } elseif ($action === 'complete') {
                if ($task['completed_at'] === null) {
                    $completed = app_now();
                    db()->prepare('UPDATE tasks SET completed_at = ?, completed_at_utc = ?, completed_timezone = ?, completed_by = ?, completed_by_name = ? WHERE id = ? AND group_id <=> ?')
                        ->execute([$completed->format('Y-m-d H:i:s'), $completed->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                            'Europe/Amsterdam', (int)$user['id'], task_user_label(user_profile()), $id, mutation_original_group('tasks', $id)]);
                    log_event((int)$user['id'], 'task_completed', 'task #' . $id . ': ' . $task['title']);
                    $message = 'Task completed. Your name and the completion time have been recorded.';
                } else {
                    $message = 'Task is already completed; its original completion record has been kept.';
                }
            } else {
                if ($task['completed_at'] !== null) {
                    db()->prepare('UPDATE tasks SET completed_at = NULL, completed_at_utc = NULL, completed_timezone = NULL, completed_by = NULL, completed_by_name = NULL WHERE id = ? AND group_id <=> ?')
                        ->execute([$id, mutation_original_group('tasks', $id)]);
                    log_event((int)$user['id'], 'task_reopened', 'task #' . $id . ': ' . $task['title']);
                }
                $message = 'Task reopened.';
            }
            db()->commit();
            set_flash($message);
            header('Location: tasks.php');
            exit;
        }
    } catch (InvalidArgumentException $error) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        $errors[] = $error->getMessage();
    } catch (PDOException $error) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        report_error($error, 'task_update');
        $errors[] = 'The task could not be saved. Please try again.';
    }
}

$members = db()->query('SELECT u.id, u.username, u.first_name, u.last_name, u.group_id, g.name AS group_name
    FROM users u JOIN `groups` g ON g.id = u.group_id' . group_sql('u') . '
    ORDER BY u.first_name, u.last_name, u.username')->fetchAll();
$taskPage = list_page_query('SELECT t.*, g.name AS group_name,
    COALESCE(NULLIF(TRIM(CONCAT_WS(\' \', a.first_name, a.last_name)), \'\'), a.username) AS assignee_name,
    a.username AS assignee_username
    FROM tasks t JOIN `groups` g ON g.id = t.group_id LEFT JOIN users a ON a.id = t.assigned_to AND a.group_id = t.group_id'
    . group_sql('t'), task_order_sql((int)$user['id']));
$tasks = $taskPage['rows'];
$sectionNames = ['Open — assigned to you', 'Open — unassigned', 'Open — assigned to other group members', 'Completed'];

release_failed_mutation($errors);
layout_start('Tasks', 'tasks');
?>
<?php foreach ($errors as $error): ?><div class="flash error"><?= e($error) ?></div><?php endforeach; ?>
<div class="card">
    <h1 class="panel-heading"><?= app_icon('tasks') ?><span><?= $editing !== null ? 'Edit task' : 'Add a task' ?></span></h1>
    <?php if ($editing !== null && $editing['completed_at'] !== null): ?>
        <p class="meta">Completed by <?= e($editing['completed_by_name'] ?? 'Deleted user') ?> on <?= e(display_record_time($editing['completed_at'], $editing['completed_at_utc'], $editing['completed_timezone'])) ?>. Editing does not change its completion record.</p>
    <?php endif; ?>
    <form method="post" action="tasks.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="<?= $editing !== null ? 'update' : 'add' ?>">
        <?php if ($editing !== null): ?><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>"><?php endif; ?>
        <?php if ($editing !== null): ?><?= edit_revision_field('tasks', $editing) ?><?php endif; ?>
        <label for="title">Title</label>
        <input type="text" id="title" name="title" required maxlength="150" value="<?= e($form['title']) ?>">
        <label for="description">Description (optional)</label>
        <textarea id="description" name="description" maxlength="5000"><?= e($form['description']) ?></textarea>
        <div class="form-grid">
            <?php if (is_admin()): ?>
            <div>
                <label for="task_group">Group</label>
                <select id="task_group" name="group_id" required>
                    <option value="">Choose a group</option>
                    <?php foreach ($groups as $group): ?>
                        <option value="<?= (int)$group['id'] ?>"<?= (string)$group['id'] === $form['group_id'] ? ' selected' : '' ?>><?= e($group['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div>
                <label for="assigned_to">Assigned to (optional)</label>
                <select id="assigned_to" name="assigned_to">
                    <option value="">Unassigned</option>
                    <?php foreach ($members as $member): ?>
                        <option value="<?= (int)$member['id'] ?>" data-group="<?= (int)$member['group_id'] ?>"<?= (string)$member['id'] === $form['assigned_to'] ? ' selected' : '' ?>><?= e(task_user_label($member)) ?><?= is_admin() ? ' — ' . e($member['group_name']) : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="target_date">Target completion date (optional)</label>
                <input type="date" id="target_date" name="target_date" value="<?= e($form['target_date']) ?>">
            </div>
            <div>
                <label for="priority">Priority (optional)</label>
                <select id="priority" name="priority">
                    <?php foreach ($priorities as $value => $label): ?>
                        <option value="<?= e($value) ?>"<?= $form['priority'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <button type="submit"><?= $editing !== null ? 'Save changes' : 'Add task' ?></button>
        <?php if ($editing !== null): ?><a class="button ghost" href="tasks.php">Cancel</a><?php endif; ?>
    </form>
</div>
<div class="card">
    <h2>Task overview</h2>
    <p class="meta">Your open tasks first, then unassigned, then other members’ tasks. In each section: target date first (earliest first), then undated tasks by priority. Completed tasks are newest first.</p>
    <?php if ($tasks === []): ?><p>No tasks yet.</p><?php else: ?>
    <table class="task-table">
        <thead><tr><th>Task</th><th>Assigned to</th><th>Target date</th><th>Priority</th><th>Completion</th><?php if (is_admin()): ?><th>Group</th><?php endif; ?><th>Actions</th></tr></thead>
        <tbody>
        <?php $lastBucket = null; foreach ($tasks as $task): $bucket = task_bucket($task, (int)$user['id']); ?>
            <?php if ($bucket !== $lastBucket): $lastBucket = $bucket; ?>
                <tr class="task-section"><th colspan="<?= is_admin() ? 7 : 6 ?>"><?= e($sectionNames[$bucket]) ?></th></tr>
            <?php endif; ?>
            <tr data-task-id="<?= (int)$task['id'] ?>" class="<?= $task['completed_at'] !== null ? 'task-completed' : '' ?>">
                <td><strong><?= e($task['title']) ?></strong><?php if ($task['description'] !== ''): ?><p class="task-description"><?= nl2br(e((string)$task['description'])) ?></p><?php endif; ?></td>
                <td><?= e($task['assignee_name'] ?? ($task['assigned_to'] === null ? 'Unassigned' : 'Unavailable member')) ?><?= (int)$task['assigned_to'] === (int)$user['id'] ? ' <span class="badge">You</span>' : '' ?></td>
                <td>
                    <?= e($task['target_date'] ?? 'Not planned') ?>
                    <?php if ($task['completed_at'] === null && $task['target_date'] !== null && $task['target_date'] < app_now()->format('Y-m-d')): ?><span class="badge badge-warn">Overdue</span><?php endif; ?>
                </td>
                <td><span class="badge priority-<?= e($task['priority']) ?>"><?= e($priorities[$task['priority']]) ?></span></td>
                <td><?php if ($task['completed_at'] !== null): ?><?= e(display_record_time($task['completed_at'], $task['completed_at_utc'], $task['completed_timezone'])) ?><br><span class="meta">By <?= e($task['completed_by_name'] ?? 'Deleted user') ?></span><?php else: ?>Open<?php endif; ?></td>
                <?php if (is_admin()): ?><td><?= e($task['group_name']) ?></td><?php endif; ?>
                <td class="task-actions">
                    <a class="button ghost" href="tasks.php?edit=<?= (int)$task['id'] ?>">Edit / reassign</a>
                    <form class="inline" method="post" action="tasks.php">
                        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$task['id'] ?>">
                        <input type="hidden" name="action" value="<?= $task['completed_at'] === null ? 'complete' : 'reopen' ?>">
                        <button type="submit"><?= $task['completed_at'] === null ? 'Complete' : 'Reopen' ?></button>
                    </form>
                    <form class="inline" method="post" action="tasks.php" data-confirm="Remove this task?">
                        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$task['id'] ?>">
                        <input type="hidden" name="action" value="delete"><button type="submit" class="danger">Remove</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
    <?php render_pager($taskPage); ?>
</div>
<script src="tasks.js" defer></script>
<?php layout_end(); ?>
