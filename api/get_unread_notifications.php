<?php
// api/get_unread_notifications.php - Real-Time Unread Notification API Engine
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notification_helpers.php';

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

// Get user zone scope
$userId = $_SESSION['user_id'] ?? 0;
$roleName = $_SESSION['role_name'] ?? 'User';
$userZoneNames = [];

if (!in_array(strtolower($roleName), ['admin', 'service hq', 'shq admin', 'super admin'])) {
    try {
        $stmt = $pdo->prepare("SELECT z.zone_name FROM user_zones uz JOIN zones z ON uz.zone_id = z.id WHERE uz.user_id = ?");
        $stmt->execute([$userId]);
        $userZoneNames = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        $userZoneNames = [];
    }
}

$unreadCount = (int)getUnreadNotificationCount($userZoneNames);
$notificationsList = getNotifications($userZoneNames, 6);

$formattedNotifications = [];
foreach ($notificationsList as $n) {
    $formattedNotifications[] = [
        'id' => (int)$n['id'],
        'serviceNo' => $n['serviceNo'],
        'officer_name' => $n['officer_name'],
        'officer_rank' => $n['officer_rank'],
        'posting_location' => $n['posting_location'],
        'posted_by' => $n['posted_by'],
        'status' => $n['status'],
        'is_read' => (int)$n['is_read'],
        'time_ago' => date('d M Y, H:i', strtotime($n['created_at']))
    ];
}

echo json_encode([
    'success' => true,
    'unread_count' => $unreadCount,
    'notifications' => $formattedNotifications
]);
