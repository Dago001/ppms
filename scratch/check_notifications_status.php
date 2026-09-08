<?php
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/settings_helper.php';
require __DIR__ . '/../includes/NotificationService.php';

echo "=== NIS-PPMS SMS & EMAIL FEATURE DIAGNOSTIC & HEALTH CHECK ===\n\n";

// 1. Check System Settings Toggles
echo "--- SYSTEM SETTINGS TOGGLES ---\n";
echo "auto_sms_enabled: " . var_export(getSystemSetting('auto_sms_enabled', '1'), true) . "\n";
echo "auto_email_enabled: " . var_export(getSystemSetting('auto_email_enabled', '1'), true) . "\n";
echo "inapp_bell_alerts_enabled: " . var_export(getSystemSetting('inapp_bell_alerts_enabled', '1'), true) . "\n\n";

// 2. Check Database Tables
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

echo "--- SMS LOGS TABLE ---\n";
if (in_array('sms_logs', $tables)) {
    $countSms = $pdo->query("SELECT COUNT(*) FROM sms_logs")->fetchColumn();
    echo "Total SMS Logs recorded: " . $countSms . "\n";
    $recentSms = $pdo->query("SELECT * FROM sms_logs ORDER BY id DESC LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
    print_r($recentSms);
} else {
    echo "sms_logs table DOES NOT EXIST!\n";
}

echo "\n--- EMAIL LOGS TABLE ---\n";
if (in_array('email_logs', $tables)) {
    $countEmail = $pdo->query("SELECT COUNT(*) FROM email_logs")->fetchColumn();
    echo "Total Email Logs recorded: " . $countEmail . "\n";
    $recentEmail = $pdo->query("SELECT * FROM email_logs ORDER BY id DESC LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
    print_r($recentEmail);
} else {
    echo "email_logs table DOES NOT EXIST!\n";
}

// 3. Test NotificationService Execution
echo "\n--- TESTING NOTIFICATION SERVICE INSTANTIATION ---\n";
$notifService = new NotificationService($pdo);
echo "NotificationService instantiated successfully.\n";

// Test SMS Method Dispatch
$smsRes = $notifService->sendPostingSMS('96969', 'OFFICER TEST', 'Zone A HQ Ikeja', date('Y-m-d'), '08012345678');
echo "\nTest SMS Response:\n";
print_r($smsRes);

// Test Email Method Dispatch
$emailRes = $notifService->sendPostingEmail('96969', 'OFFICER TEST', 'Deputy Superintendent', 'Zone A HQ Ikeja', date('Y-m-d'), 'testofficer@immigration.gov.ng');
echo "\nTest Email Response:\n";
print_r($emailRes);

echo "\n=== DIAGNOSTIC COMPLETE ===\n";
