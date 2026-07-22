<?php
declare(strict_types=1);
require_once __DIR__ . '/common.php';

handle_request(function (PDO $pdo) {
  require_fetch_request();
  $u = require_login($pdo);

  // Soft delete only — the row (and every message tied to it via
  // messages.user_id) stays exactly where it is. is_deleted=1 just
  // blocks future logins and drops the account out of current_user().
  $pdo->prepare('UPDATE users SET is_deleted = 1 WHERE id = ?')->execute([$u['id']]);
  $pdo->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$u['id']]);
  clear_session_cookie();

  json_out(['ok' => true]);
});
