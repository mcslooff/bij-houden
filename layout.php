<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/icons.php';

function queen_states(): array
{
    return [
        'unknown' => 'Unknown',
        'capped_cell' => 'Capped cell',
        'emerged' => 'Emerged',
        'mated' => 'Mated',
        'mature' => 'Mature',
        'old' => 'Old',
    ];
}

function inspection_statuses(): array
{
    return [
        'unknown' => 'Unknown',
        'excellent' => 'Excellent',
        'good' => 'Good',
        'watch' => 'Watch closely',
        'attention' => 'Needs attention',
        'critical' => 'Critical',
        'dead' => 'Dead / failed',
    ];
}

function inspection_strength_options(): array
{
    return [
        'not_assessed' => 'Not assessed',
        'unknown' => 'Unknown (legacy)',
        'weak' => 'Weak',
        'medium' => 'Medium',
        'strong' => 'Strong',
    ];
}

function inspection_temperament_options(): array
{
    return [
        'not_assessed' => 'Not assessed',
        'unknown' => 'Unknown (legacy)',
        'calm' => 'Calm',
        'normal' => 'Normal',
        'nervous' => 'Nervous',
        'aggressive' => 'Aggressive',
    ];
}

function inspection_laying_pattern_options(): array
{
    return [
        'not_assessed' => 'Not assessed',
        'none' => 'None',
        'not_uniform' => 'Not uniformly',
        'mostly_uniform' => 'Mostly uniformly',
        'uniform' => 'Uniform',
    ];
}

function inspection_population_options(): array
{
    return [
        'not_assessed' => 'Not assessed',
        'low' => 'Low',
        'medium' => 'Medium',
        'high' => 'High',
    ];
}

function inspection_flags(): array
{
    return [
        'queen_seen' => 'Queen seen',
        'queen_cells' => 'Queen cells',
        'swarmed' => 'Swarmed',
        'eggs' => 'Eggs',
        'capped_brood' => 'Capped brood',
        'uncapped_brood' => 'Uncapped brood',
        'excessive_drones' => 'Excessive drones',
    ];
}

function inspection_flag_badges(array $row): string
{
    $out = '';
    foreach (inspection_flags() as $column => $label) {
        if (!empty($row[$column])) {
            $out .= ($out === '' ? '' : ' ')
                . '<span class="badge' . ($column === 'swarmed' || $column === 'queen_cells' || $column === 'excessive_drones' ? ' badge-warn' : '')
                . '">' . e($label) . '</span>';
        }
    }
    return $out;
}

function user_profile(): array
{
    static $profile = null;
    if ($profile === null) {
        $u = current_user();
        if ($u === null) {
            return ['id' => 0, 'username' => '', 'first_name' => null, 'last_name' => null, 'photo_path' => null];
        }
        $stmt = db()->prepare('SELECT id, username, first_name, last_name, email, photo_path FROM users WHERE id = ?');
        $stmt->execute([(int)$u['id']]);
        $row = $stmt->fetch();
        $profile = $row !== false ? $row : [
            'id' => 0, 'username' => '', 'first_name' => null, 'last_name' => null, 'email' => null, 'photo_path' => null,
        ];
    }
    return $profile;
}

function avatar_tag(string $sizeClass = 'avatar-sidebar'): string
{
    $p = user_profile();
    $initial = mb_strtoupper(mb_substr($p['username'] ?: '?', 0, 1));
    return '<span class="avatar ' . $sizeClass . '">'
        . (managed_image_kind($p['photo_path']) === 'avatar' && managed_image_file($p['photo_path']) !== null
            ? '<img src="' . e($p['photo_path']) . '" alt="">'
            : e($initial))
        . '</span>';
}

function layout_start(string $title, string $active = ''): void
{
    require_login();
    $user = current_user();
    $items = [
        'dashboard' => ['Dashboard', 'index.php'],
        'apiaries' => ['Apiaries', 'apiaries.php'],
        'hives' => ['Hives', 'hives.php'],
        'colonies' => ['Colonies', 'colonies.php'],
        'inspections' => ['Inspections', 'inspections.php'],
        'tasks' => ['Tasks', 'tasks.php'],
        'queens' => ['Queens', 'queens.php'],
    ];
    if (is_admin()) {
        $items['groups'] = ['Groups', 'groups.php'];
        $items['users'] = ['Users', 'users.php'];
    }
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bij-Houden &ndash; <?= e($title) ?></title>
    <script src="sidebar-init.js"></script>
    <link rel="stylesheet" href="style.css">
    <script src="sidebar.js" defer></script>
    <script src="app.js" defer></script>
</head>
<body>
<div class="shell">
    <aside class="sidebar" id="sidebar" aria-label="Application sidebar">
        <div class="sidebar-header">
            <div class="sidebar-brand"><a href="index.php" aria-label="Bij-Houden dashboard" title="Bij-Houden">
                <?= app_icon('colonies') ?><span class="sidebar-label">Bij-Houden</span>
            </a></div>
            <button id="sidebar-toggle" class="sidebar-toggle" type="button" aria-controls="sidebar" aria-expanded="true" aria-label="Collapse sidebar" title="Collapse sidebar" hidden>
                <?= app_icon('collapse') ?>
            </button>
        </div>
        <nav aria-label="Main navigation">
            <?php foreach ($items as $key => [$label, $href]): ?>
                <a class="nav-item <?= $key === $active ? 'active' : '' ?>"
                    href="<?= e($href) ?>" aria-label="<?= e($label) ?>" title="<?= e($label) ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>>
                    <?= app_icon($key) ?><span class="sidebar-label"><?= e($label) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-footer">
            <div class="sidebar-account">
                <?= avatar_tag('avatar-sidebar') ?>
                <div class="meta">Logged in as <strong><?= e($user['username']) ?></strong></div>
            </div>
            <a class="nav-item" href="profile.php" aria-label="Profile" title="Profile"<?= basename($_SERVER['SCRIPT_NAME']) === 'profile.php' ? ' aria-current="page"' : '' ?>>
                <?= app_icon('profile') ?><span class="sidebar-label">Profile</span>
            </a>
            <form class="sidebar-logout" method="post" action="logout.php">
                <?= csrf_field() ?>
                <button class="nav-item logout-button" type="submit" aria-label="Log out" title="Log out">
                    <?= app_icon('logout') ?><span class="sidebar-label">Log out</span>
                </button>
            </form>
        </div>
    </aside>
    <main class="content">
    <?php
    $flash = take_flash();
    if ($flash !== null) {
        echo '<div class="flash ' . e($flash['type']) . '">' . e($flash['message']) . '</div>';
    }
}

function layout_end(): void
{
    ?>
    </main>
</div>
</body>
</html>
    <?php
}
