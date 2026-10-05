<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!mysql_ok() || !installed()) {
    echo json_encode(['ok' => false]);
    exit;
}

$settings = settings();
$bounds = event_bounds($settings);

echo json_encode([
    'ok' => true,
    'phase' => phase_of($settings),
    'regStart' => ms_of($bounds['reg']),
    'voteStart' => ms_of($bounds['vote']),
    'serverNow' => ms_of(app_now()),
]);
