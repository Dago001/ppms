<?php
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/settings_helper.php';
require __DIR__ . '/../includes/NotificationService.php';

echo "=== META WHATSAPP BUSINESS API INTEGRATION TEST ===\n\n";

// Enable WhatsApp in system settings for testing
updateSystemSetting('whatsapp_enabled', '1');
updateSystemSetting('whatsapp_phone_number_id', '109823475928374');
updateSystemSetting('whatsapp_access_token', 'EAAG_MOCK_TEST_TOKEN');

$notifService = new NotificationService($pdo);

echo "Testing sendPostingWhatsApp dispatch method...\n";
$res = $notifService->sendPostingWhatsApp('35562', 'DAGOGO GIFT WILFRED', 'Asst. Superintendent 2', 'ICT And Cyber Security Directorate', date('Y-m-d'), '07033573051');

print_r($res);

echo "\nChecking Database whatsapp_logs table...\n";
$logs = $pdo->query("SELECT * FROM whatsapp_logs ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
print_r($logs);

echo "\n=== META WHATSAPP TEST COMPLETE ===\n";
