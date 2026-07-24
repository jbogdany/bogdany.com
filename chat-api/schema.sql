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

-- ---------- rooms ----------
-- created_by is NULL for the seeded/public rooms ('general', anything
-- auto-created by `chat join <new-name>`) — nobody "owns" those. A room
-- made via `chat create` gets is_private=1 and created_by set to its
-- creator, who is the only person who can delete it (see delete-room.php).
CREATE TABLE IF NOT EXISTS rooms (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(30)     NOT NULL,  -- lowercase letters/numbers/hyphens, see valid_room_name()
  created_by  BIGINT UNSIGNED NULL,
  is_private  TINYINT(1)      NOT NULL DEFAULT 0,
  created_at  DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_rooms_name (name),
  KEY idx_rooms_created_by (created_by),
  CONSTRAINT fk_rooms_created_by FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO rooms (name) VALUES ('general');

-- ---------- room_members ----------
-- Membership in a private room. The creator is added here too (with
-- invited_by NULL) at creation time, so "is this person allowed in?" is
-- always just "are they in room_members OR are they rooms.created_by" —
-- see room_access_ok() in common.php. Any existing member can invite
-- another (invited_by records who), so invites chain indefinitely —
-- there's deliberately no cap on invite depth.
-- ON DELETE CASCADE on `room` means deleting a room (delete-room.php)
-- automatically clears its membership list too.
CREATE TABLE IF NOT EXISTS room_members (
  room        VARCHAR(30)     NOT NULL,
  user_id     BIGINT UNSIGNED NOT NULL,
  invited_by  BIGINT UNSIGNED NULL,
  joined_at   DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (room, user_id),
  KEY idx_room_members_user (user_id),
  CONSTRAINT fk_room_members_room FOREIGN KEY (room) REFERENCES rooms(name) ON DELETE CASCADE,
  CONSTRAINT fk_room_members_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_room_members_invited_by FOREIGN KEY (invited_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Upgrading an install that already has `rooms` without created_by/
-- is_private? Run this once:
--
-- ALTER TABLE rooms ADD COLUMN created_by BIGINT UNSIGNED NULL AFTER name;
-- ALTER TABLE rooms ADD COLUMN is_private TINYINT(1) NOT NULL DEFAULT 0 AFTER created_by;
-- ALTER TABLE rooms ADD KEY idx_rooms_created_by (created_by);
-- ALTER TABLE rooms ADD CONSTRAINT fk_rooms_created_by FOREIGN KEY (created_by) REFERENCES users(id);

-- ---------- room_bans ----------
-- Permanent — there's no expires_at and deliberately no "unban" endpoint.
-- A ban always wins over membership/ownership in room_access_ok(), except
-- the room's own creator can never be banned from their own room (see
-- ban-user.php) — banning them would permanently lock them out of a room
-- they own, with no way back in since bans don't expire.
CREATE TABLE IF NOT EXISTS room_bans (
  room       VARCHAR(30)     NOT NULL,
  user_id    BIGINT UNSIGNED NOT NULL,
  banned_by  BIGINT UNSIGNED NULL,
  banned_at  DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (room, user_id),
  KEY idx_room_bans_user (user_id),
  CONSTRAINT fk_room_bans_room FOREIGN KEY (room) REFERENCES rooms(name) ON DELETE CASCADE,
  CONSTRAINT fk_room_bans_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_room_bans_banned_by FOREIGN KEY (banned_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- messages ----------
-- Chat now requires a verified login, so every new row's `author` is a
-- snapshot of users.username taken at send time (never a client-supplied
-- display name), and `user_id` ties it back to the account. Soft-deleting
-- an account never touches these rows — chat history stays exactly as-is.
-- `room` is a plain string rather than a foreign key to `rooms.id` — it's
-- just a label, and keeping it flat means history/send never need a join.
CREATE TABLE IF NOT EXISTS messages (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  author      VARCHAR(40)     NOT NULL,
  body        VARCHAR(500)    NOT NULL,
  client_id   CHAR(64)        NOT NULL,   -- random per-browser id, only used for "is this my own message"
  user_id     BIGINT UNSIGNED NULL,       -- who actually sent it
  room        VARCHAR(30)     NOT NULL DEFAULT 'general',
  created_at  DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_created_at (created_at),
  KEY idx_client_id (client_id),
  KEY idx_user_id (user_id),
  KEY idx_room_id (room, id),
  CONSTRAINT fk_messages_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Upgrading an install that already has `messages` without user_id and/or
-- room? Run whichever of these you still need, once each:
--
-- ALTER TABLE messages ADD COLUMN user_id BIGINT UNSIGNED NULL AFTER client_id;
-- ALTER TABLE messages ADD KEY idx_user_id (user_id);
-- ALTER TABLE messages ADD CONSTRAINT fk_messages_user FOREIGN KEY (user_id) REFERENCES users(id);
-- ALTER TABLE messages ADD COLUMN room VARCHAR(30) NOT NULL DEFAULT 'general' AFTER user_id;
-- ALTER TABLE messages ADD KEY idx_room_id (room, id);
