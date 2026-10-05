<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';

if (!mysql_ok()) {
    layout_start('Admin', 'admin');
    echo '<section class="card"><h2>MySQL is not running</h2>';
    echo '<p>Start MySQL in the XAMPP control panel, then refresh this page.</p></section>';
    layout_end();
    exit;
}

if (!installed()) {
    layout_start('Admin', 'admin');
    echo '<section class="card"><h2>Setup needed</h2>';
    echo '<p>Install the database first.</p>';
    echo '<a class="btn" href="install.php">Install</a></section>';
    layout_end();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'login') {
        handle_login();
    }
    if (empty($_SESSION['admin'])) {
        flash('Sign in first.');
        redirect('admin.php');
    }
    if ($action === 'logout') {
        unset($_SESSION['admin']);
        redirect('admin.php');
    } elseif ($action === 'save_schedule') {
        handle_schedule();
    } elseif ($action === 'delete_employee') {
        handle_delete();
    } elseif ($action === 'change_password') {
        handle_password();
    }
    redirect('admin.php');
}

if (empty($_SESSION['admin'])) {
    render_login();
    exit;
}

render_dashboard();

function handle_login(): void
{
    $password = (string) ($_POST['password'] ?? '');
    $settings = settings();
    if (!password_verify($password, $settings['admin_password_hash'])) {
        flash('Wrong password.');
        redirect('admin.php');
    }
    session_regenerate_id(true);
    $_SESSION['admin'] = true;
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
    redirect('admin.php');
}

