<?php
declare(strict_types=1);

// This mirrors the movement logic in suzen.html exactly, so the server
// and the browser always agree on what's legal. Board is an 8-row x
// 6-col array; each cell is either null or ['color'=>'red'|'blue','type'=>1|2].

const SZ_ROWS = 8;
const SZ_COLS = 6;

function sz_fresh_board(): array {
  $b = [];
  for ($r = 0; $r < SZ_ROWS; $r++) $b[] = array_fill(0, SZ_COLS, null);
  for ($c = 0; $c < SZ_COLS; $c++) {
    $b[0][$c] = ['color' => 'blue', 'type' => 2];
    $b[1][$c] = ['color' => 'blue', 'type' => 1];
    $b[6][$c] = ['color' => 'red',  'type' => 1];
    $b[7][$c] = ['color' => 'red',  'type' => 2];
  }
  return $b;
}

function sz_type_dist(int $type): array {
  return $type === 1
    ? ['forward' => 1, 'right' => 2, 'backward' => 3, 'left' => 4]
    : ['forward' => 2, 'right' => 3, 'backward' => 4, 'left' => 1];
}

function sz_facing_vec(string $color, string $dir): array {
  $F = $color === 'red' ? ['dr' => -1, 'dc' => 0] : ['dr' => 1, 'dc' => 0];
  $R = $color === 'red' ? ['dr' => 0, 'dc' => 1]  : ['dr' => 0, 'dc' => -1];
  $B = ['dr' => -$F['dr'], 'dc' => -$F['dc']];
  $L = ['dr' => -$R['dr'], 'dc' => -$R['dc']];
  $map = ['forward' => $F, 'right' => $R, 'backward' => $B, 'left' => $L];
  return $map[$dir];
}

function sz_home_row(string $color): int { return $color === 'red' ? 7 : 0; }
function sz_opponent_home_row(string $color): int { return $color === 'red' ? 0 : 7; }
function sz_other_color(string $color): string { return $color === 'red' ? 'blue' : 'red'; }
function sz_in_bounds(int $r, int $c): bool { return $r >= 0 && $r < SZ_ROWS && $c >= 0 && $c < SZ_COLS; }

// Each piece moves the EXACT number of spaces shown for a direction —
// never a shorter distance — so there's at most one legal target per
// direction (up to four candidate moves per piece).
function sz_compute_moves(array $board, int $r, int $c): array {
  $piece = $board[$r][$c] ?? null;
  if (!$piece) return [];
  $dists = sz_type_dist((int)$piece['type']);
  $moves = [];
  foreach (['forward', 'right', 'backward', 'left'] as $dir) {
    $vec = sz_facing_vec($piece['color'], $dir);
    $d = $dists[$dir];
    $tr = $r + $vec['dr'] * $d;
    $tc = $c + $vec['dc'] * $d;
    if (!sz_in_bounds($tr, $tc)) continue; // moving off the board isn't legal
    $occ = $board[$tr][$tc] ?? null;
    if ($occ && $occ['color'] === $piece['color']) continue; // can't land on own piece

    $captures = [];
    for ($k = 1; $k <= $d; $k++) {
      $kr = $r + $vec['dr'] * $k;
      $kc = $c + $vec['dc'] * $k;
      $kOcc = $board[$kr][$kc] ?? null;
      if ($kOcc && $kOcc['color'] !== $piece['color']) $captures[] = [$kr, $kc];
    }
    $moves[] = ['r' => $tr, 'c' => $tc, 'dist' => $d, 'dir' => $dir, 'captures' => $captures];
  }
  return $moves;
}

function sz_count_pieces(array $board, string $color): int {
  $n = 0;
  foreach ($board as $row) {
    foreach ($row as $cell) {
      if ($cell && $cell['color'] === $color) $n++;
    }
  }
  return $n;
}

// Applies a move (already validated) to the board and returns
// [newBoard, winnerOrNull].
function sz_apply_move(array $board, int $fr, int $fc, array $move): array {
  $piece = $board[$fr][$fc];
  foreach ($move['captures'] as $cap) {
    $board[$cap[0]][$cap[1]] = null;
  }
  $board[$fr][$fc] = null;
  $board[$move['r']][$move['c']] = $piece;

  $winner = null;
  if ($move['r'] === sz_opponent_home_row($piece['color'])) {
    $winner = $piece['color'];
  } elseif (sz_count_pieces($board, sz_other_color($piece['color'])) === 0) {
    $winner = $piece['color'];
  }

  return [$board, $winner];
}
