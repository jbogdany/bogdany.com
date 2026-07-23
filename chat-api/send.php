<?php
declare(strict_types=1);
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../auth-api/session.php';

handle_request(function (PDO $pdo) {
  require_fetch_request();

  // Chat requires a verified account — the author is always the
  // account's official username, never anything the client sends.
  $user = current_user($pdo);
  if ($user === null) json_error('log in to chat', 401);
  if (!$user['verified']) json_error('verify your email to unlock chat', 403);

  $body     = read_json_body();
  $clientId = client_id_or_fail($body);
  $author   = $user['username'];
  $userId   = $user['id'];

  $message = clean_text((string)($body['body'] ?? ''), 500);
  if ($message === '') json_error('message is empty', 400);

  // --- basic flood control, keyed off the account now that one exists ---
  $stmt = $pdo->prepare('SELECT created_at FROM messages WHERE user_id = ? ORDER BY id DESC LIMIT 1');
  $stmt->execute([$userId]);
  $last = $stmt->fetchColumn();
  if ($last !== false) {
    $lastTime = new DateTime((string)$last);
    $now = new DateTime();
    $elapsed = ($now->getTimestamp() - $lastTime->getTimestamp())
      + (((int)$now->format('u') - (int)$lastTime->format('u')) / 1000000);
    if ($elapsed < 1.5) json_error('slow down — sending too fast', 429);
  }

  $stmt = $pdo->prepare(
    'SELECT COUNT(*) FROM messages WHERE user_id = ? AND created_at > (NOW(6) - INTERVAL 1 MINUTE)'
  );
  $stmt->execute([$userId]);
  if ((int)$stmt->fetchColumn() >= 20) {
    json_error('slow down — too many messages, try again in a bit', 429);
  }

  // --- insert (prepared statement — every value is a bound parameter,
  // never concatenated into the SQL string) ---
  $stmt = $pdo->prepare(
    'INSERT INTO messages (author, body, client_id, user_id, created_at) VALUES (?, ?, ?, ?, NOW(6))'
  );
  $stmt->execute([$author, $message, $clientId, $userId]);
  $id = (int)$pdo->lastInsertId();

  $stmt = $pdo->prepare('SELECT id, author, body, client_id, created_at FROM messages WHERE id = ?');
  $stmt->execute([$id]);
  $row = $stmt->fetch();

  json_out(['message' => $row]);
});
