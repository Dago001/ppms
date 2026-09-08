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
    // Get recent activities
    $query = "
        SELECT 
            al.action,
            al.created_at,
            u.username,
            al.description
        FROM activity_logs al
        LEFT JOIN users u ON al.user_id = u.id
        ORDER BY al.created_at DESC
        LIMIT 5
    ";
    
    $activities = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'activities' => array_map(function($activity) {
            return [
                'action' => htmlspecialchars($activity['action']),
                'username' => htmlspecialchars($activity['username'] ?? 'System'),
                'created_at' => $activity['created_at'],
                'description' => htmlspecialchars($activity['description'])
            ];
        }, $activities)
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