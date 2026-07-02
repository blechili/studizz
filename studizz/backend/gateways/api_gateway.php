<?php
/**
 * STUDIZ — Master AI API Gateway
 * --------------------------------
 * POST /backend/gateways/api_gateway.php
 *
 * This is the ONLY file that communicates with external APIs
 * (OpenAI, YouTube). The frontend never holds any API keys.
 *
 * Routing is controlled by the required POST field "action":
 *
 *  "process_document"  — Extract text (Python, or a server-side fetch
 *                         for a "url" field) → AI pipeline → Summarise,
 *                         Key Points, Quiz, YouTube
 *  "grade_quiz"        — Grade submitted MCQ answers
 *  "regenerate_quiz"   — Generate a fresh MCQ set for one document, with a
 *                         caller-chosen question count; optionally reuses a
 *                         few previously-asked questions, and falls back to
 *                         the AI's own general knowledge if the document
 *                         doesn't contain enough distinct material
 *  "folder_quiz"       — Generate quiz from all summaries in a folder
 *  "chat"              — Single-turn chatbot query (15/day cap)
 *  "get_chat_status"   — Return remaining daily chat count
 *  "ask_summary"       — Ask a question grounded in one document's
 *                         full extracted text (20/day cap, separate
 *                         quota from the chatbot)
 *
 * Security architecture:
 *  1. User must present a valid HTTP-Only cookie token.
 *  2. All AI requests are rate-limited (sliding window, DB-backed).
 *  3. PHP execution timeout = 90 seconds.
 *  4. cURL timeout = 85 seconds (fires before PHP limit).
 *  5. All errors are logged server-side; safe JSON returned to client.
 *
 * Expected POST body: multipart/form-data OR application/json
 * (see individual action handlers below for payload schemas).
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/Auth.php';
require_once __DIR__ . '/../services/RateLimiter.php';
require_once __DIR__ . '/../services/OpenAI.php';


// ── Hard execution cap for this script ──────────────────────
set_time_limit(AI_TIMEOUT_SEC);

if (STUDIZZ_ENV === 'dev') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(0);
}


// ── Ensure log directory exists ──────────────────────────────
if (!is_dir(LOG_DIR)) {
    mkdir(LOG_DIR, 0750, true);
}

// ── CORS + content-type headers ─────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// ── Global Throwable handler ──────────────────────────────────
// Any uncaught exception that escapes this script (e.g. PDOException from
// DB::connect() when MySQL is unreachable, or any unprotected ->execute())
// is caught here instead of producing a raw PHP stack trace in dev or a
// broken empty body in prod. The full detail is logged server-side; the
// client only ever gets a generic JSON error so the response always parses.
set_exception_handler(static function (Throwable $e): void {
    error_log(
        '[' . date('Y-m-d H:i:s') . '] [api_gateway] Uncaught '
        . get_class($e) . ': ' . $e->getMessage()
        . ' in ' . $e->getFile() . ':' . $e->getLine(),
        3,
        ERROR_LOG_FILE
    );
    if (!headers_sent()) http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'An unexpected error occurred. Please try again.']);
});

// ── Only accept POST ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    exitWith(405, 'Method not allowed.');
}

// ── Authenticate user via cookie token ──────────────────────
$userToken = Auth::resolveToken();
if ($userToken === null) {
    exitWith(401, 'Authentication required. Please complete onboarding.');
}

// ── Determine action ─────────────────────────────────────────
// Support both JSON body and multipart form for file uploads.
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (str_contains($contentType, 'application/json')) {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? '';
} else {
    $body   = $_POST;
    $action = $_POST['action'] ?? '';
}

if (empty($action)) {
    exitWith(400, 'Missing required field: action.');
}

// ── Route to action handler ──────────────────────────────────
switch ($action) {

    // ────────────────────────────────────────────────────────
    // ACTION: process_document
    // Expects: multipart/form-data with file field "document"
    //          and optional "folder_id" (int)
    // ────────────────────────────────────────────────────────
    case 'process_document':
        // 1. Rate-limit check
        if (!RateLimiter::checkAI($userToken)) {
            exitWith(429, 'Too many requests. You may send up to ' . RATE_LIMIT_MAX . ' AI requests per ' . RATE_LIMIT_WINDOW . ' seconds. Please wait.');
        }

        $folderId = isset($body['folder_id']) && $body['folder_id'] !== '' ? (int) $body['folder_id'] : null;

        // Verify the requested folder actually belongs to this user before
        // using it. The FK constraint on summaries(folder_id) only checks that
        // the folder *exists*, not that the caller *owns* it — without this
        // check User A could supply User B's folder_id and corrupt User B's
        // folder badge count via the unscoped LEFT JOIN in list_folders.
        if ($folderId !== null) {
            $pdo  = DB::connect();
            $fRow = $pdo->prepare(
                'SELECT id FROM folders WHERE id = ? AND user_token = ? AND deleted_at IS NULL LIMIT 1'
            );
            $fRow->execute([$folderId, $userToken]);
            if (!$fRow->fetch()) {
                $folderId = null;   // silently drop — attacker learns nothing;
                                    // a legit race (folder deleted between UI
                                    // render and upload) gets a safe fallback
            }
        }

        $url = trim((string) ($body['url'] ?? ''));

        if ($url !== '') {
            // ── Link-based ingestion ─────────────────────────
            // Fetched entirely server-side (resolveUrlToText) with SSRF
            // guards: scheme + private/reserved-IP checks, DNS-rebinding-safe
            // pinned-IP fetch, manual redirect re-validation, and a hard
            // byte cap. Video links are rejected before/without fetching.
            $urlResult = resolveUrlToText($url);
            if (!$urlResult['ok']) {
                exitWith($urlResult['status'], $urlResult['error']);
            }
            $extractedText = $urlResult['text'];
            $origName      = $urlResult['name'];
        } else {
            // ── Classic file upload ──────────────────────────
            if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
                exitWith(400, 'No valid file uploaded.');
            }

            $file = $_FILES['document'];

            // Validate file size
            if ($file['size'] > UPLOAD_MAX_BYTES) {
                exitWith(413, 'File exceeds the 20 MB size limit.');
            }

            // Any file type is accepted — the Python extractor decides whether
            // it can find usable text/numbers in it and rejects gracefully if not.
            //
            // Security: the file is ALWAYS stored on disk with a fixed, inert
            // ".upload" extension — never the client-supplied one — so a file
            // disguised with an executable extension (e.g. "evil.php" renamed
            // to "evil.pdf", or vice-versa) can never be served as anything
            // Apache would run. The original extension is passed to the Python
            // extractor separately, purely as a hint for which parser to try.
            $origExt  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $safeName = bin2hex(random_bytes(16)) . '.upload';
            $tmpPath  = rtrim(UPLOAD_TMP_DIR, '/') . '/' . $safeName;

            if (!move_uploaded_file($file['tmp_name'], $tmpPath)) {
                logGatewayError("Failed to move uploaded file to: {$tmpPath}");
                exitWith(500, 'File storage failed. Please try again.');
            }

            // Pass file path + original extension to Python text extractor
            $extractedText = runPythonExtractor($tmpPath, $origExt);

            // Clean up the temp file regardless of outcome
            @unlink($tmpPath);

            if ($extractedText === null) {
                exitWith(500, 'Text extraction failed. Ensure the file is not corrupted or password-protected.');
            }

            $origName = basename($file['name']);
        }

        if (strlen(trim($extractedText)) < 50) {
            exitWith(422, 'Not enough readable text was found in this ' . ($url !== '' ? 'link' : 'file') . '. Please try a text-rich document.');
        }

        // 4. Duplicate check — same user, same document content.
        // Checked BEFORE the AI calls so a repeat upload costs nothing.
        $contentHash = hash('sha256', $extractedText);
        $pdo         = DB::connect();
        $dupPayload  = fetchDuplicateSummaryPayload($pdo, $userToken, $contentHash);

        if ($dupPayload) {
            echo json_encode($dupPayload);
            break;
        }

        // Best-effort cancellation: if the client disconnected (e.g. hit
        // Cancel) and the SAPI has noticed, stop before spending OpenAI
        // budget. Not guaranteed to catch every cancel — PHP only learns
        // about the disconnect once it next attempts a check like this.
        if (connection_aborted()) exit;

        // 5. Truncate extracted text to avoid runaway cost on huge documents
        $textForAI = mb_substr($extractedText, 0, SUMMARY_CONTEXT_CHAR_CAP);

        // 5. Run AI pipeline — four features in three API calls
        $systemPrompt = 'You are Studiz AI, an expert academic study assistant. Respond only with the exact JSON structure requested. Do not include markdown code fences.';

        // ── Feature 1 & 2: Summary + Key Points ───────────────
        $sumResult = OpenAI::chat([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' =>
                "Thoroughly analyse the following academic text and respond with a JSON object containing exactly two keys:\n" .
                "1. \"summary\": A detailed academic overview — use as many paragraphs as the source material warrants " .
                "(roughly 6-10 for a long document, fewer only if the source text is genuinely short). Preserve every " .
                "important fact: specific names, dates, figures, statistics, formulas, and findings from the source text. " .
                "Do not compress or drop concrete numbers/details for the sake of brevity. If the source text is a " .
                "spreadsheet or contains tables (rows of pipe-separated values), explain what each table represents, " .
                "describe any trends, and explicitly call out the key figures — totals, maximums/minimums, notable " .
                "outliers — rather than just restating raw rows.\n" .
                "2. \"key_points\": An array of 10-15 concise bullet-point strings capturing the most important study " .
                "points, including any specific figures, statistics, or data mentioned in the text.\n\n" .
                "TEXT:\n{$textForAI}"
            ],
        ], 4500);

        if (!$sumResult['ok']) exitWith(502, $sumResult['error']);

        $sumData = json_decode($sumResult['data'], true);
        if (!isset($sumData['summary'], $sumData['key_points'])) {
            logGatewayError('Summary AI returned malformed JSON: ' . $sumResult['data']);
            exitWith(502, 'AI returned an unexpected format. Please try again.');
        }

        if (connection_aborted()) exit;

        // ── Feature 5: embedded Q&A ────────────────────────────
        // Deliberately a SEPARATE call from the summary above (this used
        // to be bundled into one call to save on tokens). An LLM
        // conditions on its own prior output tokens within a single
        // response, so generating "summary" first and "questions_and_
        // answers" second in the same JSON blob meant the answers could
        // drift toward the summary's own paraphrasing instead of the
        // original wording/numbers in the source text. Splitting this out
        // guarantees the model re-scans $textForAI (the raw python-
        // extracted text) fresh, with nothing else in context — the same
        // grounding approach the "ask_summary" action already uses.
        $qaResult = OpenAI::chat([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' =>
                "Scan the following document text and find every question, problem, or essay prompt that this text " .
                "is EXPLICITLY asking the reader to answer or solve — i.e. the text itself is a worksheet, assignment, " .
                "past exam paper, or problem set, NOT just reading material/lecture notes. Answer each one using ONLY " .
                "the document text below — never outside knowledge or assumptions, and never anything from any summary " .
                "of this text. Respond with a JSON object containing exactly one key, \"questions_and_answers\": an " .
                "array of objects, ONE per question found. Each object must have exactly these keys: \"question\" (the " .
                "question or prompt, copied or lightly cleaned up from the text), \"type\" (one of \"essay\", " .
                "\"short_answer\", \"calculation\", \"mcq\"), and \"answer\" (a complete, well-reasoned answer, quoting " .
                "exact figures/names/dates from the text where relevant — for \"essay\" type write a full, " .
                "well-structured multi-paragraph essay response; for other types give a clear, correctly justified " .
                "answer). If the text contains no such questions/prompts to answer, return an empty array [] for this " .
                "key — do not invent questions that aren't actually in the text.\n\n" .
                "TEXT:\n{$textForAI}"
            ],
        ], 3000);

        $answeredQuestions = [];
        if ($qaResult['ok']) {
            $qaData = json_decode($qaResult['data'], true);
            if (is_array($qaData['questions_and_answers'] ?? null)) {
                $answeredQuestions = $qaData['questions_and_answers'];
            } else {
                logGatewayError('Embedded Q&A AI returned malformed JSON: ' . $qaResult['data']);
            }
        } else {
            // Non-fatal: the summary already succeeded above, so a failure
            // here just means an empty Answers tab rather than losing the
            // whole upload.
            logGatewayError('Embedded Q&A AI call failed: ' . $qaResult['error']);
        }

        if (connection_aborted()) exit;

        // ── Feature 3: MCQ Quiz ──────────────────────────────
        $quizResult = OpenAI::chat([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' =>
                "Based on the following summary, generate 10 multiple-choice quiz questions.\n" .
                "Respond with a JSON array where each element has exactly these keys:\n" .
                "  \"q\": question string\n" .
                "  \"options\": array of exactly 4 strings, each starting with \"A) \", \"B) \", \"C) \", \"D) \"\n" .
                "  \"answer\": single letter string (\"A\", \"B\", \"C\", or \"D\")\n" .
                "  \"explain\": one-sentence explanation of the correct answer\n\n" .
                "SUMMARY:\n" . $sumData['summary']
            ],
        ], 2000);

        if (!$quizResult['ok']) exitWith(502, $quizResult['error']);

        $quizData = json_decode($quizResult['data'], true);
        if (!is_array($quizData) || count($quizData) < 3) {
            logGatewayError('Quiz AI returned malformed JSON: ' . $quizResult['data']);
            exitWith(502, 'Quiz generation returned an unexpected format. Please try again.');
        }

        if (connection_aborted()) exit;

        // ── Feature 4: YouTube topic extraction ──────────────
        $topicResult = OpenAI::chat([
            ['role' => 'system', 'content' => 'You are a search-query generator. Return only a short 3-6 word YouTube search query. No punctuation.'],
            ['role' => 'user',   'content' => 'What is the best YouTube search query to find educational videos about this topic? Summary: ' . mb_substr($sumData['summary'], 0, 500)],
        ], 40, 0.2);

        $youtubeLinks = [];
        if ($topicResult['ok']) {
            $ytQuery    = preg_replace('/[^\w\s]/', '', trim($topicResult['data']));
            $ytResult   = OpenAI::fetchYouTubeVideos($ytQuery);
            if ($ytResult['ok']) {
                $youtubeLinks = $ytResult['data'];
            }
        }

        // 6. Persist everything to the summaries table
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO summaries
                   (user_token, folder_id, original_name, raw_text, content_hash, summary, key_points, quiz_json, youtube_links, answered_questions)
                 VALUES
                   (:token, :folder_id, :orig_name, :raw_text, :content_hash, :summary, :key_points, :quiz_json, :yt, :answered)'
            );
            $stmt->execute([
                ':token'        => $userToken,
                ':folder_id'    => $folderId,
                ':orig_name'    => mb_substr($origName, 0, 255),
                ':raw_text'     => $extractedText,
                ':content_hash' => $contentHash,
                ':summary'      => $sumData['summary'],
                ':key_points'   => json_encode($sumData['key_points']),
                ':quiz_json'    => json_encode($quizData),
                ':yt'           => json_encode($youtubeLinks),
                ':answered'     => json_encode($answeredQuestions),
            ]);
            $summaryId = (int) $pdo->lastInsertId();
        } catch (PDOException $e) {
            // Duplicate key (23000) — an identical upload (e.g. a cancelled-but-
            // still-running request) finished and inserted between our earlier
            // check and this insert. Return that row instead of erroring.
            if ($e->getCode() === '23000') {
                $racePayload = fetchDuplicateSummaryPayload($pdo, $userToken, $contentHash);
                if ($racePayload) {
                    echo json_encode($racePayload);
                    break;
                }
                exitWith(409, 'This document is already in your Store.');
            }
            logGatewayError('DB insert failed: ' . $e->getMessage());
            exitWith(500, 'Failed to save results. Please try again.');
        }

        echo json_encode([
            'ok'                 => true,
            'summary_id'         => $summaryId,
            'summary'            => $sumData['summary'],
            'key_points'         => $sumData['key_points'],
            'quiz'               => $quizData,
            'youtube_links'      => $youtubeLinks,
            'answered_questions' => $answeredQuestions,
        ]);
        break;

    // ────────────────────────────────────────────────────────
    // ACTION: grade_quiz
    // Expects JSON: { "action": "grade_quiz", "summary_id": int,
    //                 "answers": {"0": "A", "1": "C", ...} }
    // ────────────────────────────────────────────────────────
    case 'grade_quiz':
        $summaryId = isset($body['summary_id']) ? (int) $body['summary_id'] : 0;
        $userAnswers = $body['answers'] ?? [];

        if (!$summaryId || !is_array($userAnswers) || empty($userAnswers)) {
            exitWith(400, 'Missing summary_id or answers.');
        }

        // Fetch the quiz from the DB
        $pdo  = DB::connect();
        $stmt = $pdo->prepare(
            'SELECT quiz_json FROM summaries WHERE id = ? AND user_token = ? AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute([$summaryId, $userToken]);
        $row = $stmt->fetch();

        if (!$row) exitWith(404, 'Summary not found.');

        $quiz      = json_decode($row['quiz_json'], true);
        $total     = count($quiz);
        $correct   = 0;
        $breakdown = [];

        foreach ($quiz as $i => $q) {
            $userAns   = strtoupper(trim($userAnswers[(string)$i] ?? ''));
            $correctAns = strtoupper(trim($q['answer']));
            $isCorrect  = ($userAns === $correctAns);
            if ($isCorrect) $correct++;
            $breakdown[] = [
                'question'   => $q['q'],
                'your_answer'=> $userAns,
                'correct'    => $correctAns,
                'is_correct' => $isCorrect,
                'explain'    => $q['explain'],
            ];
        }

        $score = $total > 0 ? (int) round(($correct / $total) * 100) : 0;

        // Persist attempt
        $pdo->prepare(
            'INSERT INTO quiz_attempts (user_token, summary_id, score, total_q, correct_q, answers_json)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$userToken, $summaryId, $score, $total, $correct, json_encode($userAnswers)]);

        echo json_encode([
            'ok'        => true,
            'score'     => $score,
            'correct'   => $correct,
            'total'     => $total,
            'breakdown' => $breakdown,
        ]);
        break;

    // ────────────────────────────────────────────────────────
    // ACTION: regenerate_quiz
    // Expects JSON: { "action": "regenerate_quiz", "summary_id": int,
    //                 "count": int (3-25, default 10),
    //                 "include_old": bool (default false) }
    //
    // Builds a brand-new MCQ set grounded in the document's full
    // extracted `raw_text` (same source the original quiz used).
    // If include_old is true, the model is shown the previous quiz and
    // told it may keep a few of the strongest questions; if false, it's
    // told to avoid repeating them. Either way it's explicitly allowed
    // to fall back to its own general knowledge of the subject for any
    // remaining questions if the document itself doesn't contain enough
    // distinct material for the requested count — short documents
    // shouldn't hard-cap how many questions a student can ask for.
    // The result overwrites quiz_json for this summary, the same way
    // the original quiz from process_document is persisted.
    // ────────────────────────────────────────────────────────
    case 'regenerate_quiz':
        if (!RateLimiter::checkAI($userToken)) {
            exitWith(429, 'Too many requests. You may send up to ' . RATE_LIMIT_MAX . ' AI requests per ' . RATE_LIMIT_WINDOW . ' seconds. Please wait.');
        }

        $summaryId  = isset($body['summary_id']) ? (int) $body['summary_id'] : 0;
        $count      = isset($body['count']) ? (int) $body['count'] : 10;
        $count      = max(QUIZ_REGEN_MIN_QUESTIONS, min(QUIZ_REGEN_MAX_QUESTIONS, $count));
        $includeOld = !empty($body['include_old']);

        if (!$summaryId) exitWith(400, 'Missing summary_id.');

        $pdo  = DB::connect();
        $stmt = $pdo->prepare(
            'SELECT raw_text, quiz_json FROM summaries WHERE id = ? AND user_token = ? AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute([$summaryId, $userToken]);
        $row = $stmt->fetch();

        if (!$row) exitWith(404, 'Summary not found.');

        $documentText = mb_substr($row['raw_text'], 0, SUMMARY_CONTEXT_CHAR_CAP);
        $oldQuiz      = json_decode($row['quiz_json'] ?? '[]', true);
        $oldQuiz      = is_array($oldQuiz) ? $oldQuiz : [];

        $oldQuestionsBlock = '';
        if ($oldQuiz) {
            $oldQuestionsText = implode("\n", array_map(
                static fn(int $i, array $q): string => ($i + 1) . '. ' . ($q['q'] ?? ''),
                array_keys($oldQuiz),
                $oldQuiz
            ));
            $oldQuestionsBlock = $includeOld
                ? "PREVIOUSLY ASKED QUESTIONS (the student has seen these before — you may keep a few of the " .
                  "strongest ones if they're still a good fit, but most of the {$count} should be new and cover " .
                  "different parts/angles of the material):\n{$oldQuestionsText}\n\n"
                : "PREVIOUSLY ASKED QUESTIONS (the student has already seen these — do NOT repeat them, write " .
                  "entirely different questions):\n{$oldQuestionsText}\n\n";
        }

        $quizResult = OpenAI::chat([
            ['role' => 'system', 'content' => 'You are Studiz AI, an expert academic study assistant. Respond only with the exact JSON structure requested. Do not include markdown code fences.'],
            ['role' => 'user',   'content' =>
                "Generate exactly {$count} multiple-choice quiz questions based on the document text below.\n" .
                "Prioritise the document's own content, facts, and figures first. If the document does not contain " .
                "enough distinct material to support {$count} high-quality, non-repetitive questions, supplement " .
                "the remaining questions using your own general knowledge of the subject matter so the set still " .
                "has exactly {$count} questions — but only as a fallback, and never sacrifice factual accuracy.\n\n" .
                $oldQuestionsBlock .
                "Respond with a JSON array of exactly {$count} elements. Each element must have exactly these keys:\n" .
                "  \"q\": question string\n" .
                "  \"options\": array of exactly 4 strings, each starting with \"A) \", \"B) \", \"C) \", \"D) \"\n" .
                "  \"answer\": single letter string (\"A\", \"B\", \"C\", or \"D\")\n" .
                "  \"explain\": one-sentence explanation of the correct answer\n\n" .
                "DOCUMENT TEXT:\n{$documentText}"
            ],
        ], min(6000, 250 * $count + 500));

        if (!$quizResult['ok']) exitWith(502, $quizResult['error']);

        $quizData = json_decode($quizResult['data'], true);
        if (!is_array($quizData) || count($quizData) < 3) {
            logGatewayError('Quiz regeneration AI returned malformed JSON: ' . $quizResult['data']);
            exitWith(502, 'Quiz generation returned an unexpected format. Please try again.');
        }

        $pdo->prepare('UPDATE summaries SET quiz_json = ? WHERE id = ? AND user_token = ? AND deleted_at IS NULL')
            ->execute([json_encode($quizData), $summaryId, $userToken]);

        echo json_encode(['ok' => true, 'quiz' => $quizData]);
        break;

    // ────────────────────────────────────────────────────────
    // ACTION: folder_quiz
    // Expects JSON: { "action": "folder_quiz", "folder_id": int }
    // ────────────────────────────────────────────────────────
    case 'folder_quiz':
        if (!RateLimiter::checkAI($userToken)) {
            exitWith(429, 'Rate limit reached. Please wait a moment.');
        }

        $folderId = isset($body['folder_id']) ? (int) $body['folder_id'] : 0;
        if (!$folderId) exitWith(400, 'Missing folder_id.');

        $pdo  = DB::connect();
        $stmt = $pdo->prepare(
            'SELECT summary FROM summaries WHERE folder_id = ? AND user_token = ? AND deleted_at IS NULL ORDER BY created_at'
        );
        $stmt->execute([$folderId, $userToken]);
        $rows = $stmt->fetchAll();

        if (empty($rows)) exitWith(404, 'No summaries found in this folder.');

        // Merge summaries (cap at ~8000 chars to avoid token overflow)
        $merged = '';
        foreach ($rows as $r) {
            $merged .= $r['summary'] . "\n\n";
            if (strlen($merged) > 8000) break;
        }

        $quizResult = OpenAI::chat([
            ['role' => 'system', 'content' => 'You are Studiz AI. Respond only with valid JSON. No markdown fences.'],
            ['role' => 'user',   'content' =>
                "Generate 15 multiple-choice quiz questions from the merged study material below.\n" .
                "Return a JSON array. Each element: {\"q\":\"...\",\"options\":[\"A) ...\",\"B) ...\",\"C) ...\",\"D) ...\"],\"answer\":\"A\",\"explain\":\"...\"}\n\n" .
                "MATERIAL:\n{$merged}"
            ],
        ], 3000);

        if (!$quizResult['ok']) exitWith(502, $quizResult['error']);

        $quizData = json_decode($quizResult['data'], true);
        if (!is_array($quizData)) exitWith(502, 'Quiz generation failed. Please try again.');

        echo json_encode(['ok' => true, 'quiz' => $quizData, 'folder_id' => $folderId]);
        break;

    // ────────────────────────────────────────────────────────
    // ACTION: chat
    // Expects JSON: { "action": "chat", "message": "...",
    //                 "session_id": "uuid",
    //                 "history": [{"role":"user","content":"..."},...] }
    // ────────────────────────────────────────────────────────
    case 'chat':
        // Daily cap check
        $chatStatus = RateLimiter::checkChat($userToken);
        if (!$chatStatus['allowed']) {
            exitWith(429, 'You have reached your daily limit of ' . CHAT_DAILY_MAX . ' chatbot queries. Come back tomorrow!', [
                'remaining' => 0,
                'used'      => CHAT_DAILY_MAX,
            ]);
        }

        $message   = trim($body['message'] ?? '');
        $sessionId = preg_replace('/[^a-f0-9\-]/', '', $body['session_id'] ?? '');

        if (empty($message)) exitWith(400, 'Message cannot be empty.');
        if (strlen($message) > 2000) exitWith(400, 'Message exceeds 2000 character limit.');

        // Build conversation context (cap history at last 10 turns).
        // Only 'user' and 'assistant' roles are forwarded — 'system' role
        // messages injected by a malicious client would otherwise appear later
        // in the context than our own system prompt and could override it.
        // Rebuild each entry with only the two expected keys so no extra fields
        // from a crafted payload reach the OpenAI request.
        $rawHistory = is_array($body['history'] ?? null) ? $body['history'] : [];
        $contextHistory = array_map(
            static fn(array $m) => ['role' => $m['role'], 'content' => $m['content']],
            array_slice(
                array_values(array_filter(
                    $rawHistory,
                    static fn($m) => is_array($m)
                        && in_array($m['role'] ?? '', ['user', 'assistant'], true)
                        && is_string($m['content'] ?? null)
                )),
                -10
            )
        );

        $systemMsg = [
            'role'    => 'system',
            'content' => 'You are Studiz AI Chatbot, a friendly and expert academic study assistant built into the Studiz platform. ' .
                         'Help students understand concepts, answer study questions, and guide them on how to use Studiz features. ' .
                         'Be concise, clear, and encouraging. Refuse to answer questions unrelated to studying or education.',
        ];

        $messages = array_merge([$systemMsg], $contextHistory, [
            ['role' => 'user', 'content' => $message],
        ]);

        $chatResult = OpenAI::chat($messages, 800, 0.6);

        if (!$chatResult['ok']) exitWith(502, $chatResult['error']);

        // Persist turn to chatbot_history
        if (!empty($sessionId)) {
            try {
                $pdo = DB::connect();
                $ins = $pdo->prepare(
                    'INSERT INTO chatbot_history (user_token, session_id, role, content)
                     VALUES (?, ?, ?, ?)'
                );
                $ins->execute([$userToken, $sessionId, 'user',      $message]);
                $ins->execute([$userToken, $sessionId, 'assistant', $chatResult['data']]);
            } catch (PDOException $e) {
                // History persistence is non-critical; log and continue.
                logGatewayError('Chat history insert failed: ' . $e->getMessage());
            }
        }

        // Re-query updated counts for the counter display
        $updatedStatus = RateLimiter::checkChat($userToken);

        echo json_encode([
            'ok'        => true,
            'reply'     => $chatResult['data'],
            'remaining' => $updatedStatus['remaining'],
            'used'      => $updatedStatus['used'],
        ]);
        break;

    // ────────────────────────────────────────────────────────
    // ACTION: ask_summary
    // Expects JSON: { "action": "ask_summary", "summary_id": int,
    //                 "question": "..." }
    //
    // Answers a question grounded in that document's full extracted
    // `raw_text` (not the short summary) so figures/details the
    // summary compressed away are still answerable accurately.
    // Capped at ASK_CONTEXT_CHAR_CAP chars to bound cost/latency on
    // very large documents. Has its own 20/day cap, separate from
    // the main chatbot's 15/day cap.
    // ────────────────────────────────────────────────────────
    case 'ask_summary':
        $askStatus = RateLimiter::checkAsk($userToken);
        if (!$askStatus['allowed']) {
            exitWith(429, 'You have reached your daily limit of ' . ASK_DAILY_MAX . ' questions about your summaries. Come back tomorrow!', [
                'remaining' => 0,
                'used'      => ASK_DAILY_MAX,
            ]);
        }

        $summaryId = isset($body['summary_id']) ? (int) $body['summary_id'] : 0;
        $question  = trim($body['question'] ?? '');

        if (!$summaryId)        exitWith(400, 'Missing summary_id.');
        if (empty($question))   exitWith(400, 'Question cannot be empty.');
        if (strlen($question) > 500) exitWith(400, 'Question exceeds 500 character limit.');

        // Ownership check — same pattern as grade_quiz: scoped to id + user_token.
        $pdo  = DB::connect();
        $stmt = $pdo->prepare(
            'SELECT raw_text FROM summaries WHERE id = ? AND user_token = ? AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute([$summaryId, $userToken]);
        $row = $stmt->fetch();

        if (!$row) exitWith(404, 'Summary not found.');

        $documentText = mb_substr($row['raw_text'], 0, ASK_CONTEXT_CHAR_CAP);

        $systemMsg = [
            'role'    => 'system',
            'content' => 'You are Studiz AI. Answer the student\'s question using ONLY the document text ' .
                         'provided below — never outside knowledge. Be thorough and precise: quote exact ' .
                         'figures, numbers, names, and dates from the text whenever relevant instead of ' .
                         'paraphrasing them away. If the answer is not contained in the text, politely say ' .
                         'the document does not cover that rather than guessing.',
        ];

        $contextMsg = [
            'role'    => 'user',
            'content' => "DOCUMENT TEXT:\n{$documentText}\n\nQUESTION:\n{$question}",
        ];

        $askResult = OpenAI::chat([$systemMsg, $contextMsg], 800, 0.2);

        if (!$askResult['ok']) exitWith(502, $askResult['error']);

        echo json_encode([
            'ok'     => true,
            'answer' => $askResult['data'],
        ]);
        break;

    // ────────────────────────────────────────────────────────
    // ACTION: get_chat_status
    // Returns remaining daily query count for display.
    // ────────────────────────────────────────────────────────
    case 'get_chat_status':
        $status = RateLimiter::checkChat($userToken);
        // checkChat records a hit only when 'allowed' is consumed;
        // for a status-only check we just re-count without inserting.
        echo json_encode([
            'ok'        => true,
            'remaining' => $status['remaining'],
            'used'      => $status['used'],
            'max'       => CHAT_DAILY_MAX,
        ]);
        break;

    default:
        exitWith(400, "Unknown action: {$action}");
}

// ═══════════════════════════════════════════════════════════
// Helper functions
// ═══════════════════════════════════════════════════════════

/**
 * Resolve a user-pasted URL down to plain text, ready for the same
 * pipeline a file upload feeds into (dup-check → AI calls → insert).
 *
 * Branches on what the URL actually points to:
 *  - Known video platforms (or a video/* response) → rejected, no fetch
 *    needed for the platform check (cheap substring match first).
 *  - A direct link to a document we already know how to parse (PDF,
 *    DOCX, PPTX/PPT, XLSX/XLS, TXT) → downloaded and run through the
 *    SAME runPythonExtractor() a file upload uses, with the SAME
 *    fixed ".upload" on-disk extension security pattern.
 *  - Anything else (an HTML page) → best-effort readable-text extraction
 *    via htmlToReadableText(), same "good enough, not a real readability
 *    library" trade-off philosophy as the legacy-.ppt heuristic.
 *
 * @return array  {ok:true, text, name} or {ok:false, status, error}
 */
