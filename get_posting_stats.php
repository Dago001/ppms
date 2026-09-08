<?php
require_once 'includes/auth.php';
require_once 'includes/config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

try {
    $query = "
        SELECT 
            f.location_type,
            COUNT(DISTINCT p.id) as total_postings,
            SUM(CASE WHEN p.posting_status = 'Active' THEN 1 ELSE 0 END) as active_postings,
            SUM(CASE WHEN p.posting_status = 'Completed' THEN 1 ELSE 0 END) as completed_postings,
            SUM(CASE WHEN p.posting_status = 'Pending' THEN 1 ELSE 0 END) as pending_postings
        FROM formations f
        LEFT JOIN postings p ON f.id = p.formation_id
        GROUP BY f.location_type
    ";

    $result = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);
    
    $labels = [];
    $activeData = [];
    $completedData = [];
    $pendingData = [];

    foreach ($result as $row) {
        $labels[] = $row['location_type'];
        $activeData[] = (int)$row['active_postings'];
        $completedData[] = (int)$row['completed_postings'];
        $pendingData[] = (int)$row['pending_postings'];
    }

    echo json_encode([
        'success' => true,
        'labels' => $labels,
        'activeData' => $activeData,
        'completedData' => $completedData,
        'pendingData' => $pendingData
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error occurred'
    ]);
}