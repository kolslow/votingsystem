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

$settings = settings();
$bounds = event_bounds($settings);
$phase = phase_of($settings);
$isAdmin = !empty($_SESSION['admin']);

if (!$isAdmin && $phase !== 'ended') {
    layout_start('Results');
    echo '<div class="stage"><section class="card">';
    echo '<h2>Results open at ' . h(clock_label($bounds['end'])) . '</h2>';
    echo '<p class="hint">The standings appear when voting ends.</p>';
    echo '<a class="btn" href="index.php">Back to voting</a>';
    echo '</section></div>';
    layout_end();
    exit;
}
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
if ($isAdmin) {
    echo '<a class="text-link" href="admin.php">Back to admin</a>';
}
echo '</div>';
echo '<div class="stage stage-wide"><div class="boards">';
render_tally('Best male outfit', 'male', 'male_id', $emptyNote, $isAdmin, 5);
render_tally('Best female outfit', 'female', 'female_id', $emptyNote, $isAdmin, 5);
echo '</div></div>';
if ($isAdmin) {
    echo '<dialog class="vote-modal" data-vote-modal aria-labelledby="vote-modal-title">';
    echo '<div class="vote-modal-card">';
    echo '<header class="vote-modal-head">';
    echo '<div><p class="kicker">Voted by</p><h2 id="vote-modal-title" data-modal-name></h2>';
    echo '<p class="vote-modal-meta" data-modal-meta></p></div>';
    echo '<button class="vote-modal-close" type="button" data-modal-close aria-label="Close">' . icon('x') . '</button>';
    echo '</header>';
    echo '<ul class="vote-modal-list" data-modal-list></ul>';
    echo '</div></dialog>';
}
layout_end();