function resolveUrlToText(string $url): array
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return ['ok' => false, 'status' => 400, 'error' => 'Please enter a valid URL.'];
    }
    if (isVideoUrl($url)) {
        return ['ok' => false, 'status' => 422, 'error' => "Video links aren't supported — please upload the document directly, or paste a link to an article or file."];
    }

    $fetch = curlFetchSafely($url);
    if (!$fetch['ok']) {
        return $fetch;
    }

    $kind = classifyFetchedContent($fetch['content_type'], $fetch['final_url']);

    if ($kind === 'video') {
        return ['ok' => false, 'status' => 422, 'error' => "That link points to a video — please upload the document directly, or paste a link to an article or file."];
    }

    if ($kind === 'html') {
        $parsed = htmlToReadableText($fetch['body'], $fetch['content_type']);
        $host   = (string) parse_url($fetch['final_url'], PHP_URL_HOST);
        $name   = $parsed['title'] !== '' ? $parsed['title'] : $host;
        return ['ok' => true, 'text' => $parsed['text'], 'name' => mb_substr($name, 0, 255)];
    }

    // Recognised document type fetched from a URL — written to disk with
    // the SAME fixed inert ".upload" extension as a multipart upload
    // (never a URL-derived one) and run through the same extractor.
    $safeName = bin2hex(random_bytes(16)) . '.upload';
    $tmpPath  = rtrim(UPLOAD_TMP_DIR, '/') . '/' . $safeName;
    file_put_contents($tmpPath, $fetch['body']);

    $text = runPythonExtractor($tmpPath, $kind);
    @unlink($tmpPath);

    if ($text === null) {
        return ['ok' => false, 'status' => 500, 'error' => 'Could not extract text from the linked file.'];
    }

    $path     = (string) parse_url($fetch['final_url'], PHP_URL_PATH);
    $baseName = basename($path);
    if ($baseName === '' || $baseName === '/') {
        $baseName = (string) parse_url($fetch['final_url'], PHP_URL_HOST);
    }

    return ['ok' => true, 'text' => $text, 'name' => mb_substr($baseName, 0, 255)];
}

