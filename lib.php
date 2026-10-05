<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

date_default_timezone_set(APP_TIMEZONE);

$cookiePath = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$cookiePath = rtrim($cookiePath, '/') . '/';
session_set_cookie_params([
    'lifetime' => 0,
    'path' => $cookiePath === '//' ? '/' : $cookiePath,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

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
        flash('error', 'The form expired. Try again.');
        $target = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');
        redirect($target);
    }
}

function flash(string $message): void
{
    $_SESSION['flash'] = $message;
}

function take_flash(): ?string
{
    $message = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_string($message) ? $message : null;
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
    $ready = true;
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

function render_tally(string $title, string $gender, string $column): void
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
    echo '<h3 class="list-title ' . ($gender === 'male' ? 'list-title-male' : 'list-title-female') . '">' . h($title) . '</h3>';
    if ($rows === []) {
        echo '<p class="hint">No one registered in this list.</p>';
        return;
    }
    $top = (int) $rows[0]['votes'];
    $leaders = [];
    if ($top > 0) {
        foreach ($rows as $row) {
            if ((int) $row['votes'] === $top) {
                $leaders[] = $row['name'];
            }
        }
    }
    if ($leaders !== []) {
        $label = count($leaders) > 1 ? 'Tie' : 'Leading';
        echo '<p class="winner">' . h($label) . ': ' . h(implode(', ', $leaders)) . ' · ' . $top . '</p>';
    } else {
        echo '<p class="hint">No votes yet.</p>';
    }
    $max = max(1, $top);
    foreach ($rows as $row) {
        $votes = (int) $row['votes'];
        $width = (int) round(($votes / $max) * 100);
        echo '<div class="bar-row">';
        echo '<div class="bar-meta"><strong>' . h($row['name']) . '</strong><span>' . h($row['department']) . '</span></div>';
        echo '<div class="bar"><span style="width:' . $width . '%"></span></div>';
        echo '<b>' . $votes . '</b>';
        echo '</div>';
    }
}

function time_input_value(string $time): string
{
    return substr($time, 0, 5);
}

function ms_of(DateTimeImmutable $dt): int
{
    return $dt->getTimestamp() * 1000;
}

function layout_start(string $title, string $bodyClass = '', array $attrs = []): void
{
    header('Content-Type: text/html; charset=utf-8');
    $class = trim('page ' . $bodyClass);
    $extra = '';
    foreach ($attrs as $name => $value) {
        $extra .= ' ' . $name . '="' . h((string) $value) . '"';
    }
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<meta name="robots" content="noindex">';
    echo '<title>' . h($title) . '</title>';
    echo '<link rel="preconnect" href="https://fonts.googleapis.com">';
    echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
    echo '<link href="https://fonts.googleapis.com/css2?family=Archivo+Black&family=Archivo+Narrow:wght@500;600;700&display=swap" rel="stylesheet">';
    echo '<link rel="stylesheet" href="assets/app.css">';
    echo '</head><body class="' . h($class) . '"' . $extra . '>';
    echo '<header class="top"><p class="brand">FEMFI</p><h1>' . h($title) . '</h1></header>';
    echo '<main class="wrap">';
    $message = take_flash();
    if ($message !== null) {
        echo '<p class="flash" role="status">' . h($message) . '</p>';
    }
}

function layout_end(bool $withQr = false): void
{
    echo '</main>';
    echo '<footer class="foot">Best Outfit</footer>';
    if ($withQr) {
        echo '<script src="assets/qrcode.js"></script>';
    }
    echo '<script src="assets/app.js"></script>';
    echo '</body></html>';
}
