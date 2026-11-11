<?php

declare(strict_types=1);

require_once __DIR__ . '/configuration.php';
require_once __DIR__ . '/diagnostics.php';
require_once __DIR__ . '/security_headers.php';
send_security_headers();
header('X-Request-ID: ' . request_error_id());

$root = __DIR__;
$encodedPath = explode('?', $_SERVER['REQUEST_URI'] ?? '/', 2)[0];
$path = rawurldecode($encodedPath);

$pages = [
    '/' => 'index.php', '/index.php' => 'index.php', '/login.php' => 'login.php', '/logout.php' => 'logout.php',
    '/apiaries.php' => 'apiaries.php', '/hives.php' => 'hives.php', '/colonies.php' => 'colonies.php',
    '/queens.php' => 'queens.php', '/inspections.php' => 'inspections.php', '/tasks.php' => 'tasks.php',
    '/profile.php' => 'profile.php', '/users.php' => 'users.php', '/groups.php' => 'groups.php',
    '/record.php' => 'record.php', '/owner_label.php' => 'owner_label.php',
];
$assets = [
    '/style.css' => 'text/css; charset=UTF-8', '/sidebar.js' => 'text/javascript; charset=UTF-8',
    '/tasks.js' => 'text/javascript; charset=UTF-8', '/owner-label.css' => 'text/css; charset=UTF-8',
    '/owner-label.js' => 'text/javascript; charset=UTF-8',
    '/vendor/qrcode-generator/qrcode.min.js' => 'text/javascript; charset=UTF-8',
    '/sidebar-init.js' => 'text/javascript; charset=UTF-8', '/app.js' => 'text/javascript; charset=UTF-8',
];
$imageTypes = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
if (preg_match('~^/uploads/(?:group-logos/(?:[a-f0-9]{32}|v1-[a-f0-9]{32})|avatars/u[1-9][0-9]*-(?:[a-f0-9]{12}|v1-[a-f0-9]{32}))\.(jpg|png|webp|gif)$~D', $path, $image)) {
    $assets[$path] = $imageTypes[$image[1]];
}

if (isset($pages[$path])) {
    if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST', 'HEAD'], true)) {
        http_response_code(405);
        header('Allow: GET, POST, HEAD');
        exit('Method not allowed.');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($pages[$path], ['index.php', 'record.php', 'owner_label.php'], true)) {
        http_response_code(405);
        header('Allow: GET, HEAD');
        exit('This page is read-only.');
    }
    $_SERVER['SCRIPT_NAME'] = '/' . $pages[$path];
    $_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
    $_SERVER['SCRIPT_FILENAME'] = $root . '/' . $pages[$path];
    require_once $root . '/configuration.php';
    require_once $root . '/request_validation.php';
    require_once $root . '/mutation_guard.php';
    $level = ob_get_level();
    ob_start();
    try {
        $config = app_config();
        header('Content-Type: text/html; charset=UTF-8');
        if ($config['environment'] === 'production' && in_array(strtolower((string)($_SERVER['HTTPS'] ?? '')), ['on', '1'], true)) {
            header('Strict-Transport-Security: max-age=31536000');
        }
        if ($config['environment'] === 'test') {
            header('X-Bijhouden-Environment: test');
            header('X-Bijhouden-Test-Database: ' . app_database_fingerprint());
            header('X-Bijhouden-Test-Storage: ' . app_storage_fingerprint());
        }
        validate_page_request($pages[$path]);
        begin_request_mutation($pages[$path]);
        require $root . '/' . $pages[$path];
        ob_end_flush();
    } catch (Throwable $error) {
        while (ob_get_level() > $level) {
            ob_end_clean();
        }
        $connection = $GLOBALS['db_connection'] ?? null;
        if ($connection instanceof PDO && $connection->inTransaction()) {
            $connection->rollBack();
        }
        $status = 500;
        $message = 'The application is temporarily unavailable. Please try again later.';
        if ($error instanceof RequestTooLargeException) {
            $status = 413;
            $message = $error->getMessage();
        } elseif ($error instanceof RequestValidationException) {
            $status = 400;
            $message = $error->getMessage();
        } elseif ($error instanceof MutationDeniedException) {
            $status = 403;
            $message = $error->getMessage();
        } elseif ($error instanceof MutationConflictException) {
            $status = 409;
            $message = $error->getMessage();
        } elseif ($error instanceof ConfigurationException) {
            $status = 503;
        } elseif ($error instanceof PDOException && in_array((int)($error->errorInfo[1] ?? 0), [1062, 1451, 1452, 1205, 1213], true)) {
            $status = 409;
            $message = 'The operation conflicts with existing records or another edit. Reload and try again.';
        }
        if ($status === 500 || $status === 503) {
            report_error($error, 'request_failure');
        }
        unset($_SESSION['flash']);
        header_remove('Location');
        http_response_code($status);
        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: no-store');
        print $message;
    }
    exit;
}

