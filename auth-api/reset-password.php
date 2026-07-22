<?php
declare(strict_types=1);
require_once __DIR__ . '/common.php';

handle_request(function (PDO $pdo) {
  require_fetch_request();
  if (current_user($pdo) !== null) json_error('log out first', 400);

  $body = read_json_body();
  $token    = trim((string)($body['token'] ?? ''));
  $password = (string)($body['password'] ?? '');

  if (!preg_match('/^[0-9a-f-]{36}$/', $token)) json_error('invalid or expired reset link', 400);
  if (!valid_password($password)) json_error('password must be 8-72 characters', 422);

  $stmt = $pdo->prepare(
    'SELECT id, user_id FROM password_resets WHERE token = ? AND used_at IS NULL AND expires_at > NOW(6)'
  );
  $stmt->execute([$token]);
  $row = $stmt->fetch();
  if (!$row) json_error('invalid or expired reset link', 400);

  $pdo->beginTransaction();
  $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
    ->execute([password_hash($password, PASSWORD_DEFAULT), $row['user_id']]);
  $pdo->prepare('UPDATE password_resets SET used_at = NOW(6) WHERE id = ?')->execute([$row['id']]);
  // a stolen/leaked session cookie shouldn't survive a password reset
  $pdo->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$row['user_id']]);
  $pdo->commit();

  json_out(['ok' => true]);
});