/**
 * Cheap, pre-fetch check for known video platforms — a plain substring
 * match against the URL. Best-effort, not exhaustive: defence-in-depth
 * is the Content-Type sniff in classifyFetchedContent() after fetching.
 */
function isVideoUrl(string $url): bool
{
    static $needles = [
        'youtube.com', 'youtu.be', 'vimeo.com', 'dailymotion.com',
        'twitch.tv', 'tiktok.com', 'facebook.com/watch', 'instagram.com/reel',
        'instagram.com/tv', 'streamable.com', 'wistia.com', 'rumble.com',
    ];
    $low = strtolower($url);
    foreach ($needles as $needle) {
        if (str_contains($low, $needle)) return true;
    }
    return false;
}

/**
 * True if $ip is a normal public-internet address — i.e. NOT a private
 * (10/8, 172.16/12, 192.168/16, fc00::/7, ...) or reserved (loopback
 * 127/8 ::1, link-local 169.254/16 fe80::/10 — which covers cloud
 * metadata endpoints like 169.254.169.254 — etc.) range.
 */
function isPublicIp(string $ip): bool
{
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

/**
 * SSRF guard: resolve a hostname to an IP we are actually willing to
 * connect to. Deliberately fails closed — anything we can't confirm is
 * a public IPv4 address (DNS failure, IPv6-only, private/reserved range)
 * returns null rather than guessing. IPv4-focused because the rest of
 * this stack (XAMPP locally, the planned VPS) is IPv4.
 */
function resolveHostToPublicIp(string $host): ?string
{
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return isPublicIp($host) ? $host : null;
    }
    $ip = gethostbyname($host);
    if ($ip === $host) return null;   // resolution failed
    return isPublicIp($ip) ? $ip : null;
}

