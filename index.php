<?php

require_once __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require_once __DIR__ . '/task_helpers.php';
require_once __DIR__ . '/group_helpers.php';

require_login();

$user = current_user();

$stmt = db()->prepare('SELECT username, first_name, role, created_at, last_login_at FROM users WHERE id = ?');
$stmt->execute([(int)$user['id']]);
$profile = $stmt->fetch();
$welcomeName = trim((string)($profile['first_name'] ?? ''));
if ($welcomeName === '') {
    $welcomeName = $user['username'];
}
$welcomeGroup = false;
if (user_group_id() !== null) {
    $stmt = db()->prepare('SELECT name, logo_path FROM `groups` WHERE id = ?');
    $stmt->execute([user_group_id()]);
    $welcomeGroup = $stmt->fetch();
}

$gid = is_admin() ? null : (int)user_group_id();
$gidSql = $gid === null ? '' : ' group_id = ' . $gid;

$apiaries = (int)db()->query('SELECT COUNT(*) FROM apiaries' . ($gidSql === '' ? '' : ' WHERE ' . $gidSql))->fetchColumn();
$hives = (int)db()->query('SELECT COUNT(*) FROM hives' . ($gidSql === '' ? '' : ' WHERE ' . $gidSql))->fetchColumn();
$hivesInStorage = (int)db()->query(
    'SELECT COUNT(*) FROM hives WHERE apiary_id IS NULL' . ($gidSql === '' ? '' : ' AND ' . $gidSql)
)->fetchColumn();
$colonies = (int)db()->query('SELECT COUNT(*) FROM colonies WHERE archived_at IS NULL' . ($gidSql === '' ? '' : ' AND ' . $gidSql))->fetchColumn();
$queenless = (int)db()->query(
    'SELECT COUNT(*) FROM colonies WHERE queen_id IS NULL AND archived_at IS NULL' . ($gidSql === '' ? '' : ' AND ' . $gidSql)
)->fetchColumn();
$hivesOccupied = (int)db()->query(
    'SELECT COUNT(DISTINCT c.hive_id) FROM colonies c JOIN hives h ON h.id = c.hive_id'
    . ' WHERE c.archived_at IS NULL' . ($gid === null ? '' : ' AND h.group_id = ' . $gid)
)->fetchColumn();

$activity = db()->prepare(
    "SELECT event, detail, ip, created_at FROM activity_log
     WHERE user_id = ? ORDER BY id DESC LIMIT 5"
);
$activity->execute([(int)$user['id']]);
$recentActivity = $activity->fetchAll();

