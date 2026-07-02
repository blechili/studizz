# Studiz — Project Context for Claude Code

## What this is
Studiz is an AI-powered study platform. Users upload any document (PDF, DOCX,
PPTX/PPT, XLSX/XLS, TXT, or image — no file-type whitelist; unsupported types
are rejected gracefully if no usable text is found) OR paste a link (a direct
link to one of those file types, or a regular webpage — Studiz pulls the
readable text out of the page; video links are rejected with a friendly
error), and the app extracts the text, then uses OpenAI to generate a summary,
key points, an interactive quiz, AI-solved answers for any embedded
questions/essay prompts, and YouTube video recommendations. It also has a
folder-based archive ("Store") and a daily-capped AI chatbot.

This is a real product built by a student, deployed locally on Windows XAMPP for
development, with a Linux VPS (Nginx + PHP-FPM) as the eventual production target.

## Tech stack
- Frontend: vanilla HTML, CSS, JavaScript (no frameworks). Linear.app-inspired
  dark UI. Modular PHP-include architecture (index.php pulls in section files).
- Backend: PHP 8 with a secure API gateway pattern. MySQL via PDO with prepared
  statements.
- Text extraction: Python 3 (PyMuPDF for PDFs, Tesseract OCR for images/scanned
  pages, python-docx for Word docs, python-pptx for .pptx, a no-dependency
  binary-scan heuristic for legacy .ppt, openpyxl for .xlsx, xlrd for legacy
  .xls, direct read for plain text).
- Link ingestion: api_gateway.php fetches user-pasted URLs server-side only
  (SSRF-guarded — see gotchas below), then either runs the downloaded bytes
  through the same Python extractor as a file upload (direct file links) or
  strips a webpage down to readable text with a small PHP DOMDocument helper
  (regular webpage links). Video links are rejected before/without fetching.
- AI: OpenAI GPT-4o-mini, called server-side only via PHP cURL.
- Local dev: Windows + XAMPP (Apache, MySQL).

## Architecture rules (non-negotiable)
1. ZERO API keys on the frontend. All OpenAI/YouTube calls go through
   backend/gateways/api_gateway.php using PHP cURL. The JS only ever calls our
   own PHP endpoints via fetch().
2. Rate limiting: max 5 AI requests per 60s (sliding window in MySQL).
   Chatbot: max 15 queries per rolling 24h.
3. Execution timeout enforced via set_time_limit() on the gateway.