/**
 * Resolve a (possibly relative) redirect Location header against the
 * URL that issued it.
 */
function resolveRelativeUrl(string $base, string $relative): string
{
    if (preg_match('#^https?://#i', $relative)) return $relative;

    $baseParts = parse_url($base);
    $scheme    = $baseParts['scheme'] ?? 'https';
    $host      = $baseParts['host']   ?? '';
    $port      = isset($baseParts['port']) ? ':' . $baseParts['port'] : '';

    if (str_starts_with($relative, '//')) return $scheme . ':' . $relative;
    if (str_starts_with($relative, '/'))  return "{$scheme}://{$host}{$port}{$relative}";

    $basePath = $baseParts['path'] ?? '/';
    $slashPos = strrpos($basePath, '/');
    $dir      = $slashPos !== false ? substr($basePath, 0, $slashPos) : '';
    return "{$scheme}://{$host}{$port}{$dir}/{$relative}";
}

/**
 * Fetch a URL's body server-side with SSRF protections:
 *  - scheme restricted to http/https
 *  - hostname resolved and validated as a public IP BEFORE connecting
 *  - the connection is pinned to that exact validated IP via
 *    CURLOPT_RESOLVE (the Host header / TLS SNI still use the real
 *    hostname) so a DNS answer that changes between our check and
 *    curl's own lookup can't be used to reach an internal address
 *  - redirects are followed manually (max URL_FETCH_MAX_REDIRECTS),
 *    re-validating the target host/IP and re-checking the video-domain
 *    list at every hop, rather than letting curl follow them blindly
 *  - response body capped at UPLOAD_MAX_BYTES via a write callback that
 *    aborts the transfer once exceeded
 *
 * @return array  {ok:true, body, content_type, final_url} or
 *                {ok:false, status, error}
 */
