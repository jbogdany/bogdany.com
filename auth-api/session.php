<?php
declare(strict_types=1);

// Session/account helpers shared between auth-api (which manages them)
// and chat-api (which just needs to ask "who is this?"). This file only
// depends on a PDO connection being passed in — it doesn't open its own
// or define db()/json_out — so either folder's common.php can require
// it without a duplicate-function collision.
//
// require json_error() to already be defined (chat-api/common.php and
// auth-api/common.php both define one) before calling require_login()
// or require_verified() — current_user() itself never errors, it just
// returns null.

if (!defined('SESSION_COOKIE_NAME')) define('SESSION_COOKIE_NAME', 'bogdany_session');
if (!defined('SESSION_TTL_SECONDS')) define('SESSION_TTL_SECONDS', 7 * 24 * 60 * 60); // one week

function random_token(int $bytes = 32): string {
  return bin2hex(random_bytes($bytes));
}

// RFC 4122 v4 GUID — used for the emailed verify/reset links. Deliberately
// a different shape (36-char, hyphenated) than the 64-char hex session
// token, so the two token kinds can't be confused for one another.
function make_guid(): string {
  $data = random_bytes(16);
  $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
  $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
  $hex = bin2hex($data);
  return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
    . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
}

function issue_session(PDO $pdo, int $userId): string {
  $token = random_token();
  $stmt = $pdo->prepare('INSERT INTO sessions (user_id, token, expires_at) VALUES (?, ?, NOW(6) + INTERVAL 7 DAY)');
  $stmt->execute([$userId, $token]);

  $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
  setcookie(SESSION_COOKIE_NAME, $token, [
    'expires'  => time() + SESSION_TTL_SECONDS,
    'path'     => '/',
    'secure'   => $secure,
    'httponly' => true, // never readable from JS — the terminal asks me.php instead
    'samesite' => 'Lax',
  ]);
  return $token;
}

function clear_session_cookie(): void {
  setcookie(SESSION_COOKIE_NAME, '', [
    'expires'  => time() - 3600,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
}

function current_session_token(): ?string {
  $t = (string)($_COOKIE[SESSION_COOKIE_NAME] ?? '');
  return preg_match('/^[a-f0-9]{64}$/', $t) ? $t : null;
}

// Returns the logged-in user (id, username, email, verified, roles) for
// this request's session cookie, or null if there isn't one / it's
// expired / the account has been soft-deleted. Also slides last_seen_at
// forward so an active session's cookie keeps rolling within its week.
function current_user(PDO $pdo): ?array {
  $token = current_session_token();
  if ($token === null) return null;

  $stmt = $pdo->prepare(
    'SELECT u.id, u.username, u.email, u.email_verified_at
     FROM sessions s JOIN users u ON u.id = s.user_id
     WHERE s.token = ? AND s.expires_at > NOW(6) AND u.is_deleted = 0'
  );
  $stmt->execute([$token]);
  $row = $stmt->fetch();
  if (!$row) return null;

  $pdo->prepare('UPDATE sessions SET last_seen_at = NOW(6) WHERE token = ?')->execute([$token]);

  $roleStmt = $pdo->prepare(
    'SELECT r.name FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? ORDER BY r.name'
  );
  $roleStmt->execute([$row['id']]);

  return [
    'id'       => (int)$row['id'],
    'username' => $row['username'],
    'email'    => $row['email'],
    'verified' => $row['email_verified_at'] !== null,
    'roles'    => $roleStmt->fetchAll(PDO::FETCH_COLUMN),
  ];
}

function require_login(PDO $pdo): array {
  $u = current_user($pdo);
  if ($u === null) json_error('log in first', 401);
  return $u;
}

function require_verified(PDO $pdo): array {
  $u = require_login($pdo);
  if (!$u['verified']) json_error('verify your email first', 403);
  return $u;
}
