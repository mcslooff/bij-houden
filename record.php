<?php

require_once __DIR__ . '/layout.php';
require_login();

$type = (string)($_GET['type'] ?? '');
$id = (int)($_GET['id'] ?? 0);
if (!in_array($type, ['colonies', 'queens'], true)) {
    http_response_code(404);
    layout_start('Record not found');
    echo '<div class="card"><h1>Record not found</h1><p>This record is not available in your group.</p></div>';
    layout_end();
    exit;
}
$stmt = db()->prepare("SELECT r.*, g.name AS group_name FROM `$type` r LEFT JOIN `groups` g ON g.id = r.group_id WHERE r.id = ?" . group_sql('r', ' AND '));
$stmt->execute([$id]);
$record = $stmt->fetch();
if ($record === false) {
    http_response_code(404);
    layout_start('Record not found');
    echo '<div class="card"><h1>Record not found</h1><p>This record is not available in your group.</p></div>';
    layout_end();
    exit;
}
$labels = $type === 'colonies'
    ? ['name' => 'Name', 'date_of_birth' => 'Date of birth', 'notes' => 'Notes']
    : ['name' => 'Name / line', 'hatching_date' => 'Hatching date', 'state' => 'State', 'notes' => 'Notes'];
$labels += ['group_name' => 'Group', 'created_at' => 'Created', 'archived_at' => 'Archived'];
layout_start($type === 'colonies' ? 'Colony details' : 'Queen details', $type);
?>
<div class="card">
    <h1><?= e($record['name'] ?? 'Unnamed queen') ?></h1>
    <?php if ($record['archived_at'] !== null): ?><p class="badge">Archived — read-only</p><?php endif; ?>
    <dl class="inspection-summary">
        <?php foreach ($labels as $key => $label): ?>
            <?php $value = $key === 'archived_at' && $record[$key] !== null
                ? display_record_time($record[$key], null, $record['archived_timezone'])
                : ($key === 'state' ? (queen_states()[$record[$key]] ?? $record[$key]) : ($record[$key] ?? '—')); ?>
            <div><dt><?= e($label) ?></dt><dd><?= nl2br(e((string)$value)) ?></dd></div>
        <?php endforeach; ?>
    </dl>
    <?php if ($type === 'colonies'): ?>
        <?php
        $stmt = db()->prepare('SELECT h.name AS hive_name, a.name AS apiary_name, q.name AS queen_name, q.id AS queen_id
            FROM colonies c JOIN hives h ON h.id = c.hive_id
            LEFT JOIN apiaries a ON a.id = h.apiary_id AND a.group_id <=> c.group_id
            LEFT JOIN queens q ON q.id = c.queen_id AND q.group_id <=> c.group_id
            WHERE c.id = ? AND c.group_id <=> ? AND h.group_id <=> c.group_id' . group_sql('c', ' AND '));
        $stmt->execute([$id, $record['group_id']]);
        $links = $stmt->fetch();
        ?>
        <?php if ($links !== false): ?>
        <p>Hive: <?= e($links['hive_name']) ?> · Apiary: <?= e($links['apiary_name'] ?? 'In storage') ?></p>
        <p>Queen: <?php if ($links['queen_id'] !== null): ?><a href="record.php?type=queens&id=<?= (int)$links['queen_id'] ?>"><?= e($links['queen_name'] ?? 'Unnamed queen') ?></a><?php else: ?>No queen<?php endif; ?></p>
        <p><a href="inspections.php?colony=<?= $id ?>">View inspection history</a></p>
        <?php else: ?><p>Linked records changed. Please reload.</p><?php endif; ?>
    <?php else: ?>
        <?php
        $stmt = db()->prepare('SELECT c.id, c.name, c.archived_at FROM colonies c WHERE c.queen_id = ?' . group_sql('c', ' AND ') . ' ORDER BY c.name');
        $stmt->execute([$id]);
        ?>
        <h2>Linked colonies</h2>
        <?php foreach ($stmt->fetchAll() as $row): ?>
            <p><a href="record.php?type=colonies&id=<?= (int)$row['id'] ?>"><?= e($row['name']) ?></a><?= $row['archived_at'] !== null ? ' (archived)' : '' ?></p>
        <?php endforeach; ?>
    <?php endif; ?>
    <p><a href="<?= e($type) ?>.php<?= $record['archived_at'] !== null ? '?archived=1' : '' ?>">Back to list</a></p>
</div>
<?php layout_end(); ?>