function curlFetchSafely(string $url): array
{
    $currentUrl = $url;

    for ($hop = 0; $hop <= URL_FETCH_MAX_REDIRECTS; $hop++) {
        if (isVideoUrl($currentUrl)) {
            return ['ok' => false, 'status' => 422, 'error' => "Video links aren't supported — please upload the document directly, or paste a link to an article or file."];
        }

        $parts = parse_url($currentUrl);
        if (!$parts || !in_array(($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host'])) {
            return ['ok' => false, 'status' => 400, 'error' => 'Please enter a valid http or https URL.'];
        }

        $ip = resolveHostToPublicIp($parts['host']);
        if ($ip === null) {
            return ['ok' => false, 'status' => 400, 'error' => 'This link could not be reached or points to a restricted address.'];
        }

        $port    = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
        $body    = '';
        $headers = [];
        $bytesSoFar = 0;
        $maxBytes   = UPLOAD_MAX_BYTES;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL             => $currentUrl,
            CURLOPT_RESOLVE         => ["{$parts['host']}:{$port}:{$ip}"],
            CURLOPT_FOLLOWLOCATION  => false,
            CURLOPT_TIMEOUT         => URL_FETCH_TIMEOUT_SEC,
            CURLOPT_CONNECTTIMEOUT  => URL_FETCH_CONNECT_TIMEOUT_SEC,
            CURLOPT_USERAGENT       => 'Mozilla/5.0 (compatible; StudizFetcher/1.0; +document-import)',
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,
            CURLOPT_HEADERFUNCTION  => function ($curl, $line) use (&$headers) {
                $headers[] = $line;
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION   => function ($curl, $chunk) use (&$body, &$bytesSoFar, $maxBytes) {
                $bytesSoFar += strlen($chunk);
                if ($bytesSoFar > $maxBytes) return -1;   // abort transfer
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);

        curl_exec($ch);
        $errno  = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ctype  = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($errno === CURLE_WRITE_ERROR && $bytesSoFar > $maxBytes) {
            return ['ok' => false, 'status' => 413, 'error' => "That link's content exceeds the 20 MB size limit."];
        }
        if ($errno !== 0) {
            return ['ok' => false, 'status' => 400, 'error' => 'Could not fetch that link. Please check the URL and try again.'];
        }

        if (in_array($status, [301, 302, 303, 307, 308], true)) {
            $location = null;
            foreach ($headers as $h) {
                if (preg_match('/^location:\s*(.+)$/i', trim($h), $m)) $location = trim($m[1]);
            }
            if (!$location) {
                return ['ok' => false, 'status' => 400, 'error' => 'That link could not be followed.'];
            }
            $currentUrl = resolveRelativeUrl($currentUrl, $location);
            continue;
        }

        if ($status < 200 || $status >= 300) {
            return ['ok' => false, 'status' => 400, 'error' => "That link returned an error (HTTP {$status})."];
        }

        return ['ok' => true, 'body' => $body, 'content_type' => $ctype, 'final_url' => $currentUrl];
    }

    return ['ok' => false, 'status' => 400, 'error' => 'Too many redirects while following that link.'];
}

/**
 * Classify a fetched response as a known document extension (for the
 * Python extractor), 'video' (reject), or 'html' (best-effort readable-
 * text extraction) — preferring the Content-Type header, falling back
 * to the URL's path extension for servers that send a generic type
 * like application/octet-stream on a direct download link.
 */
function classifyFetchedContent(string $contentType, string $url): string
{
    $contentType = strtolower(trim(explode(';', $contentType)[0] ?? ''));
    $path        = strtolower((string) parse_url($url, PHP_URL_PATH));
    $ext         = pathinfo($path, PATHINFO_EXTENSION);

    if (str_starts_with($contentType, 'video/')) return 'video';

    $mimeToExt = [
        'application/pdf'                                                            => 'pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'    => 'docx',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation'  => 'pptx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'          => 'xlsx',
        'application/vnd.ms-powerpoint'                                              => 'ppt',
        'application/vnd.ms-excel'                                                   => 'xls',
        'text/plain'                                                                 => 'txt',
        'text/csv'                                                                   => 'csv',
        'image/jpeg'                                                                 => 'jpg',
        'image/png'                                                                  => 'png',
    ];
    if (isset($mimeToExt[$contentType])) return $mimeToExt[$contentType];

    $knownExts = ['pdf', 'docx', 'pptx', 'ppt', 'xlsx', 'xls', 'txt', 'md', 'csv', 'log', 'jpg', 'jpeg', 'png'];
    if (in_array($ext, $knownExts, true)) return $ext;

    return 'html';
}

/**
 * Best-effort readable-text extraction from a fetched HTML page: strips
 * script/style/nav/header/footer/aside/form/etc. then prefers <main> or
 * <article> content over the full <body>. Not a real readability
 * library (no Mozilla Readability-style scoring) — same "good enough"
 * trade-off as the legacy-.ppt heuristic. A best-guess charset fix-up
 * (Content-Type header, falling back to <meta charset>) runs first so
 * non-UTF-8 pages don't come out mangled.
 *
 * @return array {text, title}
 */
function htmlToReadableText(string $html, string $contentTypeHeader = ''): array
{
    $html = trim($html);
    if ($html === '') return ['text' => '', 'title' => ''];

    $charset = 'UTF-8';
    if (preg_match('/charset=([\w-]+)/i', $contentTypeHeader, $m)) {
        $charset = $m[1];
    } elseif (preg_match('/<meta[^>]+charset=["\']?([\w-]+)/i', $html, $m)) {
        $charset = $m[1];
    }
    if (strcasecmp($charset, 'UTF-8') !== 0 && strcasecmp($charset, 'UTF8') !== 0) {
        $converted = @mb_convert_encoding($html, 'UTF-8', $charset);
        if ($converted !== false) $html = $converted;
    }

    $prevState = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($prevState);

    $xpath = new DOMXPath($dom);

    $title     = '';
    $titleNode = $dom->getElementsByTagName('title')->item(0);
    if ($titleNode) $title = trim($titleNode->textContent);

    foreach ($xpath->query('//script|//style|//noscript|//nav|//header|//footer|//aside|//form|//svg|//iframe') as $node) {
        $node->parentNode?->removeChild($node);
    }

    $main = $xpath->query('//main')->item(0) ?: $xpath->query('//article')->item(0) ?: $dom->getElementsByTagName('body')->item(0);
    $text = $main ? $main->textContent : '';

    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/\n\s*\n\s*/', "\n\n", $text);
    $text = trim($text);

    return ['text' => $text, 'title' => $title];
}

/**
 * Look up an existing summary for this user with identical document
 * content, formatted as the same JSON payload shape process_document
 * returns. Used both for the upfront duplicate check and for the rare
 * race where two identical uploads land concurrently.
 *
 * @return array|null  Response payload, or null if no match exists
 */
function fetchDuplicateSummaryPayload(PDO $pdo, string $userToken, string $contentHash): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, summary, key_points, quiz_json, youtube_links, answered_questions
           FROM summaries WHERE user_token = ? AND content_hash = ? AND deleted_at IS NULL LIMIT 1'
    );
    $stmt->execute([$userToken, $contentHash]);
    $row = $stmt->fetch();
    if (!$row) return null;

    return [
        'ok'                 => true,
        'duplicate'          => true,
        'summary_id'         => (int) $row['id'],
        'summary'            => $row['summary'],
        'key_points'         => json_decode($row['key_points'], true),
        'quiz'               => json_decode($row['quiz_json'], true),
        'youtube_links'      => json_decode($row['youtube_links'], true),
        'answered_questions' => $row['answered_questions'] ? json_decode($row['answered_questions'], true) : [],
    ];
}

/**
 * Run the Python text-extraction script via proc_open.
 * The file path and extension are passed as separate argv entries
 * (not shell-interpolated) — proc_open's array form passes each as a
 * distinct, literal argument, so neither can break out into a shell command.
 *
 * @param  string $filePath  Absolute path to the uploaded file (always
 *                            stored with a fixed ".upload" extension)
 * @param  string $origExt   The client's original file extension, used by
 *                            the extractor purely to pick which parser to try
 * @return string|null       Extracted text, or null on failure
 */
function runPythonExtractor(string $filePath, string $origExt): ?string
{
    // Defence: ensure the path is within our uploads directory.
    $realPath      = str_replace('\\', '/', realpath($filePath));
    $uploadRealDir = str_replace('\\', '/', realpath(UPLOAD_TMP_DIR));

    if ($realPath === false || !str_starts_with($realPath, $uploadRealDir)) {
    logGatewayError("Path traversal attempt detected: {$filePath}");
    return null;
    }
    $command = [PYTHON_BIN, PYTHON_SCRIPT, $realPath, $origExt];

    $descriptors = [
        0 => ['pipe', 'r'],   // stdin  (unused)
        1 => ['pipe', 'w'],   // stdout (extracted text)
        2 => ['pipe', 'w'],   // stderr (error messages)
    ];

    $proc = proc_open($command, $descriptors, $pipes);

    if (!is_resource($proc)) {
        logGatewayError('proc_open failed to launch Python extractor.');
        return null;
    }

    fclose($pipes[0]);  // close stdin immediately

    // Read stdout and stderr with a 60-second cap
    stream_set_timeout($pipes[1], 60);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($proc);

    if ($exitCode !== 0 || $output === false) {
        logGatewayError("Python extractor exited with code {$exitCode}. Stderr: {$errors}");
        return null;
    }

    return $output;
}

/**
 * Terminate the script with an HTTP error code and JSON error payload.
 *
 * @param int    $code    HTTP status code
 * @param string $message Human-readable error
 * @param array  $extra   Additional JSON fields to merge
 */
function exitWith(int $code, string $message, array $extra = []): never
{
    http_response_code($code);
    echo json_encode(array_merge(['ok' => false, 'error' => $message], $extra));
    exit;
}

/**
 * Append a timestamped error line to the secure error log.
 */
function logGatewayError(string $msg): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] [gateway] ' . $msg . PHP_EOL;
    error_log($line, 3, ERROR_LOG_FILE);
}
