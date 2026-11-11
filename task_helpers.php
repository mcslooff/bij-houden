<?php

require_once __DIR__ . '/db.php';

function task_priorities(): array
{
    return ['none' => 'No priority', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low'];
}

function task_user_label(array $user): string
{
    $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
    return $name === '' ? $user['username'] : $name . ' (' . $user['username'] . ')';
}

function accessible_task(int $id, bool $lock = false): array|false
{
    $stmt = db()->prepare('SELECT t.* FROM tasks t WHERE t.id = ?' . group_sql('t', ' AND ')
        . ($lock ? ' FOR UPDATE' : ''));
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function task_bucket(array $task, int $userId): int
{
    if ($task['completed_at'] !== null) {
        return 3;
    }
    if ($task['assigned_to'] === null) {
        return 1;
    }
    return (int)$task['assigned_to'] === $userId ? 0 : 2;
}

function task_order_sql(int $userId): string
{
    return ' ORDER BY
        CASE WHEN t.completed_at IS NOT NULL THEN 3
             WHEN t.assigned_to = ' . $userId . ' THEN 0
             WHEN t.assigned_to IS NULL THEN 1 ELSE 2 END,
        CASE WHEN t.completed_at IS NOT NULL THEN t.completed_at END DESC,
        CASE WHEN t.completed_at IS NULL THEN t.target_date IS NULL ELSE 0 END,
        CASE WHEN t.completed_at IS NULL THEN t.target_date END,
        CASE WHEN t.completed_at IS NULL THEN
            CASE t.priority WHEN \'high\' THEN 0 WHEN \'medium\' THEN 1 WHEN \'low\' THEN 2 ELSE 3 END END,
        CASE WHEN t.completed_at IS NOT NULL THEN t.id END DESC, t.id';
}