$latestInspection = db()->query('SELECT i.*, c.name AS colony_name, c.archived_at AS colony_archived_at,
    h.name AS hive_name, a.name AS apiary_name, u.username, u.first_name, u.last_name
    FROM inspections i JOIN colonies c ON c.id = i.colony_id
    JOIN hives h ON h.id = c.hive_id AND h.group_id <=> c.group_id LEFT JOIN apiaries a ON a.id = h.apiary_id AND a.group_id <=> c.group_id
    LEFT JOIN users u ON u.id = i.user_id' . group_sql('c') . '
    ORDER BY i.visited_at DESC, i.id DESC LIMIT 1')->fetch();
$inspectionOptions = [
    'strength' => ['Strength', inspection_strength_options()],
    'temperament' => ['Temperament', inspection_temperament_options()],
    'laying_pattern' => ['Laying pattern', inspection_laying_pattern_options()],
    'population' => ['Population', inspection_population_options()],
];
$taskStats = db()->prepare('SELECT
    COUNT(CASE WHEN t.completed_at IS NULL THEN 1 END) AS open_count,
    COUNT(CASE WHEN t.completed_at IS NOT NULL THEN 1 END) AS completed_count,
    COUNT(CASE WHEN t.completed_at IS NULL AND t.assigned_to = ? THEN 1 END) AS mine_count,
    COUNT(CASE WHEN t.completed_at IS NULL AND t.assigned_to IS NULL THEN 1 END) AS unassigned_count
    FROM tasks t' . group_sql('t'));
$taskStats->execute([(int)$user['id']]);
$taskCounts = $taskStats->fetch();

layout_start('Dashboard', 'dashboard');
?>
    <div class="card welcome-panel<?= $welcomeGroup !== false ? ' has-group-logo' : '' ?>" id="dashboard-welcome">
        <div class="welcome-copy">
        <h1 class="panel-heading"><?= app_icon('dashboard') ?><span>Welkom <?= e($welcomeName) ?> to Bij-Houden</span></h1>
        <p>Your dashboard for keeping track of your apiaries is up and running.</p>
        <?php if (!is_admin()): ?>
            <p class="meta">You see everything belonging to your group.</p>
        <?php endif; ?>
        <p class="meta">Last login:
            <?= $profile !== false && $profile['last_login_at'] !== null
                ? e($profile['last_login_at'])
                : 'first session' ?>
        </p>
        </div>
        <?php if ($welcomeGroup !== false): ?>
            <div class="welcome-group-logo" role="img" aria-label="<?= e($welcomeGroup['name']) ?> group logo" title="<?= e($welcomeGroup['name']) ?>">
                <?= group_avatar($welcomeGroup, 'welcome-group-avatar') ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="card" id="latest-inspection">
        <h1 class="panel-heading"><?= app_icon('inspections') ?><span>Last inspection</span></h1>
        <?php if ($latestInspection === false): ?>
            <p class="meta">No inspections recorded yet.</p>
            <p><a href="inspections.php">Log an inspection</a></p>
        <?php else: ?>
            <h2><?= e($latestInspection['colony_name']) ?><?= $latestInspection['colony_archived_at'] !== null ? ' (archived)' : '' ?></h2>
            <p class="meta"><?= e($latestInspection['visited_at']) ?> · By <?= e($latestInspection['username'] === null ? 'Deleted user' : task_user_label($latestInspection)) ?></p>
            <p>Hive: <?= e($latestInspection['hive_name']) ?> · Apiary: <?= e($latestInspection['apiary_name'] ?? 'In storage') ?></p>
            <p>Status: <strong><?= e(inspection_statuses()[$latestInspection['status']] ?? $latestInspection['status']) ?></strong></p>
            <dl class="inspection-summary">
                <?php foreach ($inspectionOptions as $key => [$label, $options]): ?>
                    <div><dt><?= e($label) ?></dt><dd><?= e($options[$latestInspection[$key]] ?? 'Not assessed') ?></dd></div>
                <?php endforeach; ?>
                <div><dt>Supers</dt><dd><?= $latestInspection['supers'] === null ? 'Not assessed' : (int)$latestInspection['supers'] ?></dd></div>
            </dl>
            <p><?= inspection_flag_badges($latestInspection) ?: '<span class="meta">No observations checked</span>' ?></p>
            <?php if ($latestInspection['action'] !== null): ?><p><strong>Action:</strong> <?= e($latestInspection['action']) ?></p><?php endif; ?>
            <?php if (!empty($latestInspection['notes'])): ?><p class="inspection-preview-notes"><?= nl2br(e(mb_substr($latestInspection['notes'], 0, 300))) ?><?= mb_strlen($latestInspection['notes']) > 300 ? '…' : '' ?></p><?php endif; ?>
            <p><a href="inspections.php?colony=<?= (int)$latestInspection['colony_id'] ?>#inspection-<?= (int)$latestInspection['id'] ?>">View full inspection and colony history</a></p>
        <?php endif; ?>
    </div>

    <div class="card">
        <h1 class="panel-heading"><?= app_icon('apiaries') ?><span>Apiaries</span></h1>
        <p><strong><?= $apiaries ?></strong> <?= $apiaries === 1 ? 'apiary' : 'apiaries' ?> registered.</p>
        <p class="meta">Manage your apiaries on the <a href="apiaries.php">Apiaries</a> page.</p>
    </div>

    <div class="card">
        <h1 class="panel-heading"><?= app_icon('hives') ?><span>Hives</span></h1>
        <p><strong><?= $hives ?></strong> hive<?= $hives === 1 ? '' : 's' ?> in total,
        <strong><?= $hivesOccupied ?></strong> occupied, <strong><?= $hivesInStorage ?></strong> in storage.</p>
        <p class="meta">Manage your hives on the <a href="hives.php">Hives</a> page.</p>
    </div>

    <div class="card">
        <h1 class="panel-heading"><?= app_icon('colonies') ?><span>Colonies</span></h1>
        <p><strong><?= $colonies ?></strong> colon<?= $colonies === 1 ? 'y' : 'ies' ?> in total,
        <strong class="<?= $queenless > 0 ? 'text-danger' : '' ?>"><?= $queenless ?></strong> without a queen.</p>
        <p class="meta">Manage your colonies on the <a href="colonies.php">Colonies</a> page.</p>
    </div>

    <div class="card" id="dashboard-tasks">
        <h1 class="panel-heading"><?= app_icon('tasks') ?><span>Tasks</span></h1>
        <p><strong><?= (int)$taskCounts['open_count'] ?></strong> open / <strong><?= (int)$taskCounts['completed_count'] ?></strong> completed</p>
        <p><strong><?= (int)$taskCounts['mine_count'] ?></strong> assigned to you · <strong><?= (int)$taskCounts['unassigned_count'] ?></strong> unassigned</p>
        <p><a href="tasks.php">View and manage tasks</a></p>
    </div>

    <div class="card">
        <h1 class="panel-heading"><?= app_icon('activity') ?><span>Recent activity</span></h1>
        <?php if ($recentActivity === []): ?>
            <p class="meta">Nothing logged yet.</p>
        <?php else: ?>
            <ul class="activity">
                <?php foreach ($recentActivity as $entry): ?>
                    <li>
                        <strong><?= e($entry['event']) ?></strong>
                        <?= $entry['detail'] !== null ? '&mdash; ' . e($entry['detail']) : '' ?>
                        <span class="meta"><?= e($entry['created_at']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
<?php
layout_end();
