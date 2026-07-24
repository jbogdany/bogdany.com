<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/rules.php';

header('Content-Type: application/json; charset=utf-8');

function db(): PDO {
  static $pdo = null;
  if ($pdo === null) {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
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

function gen_token(): string {
  return bin2hex(random_bytes(16)); // 32 hex chars
}

// Trims and caps a client-supplied display name for the lobby (the real
// account username if logged in, otherwise the guest name assigned by
// the terminal — resolved client-side, see suzen.html). This is display
// text only, not an auth credential — a seat's token is what actually
// proves who's allowed to move (see sz_authenticate()), so there's
// nothing to gain by sending a fake name beyond a misleading label in
// the lobby list, the same tier of trust every other game on this site
// already gives an anonymous guest.
function sz_clean_name(string $name): string {
  $name = trim($name);
  $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
  $name = mb_substr($name, 0, 40, 'UTF-8');
  return $name !== '' ? $name : 'guest';
}

// Confirms the token belongs to this game and returns which color
// ('red' | 'blue') it authenticates as, plus the game row itself.
function sz_authenticate(PDO $pdo, int $gameId, string $token): array {
  $stmt = $pdo->prepare('SELECT * FROM games WHERE id = ?');
  $stmt->execute([$gameId]);
  $game = $stmt->fetch();
  if (!$game) json_error('game not found', 404);

  if (hash_equals($game['red_token'], $token)) {
    return ['game' => $game, 'color' => 'red'];
  }
  if ($game['blue_token'] !== null && hash_equals($game['blue_token'], $token)) {
    return ['game' => $game, 'color' => 'blue'];
  }
  json_error('invalid game token', 403);
}

// Fetches the current (is_current = 1) state row for a game.
function sz_current_state_row(PDO $pdo, int $gameId): array {
  $stmt = $pdo->prepare('SELECT * FROM game_state_history WHERE game_id = ? AND is_current = 1 LIMIT 1');
  $stmt->execute([$gameId]);
  $row = $stmt->fetch();
  if (!$row) json_error('game state missing', 500);
  return $row;
}

function gen_room_code(PDO $pdo): string {
  $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O/1/I ambiguity
  for ($attempt = 0; $attempt < 20; $attempt++) {
    $code = '';
    for ($i = 0; $i < 6; $i++) {
      $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    $stmt = $pdo->prepare('SELECT id FROM games WHERE room_code = ?');
    $stmt->execute([$code]);
    if (!$stmt->fetch()) return $code;
  }
  throw new RuntimeException('could not generate a unique room code');
}

// Run the whole request inside a handler so any unexpected error becomes
// a clean JSON 500 instead of leaking a PHP error page / stack trace.
function handle_request(callable $fn): void {
  try {
    $fn(db());
  } catch (Throwable $e) {
    // Log detail server-side if you have error logging enabled; keep the
    // client-facing message generic.
    error_log('[suzen api] ' . $e->getMessage());
    json_error('something went wrong on the server', 500);
  }
}
