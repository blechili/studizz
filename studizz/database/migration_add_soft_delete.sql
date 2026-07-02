-- ============================================================
-- MIGRATION: Soft delete for summaries and folders
-- ------------------------------------------------------------
-- Previously every delete was a hard DELETE with no audit trail
-- and no undo window. This adds deleted_at to both tables;
-- application code now does UPDATE ... SET deleted_at = NOW()
-- instead of DELETE, and every read filters deleted_at IS NULL.
--
-- summaries.content_hash is mutated at delete time (see
-- store.php's delete_summary) to free up the existing
-- UNIQUE KEY uniq_user_content (user_token, content_hash) slot —
-- this lets a user re-upload identical content after deleting it
-- without a constraint clash against the now-invisible old row,
-- with no need to touch the index itself.
-- ============================================================

ALTER TABLE summaries ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL AFTER created_at;
ALTER TABLE folders   ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL AFTER updated_at;
