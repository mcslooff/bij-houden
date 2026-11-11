<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/db.php';
$options = getopt('', ['orphans', 'help']);
if (isset($options['help'])) {
    print "Usage: php bin/cleanup-images.php [--orphans]\nProcesses retired files; --orphans also checks unreferenced images older than 24 hours. Active file leases are skipped.\n";
    exit;
}
try {
    $counts = image_cleanup_batch(500, isset($options['orphans']));
    print json_encode($counts, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
    exit($counts['deferred'] > 0 ? 1 : 0);
} catch (Throwable $error) {
    report_error($error, 'image_cleanup_command');
    fwrite(STDERR, "Image cleanup failed. Check private diagnostics and installed migrations.\n");
    exit(2);
}
