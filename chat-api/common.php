<?php
declare(strict_types=1);

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

function handle_request(callable $fn): void {
  try {
    $fn(db());
  } catch (Throwable $e) {
    error_log('[chat api] ' . $e->getMessage());
    json_error('something went wrong on the server', 500);
  }
}
