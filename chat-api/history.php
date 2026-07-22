<?php
declare(strict_types=1);
require_once __DIR__ . '/common.php';

handle_request(function (PDO $pdo) {
  $afterId = (int)($_GET['after_id'] ?? 0);
  $limit   = (int)($_GET['limit'] ?? 30);
  if ($limit < 1) $limit = 1;
  if ($limit > 100) $limit = 100;

  if ($afterId > 0) {
    // polling: only what's new since the last message the client has seen
    $stmt = $pdo->prepare(
      'SELECT id, author, body, client_id, created_at FROM messages WHERE id > ? ORDER BY id ASC LIMIT ?'
    );
    $stmt->bindValue(1, $afterId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
  } else {
    // initial load: the most recent `limit` messages, oldest-first so they
    // read top-to-bottom naturally when the panel first renders
    $stmt = $pdo->prepare('SELECT id, author, body, client_id, created_at FROM messages ORDER BY id DESC LIMIT ?');
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = array_reverse($stmt->fetchAll());
  }

  json_out(['messages' => $rows]);
});
