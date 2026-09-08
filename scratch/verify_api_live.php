<?php
require __DIR__ . '/../includes/config.php';

// Ensure at least 1 active test API key exists
$keyRow = $pdo->query("SELECT api_key FROM api_keys WHERE is_active = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if (!$keyRow) {
    $apiKey = 'nis_live_key_' . bin2hex(random_bytes(16));
    $stmt = $pdo->prepare("INSERT INTO api_keys (client_name, api_key, permissions, is_active) VALUES ('Global Interpol Gateway', ?, '*', 1)");
    $stmt->execute([$apiKey]);
} else {
    $apiKey = $keyRow['api_key'];
}

echo "=== NIS-PPMS GLOBAL RESTFUL API GATEWAY VERIFICATION ===\n";
echo "Active API Key: " . $apiKey . "\n\n";

// Helper function to make HTTP requests
function makeApiCall($endpoint, $method = 'GET', $data = null, $apiKey = '') {
    $url = "http://localhost/nisposting/api/v1/" . $endpoint;
    $ch = curl_init();
    
    $headers = [
        "X-API-KEY: " . $apiKey,
        "Content-Type: application/json"
    ];
    
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    
    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return [
        'code' => $httpCode,
        'body' => json_decode($response, true)
    ];
}

// 1. Health Status Check
echo "[TEST 1] GET /api/v1/status (System Health & Version)\n";
$res1 = makeApiCall('status', 'GET', null, $apiKey);
echo "HTTP Code: " . $res1['code'] . "\n";
print_r($res1['body']);
echo "\n" . str_repeat('-', 60) . "\n\n";

// 2. Share Personnel Data Out
echo "[TEST 2] GET /api/v1/personnel?limit=2 (Sharing Officer Roster Data OUT)\n";
$res2 = makeApiCall('personnel?limit=2', 'GET', null, $apiKey);
echo "HTTP Code: " . $res2['code'] . "\n";
print_r($res2['body']);
echo "\n" . str_repeat('-', 60) . "\n\n";

// 3. Receive Officer Data In
echo "[TEST 3] POST /api/v1/personnel (Receiving Officer Data IN from Global Partner)\n";
$newOfficer = [
    "serviceNo" => "INT-889900",
    "surname" => "GLOBALADAMU",
    "firstName" => "MOHAMMED",
    "middleName" => "IBRAHIM",
    "gender" => "Male",
    "dob" => "1988-03-12",
    "state_of_origin" => "Kano",
    "lga" => "Municipal",
    "email" => "m.adamu@interpol-nis.org",
    "phone" => "08033445566",
    "currentRank" => "Superintendent of Immigration",
    "presentPosting" => "Zone B HQ Kaduna"
];
$res3 = makeApiCall('personnel', 'POST', $newOfficer, $apiKey);
echo "HTTP Code: " . $res3['code'] . "\n";
print_r($res3['body']);
echo "\n" . str_repeat('-', 60) . "\n\n";

// 4. Receive Posting Order In
echo "[TEST 4] POST /api/v1/postings (Receiving Global Posting Movement Order IN)\n";
$postingOrder = [
    "serviceNo" => "INT-889900",
    "newPosting" => "Murtala Muhamed International Airport Command",
    "posting_type" => "Strategic Assignment",
    "datePosted" => date('Y-m-d')
];
$res4 = makeApiCall('postings', 'POST', $postingOrder, $apiKey);
echo "HTTP Code: " . $res4['code'] . "\n";
print_r($res4['body']);
echo "\n" . str_repeat('-', 60) . "\n\n";

// 5. Share Postings Out
echo "[TEST 5] GET /api/v1/postings?serviceNo=INT-889900 (Sharing Historical Postings OUT)\n";
$res5 = makeApiCall('postings?serviceNo=INT-889900', 'GET', null, $apiKey);
echo "HTTP Code: " . $res5['code'] . "\n";
print_r($res5['body']);
echo "\n" . str_repeat('=', 60) . "\n";
