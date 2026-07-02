<?php
/**
 * STUDIZ — Logout Gateway
 * ------------------------
 * POST /backend/gateways/logout.php
 *
 * Invalidates the current session in two steps:
 *  1. Rotates the token in the DB — the old cookie value immediately
 *     fails Auth::resolveToken() on any subsequent request, including
 *     requests from other devices that may have a copy of the same
 *     cookie (e.g. a stolen token).
 *  2. Clears the session cookie on this client by expiring it.
 *
 * Always returns { "ok": true } — even if no valid token is present,
 * so the frontend can treat any 200 as "done" and reload.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/Auth.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

set_exception_handler(static function (Throwable $e): void {
    error_log(
        '[' . date('Y-m-d H:i:s') . '] [logout] Uncaught '
        . get_class($e) . ': ' . $e->getMessage()
        . ' in ' . $e->getFile() . ':' . $e->getLine(),
        3,
        ERROR_LOG_FILE
    );
    if (!headers_sent()) http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'An unexpected error occurred. Please try again.']);
});

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

$token = Auth::resolveToken();

if ($token !== null) {
    // Rotate first, then clear — this order matters: if the cookie-clear
    // Set-Cookie header somehow fails to reach the client, the rotated DB
    // token still makes the old cookie value worthless.
    Auth::rotateToken($token);
}

Auth::clearTokenCookie();

echo json_encode(['ok' => true]);
