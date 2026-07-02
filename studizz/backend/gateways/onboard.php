<?php
/**
 * STUDIZ — Onboarding Gateway
 * ----------------------------
 * POST /backend/gateways/onboard.php
 *
 * Accepts the five-field registration payload from the
 * first-visit modal, creates the user record, issues the
 * secure HTTP-Only cookie, and returns JSON.
 *
 * Expected POST body (JSON):
 *   { "name": "...", "username": "...", "class": "...",
 *     "email": "...", "phone": "..." }
 *
 * Also handles, via an "action" field:
 *   { "action": "update_username", "username": "..." } — requires an
 *   existing valid token cookie; lets a registered user rename themselves.
 *
 * Responses:
 *   201  { "ok": true,  "username": "...", "name": "..." }
 *   400  { "ok": false, "error": "..." }
 *   401  { "ok": false, "error": "..." }   (update_username, no/invalid token)
 *   405  { "ok": false, "error": "Method not allowed." }
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/Auth.php';
require_once __DIR__ . '/../services/RateLimiter.php';

// ── Bootstrap ────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

set_exception_handler(static function (Throwable $e): void {
    error_log(
        '[' . date('Y-m-d H:i:s') . '] [onboard] Uncaught '
        . get_class($e) . ': ' . $e->getMessage()
        . ' in ' . $e->getFile() . ':' . $e->getLine(),
        3,
        ERROR_LOG_FILE
    );
    if (!headers_sent()) http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'An unexpected error occurred. Please try again.']);
});



// Allow only POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

// ── Parse JSON body ──────────────────────────────────────────
$rawBody = file_get_contents('php://input');
$data    = json_decode($rawBody, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON payload.']);
    exit;
}

// ── Username change (registered users only) ──────────────────
if (($data['action'] ?? '') === 'update_username') {
    $token = Auth::resolveToken();
    if ($token === null) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Authentication required.']);
        exit;
    }

    $result = Auth::updateUsername($token, $data['username'] ?? '');
    if (!$result['ok']) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $result['error']]);
        exit;
    }

    http_response_code(200);
    echo json_encode(['ok' => true, 'username' => $result['username']]);
    exit;
}

// ── Check if user already has a valid token cookie ───────────
// If they do, they've already onboarded — return 200 silently.
$existingToken = Auth::resolveToken();
if ($existingToken !== null) {
    $user = Auth::getUserByToken($existingToken);
    http_response_code(200);
    echo json_encode(['ok' => true, 'username' => $user['username'] ?? '', 'name' => $user['name'] ?? '', 'already_registered' => true]);
    exit;
}

// ── Ignore cookie-existence pings ─────────────────────────────
// checkAlreadyRegistered() in app.js sends an empty-body POST here on
// EVERY page load to see if a valid token cookie exists. When there's
// no cookie (new/unregistered visitor), that ping used to fall through
// to the rate limiter and then to Auth::register([]), which always
// fails validation ("Name is required") but had already consumed a
// slot in the 5-per-15-min IP bucket — so a handful of page reloads
// before ever touching the form could exhaust the cap and block the
// real submission. If none of the registration fields are present,
// this is just a ping: return early without touching the rate limiter.
$hasRegistrationData = false;
foreach (['name', 'username', 'class', 'email', 'phone'] as $field) {
    if (trim((string) ($data[$field] ?? '')) !== '') {
        $hasRegistrationData = true;
        break;
    }
}
if (!$hasRegistrationData) {
    http_response_code(200);
    echo json_encode(['ok' => false, 'already_registered' => false]);
    exit;
}

// ── IP rate limit — new registrations only ───────────────────
// Authenticated users (update_username) and already-registered
// users have already exited above, so only real first-time
// registration attempts reach here. 5 per 15 minutes per IP
// stops enumeration loops without bothering legitimate users.
// The raw IP is never stored — only its sha256 hash is used as
// a bucket key, matching the same pattern as the AI rate limiter.
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$ipBucket = 'ip_' . hash('sha256', $clientIp);
if (!RateLimiter::checkRegistration($ipBucket)) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Too many attempts. Please wait a moment before trying again.']);
    exit;
}

// ── Attempt registration ─────────────────────────────────────
$result = Auth::register($data);

if (!$result['ok']) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $result['error']]);
    exit;
}

// ── Issue secure HTTP-Only cookie ───────────────────────────
Auth::issueTokenCookie($result['token']);

http_response_code(201);
echo json_encode([
    'ok'       => true,
    'username' => $result['username'],
    'name'     => $result['name'],
]);
exit;
