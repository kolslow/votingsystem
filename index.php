<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';

if (!mysql_ok()) {
    layout_start('Best Outfit');
    echo '<section class="card"><h2>MySQL is not running</h2>';
    echo '<p>Start MySQL in the XAMPP control panel, then refresh this page.</p></section>';
    layout_end();
    exit;
}

if (!installed()) {
    layout_start('Best Outfit');
    echo '<section class="card"><h2>Setup needed</h2>';
    echo '<p>Create the database before anyone registers.</p>';
    echo '<a class="btn" href="install.php">Install</a></section>';
    layout_end();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'register') {
        handle_register();
    } elseif ($action === 'enter_code') {
        handle_code();
    } elseif ($action === 'cast') {
        handle_cast();
    }
    redirect('index.php');
}

$settings = settings();
$bounds = event_bounds($settings);
$phase = phase_of($settings);
$device = device_registration();
if ($device) {
    $_SESSION['pending_code'] = $device['code'];
} elseif (($_COOKIE['outfit_device'] ?? '') !== '') {
    clear_device_cookie();
}
$pending = !empty($_SESSION['pending_code'])
    ? find_employee_by_code((string) $_SESSION['pending_code'])
    : null;
if ($pending && ($phase === 'early' || $phase === 'register')) {
    lock_device($pending, $bounds['end']);
    $pending = find_employee_by_id((int) $pending['id']);
}
$ready = $pending !== null && !empty($pending['confirmed_at']);
$voter = !empty($_SESSION['voter_id'])
    ? find_employee_by_id((int) $_SESSION['voter_id'])
    : null;
$view = $_GET['view'] ?? '';

if ($view === 'enter' && $phase !== 'vote' && $phase !== 'ended') {
    redirect($pending ? 'index.php?view=code' : 'index.php');
}

layout_start('Best Outfit', '', [
    'data-phase' => $phase,
    'data-watch' => '1',
]);

echo '<p class="lede">' . icon('calendar') . '<span>' . h(date_label($bounds['reg'])) . '</span></p>';

if (($view === 'code' || $view === 'wait') && $phase !== 'ended' && $pending) {
    render_code_screen($pending, $bounds);
} elseif ($voter && has_voted((int) $voter['id']) && $view !== 'enter') {
    render_thanks($voter);
} elseif ($phase === 'vote' && $voter && $view !== 'enter') {
    render_ballot($voter);
} elseif ($phase === 'vote') {
    render_code_entry();
} elseif ($phase === 'ended') {
    render_ended($bounds);
} else {
    render_waiting($phase, $bounds, $pending, $ready);
}

layout_end();

function handle_register(): void
{
    $settings = settings();
    $phase = phase_of($settings);
    $bounds = event_bounds($settings);
    if ($phase === 'early') {
        flash('Registration opens at ' . clock_label($bounds['reg']) . '.');
        redirect('index.php');
    }
    if ($phase !== 'register') {
        flash('Registration is closed.');
        redirect('index.php');
    }
    if (device_registration() || registered_on_this_device()) {
        flash('This device is already registered for this event.');
        redirect('index.php');
    }

    $name = clean_text((string) ($_POST['name'] ?? ''), 80);
    $department = clean_text((string) ($_POST['department'] ?? ''), 80);
    $gender = $_POST['gender'] ?? '';
    if ($name === '' || $department === '') {
        flash('Enter your name and department.');
        redirect('index.php');
    }
    if ($gender !== 'male' && $gender !== 'female') {
        flash('Choose male or female.');
        redirect('index.php');
    }

    $token = bin2hex(random_bytes(16));
    $pdo = db();
    $stmt = $pdo->prepare(
        'INSERT INTO employees (name, department, gender, code, created_at, device_token) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $name,
        $department,
        $gender,
        generate_code(),
        app_now()->format('Y-m-d H:i:s'),
        $token,
    ]);
    $employee = find_employee_by_id((int) $pdo->lastInsertId());
    $_SESSION['pending_code'] = $employee['code'];
    unset($_SESSION['voter_id']);
    remember_device($token, $bounds['end']->modify('+12 hours'));
    redirect('index.php?view=code');
}

function registered_on_this_device(): bool
{
    if (empty($_SESSION['pending_code'])) {
        return false;
    }
    return find_employee_by_code((string) $_SESSION['pending_code']) !== null;
}

