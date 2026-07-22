<?php
declare(strict_types=1);
require_once __DIR__ . '/common.php';

handle_request(function (PDO $pdo) {
  $gameId = (int)($_GET['game_id'] ?? 0);
  $token  = (string)($_GET['token'] ?? '');
  if ($gameId <= 0 || $token === '') json_error('game_id and token are required', 400);

  $auth = sz_authenticate($pdo, $gameId, $token);
  $game = $auth['game'];
  $stateRow = sz_current_state_row($pdo, $gameId);

  json_out([
    'game_id'      => $gameId,
    'room_code'    => $game['room_code'],
    'status'       => $game['status'],
    'winner'       => $game['winner'],
    'your_color'   => $auth['color'],
    'current_turn' => $stateRow['current_turn'],
    'move_number'  => (int)$stateRow['move_number'],
    'board'        => json_decode($stateRow['board_json'], true),
    'last_move'    => $stateRow['last_move_json'] !== null ? json_decode($stateRow['last_move_json'], true) : null,
  ]);
});
