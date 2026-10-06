<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

date_default_timezone_set(APP_TIMEZONE);

session_set_cookie_params([
    'lifetime' => 0,
    'path' => app_cookie_path(),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

function app_cookie_path(): string
{
    $cookiePath = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $cookiePath = rtrim($cookiePath, '/') . '/';
    return $cookiePath === '//' ? '/' : $cookiePath;
}

function pdo_options(): array
{
    return [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
}

function mysql_ok(): bool
{
    try {
        server_pdo();
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function server_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        pdo_options()
    );
    return $pdo;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        pdo_options()
    );
    return $pdo;
}

function installed(): bool
{
    try {
        $stmt = server_pdo()->prepare(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
        );
        $stmt->execute([DB_NAME, 'settings']);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function settings(): array
{
    ensure_columns();
    $row = db()->query('SELECT * FROM settings WHERE id = 1')->fetch();
    if (!$row) {
        throw new RuntimeException('Voting settings are missing.');
    }
    return $row;
}

function app_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone(APP_TIMEZONE));
}

function combine_event(string $date, string $time): DateTimeImmutable
{
    $time = strlen($time) === 5 ? $time . ':00' : $time;
    return new DateTimeImmutable($date . ' ' . $time, new DateTimeZone(APP_TIMEZONE));
}

function event_bounds(array $settings): array
{
    return [
        'reg' => combine_event($settings['event_date'], $settings['reg_start']),
        'vote' => combine_event($settings['event_date'], $settings['vote_start']),
        'end' => combine_event($settings['event_date'], $settings['vote_end']),
    ];
}

function phase_of(array $settings, ?DateTimeImmutable $now = null): string
{
    $now = $now ?? app_now();
    $bounds = event_bounds($settings);
    if ($now < $bounds['reg']) {
        return 'early';
    }
    if ($now < $bounds['vote']) {
        return 'register';
    }
    if ($now < $bounds['end']) {
        return 'vote';
    }
    return 'ended';
}

function phase_label(string $phase): string
{
    return match ($phase) {
        'early' => 'Registration has not opened',
        'register' => 'Registration is open',
        'vote' => 'Voting is open',
        'ended' => 'Voting has ended',
        default => $phase,
    };
}

function clock_label(DateTimeImmutable $dt): string
{
    return $dt->format('g:i A');
}

function date_label(DateTimeImmutable $dt): string
{
    return $dt->format('l, F j, Y');
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function require_csrf(): void
{
    $known = $_SESSION['csrf'] ?? '';
    $sent = $_POST['csrf'] ?? '';
    if ($known === '' || !is_string($sent) || !hash_equals($known, $sent)) {
        flash('The form expired. Try again.', 'error');
        $target = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');
        redirect($target);
    }
}

function flash(string $message, string $type = 'notice'): void
{
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function take_flash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    if (is_string($flash)) {
        return ['message' => $flash, 'type' => 'notice'];
    }
    if (!is_array($flash) || !is_string($flash['message'] ?? null)) {
        return null;
    }
    $type = in_array($flash['type'] ?? '', ['notice', 'success', 'error'], true) ? $flash['type'] : 'notice';
    return ['message' => $flash['message'], 'type' => $type];
}

function render_toast(array $flash): string
{
    $icon = match ($flash['type']) {
        'success' => 'check',
        'error' => 'alert',
        default => 'info',
    };
    $role = $flash['type'] === 'error' ? 'alert' : 'status';
    return '<div class="toast toast-' . h($flash['type']) . '" role="' . $role . '">'
        . '<span class="toast-icon">' . icon($icon) . '</span>'
        . '<p>' . h($flash['message']) . '</p>'
        . '<button type="button" class="toast-close" data-toast-close aria-label="Dismiss">' . icon('x') . '</button>'
        . '</div>';
}

function icon(string $name, string $class = 'icon'): string
{
    $paths = [
        'vote' => '<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
        'chart' => '<path d="M4 20V11"/><path d="M10 20V4"/><path d="M16 20v-6"/><path d="M22 20H2"/>',
        'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        'shield-lock' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><rect x="9" y="11" width="6" height="5" rx="1"/><path d="M10 11V9.5a2 2 0 0 1 4 0V11"/>',
        'lock' => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
        'arrow' => '<path d="M5 12h14"/><path d="M13 6l6 6-6 6"/>',
        'crown' => '<path d="M3 7.5l4.5 4L12 4.5l4.5 7 4.5-4-2 11.5H5L3 7.5z"/>',
        'eye' => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'eye-off' => '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 19c-6.5 0-10-7-10-7a18.5 18.5 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c6.5 0 10 7 10 7a18.5 18.5 0 0 1-2.16 3.19"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/><path d="M2 2l20 20"/>',
        'check' => '<path d="M20 6L9 17l-5-5"/>',
        'info' => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
        'alert' => '<circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/>',
        'x' => '<path d="M18 6L6 18"/><path d="M6 6l12 12"/>',
        'clock' => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        'users' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'user-plus' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6"/><path d="M22 11h-6"/>',
        'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/>',
        'qr' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3h-3z"/><path d="M21 14v.01"/><path d="M14 21h.01"/><path d="M17 21h4v-4"/>',
        'key' => '<circle cx="7.5" cy="15.5" r="5.5"/><path d="M21 2l-9.6 9.6"/><path d="M15.5 7.5l3 3L22 7l-3-3"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/>',
        'trophy' => '<path d="M8 21h8"/><path d="M12 17v4"/><path d="M7 4h10v5a5 5 0 0 1-10 0V4z"/><path d="M17 5h3v2a4 4 0 0 1-4 4"/><path d="M7 5H4v2a4 4 0 0 0 4 4"/>',
        'male' => '<circle cx="10" cy="14" r="6"/><path d="M14.5 9.5L21 3"/><path d="M15 3h6v6"/>',
        'female' => '<circle cx="12" cy="9" r="6"/><path d="M12 15v7"/><path d="M9 19h6"/>',
        'hanger' => '<path d="M12 7.5a2.2 2.2 0 1 1 2.2-2.2"/><path d="M12 7.5v1.8L3.2 15.6A1.6 1.6 0 0 0 4.1 18.5h15.8a1.6 1.6 0 0 0 .9-2.9L12 9.3"/>',
        'ticket' => '<path d="M3 9a3 3 0 0 0 0 6v3a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-3a3 3 0 0 0 0-6V6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v3z"/><path d="M13 5v2"/><path d="M13 11v2"/><path d="M13 17v2"/>',
        'link' => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
        'flag' => '<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><path d="M4 22v-7"/>',
    ];
    $fill = $name === 'crown' ? 'currentColor' : 'none';
    return '<svg class="' . h($class) . '" viewBox="0 0 24 24" width="24" height="24" fill="' . $fill . '"'
        . ' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"'
        . ' aria-hidden="true" focusable="false">' . ($paths[$name] ?? '') . '</svg>';
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/u', trim($name)) ?: [];
    $letters = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $letters .= mb_strtoupper(mb_substr($part, 0, 1));
    }
    return $letters !== '' ? $letters : '?';
}

function asset_url(string $path): string
{
    $file = __DIR__ . '/' . $path;
    return $path . (is_file($file) ? '?v=' . filemtime($file) : '');
}

function redirect(string $to): never
{
    header('Location: ' . $to);
    exit;
}

function clean_text(string $value, int $max): string
{
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    if (mb_strlen($value) > $max) {
        $value = mb_substr($value, 0, $max);
    }
    return $value;
}

function normalize_code(string $code): string
{
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
    return substr($code, 0, 6);
}

function generate_code(): string
{
    $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $max = strlen($alphabet) - 1;
    $pdo = db();
    $check = $pdo->prepare('SELECT 1 FROM employees WHERE code = ?');
    for ($attempt = 0; $attempt < 20; $attempt++) {
        $code = '';
        for ($i = 0; $i < 6; $i++) {
            $code .= $alphabet[random_int(0, $max)];
        }
        $check->execute([$code]);
        if (!$check->fetchColumn()) {
            return $code;
        }
    }
    throw new RuntimeException('Could not generate a voting code.');
}

function find_employee_by_code(string $code): ?array
{
    $stmt = db()->prepare('SELECT * FROM employees WHERE code = ?');
    $stmt->execute([$code]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function find_employee_by_id(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM employees WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function has_voted(int $employeeId): bool
{
    $stmt = db()->prepare('SELECT 1 FROM votes WHERE voter_id = ?');
    $stmt->execute([$employeeId]);
    return (bool) $stmt->fetchColumn();
}

function ensure_columns(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $stmt = db()->query("SHOW COLUMNS FROM settings LIKE 'public_url'");
    if (!$stmt->fetch()) {
        db()->exec(
            "ALTER TABLE settings ADD public_url VARCHAR(255) NOT NULL DEFAULT '' AFTER admin_password_hash"
        );
    }
    $confirmed = db()->query("SHOW COLUMNS FROM employees LIKE 'confirmed_at'");
    if (!$confirmed->fetch()) {
        db()->exec('ALTER TABLE employees ADD confirmed_at DATETIME NULL DEFAULT NULL AFTER created_at');
    }
    $device = db()->query("SHOW COLUMNS FROM employees LIKE 'device_token'");
    if (!$device->fetch()) {
        db()->exec('ALTER TABLE employees ADD device_token CHAR(32) NULL DEFAULT NULL AFTER confirmed_at');
        db()->exec('ALTER TABLE employees ADD UNIQUE KEY uq_employees_device (device_token)');
    }
    $ready = true;
}

function remember_device(string $token, DateTimeImmutable $expires): void
{
    setcookie('outfit_device', $token, [
        'expires' => $expires->getTimestamp(),
        'path' => app_cookie_path(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE['outfit_device'] = $token;
}

function clear_device_cookie(): void
{
    setcookie('outfit_device', '', [
        'expires' => time() - 3600,
        'path' => app_cookie_path(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    unset($_COOKIE['outfit_device']);
}

function device_registration(): ?array
{
    $token = (string) ($_COOKIE['outfit_device'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM employees WHERE device_token = ?');
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function candidates(string $gender, int $excludeId = 0): array
{
    $stmt = db()->prepare(
        'SELECT id, name, department, gender FROM employees
         WHERE gender = ? AND id <> ?
         ORDER BY name ASC, id ASC'
    );
    $stmt->execute([$gender, $excludeId]);
    return $stmt->fetchAll();
}

function attendee_url(): string
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $hostname = $host;
    $port = '';
    if (preg_match('/^(.+):(\d+)$/', $host, $match)) {
        $hostname = $match[1];
        $port = ':' . $match[2];
    }
    if ($hostname === 'localhost' || $hostname === '127.0.0.1') {
        $ips = lan_ips();
        if ($ips !== []) {
            $hostname = $ips[0];
            if ($port === ':80' || $port === ':443') {
                $port = '';
            }
        }
    }
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    return ($https ? 'https' : 'http') . '://' . $hostname . $port . $dir . '/';
}

function normalize_time(string $time): ?string
{
    if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
        return $time . ':00';
    }
    if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d$/', $time)) {
        return $time;
    }
    return null;
}

function valid_date(string $date): bool
{
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone(APP_TIMEZONE));
    return $dt instanceof DateTimeImmutable && $dt->format('Y-m-d') === $date;
}

function app_url(): string
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $dir = rtrim($dir, '/');
    return ($https ? 'https' : 'http') . '://' . $host . $dir . '/';
}

function lan_ips(): array
{
    $ips = [];
    $names = @gethostbynamel(gethostname()) ?: [];
    foreach ($names as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && $ip !== '127.0.0.1') {
            $ips[] = $ip;
        }
    }
    $ips = array_values(array_unique($ips));
    usort($ips, static function (string $a, string $b): int {
        $rank = static function (string $ip): int {
            if (str_starts_with($ip, '192.168.') || str_starts_with($ip, '10.')) {
                return 0;
            }
            return 1;
        };
        return $rank($a) <=> $rank($b);
    });
    return $ips;
}

function voters_for(string $column): array
{
    if ($column !== 'male_id' && $column !== 'female_id') {
        return [];
    }
    $stmt = db()->query(
        "SELECT v.$column AS candidate_id, e.name, e.department
         FROM votes v
         INNER JOIN employees e ON e.id = v.voter_id
         ORDER BY e.name ASC, e.id ASC"
    );
    $grouped = [];
    foreach ($stmt->fetchAll() as $row) {
        $grouped[(int) $row['candidate_id']][] = $row;
    }
    return $grouped;
}

function render_tally(string $title, string $gender, string $column, string $emptyNote = ''): void
{
    if ($column !== 'male_id' && $column !== 'female_id') {
        return;
    }
    $stmt = db()->prepare(
        "SELECT e.id, e.name, e.department, COUNT(v.id) AS votes
         FROM employees e
         LEFT JOIN votes v ON v.$column = e.id
         WHERE e.gender = ?
         GROUP BY e.id, e.name, e.department
         ORDER BY votes DESC, e.name ASC"
    );
    $stmt->execute([$gender]);
    $rows = $stmt->fetchAll();
    $voters = voters_for($column);
    $kind = $gender === 'male' ? 'male' : 'female';
    $total = 0;
    foreach ($rows as $row) {
        $total += (int) $row['votes'];
    }
    $top = $rows === [] ? 0 : (int) $rows[0]['votes'];

    $sig = [];
    foreach ($rows as $row) {
        $names = [];
        foreach ($voters[(int) $row['id']] ?? [] as $voter) {
            $names[] = $voter['name'];
        }
        $mark = (int) $row['votes'] === $top && $top > 0 ? '*' : '';
        $sig[] = (int) $row['id'] . $mark . ':' . implode('|', $names);
    }

    echo '<section class="board board-' . $kind . '" data-board="' . $kind . '" data-sig="' . h(implode(',', $sig)) . '">';
    echo '<header class="board-head">';
    echo '<span class="board-icon">' . icon($kind) . '</span>';
    echo '<div class="board-heading"><p class="board-kicker">Category</p><h2 class="board-title">' . h($title) . '</h2></div>';
    echo '<p class="board-total"><b data-num>' . $total . '</b><span>votes</span></p>';
    echo '</header>';

    if ($rows === []) {
        echo '<div class="empty">';
        echo '<span class="empty-icon">' . icon('hanger') . '</span>';
        echo '<strong>No contestants yet.</strong>';
        if ($emptyNote !== '') {
            echo '<p>' . h($emptyNote) . '</p>';
        }
        echo '</div></section>';
        return;
    }

    $leaders = [];
    if ($top > 0) {
        foreach ($rows as $row) {
            if ((int) $row['votes'] === $top) {
                $leaders[] = $row['name'];
            }
        }
    }
    if ($leaders !== []) {
        $label = count($leaders) > 1 ? 'Tied for first' : 'Leading';
        echo '<p class="board-status">' . icon('trophy') . '<span>' . h($label) . ': <strong>' . h(implode(', ', $leaders)) . '</strong></span></p>';
    } else {
        echo '<p class="board-status is-idle">' . icon('clock') . '<span>No votes yet</span></p>';
    }

    echo '<ol class="ranks">';
    $rank = 0;
    $previous = null;
    foreach ($rows as $index => $row) {
        $votes = (int) $row['votes'];
        if ($votes !== $previous) {
            $rank = $index + 1;
            $previous = $votes;
        }
        $isTop = $top > 0 && $votes === $top;
        $pct = $total > 0 ? (int) round(($votes / $total) * 100) : 0;
        echo '<li class="rank-row' . ($isTop ? ' is-top' : '') . '" style="--i:' . $index . '">';
        echo '<span class="rank-num">';
        if ($isTop) {
            echo '<span class="crown">' . icon('crown') . '</span>';
        }
        echo '<span class="sr-only">Rank </span>' . $rank . '</span>';
        echo '<div class="rank-body">';
        echo '<div class="rank-line">';
        echo '<div class="rank-name"><strong>' . h($row['name']) . '</strong><span>' . h($row['department']) . '</span></div>';
        echo '<div class="rank-score"><b data-num>' . $votes . '</b><small><span data-pct>' . $pct . '</span>%</small></div>';
        echo '</div>';
        echo '<div class="meter" role="presentation"><span data-meter style="--w:' . $pct . '%"></span></div>';
        $people = $voters[(int) $row['id']] ?? [];
        if ($people !== []) {
            echo '<div class="voters">';
            echo '<p class="voters-label">Voted by</p>';
            echo '<ul>';
            foreach ($people as $voter) {
                echo '<li><strong>' . h($voter['name']) . '</strong><span>' . h($voter['department']) . '</span></li>';
            }
            echo '</ul></div>';
        }
        echo '</div>';
        echo '</li>';
    }
    echo '</ol></section>';
}

function time_input_value(string $time): string
{
    return substr($time, 0, 5);
}

function ms_of(DateTimeImmutable $dt): int
{
    return $dt->getTimestamp() * 1000;
}

function layout_start(string $title, string $bodyClass = '', array $attrs = [], bool $hero = true): void
{
    header('Content-Type: text/html; charset=utf-8');
    $script = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php', '.php');
    $class = trim('page page-' . $script . ' ' . $bodyClass);
    $extra = '';
    foreach ($attrs as $name => $value) {
        $extra .= ' ' . $name . '="' . h((string) $value) . '"';
    }
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">';
    echo '<meta name="robots" content="noindex">';
    echo '<meta name="theme-color" content="#000047">';
    echo '<title>' . h($title) . ' · FEMFI</title>';
    echo '<link rel="preconnect" href="https://fonts.googleapis.com">';
    echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
    echo '<link href="https://fonts.googleapis.com/css2?family=Archivo+Black&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">';
    echo '<link rel="stylesheet" href="' . h(asset_url('assets/app.css')) . '">';
    echo '</head><body class="' . h($class) . '"' . $extra . '>';
    echo '<div class="bg" aria-hidden="true"><span class="bg-photo"></span><span class="orb orb-a"></span><span class="orb orb-b"></span>';
    echo '<span class="orb orb-c"></span><span class="bg-spot"></span><span class="bg-dots"></span></div>';
    if ($hero) {
        echo '<header class="hero"><p class="brand">FEMFI</p><h1 class="title">' . h($title) . '</h1>';
        echo '<span class="title-rule" aria-hidden="true"></span></header>';
    }
    echo '<div class="toast-stack" data-toasts aria-live="polite">';
    $flash = take_flash();
    if ($flash !== null) {
        echo render_toast($flash);
    }
    echo '</div>';
    echo '<main class="wrap">';
}

function layout_end(bool $withQr = false): void
{
    echo '</main>';
    echo '<footer class="foot"><span>FEMFI</span><i aria-hidden="true"></i><span>Best Outfit</span></footer>';
    if ($withQr) {
        echo '<script src="' . h(asset_url('assets/qrcode.js')) . '"></script>';
    }
    echo '<script src="' . h(asset_url('assets/app.js')) . '"></script>';
    echo '</body></html>';
}
