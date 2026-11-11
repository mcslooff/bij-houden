<?php

declare(strict_types=1);

class MutationDeniedException extends RuntimeException
{
}

class MutationConflictException extends RuntimeException
{
}

function edit_revision(string $table, array $record): string
{
    // Full row snapshot, not second-resolution updated_at: two edits in the
    // same second must still conflict. Never expose the record or password hash.
    if ($table === 'users') unset($record['last_login_at']);
    ksort($record);
    return hash_hmac('sha256', $table . ':' . json_encode($record, JSON_THROW_ON_ERROR), csrf_token());
}

function edit_revision_field(string $table, array $record): string
{
    $revision = $_SERVER['REQUEST_METHOD'] === 'POST'
        ? (string)($_POST['revision'] ?? '') : edit_revision($table, $record);
    return '<input type="hidden" name="revision" value="' . e($revision) . '">';
}

function mutation_lock(string $name): void
{
    if (!in_array($name, ['unassigned', 'administrators'], true)) {
        throw new LogicException('Invalid consistency lock.');
    }
    $stmt = db()->prepare('SELECT lock_name FROM consistency_locks WHERE lock_name = ? FOR UPDATE');
    $stmt->execute([$name]);
    if ($stmt->fetch() === false) {
        throw new RuntimeException('Consistency migration is required.');
    }
}

