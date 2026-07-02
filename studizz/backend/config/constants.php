<?php
declare(strict_types=1);

define('OPENAI_API_KEY',  (string) getenv('OPENAI_API_KEY'));
define('YOUTUBE_API_KEY', (string) getenv('YOUTUBE_API_KEY'));
define('OPENAI_MODEL',     'gpt-4o-mini');
define('OPENAI_ENDPOINT',  'https://api.openai.com/v1/chat/completions');
define('YOUTUBE_ENDPOINT', 'https://www.googleapis.com/youtube/v3/search');

define('RATE_LIMIT_MAX',    5);
define('RATE_LIMIT_WINDOW', 60);
define('CHAT_DAILY_MAX',    15);
define('ASK_DAILY_MAX',     20);
define('AI_TIMEOUT_SEC',    300);
define('CURL_TIMEOUT_SEC',  90);   // raised from 55s — the combined summary+Q&A call can run long on documents with embedded essay prompts

// Character caps on extracted document text fed into OpenAI calls.
// gpt-4o-mini's context window is large (128K tokens) so these are
// generous — sized to avoid runaway cost/latency on huge documents
// rather than to protect the model's context limit.
define('SUMMARY_CONTEXT_CHAR_CAP', 40000);   // process_document: text -> summary/quiz
define('ASK_CONTEXT_CHAR_CAP',     40000);   // ask_summary: text -> per-question grounding

define('QUIZ_REGEN_MIN_QUESTIONS', 3);
define('QUIZ_REGEN_MAX_QUESTIONS', 25);

// Path constants are resolved relative to this file's location so neither
// local dev nor VPS needs the absolute path hardcoded. realpath() is used
// when the path exists (gives a clean canonical form); the raw joined path
// is used as a fallback for fresh installs where directories may not yet
// have been created (realpath() returns false on non-existent paths, which
// would otherwise collapse to '/' — a dangerous silent failure).
define('UPLOAD_TMP_DIR',    rtrim(str_replace('\\', '/', realpath(__DIR__ . '/../../uploads/temp')    ?: __DIR__ . '/../../uploads/temp'), '/')    . '/');
define('UPLOAD_MAX_BYTES',  20 * 1024 * 1024);

// Link-based ingestion (process_document with a "url" field instead of a
// file). Fetches happen server-side only, with SSRF guards — see
// resolveUrlToText() / curlFetchSafely() in api_gateway.php.
define('URL_FETCH_TIMEOUT_SEC',         15);
define('URL_FETCH_CONNECT_TIMEOUT_SEC', 6);
define('URL_FETCH_MAX_REDIRECTS',       3);

// PYTHON_BIN: local dev = Windows exe path; VPS = /usr/bin/python3 or wherever pip installed it.
// Set the PYTHON_BIN environment variable in your PHP-FPM pool config (VPS) or Apache
// httpd.conf (local XAMPP). The Windows path below is kept only as the local fallback.
define('PYTHON_BIN',    (string) (getenv('PYTHON_BIN') ?: 'C:/Users/user/AppData/Local/Programs/Python/Python314/python.exe'));
define('PYTHON_SCRIPT', str_replace('\\', '/', realpath(__DIR__ . '/../../python/extract_text.py') ?: __DIR__ . '/../../python/extract_text.py'));

define('LOG_DIR',        rtrim(str_replace('\\', '/', realpath(__DIR__ . '/../logs')               ?: __DIR__ . '/../logs'),  '/')  . '/');
define('ERROR_LOG_FILE', rtrim(str_replace('\\', '/', realpath(__DIR__ . '/../logs')               ?: __DIR__ . '/../logs'),  '/') . '/errors.log');

define('USER_COOKIE_NAME',  'studizz_uid');
define('COOKIE_LIFETIME',   60 * 60 * 24 * 365);
// Local dev: http://localhost. VPS: set STUDIZ_ORIGIN env var to https://yourdomain.com
define('ALLOWED_ORIGIN',    (string) (getenv('STUDIZZ_ORIGIN') ?: 'http://localhost'));

// STUDIZ_ENV: 'dev' shows PHP errors in HTTP responses (local debugging).
//             'prod' silences them (safe for VPS). Set via env var in the
//             PHP-FPM pool config — do not hardcode 'prod' here or both
//             environments need the file changed before/after deploy.
define('STUDIZZ_ENV', (string) (getenv('STUDIZZ_ENV') ?: 'dev'));