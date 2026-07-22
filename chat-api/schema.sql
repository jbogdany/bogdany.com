-- Run this once against the "chats" database (see chat-api/config.php).
-- Same house style as suzen/schema.sql: InnoDB, utf8mb4, no surprises.

CREATE TABLE IF NOT EXISTS messages (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  author      VARCHAR(40)  NOT NULL,
  body        VARCHAR(500) NOT NULL,
  client_id   CHAR(64)     NOT NULL,   -- random per-browser id, not an auth credential
  created_at  DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_created_at (created_at),
  KEY idx_client_id (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
