<?php
declare(strict_types=1);
require_once __DIR__ . '/common.php';

handle_request(function (PDO $pdo) {
  $body   = read_json_body();
  $gameId = (int)($body['game_id'] ?? 0);
  $token  = (string)($body['token'] ?? '');
  $from   = $body['from'] ?? null;
  $to     = $body['to'] ?? null;

  if ($gameId <= 0 || $token === '' || !is_array($from) || !is_array($to)) {
    json_error('game_id, token, from and to are required', 400);
  }
  $fr = (int)($from['r'] ?? -1); $fc = (int)($from['c'] ?? -1);
  $tr = (int)($to['r'] ?? -1);   $tc = (int)($to['c'] ?? -1);
  if (!sz_in_bounds($fr, $fc) || !sz_in_bounds($tr, $tc)) json_error('move is off the board', 400);

  $pdo->beginTransaction();

  // lock the game row so two near-simultaneous submissions can't both apply
  $stmt = $pdo->prepare('SELECT * FROM games WHERE id = ? FOR UPDATE');
  $stmt->execute([$gameId]);
  $game = $stmt->fetch();
  if (!$game) { $pdo->rollBack(); json_error('game not found', 404); }

  $color = null;
  if (hash_equals($game['red_token'], $token)) $color = 'red';
  elseif ($game['blue_token'] !== null && hash_equals($game['blue_token'], $token)) $color = 'blue';
  if ($color === null) { $pdo->rollBack(); json_error('invalid game token', 403); }

  if ($game['status'] !== 'active') {
    $pdo->rollBack();
    json_error($game['status'] === 'waiting' ? 'waiting for an opponent to join' : 'this game is already over', 409);
  }

  $stmt = $pdo->prepare('SELECT * FROM game_state_history WHERE game_id = ? AND is_current = 1 LIMIT 1 FOR UPDATE');
  $stmt->execute([$gameId]);
  $stateRow = $stmt->fetch();
  if (!$stateRow) { $pdo->rollBack(); json_error('game state missing', 500); }

  if ($stateRow['current_turn'] !== $color) {
    $pdo->rollBack();
    json_error('not your turn', 409);
  }

  $board = json_decode($stateRow['board_json'], true);
  $piece = $board[$fr][$fc] ?? null;
  if (!$piece || $piece['color'] !== $color) {
    $pdo->rollBack();
    json_error('that is not your piece', 400);
  }

  $legal = sz_compute_moves($board, $fr, $fc);
  $chosen = null;
  foreach ($legal as $m) {
    if ($m['r'] === $tr && $m['c'] === $tc) { $chosen = $m; break; }
  }
  if ($chosen === null) {
    $pdo->rollBack();
    json_error('illegal move', 400);
  }

  [$newBoard, $winner] = sz_apply_move($board, $fr, $fc, $chosen);
  $nextTurn = $winner ? $stateRow['current_turn'] : sz_other_color($color);
  $lastMove = [
    'from' => ['r' => $fr, 'c' => $fc],
    'to'   => ['r' => $tr, 'c' => $tc],
    'captures' => $chosen['captures'],
    'color' => $color,
  ];

  // --- SCD Type 2: close out the current version, insert the next dated version ---
  $stmt = $pdo->prepare('UPDATE game_state_history SET valid_to = NOW(6), is_current = 0 WHERE id = ?');
  $stmt->execute([$stateRow['id']]);

  $stmt = $pdo->prepare(
    'INSERT INTO game_state_history
       (game_id, move_number, board_json, current_turn, last_move_json, valid_from, valid_to, is_current)
     VALUES (?, ?, ?, ?, ?, NOW(6), NULL, 1)'
  );
  $stmt->execute([
    $gameId,
    (int)$stateRow['move_number'] + 1,
    json_encode($newBoard),
    $nextTurn,
    json_encode($lastMove),
  ]);

  if ($winner) {
    $stmt = $pdo->prepare('UPDATE games SET status = "finished", winner = ? WHERE id = ?');
    $stmt->execute([$winner, $gameId]);
  }

  $pdo->commit();

  json_out([
    'game_id'      => $gameId,
    'room_code'    => $game['room_code'],
    'status'       => $winner ? 'finished' : 'active',
    'winner'       => $winner,
    'your_color'   => $color,
    'current_turn' => $nextTurn,
    'move_number'  => (int)$stateRow['move_number'] + 1,
    'board'        => $newBoard,
    'last_move'    => $lastMove,
  ]);
});
