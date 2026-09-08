<?php
session_start();
require_once 'includes/config.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([]);
    exit();
}

$user_id = intval($_GET['user_id'] ?? 0);

if ($user_id <= 0) {
    echo json_encode([]);
    exit();
}

try {
    $stmt = $pdo->prepare("SELECT c.id, c.name, c.zone_id, z.zone_name FROM user_commands uc JOIN commands c ON uc.command_id = c.id LEFT JOIN zones z ON c.zone_id = z.id WHERE uc.user_id = ? ORDER BY c.name");
    $stmt->execute([$user_id]);
    $commands = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: application/json');
    echo json_encode($commands);
} catch (PDOException $e) {
    echo json_encode([]);
}
?>