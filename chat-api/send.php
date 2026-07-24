<?php
declare(strict_types=1);
require_once __DIR__ . '/common.php';
require_auth_session();

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

  $room = strtolower(trim((string)($body['room'] ?? 'general')));
  if ($room === '') $room = 'general';
  if (!valid_room_name($room)) json_error('invalid room name', 422);
  if (is_room_banned($pdo, $room, $userId)) json_error("you're banned from that room", 403);
  if (!room_access_ok($pdo, $room, $userId)) json_error("you're not a member of that room", 403);

  $message = clean_text((string)($body['body'] ?? ''), 500);
  if ($message === '') json_error('message is empty', 400);

  // --- basic flood control, keyed off the account (not the room, so
  // switching rooms can't be used to dodge it) — done entirely in SQL to
  // avoid any PHP/MySQL clock or timezone mismatch. ---
  $stmt = $pdo->prepare(
    'SELECT COUNT(*) FROM messages WHERE user_id = ? AND created_at > (NOW(6) - INTERVAL 1500000 MICROSECOND)'
  );
  $stmt->execute([$userId]);
  if ((int)$stmt->fetchColumn() > 0) {
    json_error('slow down — sending too fast', 429);
  }

  $stmt = $pdo->prepare(
    'SELECT COUNT(*) FROM messages WHERE user_id = ? AND created_at > (NOW(6) - INTERVAL 1 MINUTE)'
  );
  $stmt->execute([$userId]);
  if ((int)$stmt->fetchColumn() >= 20) {
    json_error('slow down — too many messages, try again in a bit', 429);
  }

  // rooms are auto-provisioned on first use — posting to a brand-new
  // room name is how a room comes into existence
  $pdo->prepare('INSERT IGNORE INTO rooms (name) VALUES (?)')->execute([$room]);

  // --- insert (prepared statement — every value is a bound parameter,
  // never concatenated into the SQL string) ---
  $stmt = $pdo->prepare(
    'INSERT INTO messages (author, body, client_id, user_id, room, created_at) VALUES (?, ?, ?, ?, ?, NOW(6))'
  );
  $stmt->execute([$author, $message, $clientId, $userId, $room]);
  $id = (int)$pdo->lastInsertId();

  $stmt = $pdo->prepare('SELECT id, author, body, client_id, room, created_at FROM messages WHERE id = ?');
  $stmt->execute([$id]);
  $row = $stmt->fetch();

  json_out(['message' => $row]);
});
