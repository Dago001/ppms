<?php
// Script to update WhatsApp credentials in system_settings and test live API
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/settings_helper.php';

$accessToken = 'EAAbqO1UkmogBSL9vR7eZB9FgG4UVEBBt8bybGLoovlKvSY8XJJJURJM6iPZAuRZANGMmXFOC5bpt77bEu03X8yfteqYCa7dAlHzmLv0Gc8Ts5PAuMTrHZCMHfdOzedJz3RZArNzn9sHZBkidZBZBY6a5IlbwDcg3QPTfvxHkAzogEdmYEfiL9lgDaU1TIvXZCQOojjwZDZD';
$phoneNumber = '09114929022';

echo "=== STEP 1: Fetching Meta Phone Number ID from API ===\n";

// First, we query Meta to get the phone number ID linked to this access token
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => "https://graph.facebook.com/v18.0/me/phone_numbers?access_token={$accessToken}",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_SSL_VERIFYPEER => false
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "Response: $response\n\n";

$result = json_decode($response, true);

$phoneNumberId = null;

if (!empty($result['data'])) {
    foreach ($result['data'] as $ph) {
        echo "Found Phone: " . ($ph['display_phone_number'] ?? '') . " | ID: " . ($ph['id'] ?? '') . "\n";
        // Match by phone number
        $normalized = preg_replace('/\D/', '', $phoneNumber);
        $registered = preg_replace('/\D/', '', $ph['display_phone_number'] ?? '');
        if (str_ends_with($registered, substr($normalized, -8)) || $phoneNumberId === null) {
            $phoneNumberId = $ph['id'];
        }
    }
    echo "\nUsing Phone Number ID: $phoneNumberId\n";
} else {
    // Try fetching WABA phone numbers directly
    echo "\nTrying to fetch from WhatsApp Business Account...\n";
    $ch2 = curl_init();
    curl_setopt_array($ch2, [
        CURLOPT_URL => "https://graph.facebook.com/v18.0/me?fields=id,name&access_token={$accessToken}",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $r2 = curl_exec($ch2);
    curl_close($ch2);
    echo "Account Info: $r2\n";
}

if ($phoneNumberId) {
    echo "\n=== STEP 2: Saving credentials to System Settings ===\n";
    updateSystemSetting('whatsapp_enabled', '1');
    updateSystemSetting('whatsapp_phone_number_id', $phoneNumberId);
    updateSystemSetting('whatsapp_access_token', $accessToken);
    updateSystemSetting('whatsapp_api_version', 'v18.0');
    echo "Credentials saved to system_settings.\n";

    echo "\n=== STEP 3: Sending Live Test WhatsApp Message ===\n";
    require __DIR__ . '/../includes/NotificationService.php';
    $svc = new NotificationService($pdo);
    // Send test message to same WhatsApp number
    $res = $svc->sendPostingWhatsApp('35562', 'DAGOGO GIFT WILFRED', 'Asst. Superintendent 2', 'ICT And Cyber Security Directorate', date('Y-m-d'), $phoneNumber);
    echo "Result:\n";
    print_r($res);
} else {
    // Use the raw phone number as the ID for direct test
    echo "\nPhone Number ID not auto-detected. Please get the numeric Phone Number ID from Meta Business Suite > WhatsApp > API Setup.\n";
    echo "Trying direct send with raw number as ID...\n";
    
    // Still save what we have and let user supply the real Phone Number ID from Meta dashboard
    updateSystemSetting('whatsapp_enabled', '1');
    updateSystemSetting('whatsapp_access_token', $accessToken);
    echo "\nAccess Token saved. To complete setup, get your Phone Number ID from:\nhttps://developers.facebook.com/apps -> WhatsApp -> API Setup\n";
}

echo "\n=== COMPLETE ===\n";
