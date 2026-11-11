<?php

declare(strict_types=1);

function managed_image_kind(?string $path): ?string
{
    if ($path !== null && preg_match('~^uploads/avatars/u[1-9][0-9]*-(?:[a-f0-9]{12}|v1-[a-f0-9]{32})\.(jpg|png|webp|gif)$~D', $path)) return 'avatar';
    if ($path !== null && preg_match('~^uploads/group-logos/(?:[a-f0-9]{32}|v1-[a-f0-9]{32})\.(jpg|png|webp|gif)$~D', $path)) return 'logo';
    return null;
}

function normalized_image_path(string $path): bool
{
    return managed_image_kind($path) !== null && preg_match('~/v1-[a-f0-9]{32}\.(jpg|png)$|/u[1-9][0-9]*-v1-[a-f0-9]{32}\.(jpg|png)$~D', $path) === 1;
}

function managed_image_file(?string $path): ?string
{
    if (managed_image_kind($path) === null) return null;
    $root = app_config()['image_storage_path'];
    $file = $root . substr($path, strlen('uploads'));
    return is_file($file) && realpath($file) === $file ? $file : null;
}

function image_storage_lock(bool $wait = true)
{
    $root = app_config()['image_storage_path'];
    $runtimeOwner = fileowner(is_dir($root) ? $root : dirname($root));
    foreach ([$root, $root . '/avatars', $root . '/group-logos'] as $directory) {
        if (!is_dir($directory) && !@mkdir($directory, 0700) && !is_dir($directory)) {
            throw new RuntimeException('Image storage is unavailable.');
        }
        if (realpath($directory) !== $directory || !@chmod($directory, 0700)) {
            throw new RuntimeException('Image storage permissions are unavailable.');
        }
        if (function_exists('posix_geteuid') && posix_geteuid() === 0 && $runtimeOwner !== false) @chown($directory, $runtimeOwner);
    }
    if (is_link($root . '/.image-storage.lock')) throw new RuntimeException('Unsafe image storage lock.');
    $handle = @fopen($root . '/.image-storage.lock', 'c+b');
    if (is_resource($handle) && function_exists('posix_geteuid') && posix_geteuid() === 0 && $runtimeOwner !== false) {
        @chown($root . '/.image-storage.lock', $runtimeOwner);
    }
    if ($handle === false || !@chmod($root . '/.image-storage.lock', 0600)) {
        if (is_resource($handle)) fclose($handle);
        throw new RuntimeException('Image storage locking is unavailable.');
    }
    $deadline = microtime(true) + 2;
    do {
        if (flock($handle, LOCK_EX | LOCK_NB)) return $handle;
        if (!$wait) { fclose($handle); return false; }
        usleep(10000);
    } while (microtime(true) < $deadline);
    fclose($handle);
    throw new RuntimeException('Image storage is busy.');
}

function image_storage_usage(): array
{
    $bytes = 0;
    $files = 0;
    $root = app_config()['image_storage_path'];
    foreach (['avatars', 'group-logos'] as $directory) {
        if (!is_dir($root . '/' . $directory)) continue;
        foreach (new FilesystemIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS) as $file) {
            if ($file->isFile() && !$file->isLink()) { $bytes += $file->getSize(); $files++; }
        }
    }
    return ['bytes' => $bytes, 'files' => $files];
}

function image_is_referenced(string $path): bool
{
    $stmt = db()->prepare('SELECT EXISTS(SELECT 1 FROM users WHERE photo_path = ?) OR EXISTS(SELECT 1 FROM `groups` WHERE logo_path = ?)');
    $stmt->execute([$path, $path]);
    return (bool)$stmt->fetchColumn();
}

