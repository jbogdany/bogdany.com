<?php
declare(strict_types=1);
require_once __DIR__ . '/common.php';

handle_request(function (PDO $pdo) {
  $token = current_session_token();
  if ($token !== null) {
    $pdo->prepare('DELETE FROM sessions WHERE token = ?')->execute([$token]);
  }
  clear_session_cookie();
  json_out(['ok' => true]);
});