function lock_device(array $employee, DateTimeImmutable $voteEnd): void
{
    $token = (string) ($employee['device_token'] ?? '');
    if ($token === '') {
        $token = bin2hex(random_bytes(16));
        $stmt = db()->prepare('UPDATE employees SET device_token = ? WHERE id = ? AND device_token IS NULL');
        $stmt->execute([$token, (int) $employee['id']]);
    }
    if (($_COOKIE['outfit_device'] ?? '') !== $token) {
        remember_device($token, $voteEnd->modify('+12 hours'));
    }
}

function handle_code(): void
{
    $settings = settings();
    $phase = phase_of($settings);
    $bounds = event_bounds($settings);
    $code = normalize_code((string) ($_POST['code'] ?? ''));
    $employee = $code === '' ? null : find_employee_by_code($code);

    if ($code === '') {
        flash('Enter your voting code.');
        redirect($phase === 'vote' ? 'index.php?view=enter' : 'index.php?view=code');
    }
    if (!$employee) {
        flash('That code was not found.', 'error');
        redirect($phase === 'vote' ? 'index.php?view=enter' : 'index.php?view=code');
    }

    if ($phase === 'early' || $phase === 'register') {
        flash('Voting opens at ' . clock_label($bounds['vote']) . '.');
        redirect('index.php?view=code');
    }
    if ($phase === 'ended') {
        flash('Voting ended at ' . clock_label($bounds['end']) . '.');
        redirect('index.php');
    }

    $_SESSION['pending_code'] = $employee['code'];

    $_SESSION['voter_id'] = (int) $employee['id'];
    if (has_voted((int) $employee['id'])) {
        flash('This code has already been used.', 'error');
    }
    redirect('index.php');
}

