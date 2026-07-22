<?php
declare(strict_types=1);
require_once __DIR__ . '/common.php';

handle_request(function (PDO $pdo) {
  require_fetch_request();
  $body = read_json_body();

  $username = trim((string)($body['username'] ?? ''));
  $password = (string)($body['password'] ?? '');
  if ($username === '' || $password === '') json_error('invalid username or password', 401);

  $stmt = $pdo->prepare(
    'SELECT id, username, password_hash, email, email_verified_at FROM users WHERE username = ? AND is_deleted = 0'
  );
  $stmt->execute([$username]);
  $row = $stmt->fetch();

  // same generic error either way — don't let this endpoint reveal
  // whether a username exists
  if (!$row || !password_verify($password, $row['password_hash'])) {
    json_error('invalid username or password', 401);
  }

  issue_session($pdo, (int)$row['id']);

  $roleStmt = $pdo->prepare('SELECT r.name FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? ORDER BY r.name');
  $roleStmt->execute([$row['id']]);

  json_out(['user' => [
    'username' => $row['username'],
    'email'    => $row['email'],
    'verified' => $row['email_verified_at'] !== null,
    'roles'    => $roleStmt->fetchAll(PDO::FETCH_COLUMN),
  ]]);
});
