<?php
// api/fetch_audit_logs.php
ini_set('display_errors', 0);
session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';

if (!isLoggedIn()) {
    http_response_code(401);
    die(json_encode(['success' => false, 'error' => 'Unauthorized access']));
}

$userRole = $_SESSION['role_name'] ?? '';
$isAuditAllowed = in_array(strtolower($userRole), ['admin', 'super admin', 'shq admin', 'service hq', 'command admin', 'user']);

if (!$isAuditAllowed) {
    http_response_code(403);
    die(json_encode(['success' => false, 'error' => 'Forbidden']));
}

$searchQuery = trim($_GET['q'] ?? '');
$filterSQL = " WHERE 1=1";
$filterParams = [];

if (!empty($searchQuery)) {
    $filterSQL .= " AND (u.username LIKE ? OR u.full_name LIKE ? OR al.action LIKE ? OR al.description LIKE ? OR al.ip_address LIKE ?)";
    $searchWildcard = "%$searchQuery%";
    $filterParams = [$searchWildcard, $searchWildcard, $searchWildcard, $searchWildcard, $searchWildcard];
}

// --------------------------------------------
// EXPORT COMPREHENSIVE AUDIT TRAIL TO CSV
// --------------------------------------------
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=Comprehensive_System_Audit_Log_' . date('Y-m-d_H-i-s') . '.csv');
    
    $output = fopen('php://output', 'w');
    // UTF-8 BOM for Excel compatibility
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    fputcsv($output, ['Log ID', 'Timestamp', 'User / Performed By', 'Username / ServiceNo', 'Action Type', 'Activity Description', 'IP Address']);
    
    try {
        $exportStmt = $pdo->prepare("
            SELECT al.id, al.created_at, u.username, u.full_name as target_name, al.action, al.description, al.ip_address 
            FROM activity_logs al
            LEFT JOIN users u ON al.user_id = u.id
            $filterSQL
            ORDER BY al.created_at DESC
        ");
        $exportStmt->execute($filterParams);
        
        while ($row = $exportStmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, [
                $row['id'],
                date('Y-m-d H:i:s', strtotime($row['created_at'])),
                $row['target_name'] ?? ($row['username'] ?? 'System'),
                $row['username'] ?? 'N/A',
                ucwords(str_replace('_', ' ', $row['action'] ?? '')),
                $row['description'] ?? '',
                $row['ip_address'] ?? 'N/A'
            ]);
        }
    } catch (Exception $e) {}
    
    fclose($output);
    exit();
}

// --------------------------------------------
// JSON API DISPATCH FOR REAL-TIME POLLING (50 RECORDS / PAGE)
// --------------------------------------------
header('Content-Type: application/json; charset=utf-8');

try {
    // Total count for pagination
    $countStmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM activity_logs al
        LEFT JOIN users u ON al.user_id = u.id
        $filterSQL
    ");
    $countStmt->execute($filterParams);
    $totalRecords = (int)$countStmt->fetchColumn();

    $perPage = 50;
    $totalPages = max(1, (int)ceil($totalRecords / $perPage));
    $requestedPage = max(1, intval($_GET['page'] ?? 1));
    $page = min($requestedPage, $totalPages);
    $offset = ($page - 1) * $perPage;

    $stmt = $pdo->prepare("
        SELECT al.id, al.created_at, al.action, al.description, al.ip_address, u.username, u.full_name as target_name 
        FROM activity_logs al
        LEFT JOIN users u ON al.user_id = u.id
        $filterSQL
        ORDER BY al.created_at DESC
        LIMIT $perPage OFFSET $offset
    ");
    $stmt->execute($filterParams);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $formattedLogs = array_map(function($log) {
        return [
            'id' => $log['id'],
            'created_at' => $log['created_at'],
            'formatted_date' => date('d M Y, H:i', strtotime($log['created_at'])),
            'action' => $log['action'],
            'description' => $log['description'],
            'ip_address' => $log['ip_address'],
            'username' => $log['username'],
            'target_name' => $log['target_name']
        ];
    }, $logs);

    echo json_encode([
        'success' => true,
        'total_records' => $totalRecords,
        'per_page' => $perPage,
        'current_page' => $page,
        'total_pages' => $totalPages,
        'count' => count($formattedLogs),
        'logs' => $formattedLogs
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
