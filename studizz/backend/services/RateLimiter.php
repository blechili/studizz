<?php
/**
 * STUDIZ — Rate Limiter Service
 * ------------------------------
 * Implements a sliding-window rate-limit algorithm backed
 * by the MySQL `rate_limits` table.
 *
 * Rules (from spec):
 *  - Max 5 AI requests per rolling 60-second window (ai endpoint).
 *  - Max 15 chatbot queries per rolling 24-hour window (chat endpoint).
 *
 * The user is identified by their secure cookie token.
 * No raw IP addresses are stored; they are hashed before use
 * as a secondary fallback fingerprint.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

class RateLimiter
{
    /**
     * Check whether the user has exceeded the AI request limit.
     * If not, record this request and return true (allowed).
     * If exceeded, return false immediately without recording.
     *
     * @param  string $userToken  Resolved user cookie token
     * @return bool               true = allowed, false = rate-limited
     */
    public static function checkAI(string $userToken): bool
    {
        return self::check($userToken, 'ai', RATE_LIMIT_MAX, RATE_LIMIT_WINDOW);
    }

    /**
     * Check whether the user has exceeded the chatbot daily cap.
     * Returns the remaining query count alongside the boolean result.
     *
     * @param  string $userToken
     * @return array  ['allowed' => bool, 'remaining' => int, 'used' => int]
     */
    public static function checkChat(string $userToken): array
    {
        $window   = 60 * 60 * 24;   // 24 hours in seconds
        $allowed  = self::check($userToken, 'chat', CHAT_DAILY_MAX, $window);

        // Count queries used in the current 24h window for the counter display.
        // Cutoff is computed BY MYSQL (NOW() - INTERVAL), not PHP's date() —
        // see check()'s docblock for why mixing the two clocks is unsafe.
        $pdo      = DB::connect();
        $stmt     = $pdo->prepare(
            'SELECT COUNT(*) AS cnt FROM rate_limits
              WHERE user_token = ? AND endpoint = "chat" AND hit_at >= (NOW() - INTERVAL ? SECOND)'
        );
        $stmt->execute([$userToken, $window]);
        $used     = (int) ($stmt->fetch()['cnt'] ?? 0);

        return [
            'allowed'   => $allowed,
            'used'      => $used,
            'remaining' => max(0, CHAT_DAILY_MAX - $used),
        ];
    }

    /**
     * Check whether an unauthenticated IP has exceeded the registration limit.
     * 5 attempts per 15-minute window per hashed IP.
     *
     * @param  string $ipHash  sha256(REMOTE_ADDR) — raw IP is never stored
     * @return bool            true = allowed, false = rate-limited
     */
    public static function checkRegistration(string $ipHash): bool
    {
        // $ipHash arrives as 'ip_' + sha256 (67 chars total), which exceeds
        // rate_limits.user_token CHAR(64). MySQL silently truncates the value
        // on INSERT to 64 chars, but the SELECT WHERE user_token = ? comparison
        // uses the full 67-char param — so it never matches the stored 64-char
        // row and the count is always 0. Truncate here so both the write and
        // the read use the same 64-char form.
        return self::check(substr($ipHash, 0, 64), 'register', 5, 900);
    }

    /**
     * Check whether the user has exceeded the "ask about this summary"
     * daily cap. This is a SEPARATE quota from the main chatbot — asking
     * questions about a document does not consume chatbot queries.
     *
     * @param  string $userToken
     * @return array  ['allowed' => bool, 'remaining' => int, 'used' => int]
     */
    public static function checkAsk(string $userToken): array
    {
        $window  = 60 * 60 * 24;   // 24 hours in seconds
        $allowed = self::check($userToken, 'ask', ASK_DAILY_MAX, $window);

        // Cutoff computed by MySQL — see check()'s docblock.
        $pdo    = DB::connect();
        $stmt   = $pdo->prepare(
            'SELECT COUNT(*) AS cnt FROM rate_limits
              WHERE user_token = ? AND endpoint = "ask" AND hit_at >= (NOW() - INTERVAL ? SECOND)'
        );
        $stmt->execute([$userToken, $window]);
        $used   = (int) ($stmt->fetch()['cnt'] ?? 0);

        return [
            'allowed'   => $allowed,
            'used'      => $used,
            'remaining' => max(0, ASK_DAILY_MAX - $used),
        ];
    }

    // ── Core sliding-window logic ────────────────────────────

    /**
     * Generic sliding-window check + record.
     *
     * The count-then-insert below is, on its own, a classic TOCTOU race:
     * two concurrent requests from the same user can both read a count
     * under the cap before either has inserted its own row, letting both
     * through. We close that with a MySQL named lock (GET_LOCK) scoped to
     * this exact (token, endpoint) pair, so concurrent calls for the SAME
     * user+endpoint serialize through the critical section — calls for
     * DIFFERENT users/endpoints are unaffected and still run in parallel.
     * No new infra (e.g. Redis) needed; MySQL's session-level advisory
     * locks are sufficient at this scale.
     *
     * The cutoff timestamp is computed BY MYSQL (`NOW() - INTERVAL ?
     * SECOND`) rather than in PHP and passed in as a string. PHP's
     * `date()` uses php.ini's `date.timezone` (this box: Europe/Berlin);
     * `hit_at` is written via MySQL's own `CURRENT_TIMESTAMP(3)`, which
     * runs on the DB server's "SYSTEM" timezone. On this machine those
     * two clocks are an hour apart, so a PHP-computed cutoff string
     * compared against MySQL-written timestamps was silently purging
     * rows within ~1 second of insertion — meaning the cap was never
     * actually enforced, independent of the concurrency bug. Doing the
     * arithmetic in one place (the DB, against its own column's clock)
     * eliminates the cross-system skew entirely rather than requiring
     * the two services' timezones to be kept in sync forever.
     *
     * @param  string $token     User token
     * @param  string $endpoint  'ai' | 'chat' | 'ask'
     * @param  int    $maxHits   Maximum allowed hits in window
     * @param  int    $windowSec Window size in seconds
     * @return bool              true = request allowed
     */
    private static function check(
        string $token,
        string $endpoint,
        int    $maxHits,
        int    $windowSec
    ): bool {
        $pdo      = DB::connect();
        $lockName = 'studizz_rl_' . hash('crc32b', $token . ':' . $endpoint);

        $stmt = $pdo->prepare('SELECT GET_LOCK(?, 5) AS got');
        $stmt->execute([$lockName]);
        $got = (int) ($stmt->fetch()['got'] ?? 0);

        if ($got !== 1) {
            // Could not acquire the lock within 5s (e.g. a stuck peer
            // request) — fail closed rather than risk an unprotected
            // check, since this should never happen under normal load.
            return false;
        }

        try {
            // 1. Purge expired records to keep the table lean.
            $pdo->prepare(
                'DELETE FROM rate_limits
                  WHERE endpoint = ? AND hit_at < (NOW() - INTERVAL ? SECOND)'
            )->execute([$endpoint, $windowSec]);

            // 2. Count existing hits within the active window.
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) AS cnt FROM rate_limits
                  WHERE user_token = ? AND endpoint = ? AND hit_at >= (NOW() - INTERVAL ? SECOND)'
            );
            $stmt->execute([$token, $endpoint, $windowSec]);
            $count = (int) ($stmt->fetch()['cnt'] ?? 0);

            if ($count >= $maxHits) {
                return false;   // limit exceeded — caller sends 429
            }

            // 3. Record this new request.
            $pdo->prepare(
                'INSERT INTO rate_limits (user_token, endpoint) VALUES (?, ?)'
            )->execute([$token, $endpoint]);

            return true;
        } finally {
            $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
        }
    }
}