## File map
- public/index.php — assembles the page via PHP includes
- public/sections/*.php — navbar, modals, page-home, page-features, page-study,
  page-store, page-chatbot, footer, scripts, head
- public/css/linear.css — the design system
- public/js/app.js — all frontend logic (Router, Study, Store, Chatbot, Onboarding modules)
- backend/gateways/api_gateway.php — master AI gateway (process_document — file
  upload OR "url" field for link ingestion —, grade_quiz, folder_quiz, chat,
  get_chat_status, ask_summary actions)
- backend/gateways/onboard.php — registration
- backend/gateways/store.php — folder + summary CRUD
- backend/services/Auth.php, RateLimiter.php, OpenAI.php
- backend/config/db.php, constants.php
- python/extract_text.py — text extraction
- database/schema.sql — full MySQL schema

## CRITICAL local-environment gotchas (already fixed — do not regress)
- XAMPP path prefix: every absolute path in the frontend needs a `/studiz/` prefix
  (e.g. /studiz/public/css/linear.css, /studiz/backend/gateways/api_gateway.php).
  This is LOCAL ONLY — on the VPS these revert to `/`.
- db.php: credentials are hardcoded for local XAMPP (host 127.0.0.1, user root,
  empty password). The old empty-password guard was REMOVED because XAMPP's MySQL
  has no password. Do not re-add a check that throws on empty password.
- constants.php: all values are hardcoded for local dev (API keys, paths). On the
  VPS this should switch back to getenv(). API keys live here — never expose them.
- Python path in constants.php uses FORWARD slashes:
  C:/Users/user/AppData/Local/Programs/Python/Python314/python.exe
- api_gateway.php runPythonExtractor(): realpath() returns backslashes on Windows,
  so paths are normalised with str_replace('\\','/') before the traversal check.
- Uploads are ALWAYS stored on disk with a fixed, inert `.upload` extension —
  NEVER the client-supplied one. The original extension is passed to
  extract_text.py as a separate argv string, used only to pick a parser. Do not
  "simplify" this back to preserving the real extension — that would let a
  renamed executable (e.g. evil.php saved as evil.pdf) land in a web-served
  directory with a runnable extension. finfo MIME-sniffing was tried and
  rejected: it can't reliably distinguish a real .docx from generic binary on
  this Windows build, and can't be trusted to catch a disguised script either.
- OpenAI.php: json_encode uses JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE
  because PDF text can contain invalid UTF-8 bytes that break the request.
- extract_text.py: at the top it calls
  sys.stdout.reconfigure(encoding="utf-8") and the same for stderr — without this,
  Windows cp1252 crashes on characters like ● (bullet points).
- extract_text.py OCR loop is wrapped in try/except...continue so one bad page
  doesn't kill the whole document.
- extract_text.py's legacy .ppt extractor (extract_from_legacy_ppt) is a
  deliberate, user-chosen trade-off: a regex scan for UTF-16LE text runs in the
  raw OLE2 binary, NOT a real parser. This was chosen specifically to avoid
  installing LibreOffice or pywin32 (the more "correct" options — see git
  history/conversation for why). It's lossy by design (slide order isn't
  preserved, occasional stray noise like font names slips through) — don't
  "fix" this by trying to make it perfect; if higher fidelity is ever needed,
  that's a deliberate re-ask of LibreOffice vs. pywin32, not a bug fix.
- extract_from_xlsx() opens the file and passes a FILE OBJECT to
  openpyxl.load_workbook(), never the raw path string. openpyxl's
  _validate_archive() only skips its extension check when given a file-like
  object — given a string path it hard-rejects anything not ending in
  .xlsx/.xlsm/.xltx/.xltm, which would always reject our fixed ".upload"
  on-disk name. Don't "simplify" this back to passing the path directly.
  (xlrd, used for legacy .xls, has no such check — it sniffs the OLE2 magic
  bytes instead — so extract_from_xls() doesn't need this workaround.)
- Link-based ingestion (process_document with a "url" field instead of a
  file) fetches happen server-side ONLY, via curlFetchSafely() in
  api_gateway.php, with SSRF guards that must not be weakened: scheme
  restricted to http/https; hostname resolved and validated as a public IP
  (filter_var with FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
  BEFORE connecting; the actual connection is pinned to that validated IP via
  CURLOPT_RESOLVE (Host header/TLS SNI still use the real hostname) so a DNS
  answer that changes between our check and curl's own lookup can't be used
  to reach an internal address; redirects are followed MANUALLY (capped at
  URL_FETCH_MAX_REDIRECTS) with the same host/IP/video-domain checks re-run
  at every hop, never via CURLOPT_FOLLOWLOCATION. This deliberately rejects
  links to localhost/127.0.0.1/169.254.169.254/private ranges even in local
  dev — that's correct behaviour, not a bug, and matches prod. Video links
  (isVideoUrl() — a plain domain-substring list, not exhaustive) are rejected
  before any fetch happens; a Content-Type sniff after fetching is the
  defence-in-depth backstop for direct video file links.
- htmlToReadableText() (webpage-link ingestion) is best-effort, same
  trade-off philosophy as the legacy-.ppt heuristic: it strips obvious
  boilerplate tags and prefers <main>/<article> over <body>, but it's not a
  real readability-scoring library — some nav/ad text can slip through on
  unusual page layouts. Don't chase perfect extraction here without a
  deliberate re-ask (e.g. a real Readability port).
- php.ini: max_execution_time = 300 for long AI pipelines.
- declare(strict_types=1); MUST be the first statement in any PHP file — nothing
  (not even debug lines) can come before it.

## Security/correctness audit fixes (do not regress)
A full code audit turned up several real bugs, fixed as follows:
- OpenAI.php: CURLOPT_SSL_VERIFYPEER/VERIFYHOST were disabled on BOTH the
  OpenAI and YouTube calls — sending API keys over an unverified TLS
  connection (MITM risk). Now `true`/`2` on both, matching the SSRF-hardened
  curlFetchSafely() in api_gateway.php. Confirmed working against the real
  APIs after the fix (XAMPP's php.ini already has a valid curl.cainfo).
  Never disable cert verification to silence a local TLS error — fix the
  CA bundle path instead.
- RateLimiter.php had TWO independent bugs, not one:
  1. A TOCTOU race (count-then-insert with no lock) — concurrent requests
     from the same user could all pass the cap check before any recorded a
     hit. Fixed with a MySQL named lock (GET_LOCK/RELEASE_LOCK) scoped to
     `(user_token, endpoint)`, serializing concurrent calls for the SAME
     bucket while leaving different users/endpoints unblocked.
  2. A SEPARATE, more severe bug: cutoff timestamps were computed in PHP
     via `date('Y-m-d H:i:s', time() - $window)` and compared against
     MySQL's `hit_at` (written via `CURRENT_TIMESTAMP`). On this machine
     PHP's `date.timezone` (Europe/Berlin) and MySQL's `SYSTEM` timezone
     are an hour apart, so the purge step was deleting rows within ~1
     second of insertion — the rate cap was never actually enforced,
     independent of concurrency. Fixed by computing the cutoff INSIDE the
     SQL via `NOW() - INTERVAL ? SECOND` everywhere (check(), checkChat(),
     checkAsk()) instead of passing a PHP-computed string in. Never mix a
     PHP-computed timestamp with a MySQL-written one in a comparison —
     do the arithmetic on one side only.
  Verified with a real concurrency test (15 truly parallel processes
  against a cap of 5) before and after — see git history if repeating.
- db.php: docblock claimed env-var credentials while the code hardcoded
  XAMPP's root/no-password — fixed to honestly document the current
  local-dev state plus a VPS cutover checklist, WITHOUT changing the
  actual hardcoded values (still required locally, per the gotcha above).
  Also fixed the misleading "Singleton" framing: PHP-FPM is shared-nothing
  per request, so `DB::$instance` never survives across requests — it's a
  request-scoped connection cache, not a singleton in the Node/Java sense.
- app.js had ~20 static inline `style="..."` attributes baked into HTML
  template strings (mainly in `_renderScorePanel`/`_buildQuizHTML`),
  defeating linear.css's design system. Extracted into real CSS classes
  (`.review-row`, `.score-panel-wrap`, `.loading-substep`, `.quiz-submit-wrap`,
  `.lnr-empty-span`, `.mt-16`, etc.). The two remaining inline styles
  (`.folder-dot` background colors) are correctly left inline — they're
  genuinely per-instance, user-chosen folder colors from the DB, not theme
  constants, so a CSS class can't express them.
  While extracting these, found that several of them referenced CSS custom
  properties that DON'T EXIST anywhere in linear.css — `var(--bg-surface)`,
  `var(--chalk)`, `var(--success)`, `var(--danger)`, `var(--radius-md)`,
  `var(--text-muted)`, `var(--accent)` — stale names from before the
  `--lnr-*` rename. These silently fell back to CSS initial values (e.g.
  `border-color` falling back to `currentcolor`, `border-radius` to `0`),
  meaning the quiz score-review rows had no real rounded corners/card
  background, and the "All Summaries" folder dot was invisible. Fixed by
  mapping each to its real `--lnr-*` equivalent (`--lnr-card`, `--lnr-chalk`,
  `--lnr-green`/`--lnr-red`, `--r-md`, `--lnr-muted`, `--lnr-indigo`).
- store.php deletes were hard DELETEs (no audit trail, no undo). Now soft
  deletes via `deleted_at` (migration_add_soft_delete.sql) — every read
  across store.php AND api_gateway.php (fetchDuplicateSummaryPayload,
  grade_quiz, ask_summary, folder_quiz) filters `deleted_at IS NULL`.
  delete_summary ALSO mutates content_hash at delete time
  (`CONCAT(LEFT(content_hash,48),'_del',id)`) to free its slot in
  `UNIQUE KEY uniq_user_content (user_token, content_hash)` — otherwise
  re-uploading identical content after deleting it would hit a
  duplicate-key error against the now-invisible deleted row. Verified
  directly at the SQL level: delete + re-insert with the original hash
  succeeds; two simultaneously-ACTIVE rows with the same hash still
  correctly conflict (original dedup guarantee intact).
- Auth security audit (round 2) turned up three more real issues, all fixed:
  1. No logout / no token revocation: the session cookie had a 1-year lifetime
     and there was no way to invalidate a stolen token. Fixed with
     Auth::rotateToken() (rewrites the DB token to a new CSPRNG value, making
     the old cookie instantly worthless on all devices) + Auth::clearTokenCookie()
     (expires the cookie on the current client) + backend/gateways/logout.php
     (calls both in the right order: rotate first, then clear). A "Sign out"
     button is now in the navbar, hidden until auth is confirmed, revealed by
     setNavUser(). Logout reloads the page, which triggers checkAlreadyRegistered()
     → finds no valid cookie → shows onboarding modal.
  2. No rate limiting on registration endpoint: onboard.php had zero guards,
     allowing automated account creation and email/username enumeration via the
     duplicate-key error message. Fixed with RateLimiter::checkRegistration()
     (5 attempts per 15-min window, IP-based using sha256-hashed REMOTE_ADDR
     as bucket key) called in onboard.php after the already-registered check
     (so authenticated update_username calls are unaffected) but before
     Auth::register(). IMPORTANT: the IP bucket key is 'ip_' + sha256 = 67
     chars, which exceeds rate_limits.user_token CHAR(64). Without truncating
     to 64 chars before calling check(), MySQL silently truncates on INSERT but
     the SELECT WHERE comparison uses the full 67-char value → never matches →
     count is always 0 → rate limit silently never fires. checkRegistration()
     calls substr($ipHash, 0, 64) before passing to check(). Never pass a
     user_token value longer than 64 chars to check() without truncating first.
     The duplicate-key error message was also hardened from "That username or
     email is already registered." (leaked which field conflicted, enabling
     enumeration) to a generic "Registration failed. Please check your details
     and try again."
  3. display_errors = 1 hardcoded in api_gateway.php (production leak risk):
     replaced with a gate on STUDIZ_ENV ('dev' vs 'prod') defined in
     constants.php. Change STUDIZ_ENV to 'prod' before VPS deployment (same
     cutover process as the hardcoded DB credentials).
  Also added `[hidden] { display:none !important; }` to linear.css reset block
  to ensure the HTML hidden attribute is respected even when a CSS class
  (e.g. .lnr-btn) explicitly sets display on the same element.
- Authorization audit (round 3) — full endpoint review found four issues:
  1. `process_document` (api_gateway.php) accepted a `folder_id` from the
     POST body and stored it without checking ownership. The MySQL FK only
     ensures the folder exists, not that it belongs to this user — User A
     could supply User B's folder_id and their summary would land in User B's
     folder. Fixed: ownership check (`WHERE id=? AND user_token=?`) runs
     before the AI pipeline; invalid/unowned folder_id is silently dropped
     (no error, so the attacker learns nothing and a legit race is handled
     gracefully).
  2. `list_folders` (store.php) LEFT JOIN on summaries was unscoped —
     `ON s.folder_id = f.id AND s.deleted_at IS NULL` — meaning any summary
     (from any user) pointing at a given folder_id would inflate that folder's
     `summary_count` badge. Fixed: `AND s.user_token = f.user_token` added to
     the JOIN condition. Since `f.user_token` is already pinned to the current
     user by the WHERE clause, this guarantees only the folder-owner's summaries
     are counted.
  3. `move_summary` (store.php) verified the summary belongs to the caller but
     not the target folder_id. User A could move their summary into User B's
     folder_id, triggering the same badge-count inflation as (2) above. Fixed:
     folder ownership checked before the UPDATE; returns 403 on mismatch.
  4. `chat` (api_gateway.php) forwarded the client-supplied `$history` array
     to OpenAI without role filtering. A malicious client could inject
     `{"role":"system","content":"..."}` entries into the history, which appear
     later in the context than the real system prompt and can influence model
     behaviour. Fixed: history is filtered to only `user` and `assistant` roles,
     each entry rebuilt with only `role` and `content` keys so no extra fields
     from a crafted payload reach the OpenAI request.
  No vertical privilege escalation risk exists — there are no admin endpoints
  or role distinctions between users.

## Known, deliberately deferred limitations
Both confirmed explicitly with the project owner — NOT oversights, don't
"fix" silently without re-raising the scope question:
- **No job queue / async processing.** process_document does file I/O +
  Python subprocess + 3 sequential OpenAI calls synchronously in one PHP
  request (up to 300s). This doesn't scale past a handful of concurrent
  uploads on default PHP-FPM worker counts. Deferred: real fix is a
  jobs table + background worker + frontend polling, which is a
  multi-hour architectural rewrite, not a bug fix.
- **No login / account recovery.** Auth is a single HttpOnly token cookie
  issued once at registration — no password, no "log in on a new device"
  path. Clearing cookies = permanent, unrecoverable loss of the account
  and every summary in it. Deferred because the clean fix (email-based
  magic-link/OTP re-auth) needs real SMTP/email-API credentials that
  don't exist in this project yet. If revisiting, ask the owner for an
  email provider before building this rather than inventing credentials.

## Data shapes
- quiz_json: [{"q","options":["A) ...","B) ...","C) ...","D) ..."],"answer":"A","explain"}]
- key_points: ["point one", "point two", ...]
- youtube_links: [{"title","videoId","thumbnail","channel"}]

## Style / working preferences
- Owner likes complete, ready-to-paste code, not snippets with "...".
- Keep the Linear.app dark aesthetic (near-black bg, indigo accent #6366f1).
- Frontend stays vanilla JS — no frameworks.
