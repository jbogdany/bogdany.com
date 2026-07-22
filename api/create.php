<?php
declare(strict_types=1);
require_once __DIR__ . '/common.php';

handle_request(function (PDO $pdo) {
  $roomCode = gen_room_code($pdo);
  $redToken = gen_token();
  $startingTurn = random_int(0, 1) === 0 ? 'red' : 'blue'; // the "hidden piece" draw, done server-side

  $pdo->beginTransaction();

  $stmt = $pdo->prepare(
    'INSERT INTO games (room_code, status, red_token, created_at) VALUES (?, "waiting", ?, NOW())'
  );
  $stmt->execute([$roomCode, $redToken]);
  $gameId = (int)$pdo->lastInsertId();

  $stmt = $pdo->prepare(
    'INSERT INTO game_state_history
       (game_id, move_number, board_json, current_turn, last_move_json, valid_from, valid_to, is_current)
     VALUES (?, 0, ?, ?, NULL, NOW(6), NULL, 1)'
  );
  $stmt->execute([$gameId, json_encode(sz_fresh_board()), $startingTurn]);

  $pdo->commit();

  json_out([
    'game_id'   => $gameId,
    'room_code' => $roomCode,
    'token'     => $redToken,
    'color'     => 'red',
  ]);
});