function handle_cast(): void
{
    $settings = settings();
    $phase = phase_of($settings);
    $voterId = (int) ($_SESSION['voter_id'] ?? 0);
    $voter = $voterId > 0 ? find_employee_by_id($voterId) : null;
    if (!$voter) {
        flash('Enter your voting code first.');
        redirect('index.php');
    }
    if ($phase !== 'vote') {
        flash('Voting is not open.');
        redirect('index.php');
    }
    if (has_voted($voterId)) {
        flash('This code has already been used.', 'error');
        redirect('index.php');
    }

    $maleId = (int) ($_POST['male_id'] ?? 0);
    $femaleId = (int) ($_POST['female_id'] ?? 0);
    $male = $maleId > 0 ? find_employee_by_id($maleId) : null;
    $female = $femaleId > 0 ? find_employee_by_id($femaleId) : null;
    if (!$male || $male['gender'] !== 'male' || !$female || $female['gender'] !== 'female') {
        flash('Pick one male outfit and one female outfit.');
        redirect('index.php');
    }
    if ($maleId === $voterId || $femaleId === $voterId) {
        flash('You cannot vote for yourself.', 'error');
        redirect('index.php');
    }

    try {
        $stmt = db()->prepare(
            'INSERT INTO votes (voter_id, male_id, female_id, created_at) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$voterId, $maleId, $femaleId, app_now()->format('Y-m-d H:i:s')]);
    } catch (PDOException $e) {
        flash('This code has already been used.', 'error');
        redirect('index.php');
    }

    $_SESSION['celebrate'] = true;
    redirect('index.php');
}

function render_status_card(string $label, string $value, string $note, bool $live = false): void
{
    echo '<section class="card status-card' . ($live ? ' is-live' : '') . '">';
    echo '<span class="status-indicator"><span class="status-dot"></span></span>';
    echo '<div class="status-body">';
    echo '<p class="status-label">' . h($label) . '</p>';
    echo '<p class="status-value">' . h($value) . '</p>';
    if ($note !== '') {
        echo '<p class="status-note">' . h($note) . '</p>';
    }
    echo '</div></section>';
}

function render_waiting(string $phase, array $bounds, ?array $pending, bool $ready): void
{
    if ($phase === 'register' && $pending) {
        echo '<section class="card saved-card">';
        echo '<span class="saved-icon">' . icon('check') . '</span>';
        echo '<div><p class="kicker">Already registered</p>';
        echo '<h2>' . h($pending['name']) . '</h2>';
        echo '<p class="hint">' . h($pending['name']) . ' is registered on this device for this event.</p></div>';
        echo '</section>';
        echo '<a class="btn btn-xl" href="index.php?view=code"><span>Continue</span>' . icon('arrow') . '</a>';
        return;
    }
    if ($phase === 'register') {
        render_status_card(
            'Registration',
            'Open now',
            'Closes when voting starts at ' . clock_label($bounds['vote']) . '.',
            true
        );
        echo '<section class="card">';
        echo '<div class="card-head"><span class="card-head-icon">' . icon('user-plus') . '</span>';
        echo '<div><h2>Register</h2><p class="hint">You will get a voting code to use when voting starts.</p></div></div>';
        echo '<form method="post" action="index.php">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="register">';
        echo '<label for="name">Name</label>';
        echo '<input id="name" name="name" required maxlength="80" autocomplete="name" placeholder="Juan Dela Cruz">';
        echo '<label for="department">Department</label>';
        echo '<input id="department" name="department" required maxlength="80" autocomplete="organization" placeholder="Operations">';
        echo '<p class="label">Category</p>';
        echo '<div class="gender">';
        echo '<label class="gender-opt"><input type="radio" name="gender" value="male" required><span>' . icon('male') . 'Male</span></label>';
        echo '<label class="gender-opt"><input type="radio" name="gender" value="female" required><span>' . icon('female') . 'Female</span></label>';
        echo '</div>';
        echo '<button class="btn" type="submit"><span>Submit registration</span>' . icon('arrow') . '</button>';
        echo '</form></section>';
        return;
    }
    render_status_card(
        'Registration',
        'Opens at ' . clock_label($bounds['reg']),
        'Come back then to join the male and female lists.'
    );
    render_registration_countdown($bounds);
}

function render_code_screen(array $employee, array $bounds): void
{
    echo '<div class="stage"><section class="card code-card">';
    echo '<p class="kicker">You are in</p>';
    echo '<h2>' . h($employee['name']) . '</h2>';
    echo '<p class="hint">' . h($employee['department']) . ' · ' . h(ucfirst($employee['gender'])) . '</p>';
    echo '<p class="label">Your voting code</p>';
    echo '<p class="code" aria-label="' . h(implode(' ', str_split($employee['code']))) . '">';
    foreach (str_split($employee['code']) as $i => $char) {
        echo '<span style="--i:' . $i . '" aria-hidden="true">' . h($char) . '</span>';
    }
    echo '</p>';
    echo '<p class="hint">Keep this code. You will type it when voting starts. It works on any phone or Wi-Fi.</p>';
    echo '</section></div>';
    render_countdown($bounds, null);
}

function render_timer_blocks(): void
{
    echo '<div class="timer" data-clock role="timer">';
    foreach (['h' => 'Hours', 'm' => 'Minutes', 's' => 'Seconds'] as $unit => $unitLabel) {
        if ($unit !== 'h') {
            echo '<span class="time-sep" aria-hidden="true">:</span>';
        }
        echo '<div class="time-block"><span class="time-num" data-unit="' . $unit . '">--</span>';
        echo '<span class="time-label">' . $unitLabel . '</span></div>';
    }
    echo '</div>';
}

function render_registration_countdown(array $bounds): void
{
    $regMs = ms_of($bounds['reg']);
    $nowMs = ms_of(app_now());
    echo '<div class="stage">';
    echo '<section class="card countdown-card" data-countdown="' . $regMs . '" data-countdown-key="reg" data-server-now="' . $nowMs . '" data-reload-at-zero="1">';
    echo '<p class="kicker" data-countdown-title>Registration opens in</p>';
    render_timer_blocks();
    echo '<p class="hint countdown-note">' . icon('clock') . '<span>Opens at ' . h(clock_label($bounds['reg']));
    echo ' · Voting opens at ' . h(clock_label($bounds['vote'])) . '</span></p>';
    echo '</section></div>';
}

function render_countdown(array $bounds, ?string $code): void
{
    $voteMs = ms_of($bounds['vote']);
    $nowMs = ms_of(app_now());
    $opens = clock_label($bounds['vote']);
    $label = '<span class="when-locked">' . icon('lock') . '<span>Locked until ' . h($opens) . '</span></span>'
        . '<span class="when-ready"><span>Vote now</span>' . icon('arrow') . '</span>';
    echo '<div class="stage">';
    echo '<section class="card countdown-card" data-countdown="' . $voteMs . '" data-countdown-key="vote" data-server-now="' . $nowMs . '">';
    echo '<p class="kicker" data-countdown-title>Voting starts in</p>';
    render_timer_blocks();
    echo '<p class="hint countdown-note">' . icon('clock') . '<span>Voting opens at ' . h($opens) . '</span></p>';
    if ($code !== null) {
        echo '<form method="post" action="index.php">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="enter_code">';
        echo '<input type="hidden" name="code" value="' . h($code) . '">';
        echo '<button class="btn btn-xl btn-vote" id="vote-now" type="submit" disabled>' . $label . '</button>';
        echo '</form>';
    } else {
        echo '<button class="btn btn-xl btn-vote" id="vote-now" type="button" data-go="index.php?view=enter" disabled>' . $label . '</button>';
    }
    echo '</section></div>';
}

function render_code_entry(): void
{
    render_status_card('Voting', 'Open now', 'Enter your voting code.', true);
    echo '<div class="stage"><section class="card">';
    echo '<div class="card-head"><span class="card-head-icon">' . icon('ticket') . '</span>';
    echo '<div><h2>Voting is open</h2><p class="hint">Your code unlocks one ballot.</p></div></div>';
    echo '<form method="post" action="index.php">';
    echo csrf_field();
    echo '<input type="hidden" name="action" value="enter_code">';
    echo '<label for="code">Voting code</label>';
    echo '<input id="code" class="code-input" name="code" required maxlength="6" autocapitalize="characters" autocomplete="off" spellcheck="false" data-code-input>';
    echo '<button class="btn btn-xl" type="submit"><span>Vote now</span>' . icon('arrow') . '</button>';
    echo '</form></section></div>';
}

function render_ballot(array $voter): void
{
    $voterId = (int) $voter['id'];
    $males = candidates('male', $voterId);
    $females = candidates('female', $voterId);
    echo '<section class="card voter-card">';
    echo '<span class="avatar avatar-lg" aria-hidden="true">' . h(initials($voter['name'])) . '</span>';
    echo '<div><p class="kicker">Voting as</p>';
    echo '<h2>' . h($voter['name']) . '</h2>';
    echo '<p class="hint">Pick one other man and one other woman. You are not on your own list.</p></div>';
    if ($males === [] || $females === []) {
        echo '<p class="voter-note">Each list needs someone else registered. You cannot vote for yourself.</p>';
        echo '</section>';
        return;
    }
    echo '</section>';
    echo '<form method="post" action="index.php" data-ballot>';
    echo csrf_field();
    echo '<input type="hidden" name="action" value="cast">';
    echo '<section class="card ballot-card ballot-male">';
    echo '<h3 class="list-title list-title-male">' . icon('male') . '<span>Best male outfit</span></h3>';
    echo '<div class="choices">';
    foreach ($males as $person) {
        render_choice('male_id', $person);
    }
    echo '</div></section>';
    echo '<section class="card ballot-card ballot-female">';
    echo '<h3 class="list-title list-title-female">' . icon('female') . '<span>Best female outfit</span></h3>';
    echo '<div class="choices">';
    foreach ($females as $person) {
        render_choice('female_id', $person);
    }
    echo '</div></section>';
    echo '<div class="ballot-submit"><button class="btn btn-xl" type="submit"><span>Submit vote</span>' . icon('arrow') . '</button></div>';
    echo '</form>';
}

function render_choice(string $field, array $person): void
{
    $id = $field . '-' . $person['id'];
    echo '<label class="choice" for="' . h($id) . '">';
    echo '<input id="' . h($id) . '" type="radio" name="' . h($field) . '" value="' . (int) $person['id'] . '" required>';
    echo '<span class="avatar" aria-hidden="true">' . h(initials($person['name'])) . '</span>';
    echo '<span class="choice-text"><strong>' . h($person['name']) . '</strong>';
    echo '<small>' . h($person['department']) . '</small></span>';
    echo '<span class="choice-check">' . icon('check') . '</span>';
    echo '</label>';
}

function render_thanks(array $voter): void
{
    $celebrate = !empty($_SESSION['celebrate']);
    unset($_SESSION['celebrate']);
    echo '<div class="stage"><section class="card thanks-card"' . ($celebrate ? ' data-celebrate' : '') . '>';
    echo '<div class="success-mark" aria-hidden="true"><svg viewBox="0 0 52 52">';
    echo '<circle cx="26" cy="26" r="24"/><path d="M15 27l7 7 15-15"/></svg></div>';
    echo '<p class="kicker">Vote counted</p>';
    echo '<h2>Thank you for voting!</h2>';
    echo '<p class="hint">' . h($voter['name']) . ', your ballot is in. This code cannot be used again.</p>';
    echo '</section></div>';
}

function render_ended(array $bounds): void
{
    echo '<div class="stage"><section class="card ended-card">';
    echo '<span class="ended-icon">' . icon('flag') . '</span>';
    echo '<p class="kicker">That is a wrap</p>';
    echo '<h2>Voting has ended</h2>';
    echo '<p class="hint">Ballots closed at ' . h(clock_label($bounds['end'])) . '. Winners will be announced on stage.</p>';
    echo '</section></div>';
}
