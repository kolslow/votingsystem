<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';

if (!mysql_ok()) {
    layout_start('Install');
    echo '<section class="card"><h2>MySQL is not running</h2>';
    echo '<p>Start MySQL in the XAMPP control panel, then refresh this page.</p></section>';
    layout_end();
    exit;
}

if (installed()) {
    layout_start('Install');
    echo '<section class="card"><h2>Already installed</h2>';
    echo '<p>The voting database is ready.</p>';
    echo '<a class="btn" href="index.php">Open voting</a>';
    echo '<a class="btn btn-navy" href="admin.php">Open admin</a>';
    echo '</section>';
    layout_end();
    exit;
}

$today = app_now()->format('Y-m-d');
$hash = password_hash(DEFAULT_ADMIN_PASSWORD, PASSWORD_DEFAULT);

$pdo = server_pdo();
$pdo->exec(
    'CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
);
$pdo->exec('USE `' . DB_NAME . '`');

$pdo->exec(
    'CREATE TABLE settings (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        event_date DATE NOT NULL,
        reg_start TIME NOT NULL,
        vote_start TIME NOT NULL,
        vote_end TIME NOT NULL,
        admin_password_hash VARCHAR(255) NOT NULL,
        public_url VARCHAR(255) NOT NULL DEFAULT ''
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

$pdo->exec(
    'CREATE TABLE employees (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(80) NOT NULL,
        department VARCHAR(80) NOT NULL,
        gender ENUM(\'male\', \'female\') NOT NULL,
        code CHAR(6) NOT NULL,
        created_at DATETIME NOT NULL,
        confirmed_at DATETIME NULL DEFAULT NULL,
        UNIQUE KEY uq_employees_code (code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

$pdo->exec(
    'CREATE TABLE votes (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        voter_id INT UNSIGNED NOT NULL,
        male_id INT UNSIGNED NOT NULL,
        female_id INT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY uq_votes_voter (voter_id),
        CONSTRAINT fk_votes_voter FOREIGN KEY (voter_id) REFERENCES employees (id),
        CONSTRAINT fk_votes_male FOREIGN KEY (male_id) REFERENCES employees (id),
        CONSTRAINT fk_votes_female FOREIGN KEY (female_id) REFERENCES employees (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

$insert = $pdo->prepare(
    'INSERT INTO settings (id, event_date, reg_start, vote_start, vote_end, admin_password_hash)
     VALUES (1, ?, ?, ?, ?, ?)'
);
$insert->execute([$today, '18:00:00', '20:00:00', '21:00:00', $hash]);

layout_start('Install');
echo '<section class="card"><h2>Ready</h2>';
echo '<p>Registration defaults to 6:00 PM and voting defaults to 8:00 PM. Change both in admin.</p>';
echo '<p>Admin password: <strong>' . h(DEFAULT_ADMIN_PASSWORD) . '</strong></p>';
echo '<a class="btn" href="admin.php">Open admin</a>';
echo '<a class="btn btn-navy" href="index.php">Open voting</a>';
echo '</section>';
layout_end();
