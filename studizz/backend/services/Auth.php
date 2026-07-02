<?php
/**
 * STUDIZ — Auth Service
 * ----------------------
 * Handles user token resolution, onboarding (registration),
 * and session-hydration helpers.
 *
 * Security contract:
 *  - Token is a 32-byte CSPRNG hex string (64 chars).
 *  - Cookie flags: HttpOnly, Secure, SameSite=Strict.
 *  - PII never leaves the database layer.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

class Auth
{
    /**
     * Resolve the current user token from the HTTP-Only cookie.
     * Returns the token string or NULL if absent / invalid.
     */
    public static function resolveToken(): ?string
    {
        $raw = $_COOKIE[USER_COOKIE_NAME] ?? null;
        if ($raw === null) return null;

        // Validate format: exactly 64 lowercase hex characters.
        if (!preg_match('/^[a-f0-9]{64}$/', $raw)) return null;

        // Confirm the token exists in the database.
        $pdo  = DB::connect();
        $stmt = $pdo->prepare('SELECT token FROM users WHERE token = ? LIMIT 1');
        $stmt->execute([$raw]);
        $row  = $stmt->fetch();

        return $row ? $row['token'] : null;
    }

    /**
     * Register a new user during onboarding.
     *
     * @param  array $data  Keys: name, username, class, email, phone
     * @return array        ['ok' => true, 'token' => '...', ...] or ['ok' => false, 'error' => '...']
     */
    public static function register(array $data): array
    {
        // ── Input sanitisation ──────────────────────────────
        $name     = self::sanitiseString($data['name']     ?? '', 120);
        $username = self::sanitiseString($data['username'] ?? '', 60);
        $class    = self::sanitiseString($data['class']    ?? '', 80);
        $email    = filter_var(trim($data['email']  ?? ''), FILTER_VALIDATE_EMAIL);
        $phone    = preg_replace('/[^\d+\-\s()]/', '', $data['phone'] ?? '');

        // ── Validation ──────────────────────────────────────
        if (!$name)          return ['ok' => false, 'error' => 'Name is required.'];
        if (!$username)      return ['ok' => false, 'error' => 'Username is required.'];
        if (!$class)         return ['ok' => false, 'error' => 'Class is required.'];
        if (!$email)         return ['ok' => false, 'error' => 'A valid email address is required.'];
        if (strlen($phone) < 7) return ['ok' => false, 'error' => 'A valid phone number is required.'];

        // ── Generate secure token ───────────────────────────
        $token = bin2hex(random_bytes(32));   // 64-char hex

        try {
            $pdo  = DB::connect();
            $stmt = $pdo->prepare(
                'INSERT INTO users (token, name, username, class, email, phone)
                 VALUES (:token, :name, :username, :class, :email, :phone)'
            );
            $stmt->execute([
                ':token'    => $token,
                ':name'     => $name,
                ':username' => $username,
                ':class'    => $class,
                ':email'    => $email,
                ':phone'    => $phone,
            ]);
        } catch (PDOException $e) {
            // Duplicate username or email (MySQL error code 23000).
            // Generic message — do not reveal which field conflicted, as that
            // would allow an attacker to enumerate existing usernames/emails.
            if ($e->getCode() === '23000') {
                return ['ok' => false, 'error' => 'Registration failed. Please check your details and try again.'];
            }
            error_log('[Auth] Register failed: ' . $e->getMessage(), 3, ERROR_LOG_FILE);
            return ['ok' => false, 'error' => 'Registration failed. Please try again.'];
        }

        return [
            'ok'       => true,
            'token'    => $token,
            'username' => $username,
            'name'     => $name,
        ];
    }

    /**
     * Issue the secure HTTP-Only cookie to the browser.
     * Must be called BEFORE any output is sent to the client.
     */
    public static function issueTokenCookie(string $token): void
    {
        setcookie(
            USER_COOKIE_NAME,
            $token,
            [
                'expires'  => time() + COOKIE_LIFETIME,
                'path'     => '/',
                'secure'   => true,          // HTTPS only — enforced by Nginx
                'httponly' => true,          // JavaScript cannot read this cookie
                'samesite' => 'Strict',
            ]
        );
    }

    /**
     * Fetch minimal public-safe user data by token.
     * Never returns PII columns like email or phone.
     */
    public static function getUserByToken(string $token): ?array
    {
        $pdo  = DB::connect();
        $stmt = $pdo->prepare(
            'SELECT id, username, name, class FROM users WHERE token = ? LIMIT 1'
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Invalidate the current session by replacing the stored token with a
     * new CSPRNG value. Any copy of the old token (in another browser tab,
     * a stolen cookie, etc.) immediately becomes invalid because it no longer
     * matches any row in the DB.
     *
     * Does NOT issue the new token as a cookie — the caller (logout.php) clears
     * the cookie instead, so the user's browser ends up with no valid session.
     *
     * @param  string $oldToken  The token to invalidate
     * @return void
     */
    public static function rotateToken(string $oldToken): void
    {
        $newToken = bin2hex(random_bytes(32));
        $pdo = DB::connect();
        $pdo->prepare('UPDATE users SET token = ? WHERE token = ?')
            ->execute([$newToken, $oldToken]);
        // $newToken is intentionally discarded — it is never issued to any
        // client, so the account is effectively locked until the user
        // re-registers (or, once a recovery flow exists, re-authenticates).
    }

    /**
     * Expire the session cookie on the client side.
     * Sets expires to the past so the browser deletes it immediately.
     * Must be called BEFORE any output is sent to the client.
     */
    public static function clearTokenCookie(): void
    {
        setcookie(
            USER_COOKIE_NAME,
            '',
            [
                'expires'  => time() - 3600,
                'path'     => '/',
                'secure'   => true,
                'httponly' => true,
                'samesite' => 'Strict',
            ]
        );
    }

    /**
     * Change an existing user's username.
     * Same sanitisation rule as registration (non-empty after trim/strip_tags,
     * max 60 chars) — kept identical so both paths agree on what a valid
     * username looks like. Uniqueness is enforced by the DB's UNIQUE column;
     * a clash surfaces as a friendly "already taken" error.
     *
     * @return array  ['ok' => true, 'username' => '...'] or ['ok' => false, 'error' => '...']
     */
    public static function updateUsername(string $token, string $newUsername): array
    {
        $newUsername = self::sanitiseString($newUsername, 60);
        if (!$newUsername) {
            return ['ok' => false, 'error' => 'Username is required.'];
        }

        $current = self::getUserByToken($token);
        if ($current && $current['username'] === $newUsername) {
            return ['ok' => true, 'username' => $newUsername];   // no-op, already this value
        }

        $pdo = DB::connect();
        try {
            $stmt = $pdo->prepare('UPDATE users SET username = :username WHERE token = :token');
            $stmt->execute([':username' => $newUsername, ':token' => $token]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                return ['ok' => false, 'error' => 'That username is already taken.'];
            }
            error_log('[Auth] updateUsername failed: ' . $e->getMessage(), 3, ERROR_LOG_FILE);
            return ['ok' => false, 'error' => 'Could not update username. Please try again.'];
        }

        return ['ok' => true, 'username' => $newUsername];
    }

    // ── Private helpers ──────────────────────────────────────

    private static function sanitiseString(string $val, int $maxLen): string
    {
        $val = trim(strip_tags($val));
        return mb_substr($val, 0, $maxLen);
    }
}
