<?php
session_start();
require_once '../includes/config.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([]);
    exit();
}

$search = trim($_POST['search'] ?? '');

if (strlen($search) < 2) {
    echo json_encode([]);
    exit();
}

try {
    $stmt = $pdo->prepare("
        SELECT service_number, surname, firstname, middlename, rank, gender
        FROM personnel 
        WHERE service_number LIKE ? 
           OR surname LIKE ? 
           OR firstname LIKE ?
        ORDER BY surname ASC
        LIMIT 10
    ");
    
    $searchParam = "%$search%";
    $stmt->execute([$searchParam, $searchParam, $searchParam]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: application/json');
    echo json_encode($results);
    
} catch (Exception $e) {
    echo json_encode([]);
}
?>