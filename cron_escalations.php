<?php
// cron_escalations.php
// Daily Production Cron Job for 30-Day Duty Assumption Escalations & Non-Reporting Reminders
// Run via cPanel Cron or Linux Crontab: 0 7 * * * php /home/username/public_html/cron_escalations.php >/dev/null 2>&1

if (php_sapi_name() !== 'cli' && !isset($_GET['key'])) {
    // Secret key for HTTP cron triggers
    $cronSecret = 'NIS_PPMS_CRON_SECRET_2026';
    if (($_GET['key'] ?? '') !== $cronSecret) {
        die("403 Unauthorized Cron Trigger.");
    }
}

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/settings_helper.php';
require_once __DIR__ . '/includes/NotificationService.php';

echo "[" . date('Y-m-d H:i:s') . "] STARTING NIS-PPMS DAILY CRON ESCALATIONS...\n";

try {
    $notificationService = new NotificationService($pdo);
    $assumptionWindow = intval(getSystemSetting('assumption_window_days', '30'));

    // 1. Fetch officers posted > assumptionWindow days ago who have NOT reported
    $sql = "SELECT ph.serviceNo, ph.posting_location, ph.posting_date, DATEDIFF(NOW(), ph.posting_date) as days_since,
                   p.surname, p.firstName, p.middleName, p.phone, p.email, e.currentRank
            FROM posting_history ph
            JOIN tbl_emppersonal p ON ph.serviceNo = p.serviceNo
            JOIN tbl_employment e ON ph.serviceNo = e.serviceNo
            WHERE ph.posting_type = 'current'
              AND DATEDIFF(NOW(), ph.posting_date) >= ?
              AND (e.empStatus IS NULL OR e.empStatus != 'Admitted')
            ORDER BY ph.posting_date ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$assumptionWindow]);
    $overdueOfficers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Found " . count($overdueOfficers) . " officers overdue for reporting (> $assumptionWindow days).\n";

    $sentCount = 0;
    foreach ($overdueOfficers as $off) {
        $fullName = trim($off['surname'] . ' ' . $off['firstName'] . ' ' . $off['middleName']);
        $serviceNo = $off['serviceNo'];
        $location = $off['posting_location'];
        $postingDate = $off['posting_date'];
        $daysSince = $off['days_since'];

        // Send Reminder SMS
        $smsRes = $notificationService->sendReminderSMS($serviceNo, $fullName, $location, $postingDate, $daysSince, $off['phone']);
        
        // Send Reminder Email
        $emailRes = $notificationService->sendReminderEmail($serviceNo, $fullName, $location, $postingDate, $daysSince, $off['email']);

        $sentCount++;
        echo " - Sent escalation alert for Officer $serviceNo ($fullName).\n";
    }

    echo "[" . date('Y-m-d H:i:s') . "] CRON COMPLETED SUCCESSFULLY. Sent $sentCount notifications.\n";

} catch (Exception $e) {
    echo "[" . date('Y-m-d H:i:s') . "] CRON ERROR: " . $e->getMessage() . "\n";
}
