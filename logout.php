<?php
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}
csrf_check();

$user = current_user();
if ($user !== null) {
    try {
        log_event((int)$user['id'], 'logout', $user['username']);
    } catch (PDOException $error) {
        report_error($error, 'logout_audit');
    }
}

auth_clear_session('You have been logged out.');
header('Location: login.php');
exit;
