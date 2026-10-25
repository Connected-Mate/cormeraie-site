<?php
/**
 * Newsletter sign-up for the static site.
 *
 * The site is a folder of HTML files on OVH shared hosting, so the Next.js
 * route handler at /api/subscribe has no server to run on. This is its
 * stand-in: same JSON contract in and out, so the rail's subscribe box
 * needs no change — { email, website } in, { ok, message } out.
 *
 * Addresses are appended to a CSV that Apache is told to refuse
 * (see the .htaccess written by scripts/build-static.mjs). Set
 * BUTTONDOWN_API_KEY in a .env beside this file to forward them to
 * Buttondown as well; without it the CSV is the whole store.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

const STORE = __DIR__ . '/subscribers.csv';
const RATE_STORE = __DIR__ . '/.subscribe-rate.json';
const WINDOW_SECONDS = 600;
const MAX_PER_WINDOW = 5;

/** French unless the browser clearly prefers English — matches the site. */
function locale(): string
{
    $header = strtolower($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
    foreach (explode(',', $header) as $part) {
        $tag = trim(explode(';', $part)[0]);
        if ($tag === '') {
            continue;
        }
        $primary = explode('-', $tag)[0];
        if ($primary === 'fr') {
            return 'fr';
        }
        if ($primary === 'en') {
            return 'en';
        }
    }
    return 'fr';
}

function say(bool $ok, string $key, int $status): never
{
    $messages = [
        'fr' => [
            'success' => "C'est noté. À bientôt.",
            'invalid' => "Cette adresse ne semble pas valide.",
            'rate' => "Trop de tentatives. Réessaie dans quelques minutes.",
            'bad' => "Requête invalide.",
            'error' => "Une erreur est survenue, réessaie plus tard.",
        ],
        'en' => [
            'success' => "Noted. See you soon.",
            'invalid' => "That address does not look valid.",
            'rate' => "Too many attempts. Try again in a few minutes.",
            'bad' => "Invalid request.",
            'error' => "Something went wrong, try again later.",
        ],
    ];

    http_response_code($status);
    echo json_encode(
        ['ok' => $ok, 'message' => $messages[locale()][$key]],
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

/** Per-IP throttle. Best effort: one small file, rewritten under a lock. */
function rateLimited(string $ip): bool
{
    $handle = @fopen(RATE_STORE, 'c+');
    if ($handle === false) {
        return false; // never lock people out because the disk misbehaved
    }
    flock($handle, LOCK_EX);

    $raw = stream_get_contents($handle);
    $log = json_decode($raw ?: '{}', true);
    if (!is_array($log)) {
        $log = [];
    }

    $now = time();
    foreach ($log as $key => $times) {
        $kept = array_values(array_filter(
            is_array($times) ? $times : [],
            static fn ($t) => is_int($t) && $now - $t < WINDOW_SECONDS
        ));
        if ($kept === []) {
            unset($log[$key]);
        } else {
            $log[$key] = $kept;
        }
    }

    $mine = $log[$ip] ?? [];
    $mine[] = $now;
    $log[$ip] = $mine;

    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($log));
    flock($handle, LOCK_UN);
    fclose($handle);

    return count($mine) > MAX_PER_WINDOW;
}

/** Forward to Buttondown when a key is configured. Returns false on failure. */
function forwardToButtondown(string $email, string $apiKey): bool
{
    $ch = curl_init('https://api.buttondown.email/v1/subscribers');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Token ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode(['email' => $email]),
    ]);
    curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $status >= 200 && $status < 300;
}

function buttondownKey(): ?string
{
    $envFile = __DIR__ . '/.env';
    if (!is_readable($envFile)) {
        return null;
    }
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), 'BUTTONDOWN_API_KEY=')) {
            $value = trim(substr(trim($line), strlen('BUTTONDOWN_API_KEY=')), " \"'");
            return $value !== '' ? $value : null;
        }
    }
    return null;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    say(false, 'bad', 405);
}

$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$ip = trim(explode(',', (string) $ip)[0]);
if (rateLimited($ip)) {
    say(false, 'rate', 429);
}

$payload = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($payload)) {
    say(false, 'bad', 400);
}

// Honeypot: bots fill it, real visitors never see it. Pretend it worked so
// the bot learns nothing, and store nothing.
if (trim((string) ($payload['website'] ?? '')) !== '') {
    say(true, 'success', 200);
}

$email = trim((string) ($payload['email'] ?? ''));
if ($email === '' || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    say(false, 'invalid', 400);
}

$row = [gmdate('c'), $email, hash('sha256', $ip)];
$handle = @fopen(STORE, 'a');
if ($handle === false) {
    say(false, 'error', 500);
}
flock($handle, LOCK_EX);
fputcsv($handle, $row);
flock($handle, LOCK_UN);
fclose($handle);
@chmod(STORE, 0600);

$key = buttondownKey();
if ($key !== null && !forwardToButtondown($email, $key)) {
    // Kept locally, so the address is not lost; the reader need not care.
    error_log('[subscribe] Buttondown forwarding failed for a subscriber.');
}

say(true, 'success', 200);