if (isset($assets[$path])) {
    if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
        http_response_code(405);
        header('Allow: GET, HEAD');
        exit('Method not allowed.');
    }
    if (isset($image[1])) {
        $handle = null;
        try {
            if (str_starts_with($path, '/uploads/group-logos/')) define('BIJHOUDEN_SESSIONLESS', true);
            require_once $root . '/db.php';
            $logical = ltrim($path, '/');
            $allowed = false;
            if (managed_image_kind($logical) === 'logo') {
                $stmt = db()->prepare('SELECT id FROM `groups` WHERE logo_path = ? LIMIT 1');
                $stmt->execute([$logical]);
                $allowed = $stmt->fetchColumn() !== false;
            } else {
                $viewer = current_user();
                if ($viewer !== null) {
                    $stmt = db()->prepare('SELECT id FROM users WHERE photo_path = ?' . (is_admin() ? '' : ' AND id = ?') . ' LIMIT 1');
                    $stmt->execute(is_admin() ? [$logical] : [$logical, (int)$viewer['id']]);
                    $allowed = $stmt->fetchColumn() !== false;
                }
            }
            $file = $allowed ? managed_image_file($logical) : null;
            if ($file !== null) {
                $handle = @fopen($file, 'rb');
                if ($handle === false || !flock($handle, LOCK_SH | LOCK_NB)) {
                    throw new RuntimeException('Image is being processed.');
                }
            }
            $dimensions = $file === null ? false : @getimagesize($file);
            $config = app_config();
            if ($file !== null && $dimensions !== false && $dimensions['mime'] === $assets[$path]
                && $dimensions[0] <= $config['image_max_dimension'] && $dimensions[1] <= $config['image_max_dimension']
                && $dimensions[0] * $dimensions[1] <= $config['image_max_pixels'] && filesize($file) <= 2097152) {
                $type = $assets[$path];
                if (!normalized_image_path($logical)) {
                    [$bytes, $extension] = normalize_image_bytes($file, managed_image_kind($logical));
                    $type = $extension === 'jpg' ? 'image/jpeg' : 'image/png';
                } else {
                    $bytes = stream_get_contents($handle, 2097153);
                    if ($bytes === false || strlen($bytes) > 2097152) throw new RuntimeException('Image read failed.');
                }
                flock($handle, LOCK_UN); fclose($handle); $handle = null;
                header('Content-Type: ' . $type);
                header('Content-Length: ' . strlen($bytes));
                header('Content-Disposition: inline; filename="' . basename($logical) . '"');
                header('Cache-Control: ' . (managed_image_kind($logical) === 'avatar' ? 'private, no-store' : 'no-cache'));
                if (managed_image_kind($logical) === 'avatar') header('Vary: Cookie');
                if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') {
                    print $bytes;
                }
                exit;
            }
        } catch (Throwable $error) {
            if (is_resource($handle)) fclose($handle);
            if (!($error instanceof InvalidArgumentException)) report_error($error, 'image_serve');
            http_response_code($error instanceof InvalidArgumentException ? 404 : 503);
            header('Content-Type: text/plain; charset=UTF-8');
            header('Cache-Control: no-store');
            exit($error instanceof InvalidArgumentException ? 'Not found.' : 'The image is temporarily unavailable.');
        }
        if (is_resource($handle)) fclose($handle);
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: no-store');
        exit('Not found.');
    }
    $file = realpath($root . $path);
    if ($file !== false && $file === $root . $path && is_file($file)) {
        header('Content-Type: ' . $assets[$path]);
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: no-cache');
        if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') {
            readfile($file);
        }
        exit;
    }
}

http_response_code(404);
header('Content-Type: text/plain; charset=UTF-8');
print 'Not found.';
