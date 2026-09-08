<?php
require_once 'includes/auth.php';
require_once 'includes/config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

try {
    // Get total personnel count
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM personnel");
    $totalPersonnel = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    // Get total users count
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM users");
    $totalUsers = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    echo json_encode([
        'success' => true,
        'data' => [
            'total_personnel' => (int)$totalPersonnel,
            'total_users' => (int)$totalUsers
        ]
    ]);

} catch (PDOException $e) {
    error_log("Database Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error occurred'
    ]);
} catch (Exception $e) {
    error_log("General Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'An unexpected error occurred'
    ]);
}