<?php
declare(strict_types=1);
require_once __DIR__ . '/common.php';

handle_request(function (PDO $pdo) {
  $body = read_json_body();
  $room = strtoupper(trim((string)($body['room'] ?? '')));
  $name = sz_clean_name((string)($body['name'] ?? ''));
  if ($room === '') json_error('room code is required', 400);

  $pdo->beginTransaction();

  $stmt = $pdo->prepare('SELECT * FROM games WHERE room_code = ? FOR UPDATE');
  $stmt->execute([$room]);
  $game = $stmt->fetch();

  if (!$game) { $pdo->rollBack(); json_error('no game found with that code', 404); }
  if ($game['blue_token'] !== null) { $pdo->rollBack(); json_error('that game already has two players', 409); }

  $blueToken = gen_token();
  $stmt = $pdo->prepare('UPDATE games SET blue_token = ?, blue_name = ?, status = "active" WHERE id = ?');
  $stmt->execute([$blueToken, $name, $game['id']]);

  $pdo->commit();

  json_out([
    'game_id'   => (int)$game['id'],
    'room_code' => $game['room_code'],
    'token'     => $blueToken,
    'color'     => 'blue',
  ]);
});