function image_enqueue_cleanup(?string $path): void
{
    if (managed_image_kind($path) === null) return;
    if (!db()->inTransaction()) throw new LogicException('Image retirement must be queued with its metadata transaction.');
    db()->prepare('INSERT IGNORE INTO image_cleanup_queue (path) VALUES (?)')->execute([$path]);
    $GLOBALS['retired_images'][$path] = true;
    static $registered = false;
    if (!$registered) {
        $registered = true;
        register_shutdown_function(function (): void {
            foreach (array_keys($GLOBALS['retired_images'] ?? []) as $retired) cleanup_image($retired);
        });
    }
}

function release_pending_image(string $path): void
{
    $handle = $GLOBALS['pending_images'][$path] ?? null;
    if (is_resource($handle)) {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
    unset($GLOBALS['pending_images'][$path]);
}

function cleanup_image(?string $path): bool
{
    if ($path === null) return true;
    if (managed_image_kind($path) === null) {
        report_error(new RuntimeException(''), 'invalid_image_cleanup_path');
        try {
            db()->prepare('UPDATE image_cleanup_queue SET attempts = attempts + 1, last_attempt = NOW() WHERE path = ?')->execute([$path]);
        } catch (Throwable $error) {
            report_error($error, 'image_cleanup_retry');
        }
        return false;
    }
    if (db()->inTransaction()) return false;
    release_pending_image($path);
    $lock = null;
    try {
        $lock = image_storage_lock(false);
        if ($lock === false) {
            db()->prepare('INSERT IGNORE INTO image_cleanup_queue (path) VALUES (?)')->execute([$path]);
            return false;
        }
        if (!image_is_referenced($path)) {
            $file = managed_image_file($path);
            if ($file !== null) {
                $handle = @fopen($file, 'rb');
                if ($handle === false) throw new RuntimeException('Retired image could not be opened.');
                try {
                    if (!flock($handle, LOCK_EX | LOCK_NB)) return false;
                    if (!@unlink($file)) throw new RuntimeException('Retired image could not be removed.');
                } finally {
                    fclose($handle);
                }
            }
        }
        db()->prepare('DELETE FROM image_cleanup_queue WHERE path = ?')->execute([$path]);
        unset($GLOBALS['retired_images'][$path]);
        return true;
    } catch (Throwable $error) {
        report_error($error, 'image_cleanup');
        try {
            db()->prepare('INSERT INTO image_cleanup_queue (path, attempts, last_attempt) VALUES (?, 1, NOW())
                ON DUPLICATE KEY UPDATE attempts = attempts + 1, last_attempt = NOW()')->execute([$path]);
        } catch (Throwable $queueError) {
            report_error($queueError, 'image_cleanup_retry');
        }
        return false;
    } finally {
        if (is_resource($lock)) fclose($lock);
    }
}

function register_pending_image(string $path, $handle): void
{
    $GLOBALS['pending_images'][$path] = $handle;
    static $registered = false;
    if ($registered) return;
    $registered = true;
    // Registered after the request transaction guard: commit/rollback happens first.
    register_shutdown_function(function (): void {
        $connection = $GLOBALS['db_connection'] ?? null;
        try {
            if ($connection instanceof PDO && $connection->inTransaction()) $connection->rollBack();
            foreach (array_keys($GLOBALS['pending_images'] ?? []) as $path) cleanup_image($path);
        } catch (Throwable $error) {
            // Preserve files when database state cannot be confirmed; orphan cleanup can retry later.
            report_error($error, 'image_shutdown');
        } finally {
            foreach (array_keys($GLOBALS['pending_images'] ?? []) as $path) release_pending_image($path);
        }
    });
}

function strip_jpeg_metadata(string $bytes): string
{
    if (!str_starts_with($bytes, "\xff\xd8")) throw new RuntimeException('JPEG encoding failed.');
    $out = substr($bytes, 0, 2);
    $offset = 2;
    while ($offset + 3 < strlen($bytes)) {
        $start = $offset;
        if ($bytes[$offset] !== "\xff") throw new RuntimeException('JPEG marker encoding failed.');
        while ($bytes[$offset] === "\xff") $offset++;
        $marker = ord($bytes[$offset++]);
        if ($marker === 0xda || $marker === 0xd9) return $out . substr($bytes, $start);
        $length = unpack('n', substr($bytes, $offset, 2))[1];
        if ($length < 2 || $offset + $length > strlen($bytes)) throw new RuntimeException('JPEG segment encoding failed.');
        if ($marker !== 0xfe && !($marker >= 0xe1 && $marker <= 0xef)) {
            $out .= substr($bytes, $start, $offset + $length - $start);
        }
        $offset += $length;
    }
    throw new RuntimeException('JPEG scan encoding failed.');
}

function normalize_image_bytes(string $source, string $kind): array
{
    if (!extension_loaded('gd')) throw new RuntimeException('PHP GD is required for image processing.');
    if (!in_array($kind, ['avatar', 'logo'], true)) throw new LogicException('Invalid image kind.');
    $size = filesize($source);
    if ($size === false || $size < 1 || $size > 2097152) {
        throw new InvalidArgumentException('Image is too large or empty. Maximum size is 2 MB.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($source);
    $dimensions = @getimagesize($source);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)
        || $dimensions === false || $dimensions['mime'] !== $mime) {
        throw new InvalidArgumentException('Choose a valid JPEG, PNG, WebP, or GIF image.');
    }
    [$width, $height] = $dimensions;
    $config = app_config();
    if ($width < 1 || $height < 1 || $width > $config['image_max_dimension'] || $height > $config['image_max_dimension']
        || $width * $height > $config['image_max_pixels']) {
        throw new InvalidArgumentException('Image dimensions are too large. Resize it before uploading (maximum '
            . $config['image_max_dimension'] . ' pixels per side and ' . $config['image_max_pixels'] . ' total pixels).');
    }
    $maximum = $kind === 'avatar' ? 512 : 1600;
    $scale = min(1, $maximum / $width, $maximum / $height);
    $estimated = $width * $height * 8 + (int)($width * $scale) * (int)($height * $scale) * 4 + $size * 4 + 8388608;
    $memory = ini_get('memory_limit');
    $limit = $memory === '-1' ? -1 : ini_parse_quantity($memory);
    if ($limit > 0 && memory_get_usage(true) + $estimated > $limit) {
        throw new InvalidArgumentException('Image is too large to process within the available memory. Resize it first.');
    }
    $image = @imagecreatefromstring(file_get_contents($source));
    if ($image === false) throw new InvalidArgumentException('Choose a valid JPEG, PNG, WebP, or GIF image.');
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($source, 'IFD0', true, false);
        $orientation = (int)($exif['IFD0']['Orientation'] ?? 1);
        if (in_array($orientation, [2, 5, 7], true)) imageflip($image, IMG_FLIP_HORIZONTAL);
        if ($orientation === 4) imageflip($image, IMG_FLIP_VERTICAL);
        $rotation = match ($orientation) {3 => 180, 5, 8 => 90, 6, 7 => -90, default => 0};
        if ($rotation !== 0) {
            $rotated = imagerotate($image, $rotation, 0);
            if ($rotated === false) throw new RuntimeException('Image orientation could not be normalized.');
            $image = $rotated;
        }
    }
    $width = imagesx($image); $height = imagesy($image);
    $scale = min(1, $maximum / $width, $maximum / $height);
    $target = imagecreatetruecolor(max(1, (int)round($width * $scale)), max(1, (int)round($height * $scale)));
    if ($target === false) throw new RuntimeException('Image normalization failed.');
    if ($mime !== 'image/jpeg') {
        imagealphablending($target, false); imagesavealpha($target, true);
        imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
    }
    if (!imagecopyresampled($target, $image, 0, 0, 0, 0, imagesx($target), imagesy($target), $width, $height)) {
        throw new RuntimeException('Image resampling failed.');
    }
    unset($image);
    ob_start();
    try {
        $ok = $mime === 'image/jpeg' ? imagejpeg($target, null, 85) : imagepng($target, null, 6);
        $bytes = ob_get_contents();
    } finally {
        ob_end_clean();
        unset($target);
    }
    if (!$ok || !is_string($bytes) || $bytes === '') throw new RuntimeException('Image encoding failed.');
    if ($mime === 'image/jpeg') $bytes = strip_jpeg_metadata($bytes);
    if (strlen($bytes) > 2097152) throw new InvalidArgumentException('Processed image is too large. Resize it before uploading (maximum 2 MB).');
    return [$bytes, $mime === 'image/jpeg' ? 'jpg' : 'png'];
}

function stage_normalized_image(string $source, string $kind, int $userId = 0): string
{
    if ($kind === 'avatar' && $userId < 1) throw new LogicException('An avatar owner is required.');
    [$bytes, $extension] = normalize_image_bytes($source, $kind);
    $lock = image_storage_lock();
    $handle = null;
    $file = null;
    $registered = false;
    try {
        $usage = image_storage_usage();
        if ($usage['bytes'] + strlen($bytes) > app_config()['image_storage_quota_bytes'] || $usage['files'] >= app_config()['image_storage_max_files']) {
            throw new InvalidArgumentException('Image storage is full. Remove an unused image or ask an administrator.');
        }
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $path = $kind === 'avatar' ? 'uploads/avatars/u' . $userId . '-v1-' . bin2hex(random_bytes(16)) . '.' . $extension
                : 'uploads/group-logos/v1-' . bin2hex(random_bytes(16)) . '.' . $extension;
            $file = app_config()['image_storage_path'] . substr($path, strlen('uploads'));
            $handle = @fopen($file, 'x+b');
            if ($handle !== false) break;
        }
        if (!is_resource($handle) || !@chmod($file, 0600) || !flock($handle, LOCK_EX)) {
            throw new RuntimeException('Image could not be stored.');
        }
        register_pending_image($path, $handle);
        $registered = true;
        if (fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
            throw new RuntimeException('Image could not be stored.');
        }
        clearstatcache(true, $file);
        $handle = null;
        return $path;
    } catch (Throwable $error) {
        if (!$registered && is_resource($handle)) {
            fclose($handle);
            if ($file !== null) @unlink($file);
        }
        throw $error;
    } finally {
        fclose($lock);
    }
}

