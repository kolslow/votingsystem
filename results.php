<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';

if (!mysql_ok()) {
    layout_start('Results', 'admin');
    echo '<section class="card"><h2>MySQL is not running</h2>';
    echo '<p>' . h(db_down_message()) . '</p></section>';
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

$emptyNote = match ($phase) {
    'early' => 'Registration opens at ' . clock_label($bounds['reg']) . '.',
    'register' => 'Registration is open now.',
    default => 'No one registered in this category.',
};
$ballots = (int) $counts['ballots'];

layout_start('Results', 'admin wide', ['data-phase' => $phase, 'data-live-results' => '5']);
echo '<div class="results-meta">';
if ($phase === 'vote') {
    echo '<span class="pill pill-live"><span class="live-dot"></span>Live results</span>';
} else {
    echo '<span class="pill"><span class="pill-dot"></span>' . h(phase_label($phase)) . '</span>';
}
echo '<p class="meta-line">' . h(date_label($bounds['vote'])) . '<i aria-hidden="true"></i>';
echo '<b data-total-votes>' . $ballots . '</b>&nbsp;<span data-total-label>' . ($ballots === 1 ? 'total vote' : 'total votes') . '</span></p>';
echo '<a class="text-link" href="admin.php">Back to admin</a>';
echo '</div>';
echo '<div class="stage stage-wide"><div class="boards">';
render_tally('Best male outfit', 'male', 'male_id', $emptyNote);
render_tally('Best female outfit', 'female', 'female_id', $emptyNote);
echo '</div></div>';
layout_end();
