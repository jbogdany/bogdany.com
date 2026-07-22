<?php
declare(strict_types=1);
require_once __DIR__ . '/common.php';

handle_request(function (PDO $pdo) {
  require_fetch_request();
  if (current_user($pdo) !== null) json_error('log out first', 400);

  $body = read_json_body();
  $email = trim((string)($body['email'] ?? ''));
  if (!valid_email($email)) json_error('please enter a valid email address', 422);

  $stmt = $pdo->prepare('SELECT id, username FROM users WHERE email = ? AND is_deleted = 0');
  $stmt->execute([$email]);
  $row = $stmt->fetch();

  // Always respond the same way regardless of whether the email matched —
  // a different message for "no such account" would let this endpoint be
  // used to probe which emails have accounts here.
  if ($row) {
    $token = make_guid();
    $pdo->prepare(
      'INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, NOW(6) + INTERVAL 1 HOUR)'
    )->execute([$row['id'], $token]);

    // reuses the terminal's existing ?run= boot param (see index.htm boot())
    // so clicking the emailed link drops the token straight into the
    // reset-password command instead of landing on a separate web form
    $link = site_base_url() . '/index.htm?run=' . urlencode('reset-password ' . $token);
    site_mail(
      $email,
      'Reset your bogdany.com password',
      "Hi {$row['username']},\n\n"
        . "Click the link below to set a new password:\n\n{$link}\n\n"
        . "Or, at the bogdany.com prompt, run:\n\n  reset-password {$token}\n\n"
        . "This link expires in 1 hour. If you didn't request this, you can ignore this email.\n"
    );
  }

  json_out(['ok' => true, 'message' => 'if that email has an account here, a reset link is on its way']);
});
