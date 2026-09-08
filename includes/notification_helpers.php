<?php
// includes/notification_helpers.php
// Posting-notification helper library (posting_notifications table).
//
// These functions used to be expected out of includes/notifications.php,
// but that file is actually the full standalone /notifications page (own
// session_start, HTML output, admit/report POST handlers) - require_once-ing
// it from editp.php / ajax2/post_officer.php / api/get_unread_notifications.php
// executed that entire page body and fataled on a call to a function none of
// them ever defined. This file holds just the data-layer functions those
// call sites actually need.

if (!function_exists('detectPostingZone')) {
    function detectPostingZone($postingLocation) {
        if (empty($postingLocation)) return '';
        if (function_exists('getFormationZone')) {
            $zone = getFormationZone($postingLocation);
            return ($zone && $zone !== 'Unknown') ? $zone : '';
        }
        return '';
    }
}

if (!function_exists('createPostingNotification')) {
    function createPostingNotification($serviceNo, $officerName, $officerRank, $postingLocation, $postingZone, $postedBy, $postingDate) {
        global $pdo;
        if (!isset($pdo)) return false;

        try {
            $message = "Officer " . $officerName . " (NIS No: " . $serviceNo . ") posted to " . $postingLocation . ".";
            $stmt = $pdo->prepare("
                INSERT INTO posting_notifications
                    (serviceNo, officer_name, officer_rank, posting_location, posting_zone, posting_date, posted_by, created_by, message, status, is_read)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 0)
            ");
            return $stmt->execute([
                $serviceNo,
                $officerName,
                $officerRank,
                $postingLocation,
                $postingZone,
                $postingDate,
                $postedBy,
                $_SESSION['user_id'] ?? null,
                $message
            ]);
        } catch (PDOException $e) {
            error_log("createPostingNotification error: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('buildNotificationZoneFilterClause')) {
    // Internal: build an "AND (...)" SQL fragment + bound params restricting
    // posting_notifications rows to the given zone/command names. An empty
    // $zoneNames means unrestricted (Admin/SHQ - matches every other zone
    // scoping pattern used across this codebase).
    function buildNotificationZoneFilterClause($zoneNames) {
        if (empty($zoneNames)) {
            return ['sql' => '', 'params' => []];
        }
        $conditions = [];
        $params = [];
        foreach ($zoneNames as $zn) {
            $conditions[] = "(posting_location LIKE ? OR posting_zone LIKE ?)";
            $params[] = "%" . $zn . "%";
            $params[] = "%" . $zn . "%";
        }
        return ['sql' => " AND (" . implode(" OR ", $conditions) . ")", 'params' => $params];
    }
}

if (!function_exists('getNotifications')) {
    function getNotifications($zoneNames = [], $limit = 100) {
        global $pdo;
        if (!isset($pdo)) return [];

        try {
            $filter = buildNotificationZoneFilterClause($zoneNames);
            $limit = max(1, (int)$limit);
            $stmt = $pdo->prepare("SELECT * FROM posting_notifications WHERE 1=1" . $filter['sql'] . " ORDER BY created_at DESC LIMIT " . $limit);
            $stmt->execute($filter['params']);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("getNotifications error: " . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('getUnreadNotificationCount')) {
    function getUnreadNotificationCount($zoneNames = []) {
        global $pdo;
        if (!isset($pdo)) return 0;

        try {
            $filter = buildNotificationZoneFilterClause($zoneNames);
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM posting_notifications WHERE is_read = 0" . $filter['sql']);
            $stmt->execute($filter['params']);
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("getUnreadNotificationCount error: " . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('markNotificationAsRead')) {
    function markNotificationAsRead($notificationId) {
        global $pdo;
        if (!isset($pdo) || !is_numeric($notificationId)) return false;

        try {
            $stmt = $pdo->prepare("UPDATE posting_notifications SET is_read = 1 WHERE id = ?");
            return $stmt->execute([(int)$notificationId]);
        } catch (PDOException $e) {
            error_log("markNotificationAsRead error: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('markAllNotificationsAsRead')) {
    function markAllNotificationsAsRead($zoneNames = []) {
        global $pdo;
        if (!isset($pdo)) return false;

        try {
            $filter = buildNotificationZoneFilterClause($zoneNames);
            $stmt = $pdo->prepare("UPDATE posting_notifications SET is_read = 1 WHERE is_read = 0" . $filter['sql']);
            return $stmt->execute($filter['params']);
        } catch (PDOException $e) {
            error_log("markAllNotificationsAsRead error: " . $e->getMessage());
            return false;
        }
    }
}
