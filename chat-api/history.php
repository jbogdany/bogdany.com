<?php
declare(strict_types=1);
require_once __DIR__ . '/common.php';
require_auth_session();

handle_request(function (PDO $pdo) {
  // Reading history requires the same verified login as sending — chat
  // is fully gated behind an account now, not just posting to it.
  $user = current_user($pdo);
  if ($user === null) json_error('log in to chat', 401);
  if (!$user['verified']) json_error('verify your email to unlock chat', 403);

  $room = strtolower(trim((string)($_GET['room'] ?? 'general')));
  if ($room === '') $room = 'general';
  if (!valid_room_name($room)) json_error('invalid room name', 422);
  if (is_room_banned($pdo, $room, $user['id'])) json_error("you're banned from that room", 403);
  if (!room_access_ok($pdo, $room, $user['id'])) json_error("you're not a member of that room", 403);

  $afterId = (int)($_GET['after_id'] ?? 0);
  $limit   = (int)($_GET['limit'] ?? 30);
  if ($limit < 1) $limit = 1;
  if ($limit > 100) $limit = 100;

  if ($afterId > 0) {
    // polling: only what's new since the last message the client has seen
    $stmt = $pdo->prepare(
      'SELECT id, author, body, client_id, room, created_at FROM messages
       WHERE room = ? AND id > ? ORDER BY id ASC LIMIT ?'
    );
    $stmt->bindValue(1, $room, PDO::PARAM_STR);
    $stmt->bindValue(2, $afterId, PDO::PARAM_INT);
    $stmt->bindValue(3, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
  } else {
    // initial load: the most recent `limit` messages in this room,
    // oldest-first so they read top-to-bottom naturally when the panel
    // first renders
    $stmt = $pdo->prepare(
      'SELECT id, author, body, client_id, room, created_at FROM messages
       WHERE room = ? ORDER BY id DESC LIMIT ?'
    );
    $stmt->bindValue(1, $room, PDO::PARAM_STR);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = array_reverse($stmt->fetchAll());
  }

  json_out(['messages' => $rows, 'room' => $room]);
});
