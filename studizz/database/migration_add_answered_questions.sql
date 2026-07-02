-- ============================================================
-- MIGRATION: add answered_questions to summaries
-- (AI-solved questions/essay-prompts detected inside an uploaded
-- document — distinct from the auto-generated study quiz).
-- Run this once against an EXISTING studiz_db database that was
-- created before this migration. Fresh installs get this column
-- directly from schema.sql and don't need this file.
-- ============================================================

USE studiz_db;

ALTER TABLE summaries
  ADD COLUMN answered_questions JSON DEFAULT NULL AFTER youtube_links;
