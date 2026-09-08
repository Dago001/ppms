<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_once '../includes/security.php';
require_once '../includes/PostingConflictChecker.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Please login first']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Security validation failed. Please refresh the page and try again.']);
    exit();
}

$serviceNo = trim($_POST['serviceNo'] ?? '');
$commandId = intval($_POST['command_id'] ?? 0);
$bypassConflict = intval($_POST['bypass_conflict'] ?? 0);

if (empty($serviceNo)) {
    echo json_encode(['success' => false, 'message' => 'Service number is required']);
    exit();
}

if ($commandId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Command is required']);
    exit();
}

// Check authorization: Admin, SHQ Admin, Command Admin only
if (!canInitiatePosting($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Access Denied: Your account role does not have permission to initiate officer postings. User role accounts are restricted to Viewing and Downloading reports only.']);
    exit();
}

// -------------------------------------------------------------
// INTELLIGENT POSTING CONFLICT ENGINE CHECK
// -------------------------------------------------------------
if (!$bypassConflict) {
    $conflictCheck = PostingConflictChecker::evaluate($pdo, $serviceNo);
    if ($conflictCheck['has_conflict']) {
        echo json_encode([
            'success' => false,
            'requires_override' => true,
            'officer_name' => $conflictCheck['officer_name'],
            'present_location' => $conflictCheck['present_location'],
            'conflicts' => $conflictCheck['conflicts'],
            'warnings' => $conflictCheck['warnings'],
            'message' => 'Posting Conflict Warning: ' . implode(' | ', $conflictCheck['warnings'])
        ]);
        exit();
    }
}

if (!function_exists('postOfficer')) {
    function postOfficer($serviceNo, $commandId, $userId) {
        global $pdo;
        try {
            // Get target command name
            $stmtCmd = $pdo->prepare("SELECT formation_name FROM formation_structure WHERE id = ?");
            $stmtCmd->execute([$commandId]);
            $commandName = $stmtCmd->fetchColumn();

            if (!$commandName) {
                // Fallback to zones table
                $stmtZone = $pdo->prepare("SELECT zone_name FROM zones WHERE id = ?");
                $stmtZone->execute([$commandId]);
                $commandName = $stmtZone->fetchColumn();
            }

            if (!$commandName) {
                return ['success' => false, 'message' => 'Invalid destination command selected'];
            }

            // Get logged-in user's full name
            $stmtUser = $pdo->prepare("SELECT full_name, username FROM users WHERE id = ?");
            $stmtUser->execute([$userId]);
            $userData = $stmtUser->fetch(PDO::FETCH_ASSOC);
            $postedByName = !empty($userData['full_name']) ? $userData['full_name'] : ($userData['username'] ?? 'System Administrator');

            $pdo->beginTransaction();

            // Insert into posting_history with created_by and posted_by
            $stmtHist = $pdo->prepare("INSERT INTO posting_history (serviceNo, posting_location, posting_date, posting_type, created_by, posted_by) VALUES (?, ?, CURDATE(), 'transfer', ?, ?)");
            $stmtHist->execute([$serviceNo, $commandName, $userId, $postedByName]);

            // Update current_posting
            $stmtCheck = $pdo->prepare("SELECT id FROM current_posting WHERE serviceNo = ?");
            $stmtCheck->execute([$serviceNo]);
            if ($stmtCheck->fetch()) {
                $pdo->prepare("UPDATE current_posting SET posting_location = ?, updated_at = NOW() WHERE serviceNo = ?")->execute([$commandName, $serviceNo]);
            } else {
                $pdo->prepare("INSERT INTO current_posting (serviceNo, posting_location) VALUES (?, ?)")->execute([$serviceNo, $commandName]);
            }

            // Update tbl_employment
            $pdo->prepare("UPDATE tbl_employment SET presentPosting = ? WHERE serviceNo = ?")->execute([$commandName, $serviceNo]);

            $pdo->commit();

            // Trigger notification
            try {
                require_once '../includes/formation_helper.php';
                require_once '../includes/notification_helpers.php';

                $stmtEmp = $pdo->prepare("
                    SELECT ep.surname, ep.firstName, ep.middleName, te.currentRank 
                    FROM tbl_emppersonal ep 
                    LEFT JOIN tbl_employment te ON ep.serviceNo = te.serviceNo 
                    WHERE ep.serviceNo = ?
                ");
                $stmtEmp->execute([$serviceNo]);
                $empData = $stmtEmp->fetch(PDO::FETCH_ASSOC);
                $officerFullName = trim(($empData['surname'] ?? '') . ' ' . ($empData['firstName'] ?? '') . ' ' . ($empData['middleName'] ?? ''));
                $officerRank = $empData['currentRank'] ?? 'N/A';
                $postingZone = detectPostingZone($commandName);

                // createPostingNotification automatically dispatches real-time SMS & Email via NotificationService
                createPostingNotification($serviceNo, $officerFullName, $officerRank, $commandName, $postingZone, $postedByName, date('Y-m-d'));
                logSystemActivity('officer_posted', "Officer #$serviceNo ($officerFullName, Rank: $officerRank) posted to '$commandName' by $postedByName", $userId);
            } catch (Exception $ne) {}

            return ['success' => true, 'message' => "Officer #{$serviceNo} successfully posted to {$commandName}!"];
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['success' => false, 'message' => 'Error processing posting: ' . $e->getMessage()];
        }
    }
}

$result = postOfficer($serviceNo, $commandId, $_SESSION['user_id']);
echo json_encode($result);