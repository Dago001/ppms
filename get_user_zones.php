<?php
session_start();
require_once 'includes/config.php';

if (!isset($_GET['user_id'])) {
    echo json_encode([]);
    exit();
}

$user_id = $_GET['user_id'];

try {
    // Check if assigned_command column exists
    $hasAssignedCommand = false;
    $columns = $pdo->query("SHOW COLUMNS FROM user_zones LIKE 'assigned_command'")->fetchAll();
    if (count($columns) > 0) {
        $hasAssignedCommand = true;
    }
    
    if ($hasAssignedCommand) {
        $stmt = $pdo->prepare("
            SELECT uz.zone_id, uz.assigned_command, z.id, z.zone_name, z.zone_code 
            FROM user_zones uz 
            JOIN zones z ON uz.zone_id = z.id 
            WHERE uz.user_id = ?
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT uz.zone_id, NULL as assigned_command, z.id, z.zone_name, z.zone_code 
            FROM user_zones uz 
            JOIN zones z ON uz.zone_id = z.id 
            WHERE uz.user_id = ?
        ");
    }
    
    $stmt->execute([$user_id]);
    $zones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: application/json');
    echo json_encode($zones);
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode([]);
}
?>