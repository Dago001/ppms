<?php
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/NotificationService.php';
require __DIR__ . '/../includes/notification_helpers.php';

echo "=== REAL-TIME POSTING SMS & EMAIL DISPATCH TEST ===\n\n";

$serviceNo = '35562';
$officerFullName = 'DAGOGO GIFT WILFRED';
$officerRank = 'Asst. Superintendent 2';
$newLocation = 'ICT And Cyber Security Directorate';
$postingDate = date('Y-m-d');
$postedByName = 'System Administrator';

// 1. Dispatch In-App Notification
$postingZone = detectPostingZone($newLocation);
createPostingNotification($serviceNo, $officerFullName, $officerRank, $newLocation, $postingZone, $postedByName, $postingDate);
echo "[STEP 1] Created In-App Bell Alert in posting_notifications.\n";

// 2. Dispatch Real-Time SMS
$notificationService = new NotificationService($pdo);
$smsRes = $notificationService->sendPostingSMS($serviceNo, $officerFullName, $newLocation, $postingDate);
echo "[STEP 2] Real-time SMS Dispatch Status:\n";
print_r($smsRes);

// 3. Dispatch Real-Time Email
$emailRes = $notificationService->sendPostingEmail($serviceNo, $officerFullName, $officerRank, $newLocation, $postingDate);
echo "\n[STEP 3] Real-time Email Dispatch Status:\n";
print_r($emailRes);

// 4. Verify Latest SMS & Email Logs in DB
$latestSms = $pdo->query("SELECT * FROM sms_logs WHERE serviceNo = '$serviceNo' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$latestEmail = $pdo->query("SELECT * FROM email_logs WHERE serviceNo = '$serviceNo' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

echo "\n--- DB SMS LOG ENTRY ---\n";
print_r($latestSms);

echo "\n--- DB EMAIL LOG ENTRY ---\n";
print_r($latestEmail);

echo "\n=== REAL-TIME DISPATCH TEST COMPLETE ===\n";