function stage_image_upload(array $file, string $kind, int $userId = 0): string
{
    if (in_array($file['error'] ?? null, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
        throw new InvalidArgumentException('Image is too large. Maximum size is 2 MB.');
    }
    if (($file['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
        throw new InvalidArgumentException('The image upload failed. Please try again.');
    }
    return stage_normalized_image($file['tmp_name'], $kind, $userId);
}

function image_cleanup_batch(int $limit = 50, bool $orphans = false): array
{
    $counts = ['cleaned' => 0, 'deferred' => 0];
    $rows = db()->query('SELECT path FROM image_cleanup_queue ORDER BY attempts, queued_at LIMIT ' . max(1, min(500, $limit)))->fetchAll();
    foreach ($rows as $row) $counts[cleanup_image($row['path']) ? 'cleaned' : 'deferred']++;
    if ($orphans) {
        $root = app_config()['image_storage_path'];
        foreach (['avatars', 'group-logos'] as $directory) {
            if (!is_dir($root . '/' . $directory)) continue;
            foreach (new FilesystemIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS) as $file) {
                $path = 'uploads/' . $directory . '/' . $file->getFilename();
                if (managed_image_kind($path) !== null && $file->isFile() && !$file->isLink()
                    && $file->getMTime() < time() - 86400 && !image_is_referenced($path)) {
                    $counts[cleanup_image($path) ? 'cleaned' : 'deferred']++;
                }
            }
        }
    }
    return $counts;
}
