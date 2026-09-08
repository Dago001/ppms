<?php
session_start();
require_once '../includes/config.php';

header('Content-Type: application/json');

$count = 0;
try {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM posting_notifications WHERE is_read = 0");
    $count = $stmt->fetch()['count'] ?? 0;
} catch (PDOException $e) {
    $count = 0;
}

echo json_encode(['count' => $count]);
?>