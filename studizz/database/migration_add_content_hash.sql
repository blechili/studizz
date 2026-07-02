-- ============================================================
-- MIGRATION: add content_hash to summaries (duplicate prevention)
-- Run this once against an EXISTING studiz_db database that was
-- created before this migration. Fresh installs get this column
-- directly from schema.sql and don't need this file.
-- ============================================================

USE studiz_db;

-- 1. Add the column as nullable first (existing rows have no hash yet).
ALTER TABLE summaries
  ADD COLUMN content_hash CHAR(64) NULL AFTER raw_text;

-- 2. Backfill existing rows from their already-stored raw_text.
UPDATE summaries
   SET content_hash = SHA2(raw_text, 256)
 WHERE content_hash IS NULL;

-- 3. Remove any duplicates already sitting in the table (keeps the
--    oldest copy of each). Quiz attempts tied to a removed duplicate's
--    id become orphaned history rows — harmless, just no longer viewable.
DELETE s1 FROM summaries s1
INNER JOIN summaries s2
        ON s1.user_token    = s2.user_token
       AND s1.content_hash  = s2.content_hash
       AND s1.id            > s2.id;

-- 4. Lock the column down and enforce one-copy-per-user going forward.
ALTER TABLE summaries
  MODIFY COLUMN content_hash CHAR(64) NOT NULL,
  ADD UNIQUE KEY uniq_user_content (user_token, content_hash);
