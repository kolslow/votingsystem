<?php
declare(strict_types=1);

$serverName = strtolower((string) ($_SERVER['SERVER_NAME'] ?? ''));
$hosted = $serverName !== '' && $serverName !== 'localhost' && $serverName !== '127.0.0.1';

if ($hosted) {
    define('DB_HOST', 'sql107.epizy.com');
    define('DB_NAME', 'if0_43098100_votingsystem');
    define('DB_USER', 'if0_43098100');
    define('DB_PASS', '7hG5yaqP03');
} else {
    define('DB_HOST', '127.0.0.1');
    define('DB_NAME', 'votingsystem');
    define('DB_USER', 'root');
    define('DB_PASS', '');
}

const APP_TIMEZONE = 'Asia/Manila';
const DEFAULT_ADMIN_PASSWORD = 'admin123';
