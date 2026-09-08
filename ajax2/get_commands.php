<?php
// Predates the current schema (targets a `commands` table and
// includes/functions.php, neither of which exist anymore) and isn't called
// from anywhere in the current UI - posting.php, its only caller, now
// redirects to dashboard.php. Fail gracefully instead of fataling for anyone
// who still has this endpoint bookmarked/cached.
session_start();
require_once '../includes/config.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([]);
    exit();
}

header('Content-Type: application/json');
echo json_encode([]);
exit();

$zoneId = intval($_POST['zone_id'] ?? 0);

if ($zoneId <= 0) {
    echo json_encode([]);
    exit();
}

$userRole = getUserRole($_SESSION['user_id']);
$userFormations = getUserFormations($_SESSION['user_id']);

// SHQ and Admin can see all commands in a zone
if (in_array($userRole['name'], ['admin', 'Service HQ'])) {
    $commands = getCommandsByZone($zoneId);
} else {
    // Filter to only user's assigned commands
    $commands = array_values(array_filter($userFormations, function($cmd) use ($zoneId) {
        return $cmd['zone_id'] == $zoneId;
    }));
}

header('Content-Type: application/json');
echo json_encode($commands);
?>