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
$pending = !empty($_SESSION['pending_code'])
    ? find_employee_by_code((string) $_SESSION['pending_code'])
    : null;
$ready = $pending !== null && !empty($pending['confirmed_at']);
$voter = !empty($_SESSION['voter_id'])
    ? find_employee_by_id((int) $_SESSION['voter_id'])
    : null;
$view = $_GET['view'] ?? '';

layout_start('Best Outfit', '', [
    'data-phase' => $phase,
    'data-watch' => '1',
]);

if ($view === 'code' && $pending && $phase !== 'ended') {
    render_code_screen($pending, $bounds, $phase, $ready);
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

    $pdo = db();
    $stmt = $pdo->prepare(
        'INSERT INTO employees (name, department, gender, code, created_at) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $name,
        $department,
        $gender,
        generate_code(),
        app_now()->format('Y-m-d H:i:s'),
    ]);
    $employee = find_employee_by_id((int) $pdo->lastInsertId());
    $_SESSION['pending_code'] = $employee['code'];
    unset($_SESSION['voter_id']);
    redirect('index.php?view=code');
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
        redirect($phase === 'vote' ? 'index.php?view=enter' : 'index.php');
    }
    if (!$employee) {
        flash('That code was not found.');
        redirect($phase === 'vote' ? 'index.php?view=enter' : 'index.php');
    }

    if ($phase === 'early' || $phase === 'register') {
        $stmt = db()->prepare('UPDATE employees SET confirmed_at = ? WHERE id = ?');
        $stmt->execute([app_now()->format('Y-m-d H:i:s'), (int) $employee['id']]);
        $_SESSION['pending_code'] = $employee['code'];
        unset($_SESSION['voter_id']);
        flash('Code saved. You can vote when the timer ends.');
        redirect('index.php?view=code');
    }
    if ($phase === 'ended') {
        flash('Voting ended at ' . clock_label($bounds['end']) . '.');
        redirect('index.php');
    }
    if (empty($employee['confirmed_at'])) {
        flash('Enter your voting code before voting starts.');
        redirect('index.php?view=enter');
    }

    $_SESSION['pending_code'] = $employee['code'];

    $_SESSION['voter_id'] = (int) $employee['id'];
    if (has_voted((int) $employee['id'])) {
        flash('This code has already been used.');
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
        flash('This code has already been used.');
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
        flash('You cannot vote for yourself.');
        redirect('index.php');
    }

    try {
        $stmt = db()->prepare(
            'INSERT INTO votes (voter_id, male_id, female_id, created_at) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$voterId, $maleId, $femaleId, app_now()->format('Y-m-d H:i:s')]);
    } catch (PDOException $e) {
        flash('This code has already been used.');
        redirect('index.php');
    }

    flash('Your vote is in.');
    redirect('index.php');
}

function render_waiting(string $phase, array $bounds, ?array $pending, bool $ready): void
{
    echo '<p class="lede">' . h(date_label($bounds['reg'])) . '</p>';
    if ($phase === 'register') {
        echo '<section class="card">';
        echo '<h2>Register</h2>';
        echo '<p class="hint">You will get a voting code. Type it in before voting starts.</p>';
        echo '<form method="post" action="index.php">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="register">';
        echo '<label for="name">Name</label>';
        echo '<input id="name" name="name" required maxlength="80" autocomplete="name">';
        echo '<label for="department">Department</label>';
        echo '<input id="department" name="department" required maxlength="80" autocomplete="organization">';
        echo '<p class="label">Category</p>';
        echo '<div class="gender">';
        echo '<label class="gender-opt"><input type="radio" name="gender" value="male" required><span>Male</span></label>';
        echo '<label class="gender-opt"><input type="radio" name="gender" value="female" required><span>Female</span></label>';
        echo '</div>';
        echo '<button class="btn" type="submit">Get my code</button>';
        echo '</form></section>';
        if ($pending && !$ready) {
            echo '<p class="center"><a class="text-link" href="index.php?view=code">Enter your voting code</a></p>';
        }
    } else {
        echo '<section class="card">';
        echo '<h2>Registration opens at ' . h(clock_label($bounds['reg'])) . '</h2>';
        echo '<p class="hint">Come back then to join the male and female lists.</p>';
        echo '</section>';
    }
    if ($phase === 'register' && !$ready) {
        render_save_code_form();
    }
    render_countdown($bounds, $ready && $pending ? $pending['code'] : null);
}

