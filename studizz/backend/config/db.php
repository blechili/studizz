<?php
/**
 * STUDIZ — Database Configuration
 * ---------------------------------
 * Provides a request-scoped PDO connection cache (NOT a true cross-request
 * singleton — see the note on $instance below) used by every backend script.
 *
 * CURRENT STATE — local XAMPP dev only:
 *   Credentials below are hardcoded (host 127.0.0.1, user root, empty
 *   password) because that's XAMPP's default MySQL setup. This is
 *   intentional for local development — do not add a guard that throws
 *   on an empty password.
 *
 * VPS CUTOVER CHECKLIST (when deploying to the production target):
 *   Replace the hardcoded values below with getenv() reads, and set these
 *   in /etc/php/8.x/fpm/pool.d/www.conf (or a .env loader, not committed):
 *     env[STUDIZ_DB_HOST] = 127.0.0.1
 *     env[STUDIZ_DB_PORT] = 3306
 *     env[STUDIZ_DB_NAME] = studiz_db
 *     env[STUDIZ_DB_USER] = studiz_user
 *     env[STUDIZ_DB_PASS] = <strong-password>
 */

declare(strict_types=1);

class DB
{
    // NOT a true singleton: PHP-FPM is shared-nothing per request, so
    // this static property is reinitialised to null at the start of
    // every single HTTP request — there is no connection reuse ACROSS
    // requests. What this actually buys is intra-request memoisation:
    // if multiple service classes call DB::connect() within the same
    // request, they share one connection instead of opening several.
    private static ?PDO $instance = null;

    /**
     * Returns the request-scoped PDO connection (see class-level note).
     * Throws a RuntimeException (caught at call-site) if
     * the connection cannot be established.
     */
    public static function connect(): PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        // Read from environment variables set in:
        //   Local XAMPP: C:\xampp\apache\conf\httpd.conf (SetEnv ...)
        //   VPS PHP-FPM: /etc/php/8.x/fpm/pool.d/studiz.conf (env[...] = ...)
        // Fallbacks keep local XAMPP working without any env vars set.
        $host = (string) (getenv('STUDIZ_DB_HOST') ?: '127.0.0.1');
        $port = (string) (getenv('STUDIZ_DB_PORT') ?: '3306');
        $name = (string) (getenv('STUDIZ_DB_NAME') ?: 'studiz_db');
        $user = (string) (getenv('STUDIZ_DB_USER') ?: 'root');
        $pass = (string) (getenv('STUDIZ_DB_PASS') ?: '');

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,   // real prepared statements
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        ];

        self::$instance = new PDO($dsn, $user, $pass, $options);
        return self::$instance;
    }

    // Prevent direct instantiation and cloning.
    private function __construct() {}
    private function __clone()    {}
}
