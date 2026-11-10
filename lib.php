<?php
// Shared helpers for the Lady English website.
const DATA_DIR = __DIR__ . '/data';
const FALLBACK_RATE = 47.7;   // used only if the live exchange rate cannot be loaded

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// Courses: [name, original price in USD per level]. Change prices here.
function courses(): array {
    return [['English', 140], ['French', 180], ['Spanish', 180], ['Turkish', 180], ['Italian', 180]];
}

function ensure_data_dir(): void {
    if (!is_dir(DATA_DIR)) { @mkdir(DATA_DIR, 0755, true); }
}

// Offer settings (changed by the owner in admin.php).
function settings(): array {
    $s = ['offerActive' => true, 'offerPercent' => 30];
    $f = DATA_DIR . '/settings.json';
    if (is_file($f)) {
        $j = json_decode((string)@file_get_contents($f), true);
        if (is_array($j)) {
            $s['offerActive'] = !empty($j['offerActive']);
            $p = (int)($j['offerPercent'] ?? 30);
            if ($p >= 1 && $p <= 90) { $s['offerPercent'] = $p; }
        }
    }
    return $s;
}

function save_settings(bool $on, int $pct): bool {
    ensure_data_dir();
    $json = json_encode(['offerActive' => $on, 'offerPercent' => $pct]);
    return @file_put_contents(DATA_DIR . '/settings.json', $json, LOCK_EX) !== false;
}

function price_now(int $base, array $s): int {
    return $s['offerActive'] ? (int)round($base * (100 - $s['offerPercent']) / 100) : $base;
}

function http_get(string $url): ?string {
    if (function_exists('curl_init')) {
        $c = curl_init($url);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_FOLLOWLOCATION => true]);
        $r = curl_exec($c);
        curl_close($c);
        return is_string($r) ? $r : null;
    }
    $ctx = stream_context_create(['http' => ['timeout' => 4]]);
    $r = @file_get_contents($url, false, $ctx);
    return is_string($r) ? $r : null;
}

// Returns [USD to TRY rate, true if the rate is real (live or cached), false if it is the fallback].
function usd_try(): array {
    $f = DATA_DIR . '/rate.json';
    $cached = null;
    if (is_file($f)) {
        $j = json_decode((string)@file_get_contents($f), true);
        if (is_array($j) && isset($j['rate'], $j['time']) && is_numeric($j['rate'])) {
            $cached = (float)$j['rate'];
            if (time() - (int)$j['time'] < 6 * 3600) { return [$cached, true]; }
        }
    }
    $raw = http_get('https://api.frankfurter.app/latest?from=USD&to=TRY');
    $d = $raw ? json_decode($raw, true) : null;
    $r = is_array($d) ? ($d['rates']['TRY'] ?? null) : null;
    if (is_numeric($r) && $r > 1 && $r < 1000) {
        ensure_data_dir();
        @file_put_contents($f, json_encode(['rate' => (float)$r, 'time' => time()]), LOCK_EX);
        return [(float)$r, true];
    }
    if ($cached !== null) { return [$cached, true]; }
    return [FALLBACK_RATE, false];
}

// ===== Visitor messages (inbox) and email notification =====
const MSG_GUARD = "<?php http_response_code(404); exit; ?>\n";   // keeps the file unreadable from the web

function load_config(): array {
    $f = __DIR__ . '/config.php';
    $c = is_file($f) ? include $f : [];
    return is_array($c) ? $c : [];
}

function email_configured(): bool {
    $c = load_config();
    return !empty($c['smtp_user']) && !empty($c['smtp_password']) && is_file(__DIR__ . '/phpmailer/PHPMailer.php');
}

function load_messages(): array {
    $f = DATA_DIR . '/messages.php';
    if (!is_file($f)) { return []; }
    $raw = (string)@file_get_contents($f);
    $j = json_decode(substr($raw, strlen(MSG_GUARD)), true);
    return is_array($j) ? $j : [];
}

function write_messages(array $list): bool {
    ensure_data_dir();
    $json = json_encode(array_values($list), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return @file_put_contents(DATA_DIR . '/messages.php', MSG_GUARD . $json, LOCK_EX) !== false;
}

function save_message(string $name, string $email, string $text): bool {
    $list = load_messages();
    array_unshift($list, ['id' => bin2hex(random_bytes(6)), 'time' => date('Y-m-d H:i'), 'name' => $name, 'email' => $email, 'message' => $text]);
    return write_messages(array_slice($list, 0, 500));
}

function delete_message(string $id): bool {
    $list = array_filter(load_messages(), fn($m) => ($m['id'] ?? '') !== $id);
    return write_messages($list);
}

// Emails the owner. Uses Gmail (needs config.php) if set up, otherwise PHP's mail().
function send_notification(string $name, string $email, string $text): bool {
    $cfg = load_config();
    $to = !empty($cfg['owner_email']) ? (string)$cfg['owner_email'] : 'ladyenglishcourse@gmail.com';
    $subject = 'New message from ' . $name . ' (Lady English website)';
    $body = "Name: $name\nEmail: " . ($email !== '' ? $email : '(not given)') . "\n\nMessage:\n$text\n";

    if (email_configured()) {
        try {
            require_once __DIR__ . '/phpmailer/Exception.php';
            require_once __DIR__ . '/phpmailer/PHPMailer.php';
            require_once __DIR__ . '/phpmailer/SMTP.php';
            $m = new \PHPMailer\PHPMailer\PHPMailer(true);
            $m->isSMTP();
            $m->Host = 'smtp.gmail.com';
            $m->SMTPAuth = true;
            $m->Username = (string)$cfg['smtp_user'];
            $m->Password = (string)$cfg['smtp_password'];
            $m->SMTPSecure = 'tls';
            $m->Port = 587;
            $m->Timeout = 10;
            $m->CharSet = 'UTF-8';
            $m->setFrom((string)$cfg['smtp_user'], 'Lady English Website');
            $m->addAddress($to);
            if ($email !== '') { $m->addReplyTo($email, $name); }
            $m->Subject = $subject;
            $m->Body = $body;
            $m->send();
            return true;
        } catch (\Throwable $e) {
            error_log('Lady English email error: ' . $e->getMessage());
            return false;
        }
    }

    $host = strtolower(explode(':', (string)($_SERVER['HTTP_HOST'] ?? 'ladyenglish.com'))[0]);
    $host = preg_replace('/^www\./', '', preg_replace('/[^a-z0-9.\-]/', '', $host));
    if ($host === '' || $host === 'localhost') { $host = 'ladyenglish.com'; }
    $headers = ['From: Lady English Website <noreply@' . $host . '>', 'MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8'];
    if ($email !== '') { $headers[] = 'Reply-To: ' . $email; }
    return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, implode("\r\n", $headers));
}
