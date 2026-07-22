<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/session.php';

function db(): PDO {
  static $pdo = null;
  if ($pdo === null) {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    // Same reasoning as chat-api/common.php: EMULATE_PREPARES off forces
    // real server-side prepared statements, so every ->execute([...])
    // below sends parameters separately from the SQL text.
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
      PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
  }
  return $pdo;
}

function json_out(array $data, int $code = 200): void {
  header('Content-Type: application/json; charset=utf-8');
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

// Same CSRF floor as chat-api: a plain cross-site <form> can't set a
// custom JSON content type, so requiring it blocks naive form-based
// forgery even before any login system gets involved.
function require_fetch_request(): void {
  $ct = $_SERVER['CONTENT_TYPE'] ?? '';
  if (stripos($ct, 'application/json') === false) {
    json_error('bad request', 400);
  }
}

function handle_request(callable $fn): void {
  try {
    $fn(db());
  } catch (Throwable $e) {
    error_log('[auth api] ' . $e->getMessage());
    json_error('something went wrong on the server', 500);
  }
}

// ---------- validation ----------
// 3-40 chars, letters/numbers/underscore/hyphen. This is the PERMANENT,
// official username — the one chat and everything else keys off of —
// not the free-typed name someone might use for a single guest session.
function valid_username(string $u): bool {
  return (bool)preg_match('/^[A-Za-z0-9_-]{3,40}$/', $u);
}

function valid_password(string $p): bool {
  $len = strlen($p); // bytes are fine here — bcrypt only reads the first 72 anyway
  return $len >= 8 && $len <= 72;
}

function valid_email(string $e): bool {
  return strlen($e) <= 190 && filter_var($e, FILTER_VALIDATE_EMAIL) !== false;
}

// ---------- mail ----------
// PHP's built-in mail() — no PHPMailer/SMTP config needed, and it works
// out of the box on GoDaddy shared hosting as long as the From address
// matches the hosting account's domain.
function site_mail(string $to, string $subject, string $body): bool {
  $host = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'bogdany.com');
  $headers = "From: bogdany.com <no-reply@{$host}>\r\n"
    . "Content-Type: text/plain; charset=UTF-8\r\n";
  return @mail($to, $subject, $body, $headers);
}

function site_base_url(): string {
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'] ?? 'bogdany.com';
  return $scheme . '://' . $host;
}
