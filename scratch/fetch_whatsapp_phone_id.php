<?php
// Fetch WhatsApp Business Account ID and Phone Number IDs from Meta
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/settings_helper.php';

$accessToken = 'EAAbqO1UkmogBSL9vR7eZB9FgG4UVEBBt8bybGLoovlKvSY8XJJJURJM6iPZAuRZANGMmXFOC5bpt77bEu03X8yfteqYCa7dAlHzmLv0Gc8Ts5PAuMTrHZCMHfdOzedJz3RZArNzn9sHZBkidZBZBY6a5IlbwDcg3QPTfvxHkAzogEdmYEfiL9lgDaU1TIvXZCQOojjwZDZD';
$userId = '122093745285439736';

// Step 1: Get WhatsApp Business Accounts linked to this user
echo "=== Fetching WhatsApp Business Accounts ===\n";
$endpoints = [
    "businesses" => "https://graph.facebook.com/v18.0/{$userId}/businesses?access_token={$accessToken}",
    "owned_whatsapp_business_accounts" => "https://graph.facebook.com/v18.0/{$userId}/owned_whatsapp_business_accounts?access_token={$accessToken}",
    "whatsapp_business_account" => "https://graph.facebook.com/v18.0/{$userId}/whatsapp_business_account?access_token={$accessToken}",
];

$wabaId = null;

foreach ($endpoints as $label => $url) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    echo "[$label] HTTP $code: $response\n\n";

    $data = json_decode($response, true);
    if (!empty($data['data'][0]['id'])) {
        $wabaId = $data['data'][0]['id'];
    } elseif (!empty($data['id'])) {
        $wabaId = $data['id'];
    }
}

// Step 2: If we have WABA ID, get phone numbers
if ($wabaId) {
    echo "=== Fetching Phone Numbers for WABA ID: $wabaId ===\n";
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "https://graph.facebook.com/v18.0/{$wabaId}/phone_numbers?access_token={$accessToken}",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $r = curl_exec($ch);
    curl_close($ch);
    echo "Phone Numbers Response: $r\n";

    $phoneData = json_decode($r, true);
    if (!empty($phoneData['data'])) {
        foreach ($phoneData['data'] as $ph) {
            echo "\n✅ PHONE NUMBER ID FOUND: " . $ph['id'] . "\n";
            echo "   Display: " . ($ph['display_phone_number'] ?? 'N/A') . "\n";
            echo "   Status: " . ($ph['code_verification_status'] ?? 'N/A') . "\n";
        }
    }
}

echo "\n=== DONE ===\n";