function begin_request_mutation(string $page): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || in_array($page, ['login.php', 'logout.php'], true)
        || ($page === 'profile.php' && ($_POST['action'] ?? '') !== 'photo')) {
        return;
    }
    require_once __DIR__ . '/db.php';
    $snapshot = current_user();
    if ($snapshot === null) {
        return;
    }
    csrf_check();
    $administrative = in_array($page, ['users.php', 'groups.php'], true);
    if ($administrative && $snapshot['role'] !== 'admin') {
        throw new MutationDeniedException('Only administrators can access that page.');
    }
    $action = $_POST['action'] ?? '';
    $tables = ['apiaries.php' => 'apiaries', 'hives.php' => 'hives', 'colonies.php' => 'colonies',
        'queens.php' => 'queens', 'tasks.php' => 'tasks'];
    $type = $tables[$page] ?? null;
    db()->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    db()->beginTransaction();
    try {
        if ($page === 'users.php') {
            mutation_lock('administrators');
        }
        $userIds = [(int)$snapshot['id']];
        if ($page === 'users.php' && $action !== 'add') {
            $userIds[] = input_id($_POST, 'id', false);
        }
        if ($page === 'tasks.php' && in_array($action, ['add', 'update'], true) && input_id($_POST, 'assigned_to') !== null) {
            $userIds[] = input_id($_POST, 'assigned_to');
        }
        $userIds = array_unique($userIds);
        sort($userIds, SORT_NUMERIC);
        $users = [];
        foreach ($userIds as $id) {
            $stmt = db()->prepare('SELECT id, username, role, group_id, auth_version FROM users WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if ($row !== false) {
                $users[$id] = $row;
            }
        }
        $actor = $users[(int)$snapshot['id']] ?? null;
        if ($actor === null || (int)$actor['auth_version'] !== (int)$snapshot['auth_version']
            || $actor['role'] !== $snapshot['role'] || !same_group($snapshot['group_id'] === null ? null : (int)$snapshot['group_id'], $actor['group_id'])) {
            auth_clear_session('Your account changed. Please log in again.');
            throw new MutationDeniedException('Your account changed. Please log in again.');
        }
        $admin = $actor['role'] === 'admin';
        $groups = [];
        $records = [];
        if ($type !== null && $action !== 'add') {
            $id = input_id($_POST, 'id', false);
            $stmt = db()->prepare("SELECT id, group_id FROM `$type` WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            $noun = ['apiaries' => 'apiary', 'hives' => 'hive', 'colonies' => 'colony', 'queens' => 'queen', 'tasks' => 'task'][$type];
            if ($row === false || (!$admin && ($actor['group_id'] === null || !same_group((int)$actor['group_id'], $row['group_id'])))) {
                throw new MutationDeniedException('You do not manage that ' . $noun . '.');
            }
            $groups[] = $row['group_id'];
            $records[$type . ':' . $id] = $row;
        }
        if ($type !== null && in_array($action, ['add', 'update'], true)) {
            $groups[] = $admin ? input_id($_POST, 'group_id') : $actor['group_id'];
        }
        if ($page === 'groups.php' && $action !== 'add') {
            $groups[] = input_id($_POST, 'id', false);
        }
        if ($page === 'users.php') {
            if ($action !== 'add') {
                $target = $users[input_id($_POST, 'id', false)] ?? null;
                if ($target === null) {
                    throw new MutationDeniedException('That user does not exist.');
                }
                $groups[] = $target['group_id'];
                $references = ['SELECT DISTINCT group_id FROM tasks WHERE assigned_to = ?'];
                if ($action === 'delete') {
                    $references[] = 'SELECT DISTINCT group_id FROM tasks WHERE created_by = ? OR completed_by = ?';
                    $references[] = 'SELECT DISTINCT c.group_id FROM inspections i JOIN colonies c ON c.id = i.colony_id WHERE i.user_id = ?';
                }
                foreach ($references as $sql) {
                    $stmt = db()->prepare($sql);
                    $targetId = (int)$target['id'];
                    $stmt->execute(substr_count($sql, '?') === 2 ? [$targetId, $targetId] : [$targetId]);
                    foreach ($stmt->fetchAll() as $reference) {
                        $groups[] = $reference['group_id'];
                    }
                }
            }
            if ($action !== 'delete') {
                $groups[] = input_id($_POST, 'group_id');
            }
        }
        if ($page === 'inspections.php') {
            $id = input_id($_POST, 'colony_id', false);
            $stmt = db()->prepare('SELECT id, group_id FROM colonies WHERE id = ?');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if ($row === false || (!$admin && ($actor['group_id'] === null || !same_group((int)$actor['group_id'], $row['group_id'])))) {
                throw new MutationDeniedException('That colony does not exist or is not managed by your group.');
            }
            $groups[] = $row['group_id'];
            $records['colonies:' . $id] = $row;
        }
        if (in_array(null, $groups, true)) {
            mutation_lock('unassigned');
        }
        $groupIds = array_values(array_unique(array_map('intval', array_filter($groups, fn($id) => $id !== null))));
        sort($groupIds, SORT_NUMERIC);
        foreach ($groupIds as $id) {
            $stmt = db()->prepare('SELECT id FROM `groups` WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            if ($stmt->fetch() === false) {
                throw new MutationConflictException('The selected group no longer exists. Reload and try again.');
            }
        }
        if ($page === 'users.php' && isset($references, $targetId)) {
            foreach ($references as $sql) {
                $stmt = db()->prepare($sql);
                $stmt->execute(substr_count($sql, '?') === 2 ? [$targetId, $targetId] : [$targetId]);
                foreach ($stmt->fetchAll() as $reference) {
                    $id = $reference['group_id'];
                    if (($id === null && !in_array(null, $groups, true)) || ($id !== null && !in_array((int)$id, $groupIds, true))) {
                        throw new MutationConflictException('Referenced record ownership changed. Reload and try again.');
                    }
                }
            }
        }
        foreach ($records as $key => $metadata) {
            [$table, $id] = explode(':', $key);
            $sql = "SELECT * FROM `$table` WHERE id = ? AND group_id <=> ?";
            $params = [(int)$id, $metadata['group_id']];
            if (!$admin) {
                $sql .= ' AND group_id = ?';
                $params[] = $actor['group_id'];
            }
            $stmt = db()->prepare($sql . ' FOR UPDATE');
            $stmt->execute($params);
            $record = $stmt->fetch();
            if ($record === false) {
                throw new MutationConflictException('Record ownership changed. Reload and try again.');
            }
            $records[$key] = $record;
            $dependencies = match ($table) {
                'apiaries' => ['hives', 'apiary_id'], 'hives' => ['colonies', 'hive_id'],
                'queens' => ['colonies', 'queen_id'], default => null,
            };
            if ($dependencies !== null) {
                [$child, $foreign] = $dependencies;
                $stmt = db()->prepare("SELECT COUNT(*) FROM `$child` WHERE `$foreign` = ? AND NOT (group_id <=> ?)");
                $stmt->execute([(int)$id, $record['group_id']]);
                if ((int)$stmt->fetchColumn() > 0) {
                    throw new MutationConflictException('Linked records have inconsistent ownership. Resolve it before editing.');
                }
            }
        }
        $inspectionReadOnly = $page === 'inspections.php'
            && ($records['colonies:' . (int)($_POST['colony_id'] ?? 0)]['archived_at'] ?? null) !== null;
        if ($action === 'update' && !$inspectionReadOnly) {
            $revisionTable = $type ?? match ($page) {
                'groups.php' => 'groups', 'users.php' => 'users', 'inspections.php' => 'inspections', default => null,
            };
            if ($revisionTable !== null) {
                $id = input_id($_POST, 'id', false);
                $record = $records[$revisionTable . ':' . $id] ?? null;
                if ($record === null) {
                    // Parent/group and actor locks above serialize these edits.
                    $stmt = db()->prepare("SELECT * FROM `$revisionTable` WHERE id = ? FOR UPDATE");
                    $stmt->execute([$id]);
                    $record = $stmt->fetch();
                }
                if ($record !== false && $revisionTable === 'inspections'
                    && (int)$record['colony_id'] !== input_id($_POST, 'colony_id', false)) {
                    throw new MutationDeniedException('You do not manage that inspection.');
                }
                $revision = $_POST['revision'] ?? '';
                if ($record === false || !is_string($revision) || !hash_equals(edit_revision($revisionTable, $record), $revision)) {
                    throw new MutationConflictException('This record changed since you opened the form. Reload and review the latest values before saving.');
                }
            }
        }
        $GLOBALS['mutation_context'] = ['actor' => $actor, 'records' => $records, 'users' => $users];
    } catch (Throwable $error) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $error;
    }
    register_shutdown_function(function (): void {
        if (!db()->inTransaction()) {
            return;
        }
        $error = error_get_last();
        $fatal = $error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true);
        // Form handlers explicitly commit or redirect after a successful audited write.
        if ($fatal || !in_array(http_response_code(), [302, 303], true)) {
            db()->rollBack();
            return;
        }
        try {
            db()->commit();
        } catch (Throwable $failure) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            if (ob_get_level() > 0) {
                ob_clean();
            }
            header_remove('Location');
            unset($_SESSION['flash']);
            http_response_code(503);
            header('Content-Type: text/plain; charset=UTF-8');
            print 'The operation could not be committed. Please reload and try again.';
            report_error($failure, 'mutation_commit');
        }
    });
}

function mutation_original_group(string $table, int $id): ?int
{
    $record = $GLOBALS['mutation_context']['records'][$table . ':' . $id] ?? null;
    if ($record === null || !db()->inTransaction()) {
        throw new LogicException('A locked mutation context is required.');
    }
    return $record['group_id'] === null ? null : (int)$record['group_id'];
}

function begin_if_needed(): void
{
    if (!db()->inTransaction()) {
        db()->beginTransaction();
    }
}

function release_failed_mutation(array $errors): void
{
    if ($errors !== [] && isset($GLOBALS['mutation_context']) && db()->inTransaction()) {
        db()->rollBack();
    }
}
