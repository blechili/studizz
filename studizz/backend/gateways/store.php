<?php
/**
 * STUDIZ — Store Gateway (Folder & Summary CRUD)
 * ------------------------------------------------
 * POST /backend/gateways/store.php
 *
 * Actions:
 *  "list_folders"      — Get all folders for the user
 *  "create_folder"     — Create a new folder
 *  "rename_folder"     — Update a folder's name or color
 *  "delete_folder"     — Soft-delete a folder (summaries unlinked)
 *  "list_summaries"    — List summaries (optionally filtered by folder_id)
 *  "get_summary"       — Fetch a single summary's full data
 *  "move_summary"      — Move a summary to a different folder
 *  "delete_summary"    — Soft-delete a summary
 *
 * Deletes are SOFT: rows get `deleted_at = NOW()` instead of being
 * physically removed, and every read below filters `deleted_at IS NULL`.
 * This buys an audit trail / future undo window instead of the previous
 * irreversible hard DELETE. See database/migration_add_soft_delete.sql.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/Auth.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

set_exception_handler(static function (Throwable $e): void {
    error_log(
        '[' . date('Y-m-d H:i:s') . '] [store] Uncaught '
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

$userToken = Auth::resolveToken();
if ($userToken === null) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Authentication required.']);
    exit;
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';
$pdo    = DB::connect();

switch ($action) {

    // ── List all folders ─────────────────────────────────────
    case 'list_folders':
        $stmt = $pdo->prepare(
            'SELECT f.id, f.name, f.color, f.created_at,
                    COUNT(s.id) AS summary_count
               FROM folders f
               LEFT JOIN summaries s ON s.folder_id = f.id
                                    AND s.user_token = f.user_token
                                    AND s.deleted_at IS NULL
              WHERE f.user_token = ? AND f.deleted_at IS NULL
              GROUP BY f.id
              ORDER BY f.created_at DESC'
        );
        $stmt->execute([$userToken]);
        echo json_encode(['ok' => true, 'folders' => $stmt->fetchAll()]);
        break;

    // ── Create folder ────────────────────────────────────────
    case 'create_folder':
        $name  = mb_substr(trim(strip_tags($body['name'] ?? '')), 0, 120);
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', $body['color'] ?? '') ? $body['color'] : '#6C63FF';
        if (!$name) { exitStore(400, 'Folder name is required.'); }

        $stmt = $pdo->prepare('INSERT INTO folders (user_token, name, color) VALUES (?, ?, ?)');
        $stmt->execute([$userToken, $name, $color]);
        echo json_encode(['ok' => true, 'id' => (int) $pdo->lastInsertId(), 'name' => $name, 'color' => $color]);
        break;

    // ── Rename/recolor folder ────────────────────────────────
    case 'rename_folder':
        $id    = (int) ($body['id'] ?? 0);
        $name  = mb_substr(trim(strip_tags($body['name'] ?? '')), 0, 120);
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', $body['color'] ?? '') ? $body['color'] : null;
        if (!$id || !$name) { exitStore(400, 'Folder id and name are required.'); }

        $sets = ['name = ?'];
        $vals = [$name];
        if ($color) { $sets[] = 'color = ?'; $vals[] = $color; }
        $vals[] = $id;
        $vals[] = $userToken;

        $pdo->prepare('UPDATE folders SET ' . implode(', ', $sets) . ' WHERE id = ? AND user_token = ? AND deleted_at IS NULL')->execute($vals);
        echo json_encode(['ok' => true]);
        break;

    // ── Delete folder (soft) ─────────────────────────────────
    case 'delete_folder':
        $id = (int) ($body['id'] ?? 0);
        if (!$id) { exitStore(400, 'Folder id is required.'); }

        // Unlink summaries first — a deleted folder shouldn't keep showing
        // up as their folder_id even though list_folders now hides it.
        $pdo->prepare('UPDATE summaries SET folder_id = NULL WHERE folder_id = ? AND user_token = ?')
            ->execute([$id, $userToken]);
        $pdo->prepare('UPDATE folders SET deleted_at = NOW() WHERE id = ? AND user_token = ? AND deleted_at IS NULL')
            ->execute([$id, $userToken]);
        echo json_encode(['ok' => true]);
        break;

    // ── List summaries ───────────────────────────────────────
    case 'list_summaries':
        $folderId = isset($body['folder_id']) ? (int) $body['folder_id'] : null;
        if ($folderId) {
            $stmt = $pdo->prepare(
                'SELECT id, original_name, folder_id, created_at,
                        LEFT(summary, 200) AS preview
                   FROM summaries WHERE user_token = ? AND folder_id = ? AND deleted_at IS NULL
                 ORDER BY created_at DESC'
            );
            $stmt->execute([$userToken, $folderId]);
        } else {
            $stmt = $pdo->prepare(
                'SELECT id, original_name, folder_id, created_at,
                        LEFT(summary, 200) AS preview
                   FROM summaries WHERE user_token = ? AND deleted_at IS NULL
                 ORDER BY created_at DESC'
            );
            $stmt->execute([$userToken]);
        }
        echo json_encode(['ok' => true, 'summaries' => $stmt->fetchAll()]);
        break;

    // ── Get single summary ───────────────────────────────────
    case 'get_summary':
        $id   = (int) ($body['id'] ?? 0);
        if (!$id) { exitStore(400, 'Summary id is required.'); }
        $stmt = $pdo->prepare(
            'SELECT id, original_name, summary, key_points, quiz_json, youtube_links, answered_questions, folder_id, created_at
               FROM summaries WHERE id = ? AND user_token = ? AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute([$id, $userToken]);
        $row = $stmt->fetch();
        if (!$row) { exitStore(404, 'Summary not found.'); }

        // Decode JSON columns
        $row['key_points']         = json_decode($row['key_points'],    true);
        $row['quiz_json']          = json_decode($row['quiz_json'],     true);
        $row['youtube_links']      = json_decode($row['youtube_links'], true);
        $row['answered_questions'] = $row['answered_questions'] ? json_decode($row['answered_questions'], true) : [];
        echo json_encode(['ok' => true, 'summary' => $row]);
        break;

    // ── Move summary to folder ───────────────────────────────
    case 'move_summary':
        $id       = (int) ($body['id']        ?? 0);
        $folderId = isset($body['folder_id']) ? (int) $body['folder_id'] : null;
        if (!$id) { exitStore(400, 'Summary id is required.'); }

        // Verify the target folder belongs to this user. Without this check
        // User A could move their summary into User B's folder_id, which
        // would corrupt User B's badge count via the summaries LEFT JOIN in
        // list_folders — even though User B can never read User A's content.
        if ($folderId !== null) {
            $fRow = $pdo->prepare(
                'SELECT id FROM folders WHERE id = ? AND user_token = ? AND deleted_at IS NULL LIMIT 1'
            );
            $fRow->execute([$folderId, $userToken]);
            if (!$fRow->fetch()) { exitStore(403, 'That folder does not belong to your account.'); }
        }

        $pdo->prepare('UPDATE summaries SET folder_id = ? WHERE id = ? AND user_token = ? AND deleted_at IS NULL')
            ->execute([$folderId, $id, $userToken]);
        echo json_encode(['ok' => true]);
        break;

    // ── Delete summary (soft) ────────────────────────────────
    case 'delete_summary':
        $id = (int) ($body['id'] ?? 0);
        if (!$id) { exitStore(400, 'Summary id is required.'); }

        // content_hash is mutated here to free up its slot in
        // UNIQUE KEY uniq_user_content (user_token, content_hash) — without
        // this, re-uploading the identical document after deleting it would
        // hit a duplicate-key error against the now-invisible deleted row.
        // Truncated to 48 chars to leave room for the suffix within the
        // column's CHAR(64) width; the mutated value is never compared
        // against anything again once a row is soft-deleted.
        $pdo->prepare(
            "UPDATE summaries
                SET deleted_at = NOW(),
                    content_hash = CONCAT(LEFT(content_hash, 48), '_del', id)
              WHERE id = ? AND user_token = ? AND deleted_at IS NULL"
        )->execute([$id, $userToken]);
        echo json_encode(['ok' => true]);
        break;

    default:
        exitStore(400, "Unknown action: {$action}");
}

function exitStore(int $code, string $msg): never
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}
