<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/db.php';
$options = getopt('', ['apply', 'help']);
if (isset($options['help'])) {
    print "Usage: php bin/normalize-images.php [--apply]\nChecks existing image references. --apply re-encodes legacy images transactionally and queues retired files.\n";
    exit;
}
$counts = ['normalized' => 0, 'legacy' => 0, 'missing' => 0, 'failed' => 0];
try {
    foreach (['users' => ['photo_path', 'avatar'], 'groups' => ['logo_path', 'logo']] as $table => [$column, $kind]) {
        $rows = db()->query("SELECT id, `$column` AS path FROM `$table` WHERE `$column` IS NOT NULL ORDER BY id")->fetchAll();
        foreach ($rows as $row) {
            if (managed_image_kind($row['path']) !== $kind) { $counts['failed']++; continue; }
            $source = managed_image_file($row['path']);
            if ($source === null) { $counts['missing']++; continue; }
            if (normalized_image_path($row['path'])) { $counts['normalized']++; continue; }
            $counts['legacy']++;
            if (!isset($options['apply'])) continue;
            $newPath = null;
            try {
                db()->beginTransaction();
                $stmt = db()->prepare("SELECT `$column` FROM `$table` WHERE id = ? FOR UPDATE");
                $stmt->execute([(int)$row['id']]);
                if ($stmt->fetchColumn() !== $row['path']) { db()->rollBack(); continue; }
                $newPath = stage_normalized_image($source, $kind, (int)$row['id']);
                db()->prepare("UPDATE `$table` SET `$column` = ? WHERE id = ? AND `$column` = ?")
                    ->execute([$newPath, (int)$row['id'], $row['path']]);
                image_enqueue_cleanup($row['path']);
                log_event(null, 'image_normalized', $table . ' #' . $row['id']);
                db()->commit();
                cleanup_image($newPath);
                cleanup_image($row['path']);
                $counts['normalized']++;
            } catch (Throwable $error) {
                if (db()->inTransaction()) db()->rollBack();
                if ($newPath !== null) cleanup_image($newPath);
                report_error($error, 'legacy_image_normalization');
                $counts['failed']++;
            }
        }
    }
    print json_encode($counts, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
    exit($counts['failed'] > 0 || $counts['missing'] > 0 ? 1 : 0);
} catch (Throwable $error) {
    if (db()->inTransaction()) db()->rollBack();
    report_error($error, 'image_normalization_command');
    fwrite(STDERR, "Image normalization failed. Existing references have been retained. Check private diagnostics.\n");
    exit(2);
}
