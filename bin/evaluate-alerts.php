<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/locations.php';
require dirname(__DIR__) . '/app/rules.php';
require dirname(__DIR__) . '/app/mailer.php';
require dirname(__DIR__) . '/app/notifier.php';
require dirname(__DIR__) . '/app/evaluator.php';

echo json_encode(evaluate_alert_rules(), JSON_PRETTY_PRINT), PHP_EOL;
