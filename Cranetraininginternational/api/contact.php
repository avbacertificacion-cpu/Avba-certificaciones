<?php
/**
 * Contact / quote-request handler for the Crane Training International site.
 *
 * - Validates all fields server-side (never trust the client-side check).
 * - Honeypot field "website" silently discards bot submissions.
 * - Per-IP rate limiting (max 5 submissions / hour) via a local JSON file.
 * - Sends mail through SmtpMailer using credentials from
 *   config/mail-config.php, which is NOT committed to the repo — see
 *   Cranetraininginternational/README.md.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function respond(bool $success, string $message, int $httpCode = 200): void
{
    http_response_code($httpCode);
    echo json_encode(['success' => $success, 'message' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Method not allowed.', 405);
}

// --- Honeypot -----------------------------------------------------------
// Hidden field real users never see or fill. Bots that auto-fill every
// input trip it. Respond as if successful so scrapers gain no signal.
if (!empty($_POST['website'])) {
    respond(true, 'Thank you. Your request has been sent.');
}

// --- Rate limiting (per IP, file-based) ----------------------------------
$storageDir = __DIR__ . '/../storage';
if (!is_dir($storageDir)) {
    @mkdir($storageDir, 0750, true);
}

$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rateLimitFile = $storageDir . '/rate-limit.json';
$maxPerHour = 5;
$windowSeconds = 3600;
$now = time();

$fp = @fopen($rateLimitFile, 'c+');
if ($fp && flock($fp, LOCK_EX)) {
    $raw = stream_get_contents($fp);
    $data = json_decode($raw ?: '{}', true);
    if (!is_array($data)) {
        $data = [];
    }

    $history = $data[$ip] ?? [];
    $history = array_values(array_filter($history, function ($ts) use ($now, $windowSeconds) {
        return is_int($ts) && ($now - $ts) < $windowSeconds;
    }));

    if (count($history) >= $maxPerHour) {
        flock($fp, LOCK_UN);
        fclose($fp);
        respond(false, 'Too many requests from this connection. Please try again later or email us directly.', 429);
    }

    $history[] = $now;
    $data[$ip] = $history;

    // Prune IPs with no recent activity so the file doesn't grow forever.
    foreach ($data as $key => $timestamps) {
        $timestamps = array_values(array_filter($timestamps, function ($ts) use ($now, $windowSeconds) {
            return is_int($ts) && ($now - $ts) < $windowSeconds;
        }));
        if (empty($timestamps)) {
            unset($data[$key]);
        } else {
            $data[$key] = $timestamps;
        }
    }

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
} elseif ($fp) {
    fclose($fp);
}

// --- Field validation ------------------------------------------------------
function field(string $key): string
{
    return trim((string) ($_POST[$key] ?? ''));
}

$name = field('name');
$company = field('company');
$email = field('email');
$phone = field('phone');
$program = field('program');
$participants = field('participants');
$location = field('location');
$message = field('message');

$errors = [];

if ($name === '' || mb_strlen($name) > 150) {
    $errors[] = 'name';
}
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 200) {
    $errors[] = 'email';
}
if ($location === '' || !in_array($location, ['CTI Houston', 'Client site'], true)) {
    $errors[] = 'location';
}
if ($participants !== '' && (!ctype_digit($participants) || (int) $participants < 1)) {
    $errors[] = 'participants';
}
if (mb_strlen($message) > 4000 || mb_strlen($company) > 150 || mb_strlen($phone) > 40 || mb_strlen($program) > 150) {
    $errors[] = 'length';
}

if (!empty($errors)) {
    respond(false, 'Please correct the highlighted fields and resubmit.', 422);
}

// --- Load server-only SMTP config ------------------------------------------
$configPath = __DIR__ . '/../config/mail-config.php';
if (!is_file($configPath)) {
    error_log('CTI contact form: missing config/mail-config.php on server.');
    respond(false, 'The contact form is not fully configured yet. Please email us directly.', 503);
}

$config = require $configPath;

require __DIR__ . '/smtp-mailer.php';

$strip = static function (string $value): string {
    return str_replace(["\r", "\n"], ' ', $value);
};

$bodyLines = [
    'New training request from the CTI website',
    '',
    'Name: ' . $strip($name),
    'Company: ' . $strip($company ?: '(not provided)'),
    'Email: ' . $strip($email),
    'Phone: ' . $strip($phone ?: '(not provided)'),
    'Program of interest: ' . $strip($program ?: '(not provided)'),
    'Number of participants: ' . $strip($participants ?: '(not provided)'),
    'Training location: ' . $strip($location),
    '',
    'Message:',
    $message !== '' ? $message : '(none)',
    '',
    'Submitted: ' . date('Y-m-d H:i:s T'),
    'IP: ' . $ip,
];

try {
    $mailer = new SmtpMailer(
        (string) $config['smtp_host'],
        (int) $config['smtp_port'],
        (string) $config['smtp_secure'],
        (string) $config['smtp_username'],
        (string) $config['smtp_password']
    );

    $mailer->send(
        (string) $config['from_email'],
        (string) $config['from_name'],
        (string) $config['contact_to'],
        'New training request — ' . ($program !== '' ? $program : 'General inquiry') . ' (' . $name . ')',
        implode("\n", $bodyLines),
        $email
    );
} catch (Throwable $e) {
    error_log('CTI contact form SMTP error: ' . $e->getMessage());
    respond(false, 'We could not send your request right now. Please email us directly and we will respond as soon as possible.', 502);
}

respond(true, 'Thank you. Your request has been sent — we will follow up shortly.');
