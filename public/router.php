<?php

if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit('Not found.');
}
require dirname(__DIR__) . '/web.php';
