<?php
declare(strict_types=1);
require_once __DIR__ . '/common.php';

handle_request(function (PDO $pdo) {
  require_fetch_request();
  $body = read_json_body();

  $username = trim((string)($body['username'] ?? ''));
  $password = (string)($body['password'] ?? '');
  $email    = trim((string)($body['email'] ?? ''));

  if (!valid_username($username)) {
    json_error('username must be 3-40 characters: letters, numbers, "_" or "-"', 422);
  }
  if (!valid_password($password)) {
    json_error('password must be 8-72 characters', 422);
  }
  if (!valid_email($email)) {
    json_error('please enter a valid email address', 422);
  }

  $hash = password_hash($password, PASSWORD_DEFAULT);

  try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, email) VALUES (?, ?, ?)');
    $stmt->execute([$username, $hash, $email]);
    $userId = (int)$pdo->lastInsertId();

    // every new account starts as a plain "customer" — customer_service
    // and administrator are granted by hand later, not through this form
    $stmt = $pdo->prepare('INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE name = ?');
    $stmt->execute([$userId, 'customer']);

    $token = make_guid();
    $stmt = $pdo->prepare(
      'INSERT INTO email_verifications (user_id, token, expires_at) VALUES (?, ?, NOW(6) + INTERVAL 1 DAY)'
    );
    $stmt->execute([$userId, $token]);

    $pdo->commit();
  } catch (PDOException $e) {
    $pdo->rollBack();
    if ((int)($e->errorInfo[1] ?? 0) === 1062) { // duplicate key
      json_error('that username or email is already taken', 409);
    }
    throw $e;
  }

  $link = site_base_url() . '/auth-api/verify.php?token=' . urlencode($token);
  site_mail(
    $email,
    'Verify your bogdany.com account',
    "Hi {$username},\n\n"
      . "Click the link below to verify your account and unlock chat:\n\n{$link}\n\n"
      . "This link expires in 24 hours. If you didn't create this account, you can ignore this email.\n"
  );

  // logged in immediately — verification unlocks chat, not the account itself
  issue_session($pdo, $userId);

  json_out(['user' => [
    'username' => $username,
    'email'    => $email,
    'verified' => false,
    'roles'    => ['customer'],
  ]], 201);
});
