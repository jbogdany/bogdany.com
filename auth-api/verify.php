<?php
declare(strict_types=1);
require_once __DIR__ . '/common.php';

// This one is opened directly by clicking the emailed link, not called
// via fetch() — so unlike the rest of auth-api it renders a small HTML
// page instead of JSON.

$token = (string)($_GET['token'] ?? '');
$ok = false;
$message = 'That verification link is invalid or has expired.';

if (preg_match('/^[0-9a-f-]{36}$/', $token)) {
  $pdo = db();
  try {
    $stmt = $pdo->prepare(
      'SELECT id, user_id FROM email_verifications WHERE token = ? AND used_at IS NULL AND expires_at > NOW(6)'
    );
    $stmt->execute([$token]);
    $row = $stmt->fetch();

    if ($row) {
      $pdo->beginTransaction();
      $pdo->prepare('UPDATE users SET email_verified_at = NOW(6) WHERE id = ?')->execute([$row['user_id']]);
      $pdo->prepare('UPDATE email_verifications SET used_at = NOW(6) WHERE id = ?')->execute([$row['id']]);
      $pdo->commit();
      $ok = true;
      $message = "Your account is verified. Chat is unlocked \u{2014} head back to the terminal.";
    }
  } catch (Throwable $e) {
    error_log('[auth api] verify: ' . $e->getMessage());
  }
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>bogdany.com — verification</title>
<style>
  * { box-sizing: border-box; }
  body {
    background: #0d0b07;
    color: <?= $ok ? '#ffb000' : '#ff6a4d' ?>;
    font-family: "IBM Plex Mono", ui-monospace, "SF Mono", Menlo, Consolas, monospace;
    display: flex; align-items: center; justify-content: center;
    min-height: 100vh; margin: 0; padding: 2rem;
  }
  .box { max-width: 32rem; text-align: center; line-height: 1.6; }
  .status { font-size: 1.1rem; margin-bottom: 1.5rem; }
  a { color: #ffb000; }
</style>
</head>
<body>
  <div class="box">
    <p class="status">[<?= $ok ? 'ok' : 'error' ?>] <?= htmlspecialchars($message, ENT_QUOTES) ?></p>
    <p><a href="/index.htm">&larr; back to bogdany.com</a></p>
  </div>
</body>
</html>