function handle_schedule(): void
{
    $date = (string) ($_POST['event_date'] ?? '');
    $reg = normalize_time((string) ($_POST['reg_start'] ?? ''));
    $vote = normalize_time((string) ($_POST['vote_start'] ?? ''));
    $end = normalize_time((string) ($_POST['vote_end'] ?? ''));
    if (!valid_date($date) || $reg === null || $vote === null || $end === null) {
        flash('Enter a valid date and times.');
        redirect('admin.php');
    }
    if ($vote <= $reg) {
        flash('Voting must start after registration opens.');
        redirect('admin.php');
    }
    if ($end <= $vote) {
        flash('Voting must end after it starts.');
        redirect('admin.php');
    }
    $current = settings();
    $unchanged = $current['event_date'] === $date
        && $current['reg_start'] === $reg
        && $current['vote_start'] === $vote
        && $current['vote_end'] === $end;
    $pdo = db();
    $pdo->beginTransaction();
    try {
        if (!$unchanged) {
            $pdo->exec('DELETE FROM votes');
            $pdo->exec('DELETE FROM employees');
            unset($_SESSION['voter_id'], $_SESSION['pending_code']);
        }
        $stmt = $pdo->prepare(
            'UPDATE settings SET event_date = ?, reg_start = ?, vote_start = ?, vote_end = ? WHERE id = 1'
        );
        $stmt->execute([$date, $reg, $vote, $end]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('The schedule could not be saved.');
        redirect('admin.php');
    }
    flash($unchanged
        ? 'Event hours saved.'
        : 'New schedule saved. Previous registrations and votes were removed.');
    redirect('admin.php');
}

function handle_delete(): void
{
    $settings = settings();
    $phase = phase_of($settings);
    if ($phase === 'vote' || $phase === 'ended') {
        flash('Registrations can be removed only before voting starts.');
        redirect('admin.php');
    }
    $id = (int) ($_POST['employee_id'] ?? 0);
    $stmt = db()->prepare(
        'DELETE FROM employees WHERE id = ? AND NOT EXISTS (SELECT 1 FROM votes WHERE voter_id = employees.id)'
    );
    $stmt->execute([$id]);
    flash($stmt->rowCount() === 1 ? 'Registration removed.' : 'That registration could not be removed.');
    redirect('admin.php');
}

function handle_password(): void
{
    $settings = settings();
    $current = (string) ($_POST['current_password'] ?? '');
    $next = (string) ($_POST['new_password'] ?? '');
    if (!password_verify($current, $settings['admin_password_hash'])) {
        flash('Current password is wrong.');
        redirect('admin.php');
    }
    if (strlen($next) < 6) {
        flash('Use at least 6 characters for the new password.');
        redirect('admin.php');
    }
    $stmt = db()->prepare('UPDATE settings SET admin_password_hash = ? WHERE id = 1');
    $stmt->execute([password_hash($next, PASSWORD_DEFAULT)]);
    flash('Password updated.');
    redirect('admin.php');
}

function render_login(): void
{
    layout_start('Admin', 'admin');
    echo '<section class="card narrow">';
    echo '<h2>Sign in</h2>';
    if (password_verify(DEFAULT_ADMIN_PASSWORD, settings()['admin_password_hash'])) {
        echo '<p class="hint">Default password: ' . h(DEFAULT_ADMIN_PASSWORD) . '</p>';
    }
    echo '<form method="post" action="admin.php">';
    echo csrf_field();
    echo '<input type="hidden" name="action" value="login">';
    echo '<label for="password">Password</label>';
    echo '<input id="password" type="password" name="password" required autocomplete="current-password">';
    echo '<button class="btn" type="submit">Sign in</button>';
    echo '</form></section>';
    layout_end();
}

function render_dashboard(): void
{
    $settings = settings();
    $bounds = event_bounds($settings);
    $phase = phase_of($settings);
    $counts = db()->query(
        'SELECT
            (SELECT COUNT(*) FROM employees) AS people,
            (SELECT COUNT(*) FROM employees WHERE gender = \'male\') AS males,
            (SELECT COUNT(*) FROM employees WHERE gender = \'female\') AS females,
            (SELECT COUNT(*) FROM votes) AS ballots'
    )->fetch();
    $roster = db()->query(
        'SELECT e.id, e.name, e.department, e.gender, e.code, e.created_at, e.confirmed_at,
                (v.id IS NOT NULL) AS voted
         FROM employees e
         LEFT JOIN votes v ON v.voter_id = e.id
         ORDER BY e.created_at ASC, e.id ASC'
    )->fetchAll();
    $canDelete = $phase === 'early' || $phase === 'register';
    $url = attendee_url();

    layout_start('Admin', 'admin');
    echo '<p class="status-row"><span class="pill">' . h(phase_label($phase)) . '</span>';
    echo '<a class="text-link" href="index.php">Attendee page</a></p>';

    echo '<section class="card">';
    echo '<h2>Event hours</h2>';
    echo '<p class="hint">' . h(date_label($bounds['reg'])) . ' · registration ' . h(clock_label($bounds['reg']));
    echo ' · voting ' . h(clock_label($bounds['vote'])) . ' to ' . h(clock_label($bounds['end'])) . '</p>';
    echo '<form class="hours" method="post" action="admin.php"';
    if ((int) $counts['people'] > 0 || (int) $counts['ballots'] > 0) {
        echo ' data-confirm-schedule="Saving a different schedule removes the previous registrations and votes. Continue?"';
    }
    echo '>';
    echo csrf_field();
    echo '<input type="hidden" name="action" value="save_schedule">';
    echo '<label for="event_date">Event date</label>';
    echo '<input id="event_date" type="date" name="event_date" required value="' . h($settings['event_date']) . '">';
    echo '<div class="hour-grid">';
    echo '<div><label for="reg_start">Registration opens</label>';
    echo '<input id="reg_start" type="time" name="reg_start" required value="' . h(time_input_value($settings['reg_start'])) . '"></div>';
    echo '<div><label for="vote_start">Voting starts</label>';
    echo '<input id="vote_start" type="time" name="vote_start" required value="' . h(time_input_value($settings['vote_start'])) . '"></div>';
    echo '<div><label for="vote_end">Voting ends</label>';
    echo '<input id="vote_end" type="time" name="vote_end" required value="' . h(time_input_value($settings['vote_end'])) . '"></div>';
    echo '</div>';
    echo '<button class="btn" type="submit">Save hours</button>';
    echo '<p class="hint">Saving a different date or time clears the previous registrations and votes.</p>';
    echo '</form></section>';

    echo '<section class="card">';
    echo '<h2>Venue QR</h2>';
    echo '<p class="hint">This code opens the registration and voting page. It updates from this server address.</p>';
    echo '<div id="qrcode" class="qr" data-url="' . h($url) . '"></div>';
    echo '<p class="url">' . h($url) . '</p>';
    echo '</section>';

    echo '<section class="card">';
    echo '<h2>Roster</h2>';
    echo '<p class="stats">' . (int) $counts['people'] . ' registered · ';
    echo (int) $counts['males'] . ' male · ' . (int) $counts['females'] . ' female · ';
    echo (int) $counts['ballots'] . ' votes</p>';
    if ($roster === []) {
        echo '<p class="hint">No one has registered yet.</p>';
    }
    foreach ($roster as $person) {
        echo '<article class="roster-item">';
        echo '<div><strong>' . h($person['name']) . '</strong>';
        echo '<span>' . h($person['department']) . ' · ' . h(ucfirst($person['gender'])) . '</span></div>';
        echo '<p class="code code-sm">' . h($person['code']) . '</p>';
        if (!empty($person['confirmed_at'])) {
            echo '<span class="badge">Code saved</span>';
        }
        if ((int) $person['voted'] === 1) {
            echo '<span class="badge">Voted</span>';
        }
        if ($canDelete && (int) $person['voted'] !== 1) {
            echo '<form method="post" action="admin.php" data-confirm="Remove this registration?">';
            echo csrf_field();
            echo '<input type="hidden" name="action" value="delete_employee">';
            echo '<input type="hidden" name="employee_id" value="' . (int) $person['id'] . '">';
            echo '<button class="btn btn-ghost" type="submit">Remove</button>';
            echo '</form>';
        }
        echo '</article>';
    }
    echo '</section>';

    echo '<section class="card">';
    echo '<h2>Results</h2>';
    echo '<p class="hint">Male and female standings are on their own page.</p>';
    echo '<a class="btn" href="results.php">Open results</a>';
    echo '</section>';

    echo '<section class="card">';
    echo '<h2>Password</h2>';
    echo '<form method="post" action="admin.php">';
    echo csrf_field();
    echo '<input type="hidden" name="action" value="change_password">';
    echo '<label for="current_password">Current password</label>';
    echo '<input id="current_password" type="password" name="current_password" required autocomplete="current-password">';
    echo '<label for="new_password">New password</label>';
    echo '<input id="new_password" type="password" name="new_password" required minlength="6" autocomplete="new-password">';
    echo '<button class="btn btn-navy" type="submit">Update password</button>';
    echo '</form>';
    echo '<form class="logout" method="post" action="admin.php">';
    echo csrf_field();
    echo '<input type="hidden" name="action" value="logout">';
    echo '<button class="btn btn-ghost" type="submit">Log out</button>';
    echo '</form></section>';

    layout_end(true);
}

