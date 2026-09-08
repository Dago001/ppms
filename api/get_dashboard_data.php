<?php
require_once '../includes/auth.php';
require_once '../includes/config.php';
require_once '../includes/dashboard_analytics.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $analytics = getDashboardAnalytics($pdo);
    echo json_encode([
        'success' => true,
        'data' => $analytics
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error'
    ]);
}