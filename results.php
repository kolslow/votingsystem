<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';

if (!mysql_ok()) {
    layout_start('Results', 'admin');
    echo '<section class="card"><h2>MySQL is not running</h2>';
    echo '<p>Start MySQL in the XAMPP control panel, then refresh this page.</p></section>';
    layout_end();
    exit;
}

if (!installed()) {
    layout_start('Results', 'admin');
    echo '<section class="card"><h2>Setup needed</h2>';
    echo '<a class="btn" href="install.php">Install</a></section>';
    layout_end();
    exit;
}

if (empty($_SESSION['admin'])) {
    flash('Sign in to view results.');
    redirect('admin.php');
}

$settings = settings();
$bounds = event_bounds($settings);
$phase = phase_of($settings);
$counts = db()->query(
    'SELECT
        (SELECT COUNT(*) FROM employees) AS people,
        (SELECT COUNT(*) FROM votes) AS ballots'
)->fetch();

layout_start('Results', 'admin', ['data-refresh' => '5']);
echo '<p class="status-row"><span class="pill">' . h(phase_label($phase)) . '</span>';
echo '<a class="text-link" href="admin.php">Back to admin</a></p>';
echo '<p class="lede">' . h(date_label($bounds['vote'])) . ' · ' . (int) $counts['ballots'] . ' votes</p>';
echo '<section class="card">';
render_tally('Best male outfit', 'male', 'male_id');
echo '</section>';
echo '<section class="card">';
render_tally('Best female outfit', 'female', 'female_id');
echo '</section>';
layout_end();
