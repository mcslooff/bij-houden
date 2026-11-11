<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/db.php';

$checks = [
    'hive_apiary_missing' => 'SELECT COUNT(*) FROM hives h LEFT JOIN apiaries a ON a.id = h.apiary_id WHERE h.apiary_id IS NOT NULL AND a.id IS NULL',
    'colony_hive_missing' => 'SELECT COUNT(*) FROM colonies c LEFT JOIN hives h ON h.id = c.hive_id WHERE h.id IS NULL',
    'colony_queen_missing' => 'SELECT COUNT(*) FROM colonies c LEFT JOIN queens q ON q.id = c.queen_id WHERE c.queen_id IS NOT NULL AND q.id IS NULL',
    'inspection_colony_missing' => 'SELECT COUNT(*) FROM inspections i LEFT JOIN colonies c ON c.id = i.colony_id WHERE c.id IS NULL',
    'task_assignee_missing' => 'SELECT COUNT(*) FROM tasks t LEFT JOIN users u ON u.id = t.assigned_to WHERE t.assigned_to IS NOT NULL AND u.id IS NULL',
    'hive_apiary_group' => 'SELECT COUNT(*) FROM hives h JOIN apiaries a ON a.id = h.apiary_id WHERE NOT (h.group_id <=> a.group_id)',
    'colony_hive_group' => 'SELECT COUNT(*) FROM colonies c JOIN hives h ON h.id = c.hive_id WHERE NOT (c.group_id <=> h.group_id)',
    'colony_queen_group' => 'SELECT COUNT(*) FROM colonies c JOIN queens q ON q.id = c.queen_id WHERE NOT (c.group_id <=> q.group_id)',
    'active_colony_archived_queen' => 'SELECT COUNT(*) FROM colonies c JOIN queens q ON q.id = c.queen_id WHERE c.archived_at IS NULL AND q.archived_at IS NOT NULL',
    'task_assignee_group' => 'SELECT COUNT(*) FROM tasks t JOIN users u ON u.id = t.assigned_to WHERE NOT (t.group_id <=> u.group_id)',
    'duplicate_active_hive' => 'SELECT COUNT(*) FROM (SELECT hive_id FROM colonies WHERE archived_at IS NULL GROUP BY hive_id HAVING COUNT(*) > 1) d',
    'duplicate_active_queen' => 'SELECT COUNT(*) FROM (SELECT queen_id FROM colonies WHERE archived_at IS NULL AND queen_id IS NOT NULL GROUP BY queen_id HAVING COUNT(*) > 1) d',
];
try {
    $counts = [];
    foreach ($checks as $name => $sql) {
        $counts[$name] = (int)db()->query($sql)->fetchColumn();
    }
    print json_encode($counts, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
    exit(array_sum($counts) === 0 ? 0 : 1);
} catch (Throwable $error) {
    fwrite(STDERR, "Integrity inspection failed. Check the database configuration and installed schema.\n");
    exit(2);
}
