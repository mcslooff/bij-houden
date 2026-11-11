<?php
require_once __DIR__ . '/db.php';

if (current_user() !== null) {
    header('Location: index.php');
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $result = attempt_login($_POST['username'] ?? null, $_POST['password'] ?? null);
    if ($result['success']) {
        header('Location: index.php');
        exit;
    }
    $errors[] = $result['error'];
    if ($result['retry_after'] > 0) {
        http_response_code(429);
        header('Retry-After: ' . $result['retry_after']);
    }
}

$flash = take_flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bij-Houden &ndash; Login</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<header class="app-header">
    <span class="brand">Bij-Houden</span>
</header>
<main>
    <div class="card">
        <h1>Login</h1>

        <?php if ($flash !== null): ?>
            <div class="flash <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
        <?php endif; ?>

        <?php foreach ($errors as $error): ?>
            <div class="flash error"><?= e($error) ?></div>
        <?php endforeach; ?>

        <form method="post" action="login.php">
            <?= csrf_field() ?>
            <label for="username">Username</label>
            <input type="text" id="username" name="username" maxlength="50" autocomplete="username" required autofocus>
            <label for="password">Password</label>
            <input type="password" id="password" name="password" maxlength="72" autocomplete="current-password" required>
            <button type="submit">Log in</button>
        </form>

        <p class="meta">Need an account? Ask your administrator.</p>
    </div>
</main>
</body>
</html>
