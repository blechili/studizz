-- ============================================================
-- STUDIZ DATABASE SCHEMA
-- Engine: MySQL 8.x | Charset: utf8mb4
-- ============================================================

CREATE DATABASE IF NOT EXISTS studiz_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE studiz_db;

-- ============================================================
-- TABLE: users
-- Stores onboarded user profiles. PII is stored server-side
-- only. The client receives only a secure random token cookie.
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  token         CHAR(64)     NOT NULL UNIQUE,
  name          VARCHAR(120) NOT NULL,
  username      VARCHAR(60)  NOT NULL UNIQUE,
  class         VARCHAR(80)  NOT NULL,
  email         VARCHAR(180) NOT NULL UNIQUE,
  phone         VARCHAR(20)  NOT NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_token (token),
  INDEX idx_email (email)
) ENGINE=InnoDB;

-- ============================================================
-- TABLE: rate_limits
-- Tracks rolling 60-second AI request windows per user.
-- ============================================================
CREATE TABLE IF NOT EXISTS rate_limits (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_token  CHAR(64)    NOT NULL,
  endpoint    VARCHAR(40) NOT NULL DEFAULT 'ai',
  hit_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  INDEX idx_rl_token_time (user_token, hit_at)
) ENGINE=InnoDB;

-- ============================================================
-- TABLE: folders
-- Virtual collections users create to organise summaries.
-- ============================================================
CREATE TABLE IF NOT EXISTS folders (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_token  CHAR(64)     NOT NULL,
  name        VARCHAR(120) NOT NULL,
  color       CHAR(7)      NOT NULL DEFAULT '#6C63FF',
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at  DATETIME     NULL DEFAULT NULL,
  INDEX idx_folder_user (user_token)
) ENGINE=InnoDB;

-- ============================================================
-- TABLE: summaries
-- Stores every AI-generated study artefact.
-- ============================================================
CREATE TABLE IF NOT EXISTS summaries (
  id              INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
  user_token      CHAR(64)      NOT NULL,
  folder_id       INT UNSIGNED  DEFAULT NULL,
  original_name   VARCHAR(255)  NOT NULL,
  raw_text        LONGTEXT      NOT NULL,
  content_hash    CHAR(64)      NOT NULL,
  summary         LONGTEXT      NOT NULL,
  key_points      JSON          NOT NULL,
  quiz_json       JSON          NOT NULL,
  youtube_links   JSON          DEFAULT NULL,
  answered_questions JSON       DEFAULT NULL,
  created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  deleted_at      DATETIME      NULL DEFAULT NULL,
  INDEX idx_sum_user   (user_token),
  INDEX idx_sum_folder (folder_id),
  UNIQUE KEY uniq_user_content (user_token, content_hash),
  CONSTRAINT fk_sum_folder FOREIGN KEY (folder_id) REFERENCES folders(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- TABLE: quiz_attempts
-- Records every quiz attempt for grading history.
-- ============================================================
CREATE TABLE IF NOT EXISTS quiz_attempts (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_token    CHAR(64)     NOT NULL,
  summary_id    INT UNSIGNED DEFAULT NULL,
  folder_id     INT UNSIGNED DEFAULT NULL,
  score         TINYINT UNSIGNED NOT NULL,
  total_q       TINYINT UNSIGNED NOT NULL,
  correct_q     TINYINT UNSIGNED NOT NULL,
  answers_json  JSON         NOT NULL,
  taken_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_qa_user (user_token)
) ENGINE=InnoDB;

-- ============================================================
-- TABLE: chatbot_usage
-- Enforces the 15-query-per-rolling-24h cap per user.
-- ============================================================
CREATE TABLE IF NOT EXISTS chatbot_usage (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_token  CHAR(64)     NOT NULL,
  queried_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_chat_user_time (user_token, queried_at)
) ENGINE=InnoDB;

-- ============================================================
-- TABLE: chatbot_history
-- Stores per-session conversation turns for multi-turn context.
-- ============================================================
CREATE TABLE IF NOT EXISTS chatbot_history (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_token  CHAR(64)     NOT NULL,
  session_id  CHAR(36)     NOT NULL,
  role        ENUM('user','assistant') NOT NULL,
  content     TEXT         NOT NULL,
  sent_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ch_session (session_id)
) ENGINE=InnoDB;
