-- Run this once against the "chats" database (see chat-api/config.php,
-- which auth-api/config.php also points at — accounts and messages
-- share one database). Same house style throughout: InnoDB, utf8mb4,
-- no surprises.
--
-- FRESH INSTALL: run the whole file top to bottom.
--
-- UPGRADING an existing install that only has the old `messages` table
-- (author/body/client_id, no accounts): the CREATE TABLE IF NOT EXISTS
-- statements below are safe to re-run, but the ALTER TABLE block near
-- the bottom that adds `user_id` to `messages` will error with
-- "Duplicate column" if you run it twice — that's expected, it only
-- needs to run once.

-- ---------- roles ----------
CREATE TABLE IF NOT EXISTS roles (
  id    TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name  VARCHAR(30)      NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO roles (id, name) VALUES
  (1, 'customer'),
  (2, 'customer_service'),
  (3, 'administrator');

-- ---------- users ----------
CREATE TABLE IF NOT EXISTS users (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  username           VARCHAR(40)     NOT NULL,   -- the OFFICIAL username — what chat uses
  password_hash      VARCHAR(255)    NOT NULL,   -- password_hash(), never plaintext
  email              VARCHAR(190)    NOT NULL,
  email_verified_at  DATETIME(6)     NULL,       -- NULL = unverified; chat requires this set
  is_deleted         TINYINT(1)      NOT NULL DEFAULT 0, -- soft delete — never hard-deleted
  created_at         DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at         DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- user_roles (many-to-many: a user can hold more than one role) ----------
CREATE TABLE IF NOT EXISTS user_roles (
  user_id  BIGINT UNSIGNED  NOT NULL,
  role_id  TINYINT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, role_id),
  KEY idx_user_roles_role (role_id),
  CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- sessions (the token stored in the weekly cookie) ----------
CREATE TABLE IF NOT EXISTS sessions (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id      BIGINT UNSIGNED NOT NULL,
  token        CHAR(64)        NOT NULL,  -- random 32-byte hex, HttpOnly cookie value
  expires_at   DATETIME(6)     NOT NULL,  -- 7 days out from issue
  created_at   DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  last_seen_at DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_sessions_token (token),
  KEY idx_sessions_user (user_id),
  CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- email verification tokens ----------
CREATE TABLE IF NOT EXISTS email_verifications (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     BIGINT UNSIGNED NOT NULL,
  token       CHAR(36)        NOT NULL,  -- GUID, emailed as a link
  expires_at  DATETIME(6)     NOT NULL,  -- 24h
  used_at     DATETIME(6)     NULL,
  created_at  DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_email_verifications_token (token),
  KEY idx_email_verifications_user (user_id),
  CONSTRAINT fk_email_verifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- password reset tokens ----------
CREATE TABLE IF NOT EXISTS password_resets (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     BIGINT UNSIGNED NOT NULL,
  token       CHAR(36)        NOT NULL,  -- GUID, emailed as a link
  expires_at  DATETIME(6)     NOT NULL,  -- 1h
  used_at     DATETIME(6)     NULL,
  created_at  DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_password_resets_token (token),
  KEY idx_password_resets_user (user_id),
  CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- messages ----------
-- Chat now requires a verified login, so every new row's `author` is a
-- snapshot of users.username taken at send time (never a client-supplied
-- display name), and `user_id` ties it back to the account. Soft-deleting
-- an account never touches these rows — chat history stays exactly as-is.
CREATE TABLE IF NOT EXISTS messages (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  author      VARCHAR(40)     NOT NULL,
  body        VARCHAR(500)    NOT NULL,
  client_id   CHAR(64)        NOT NULL,   -- random per-browser id, only used for "is this my own message"
  user_id     BIGINT UNSIGNED NULL,       -- who actually sent it
  created_at  DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_created_at (created_at),
  KEY idx_client_id (client_id),
  KEY idx_user_id (user_id),
  CONSTRAINT fk_messages_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Upgrading an install that already has `messages` without user_id?
-- Run just this block once:
--
-- ALTER TABLE messages ADD COLUMN user_id BIGINT UNSIGNED NULL AFTER client_id;
-- ALTER TABLE messages ADD KEY idx_user_id (user_id);
-- ALTER TABLE messages ADD CONSTRAINT fk_messages_user FOREIGN KEY (user_id) REFERENCES users(id);
