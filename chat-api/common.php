<?php
declare(strict_types=1);

// Converts a FATAL error anywhere in this request (a missing required
// file, calling an undefined function, a parse error in an included
// file, etc.) into a proper JSON response instead of a blank/broken 500
// with no body — that's what a plain try/catch can't do, since fatal
// errors aren't always catchable Throwables and can happen outside any
// try block (e.g. a top-level require_once). Flip DEBUG_ERRORS to false
// once things are working — it's currently on so the real cause shows
// up in the response instead of only the server's error log.
define('DEBUG_ERRORS', true);
ob_start();
register_shutdown_function(function () {
  $err = error_get_last();
  if (!$err || !in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
    return; // normal request — whatever was already echoed just flushes as-is
  }
  if (ob_get_level() > 0) ob_end_clean(); // discard any partial output from before the crash
  if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
  }
  echo json_encode([
    'error' => 'server error',
    'debug' => DEBUG_ERRORS ? ($err['message'] . ' in ' . $err['file'] . ' on line ' . $err['line']) : null,
  ]);
});

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

function db(): PDO {
  static $pdo = null;
  if ($pdo === null) {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    // ATTR_EMULATE_PREPARES = false forces *real* server-side prepared
    // statements, so parameters are always sent separately from the SQL
    // text — the standard, reliable defense against SQL injection. Every
    // query in this API uses ->prepare()/->execute([...]) for that reason;
    // none of them ever concatenate request data into a SQL string.
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
      PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
  }
  return $pdo;
}

function json_out(array $data, int $code = 200): void {
  http_response_code($code);
  echo json_encode($data);
  exit;
}

function json_error(string $message, int $code = 400): void {
  json_out(['error' => $message], $code);
}

function read_json_body(): array {
  $raw = file_get_contents('php://input');
  if ($raw === false || $raw === '') return [];
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

// Lightweight CSRF hardening. A plain HTML <form> submitted from another
// site (the classic CSRF vector) cannot set a custom JSON Content-Type —
// browsers restrict cross-site forms to a small allowlist of MIME types
// that doesn't include application/json. Requiring this header therefore
// blocks naive cross-site form-based forgery without needing a login
// system; it's not authentication (this chat is intentionally anonymous),
// just a floor under "did this request come from our own page's script".
function require_fetch_request(): void {
  $ct = $_SERVER['CONTENT_TYPE'] ?? '';
  if (stripos($ct, 'application/json') === false) {
    json_error('bad request', 400);
  }
}

// Trims, strips control characters (keeping tab/newline), and hard-caps
// the length — every endpoint runs user-supplied text through this so
// validation is identical everywhere rather than re-implemented per file.
// This is about storing sane data, not "sanitizing for HTML" — this API
// never outputs HTML, and the browser is responsible for rendering stored
// text as plain text (textContent, never innerHTML) when it displays it.
function clean_text(string $s, int $maxLen): string {
  $s = trim($s);
  $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
  return mb_substr($s, 0, $maxLen, 'UTF-8');
}

// The client id is a random per-browser token the front end generates
// (see index.htm) — it's only used to attribute "your own" messages in
// the UI and for basic flood control below, never as an auth credential.
function client_id_or_fail(array $body): string {
  $id = (string)($body['client_id'] ?? '');
  if (!preg_match('/^[a-f0-9]{16,64}$/', $id)) {
    json_error('invalid client id', 400);
  }
  return $id;
}

// 2-30 chars, lowercase letters/numbers/hyphens, no leading/trailing
// hyphen. Rooms are plain strings (see schema.sql) rather than a
// separate id — this is the one place that shape gets enforced.
function valid_room_name(string $s): bool {
  return (bool)preg_match('/^[a-z0-9](?:[a-z0-9-]{0,28}[a-z0-9])?$/', $s);
}

// Null if the room doesn't exist yet.
function get_room(PDO $pdo, string $room): ?array {
  $stmt = $pdo->prepare('SELECT id, created_by, is_private FROM rooms WHERE name = ?');
  $stmt->execute([$room]);
  $row = $stmt->fetch();
  return $row ?: null;
}

function is_room_banned(PDO $pdo, string $room, int $userId): bool {
  $stmt = $pdo->prepare('SELECT 1 FROM room_bans WHERE room = ? AND user_id = ?');
  $stmt->execute([$room, $userId]);
  return $stmt->fetchColumn() !== false;
}

// True if $userId may read/post in $room. A room that doesn't exist yet
// is always "ok" — that's how a brand-new PUBLIC room name comes into
// existence the first time someone joins or posts to it (see
// join-room.php / send.php). A ban always wins, even in a public room —
// checked first, ahead of ownership or membership. Otherwise: public
// rooms are open to everyone, and a private room is ok if the user
// created it or is a listed member.
function room_access_ok(PDO $pdo, string $room, int $userId): bool {
  $r = get_room($pdo, $room);
  if ($r === null) return true;
  if (is_room_banned($pdo, $room, $userId)) return false;
  if (!$r['is_private']) return true;
  if ($r['created_by'] !== null && (int)$r['created_by'] === $userId) return true;
  $stmt = $pdo->prepare('SELECT 1 FROM room_members WHERE room = ? AND user_id = ?');
  $stmt->execute([$room, $userId]);
  return $stmt->fetchColumn() !== false;
}

// Every chat-api endpoint needs auth-api's session helpers (current_user()
// etc.) but lives in a different folder, so it's always a relative
// require across a folder boundary — exactly the kind of thing that
// silently breaks if the two folders ever aren't true filesystem
// siblings (wrong upload path, a symlink, etc.). Checking file_exists()
// first turns that from a blank fatal error into a specific, readable
// one that names the exact path it looked for.
function require_auth_session(): void {
  $path = __DIR__ . '/../auth-api/session.php';
  if (!file_exists($path)) {
    json_error('server misconfigured — expected file not found: ' . $path, 500);
  }
  require_once $path;
}

function handle_request(callable $fn): void {
  try {
    $fn(db());
  } catch (Throwable $e) {
    error_log('[chat api] ' . $e->getMessage());
    json_error('something went wrong on the server: ' . $e->getMessage(), 500);
  }
}