function render_code_screen(array $employee, array $bounds, string $phase, bool $ready): void
{
    echo '<section class="card code-card">';
    echo '<p class="kicker">You are in</p>';
    echo '<h2>' . h($employee['name']) . '</h2>';
    echo '<p class="hint">' . h($employee['department']) . ' · ' . h(ucfirst($employee['gender'])) . '</p>';
    echo '<p class="label">Your voting code</p>';
    echo '<p class="code">' . h($employee['code']) . '</p>';
    echo '<p class="hint">Type this code below before voting starts. It works on any phone or Wi-Fi.</p>';
    echo '</section>';
    if ($phase === 'vote' && $ready) {
        echo '<form method="post" action="index.php">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="enter_code">';
        echo '<input type="hidden" name="code" value="' . h($employee['code']) . '">';
        echo '<button class="btn" type="submit">Vote now</button>';
        echo '</form>';
    } elseif ($phase === 'vote') {
        echo '<section class="card"><h2>Code not saved</h2>';
        echo '<p class="hint">Enter your voting code before voting starts.</p></section>';
    } elseif ($ready) {
        echo '<section class="card"><p class="kicker">Code saved</p>';
        echo '<p class="hint">You can vote when the timer ends.</p></section>';
        render_countdown($bounds, $employee['code']);
    } else {
        render_save_code_form();
        render_countdown($bounds, null);
    }
}

function render_save_code_form(): void
{
    echo '<section class="card">';
    echo '<h2>Voting code</h2>';
    echo '<p class="hint">Required before voting starts.</p>';
    echo '<form method="post" action="index.php">';
    echo csrf_field();
    echo '<input type="hidden" name="action" value="enter_code">';
    echo '<label for="code">Voting code</label>';
    echo '<input id="code" class="code-input" name="code" required maxlength="6" autocapitalize="characters" autocomplete="off" spellcheck="false" data-code-input placeholder="K7M4QP">';
    echo '<button class="btn" type="submit">Save code</button>';
    echo '</form></section>';
}

function render_countdown(array $bounds, ?string $code): void
{
    $voteMs = ms_of($bounds['vote']);
    $nowMs = ms_of(app_now());
    echo '<section class="card countdown-card" data-vote-start="' . $voteMs . '" data-server-now="' . $nowMs . '">';
    echo '<p class="kicker">Voting starts in</p>';
    echo '<p class="clock" data-clock>--:--:--</p>';
    echo '<p class="hint">Opens at ' . h(clock_label($bounds['vote'])) . '</p>';
    if ($code !== null) {
        echo '<form method="post" action="index.php">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="enter_code">';
        echo '<input type="hidden" name="code" value="' . h($code) . '">';
        echo '<button class="btn" id="vote-now" type="submit" disabled>Vote now</button>';
        echo '</form>';
    } else {
        echo '<button class="btn" id="vote-now" type="button" data-reload="1" disabled>Vote now</button>';
    }
    echo '</section>';
}

function render_code_entry(): void
{
    echo '<section class="card">';
    echo '<h2>Voting is open</h2>';
    echo '<p class="hint">Enter the code you saved before voting started.</p>';
    echo '<form method="post" action="index.php">';
    echo csrf_field();
    echo '<input type="hidden" name="action" value="enter_code">';
    echo '<label for="code">Voting code</label>';
    echo '<input id="code" class="code-input" name="code" required maxlength="6" autocapitalize="characters" autocomplete="off" spellcheck="false" data-code-input placeholder="K7M4QP">';
    echo '<button class="btn" type="submit">Vote now</button>';
    echo '</form></section>';
}

function render_ballot(array $voter): void
{
    $voterId = (int) $voter['id'];
    $males = candidates('male', $voterId);
    $females = candidates('female', $voterId);
    echo '<section class="card">';
    echo '<p class="kicker">Voting as</p>';
    echo '<h2>' . h($voter['name']) . '</h2>';
    echo '<p class="hint">Pick one other man and one other woman. You are not on your own list.</p>';
    if ($males === [] || $females === []) {
        echo '<p>Each list needs someone else registered. You cannot vote for yourself.</p>';
        echo '</section>';
        return;
    }
    echo '<form method="post" action="index.php" data-ballot>';
    echo csrf_field();
    echo '<input type="hidden" name="action" value="cast">';
    echo '<h3 class="list-title list-title-male">Best male outfit</h3>';
    foreach ($males as $person) {
        render_choice('male_id', $person);
    }
    echo '<h3 class="list-title list-title-female">Best female outfit</h3>';
    foreach ($females as $person) {
        render_choice('female_id', $person);
    }
    echo '<button class="btn" type="submit">Submit vote</button>';
    echo '</form></section>';
}

function render_choice(string $field, array $person): void
{
    $id = $field . '-' . $person['id'];
    echo '<label class="choice" for="' . h($id) . '">';
    echo '<input id="' . h($id) . '" type="radio" name="' . h($field) . '" value="' . (int) $person['id'] . '" required>';
    echo '<span><strong>' . h($person['name']) . '</strong>';
    echo '<small>' . h($person['department']) . '</small></span>';
    echo '</label>';
}

function render_thanks(array $voter): void
{
    echo '<section class="card">';
    echo '<p class="kicker">Vote recorded</p>';
    echo '<h2>Thank you, ' . h($voter['name']) . '</h2>';
    echo '<p class="hint">This code cannot be used again.</p>';
    echo '</section>';
}

function render_ended(array $bounds): void
{
    echo '<section class="card">';
    echo '<h2>Voting has ended</h2>';
    echo '<p class="hint">Ballots closed at ' . h(clock_label($bounds['end'])) . '.</p>';
    echo '</section>';
}
