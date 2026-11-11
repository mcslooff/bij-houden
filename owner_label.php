<?php

require_once __DIR__ . '/group_helpers.php';

header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    exit('This owner label is read-only.');
}

$token = (string)($_GET['token'] ?? '');
$group = false;
if (preg_match('/^[a-f0-9]{32}$/D', $token)) {
    $stmt = db()->prepare('SELECT name, owner_name, owner_address, owner_phone, owner_email, logo_path
        FROM `groups` WHERE owner_label_token = ?');
    $stmt->execute([$token]);
    $group = $stmt->fetch();
}
if ($group === false) {
    http_response_code(404);
    exit('Owner label not found.');
}
$url = owner_label_absolute_url($token);
$logo = group_logo_path($group['logo_path']);
if (managed_image_file($logo) === null) $logo = null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Hive owner — <?= e($group['name']) ?></title>
    <link rel="stylesheet" href="owner-label.css">
    <script src="vendor/qrcode-generator/qrcode.min.js" defer></script>
    <script src="owner-label.js" defer></script>
</head>
<body>
    <?php if (current_user() !== null): ?>
    <div class="print-toolbar">
        <button id="print-owner-label" type="button" hidden>Print owner label</button>
        <span>Print this label or save it as a PDF.</span>
    </div>
    <?php endif; ?>
    <main class="owner-label">
        <header class="owner-brand">
            <?php if ($logo !== null): ?>
                <img class="owner-logo" src="<?= e($logo) ?>" alt="<?= e($group['name']) ?> logo">
            <?php endif; ?>
            <h1><?= e($group['name']) ?></h1>
        </header>
        <h2>Hive owner / contact</h2>
        <div class="owner-label-content">
            <dl class="owner-contact">
                <div><dt>Name</dt><dd><?= e($group['owner_name'] ?? 'Not provided') ?></dd></div>
                <?php if (!empty($group['owner_address'])): ?>
                    <div><dt>Address</dt><dd><?= nl2br(e($group['owner_address'])) ?></dd></div>
                <?php endif; ?>
                <?php if (!empty($group['owner_phone'])): ?>
                    <div><dt>Phone</dt><dd><a href="tel:<?= e(preg_replace('/[^+0-9*#]/', '', $group['owner_phone'])) ?>"><?= e($group['owner_phone']) ?></a></dd></div>
                <?php endif; ?>
                <?php if (!empty($group['owner_email'])): ?>
                    <div><dt>E-mail</dt><dd><a href="mailto:<?= e($group['owner_email']) ?>"><?= e($group['owner_email']) ?></a></dd></div>
                <?php endif; ?>
            </dl>
            <aside class="owner-qr-block">
                <div id="owner-qr" data-url="<?= e($url) ?>"></div>
                <p>Scan for owner contact details</p>
                <noscript>Enable JavaScript to generate the printable QR code.</noscript>
                <p id="qr-error" hidden>The QR code could not be generated. Reload this page before printing.</p>
            </aside>
        </div>
        <footer><a class="owner-public-url" href="<?= e($url) ?>"><?= e($url) ?></a></footer>
    </main>
</body>
</html>
