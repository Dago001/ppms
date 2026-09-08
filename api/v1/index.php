<?php
// api/v1/index.php
// Production RESTful API Engine for NIS-PPMS (Global Integration Interface)

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/settings_helper.php';

$startTime = microtime(true);
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

// Global CORS Handling
if (getSystemSetting('api_global_cors_enabled', '1') === '1') {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-API-KEY, X-Requested-With");
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(json_encode(["status" => "success", "message" => "CORS Preflight OK"]));
}

// Function helper for standard JSON responses
function sendJsonResponse($code, $status, $message, $data = null, $meta = null) {
    http_response_code($code);
    $response = [
        "status" => $status,
        "code" => $code,
        "message" => $message,
        "timestamp" => date('Y-m-d H:i:s')
    ];
    if ($data !== null) $response["data"] = $data;
    if ($meta !== null) $response["meta"] = $meta;
    
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit();
}

// 1. Check Master API Switch
if (getSystemSetting('api_master_enabled', '1') !== '1') {
    sendJsonResponse(503, "error", "Global NIS-PPMS API Service is currently disabled by System Administrator.");
}

// 2. Extract & Validate API Key
$apiKey = '';
$headers = getallheaders();

if (!empty($headers['X-API-KEY'])) {
    $apiKey = trim($headers['X-API-KEY']);
} elseif (!empty($headers['x-api-key'])) {
    $apiKey = trim($headers['x-api-key']);
} elseif (!empty($headers['Authorization']) && preg_match('/Bearer\s+(.*)$/i', $headers['Authorization'], $matches)) {
    $apiKey = trim($matches[1]);
} elseif (!empty($_GET['api_key'])) {
    $apiKey = trim($_GET['api_key']);
}

$keyRecord = validateAPIKey($apiKey, $clientIp);
if (!$keyRecord) {
    logAPIRequest(null, $_SERVER['REQUEST_URI'] ?? '/api/v1', $_SERVER['REQUEST_METHOD'], $clientIp, 401, 0, "Unauthorized: Invalid or Missing API Key");
    sendJsonResponse(401, "error", "Unauthorized: Invalid, missing, or deactivated X-API-KEY header.");
}

$permissions = array_map('trim', explode(',', $keyRecord['permissions']));

// Route parsing
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$route = $_GET['endpoint'] ?? '';

if (empty($route)) {
    if (strpos($uri, '/api/v1/personnel') !== false) $route = 'personnel';
    elseif (strpos($uri, '/api/v1/postings') !== false) $route = 'postings';
    elseif (strpos($uri, '/api/v1/disciplinary') !== false) $route = 'disciplinary';
    elseif (strpos($uri, '/api/v1/status') !== false) $route = 'status';
    else $route = 'status';
}

$method = $_SERVER['REQUEST_METHOD'];
$inputJSON = json_decode(file_get_contents('php://input'), true) ?? $_POST;

// Route Handlers
try {
    switch ($route) {
        // ============================================
        // 1. SYSTEM STATUS & HEALTH ENDPOINT
        // ============================================
        case 'status':
            $responseMs = round((microtime(true) - $startTime) * 1000, 2);
            logAPIRequest($keyRecord['id'], '/api/v1/status', $method, $clientIp, 200, $responseMs, "Health Check");
            sendJsonResponse(200, "success", "NIS-PPMS Global RESTful API is operational.", [
                "api_name" => "Nigeria Immigration Service PPMS Global API Gateway",
                "version" => "v1.0",
                "client_authenticated" => $keyRecord['client_name'],
                "server_time" => date('c'),
                "active_permissions" => $permissions
            ]);
            break;

        // ============================================
        // 2. PERSONNEL ENDPOINTS (SHARE & RECEIVE)
        // ============================================
        case 'personnel':
            if ($method === 'GET') {
                if (!in_array('read_personnel', $permissions) && !in_array('*', $permissions)) {
                    sendJsonResponse(403, "error", "Forbidden: Your API key lacks 'read_personnel' permission.");
                }

                $limit = min(max(intval($_GET['limit'] ?? 50), 1), 500);
                $offset = max(intval($_GET['offset'] ?? 0), 0);
                $serviceNo = trim($_GET['serviceNo'] ?? '');
                $surname = trim($_GET['surname'] ?? '');
                $posting = trim($_GET['posting'] ?? '');

                $where = ["1=1"];
                $params = [];

                if (!empty($serviceNo)) {
                    $where[] = "p.serviceNo LIKE ?";
                    $params[] = "%" . $serviceNo . "%";
                }
                if (!empty($surname)) {
                    $where[] = "(p.surname LIKE ? OR p.firstName LIKE ?)";
                    $params[] = "%" . $surname . "%";
                    $params[] = "%" . $surname . "%";
                }
                if (!empty($posting)) {
                    $where[] = "e.presentPosting LIKE ?";
                    $params[] = "%" . $posting . "%";
                }

                $sql = "SELECT p.serviceNo, p.surname, p.firstName, p.middleName, p.gender, p.dob, p.state_of_origin, p.lga, p.email, p.phone,
                               e.currentRank, e.presentPosting, e.dofa, e.empStatus, e.disciplinary_status, e.disciplinary_remarks
                        FROM tbl_emppersonal p
                        LEFT JOIN tbl_employment e ON p.serviceNo = e.serviceNo
                        WHERE " . implode(" AND ", $where) . "
                        ORDER BY p.serviceNo DESC LIMIT $limit OFFSET $offset";

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $responseMs = round((microtime(true) - $startTime) * 1000, 2);
                logAPIRequest($keyRecord['id'], '/api/v1/personnel', $method, $clientIp, 200, $responseMs, "Shared " . count($records) . " personnel records");
                
                sendJsonResponse(200, "success", "Personnel records retrieved successfully.", $records, [
                    "count" => count($records),
                    "limit" => $limit,
                    "offset" => $offset
                ]);
            } 
            elseif ($method === 'POST') {
                if (!in_array('write_personnel', $permissions) && !in_array('*', $permissions)) {
                    sendJsonResponse(403, "error", "Forbidden: Your API key lacks 'write_personnel' permission.");
                }

                $svcNo = trim($inputJSON['serviceNo'] ?? '');
                $surname = trim($inputJSON['surname'] ?? '');
                $firstName = trim($inputJSON['firstName'] ?? '');

                if (empty($svcNo) || empty($surname) || empty($firstName)) {
                    sendJsonResponse(422, "error", "Validation Error: serviceNo, surname, and firstName are required.");
                }

                // Insert/Update Personal
                $stmtP = $pdo->prepare("INSERT INTO tbl_emppersonal (serviceNo, surname, firstName, middleName, gender, dob, state_of_origin, lga, email, phone) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) 
                    ON DUPLICATE KEY UPDATE surname = VALUES(surname), firstName = VALUES(firstName), middleName = VALUES(middleName), gender = VALUES(gender)");
                $stmtP->execute([
                    $svcNo, $surname, $firstName,
                    $inputJSON['middleName'] ?? '',
                    $inputJSON['gender'] ?? 'Not Specified',
                    $inputJSON['dob'] ?? null,
                    $inputJSON['state_of_origin'] ?? $inputJSON['stateOfOrigin'] ?? '',
                    $inputJSON['lga'] ?? '',
                    $inputJSON['email'] ?? '',
                    $inputJSON['phone'] ?? ''
                ]);

                // Insert/Update Employment
                // Disciplinary Guard: block a presentPosting change via this endpoint too, the
                // same rule the dedicated 'postings' write endpoint enforces - otherwise a
                // write_personnel-only API key could silently bypass it.
                if (getSystemSetting('disciplinary_guard_enabled', '1') === '1' && !empty($inputJSON['presentPosting'])) {
                    $stmtDiscP = $pdo->prepare("SELECT disciplinary_status FROM tbl_employment WHERE serviceNo = ?");
                    $stmtDiscP->execute([$svcNo]);
                    $discStatus = $stmtDiscP->fetchColumn();
                    if (!empty($discStatus) && strtolower($discStatus) !== 'clean') {
                        sendJsonResponse(422, "error", "POSTING BLOCKED (Disciplinary Guard): Officer $svcNo is currently under disciplinary action ('$discStatus'). Posting prohibited under NIS Regulations.");
                    }
                }

                $stmtE = $pdo->prepare("INSERT INTO tbl_employment (serviceNo, currentRank, presentPosting, dofa, empStatus)
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE currentRank = VALUES(currentRank), presentPosting = VALUES(presentPosting)");
                $stmtE->execute([
                    $svcNo,
                    $inputJSON['currentRank'] ?? 'Constable',
                    $inputJSON['presentPosting'] ?? 'NIS Headquarters, Abuja',
                    $inputJSON['dofa'] ?? date('Y-m-d'),
                    $inputJSON['empStatus'] ?? 'Active'
                ]);

                $responseMs = round((microtime(true) - $startTime) * 1000, 2);
                logAPIRequest($keyRecord['id'], '/api/v1/personnel', $method, $clientIp, 200, $responseMs, "Registered/Updated officer $svcNo");
                
                sendJsonResponse(200, "success", "Personnel record $svcNo created/updated successfully.", [
                    "serviceNo" => $svcNo,
                    "surname" => $surname,
                    "firstName" => $firstName,
                    "currentRank" => $inputJSON['currentRank'] ?? 'Constable',
                    "presentPosting" => $inputJSON['presentPosting'] ?? 'NIS Headquarters, Abuja'
                ]);
            }
            break;

        // ============================================
        // 3. POSTING ORDERS ENDPOINTS (SHARE & RECEIVE)
        // ============================================
        case 'postings':
            if ($method === 'GET') {
                if (!in_array('read_postings', $permissions) && !in_array('*', $permissions)) {
                    sendJsonResponse(403, "error", "Forbidden: Your API key lacks 'read_postings' permission.");
                }

                $limit = min(max(intval($_GET['limit'] ?? 50), 1), 200);
                $serviceNo = trim($_GET['serviceNo'] ?? '');

                $where = ["1=1"];
                $params = [];

                if (!empty($serviceNo)) {
                    $where[] = "ph.serviceNo = ?";
                    $params[] = $serviceNo;
                }

                $sql = "SELECT ph.id, ph.serviceNo, ph.posting_location, ph.posting_date, ph.posting_type, ph.created_at,
                               CONCAT(COALESCE(ep.surname, ''), ' ', COALESCE(ep.firstName, '')) as officer_name
                        FROM posting_history ph
                        LEFT JOIN tbl_emppersonal ep ON ph.serviceNo = ep.serviceNo
                        WHERE " . implode(" AND ", $where) . "
                        ORDER BY ph.id DESC LIMIT $limit";

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $postings = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $responseMs = round((microtime(true) - $startTime) * 1000, 2);
                logAPIRequest($keyRecord['id'], '/api/v1/postings', $method, $clientIp, 200, $responseMs, "Retrieved " . count($postings) . " postings");

                sendJsonResponse(200, "success", "Posting history records retrieved successfully.", $postings);
            }
            elseif ($method === 'POST') {
                if (!in_array('write_postings', $permissions) && !in_array('*', $permissions)) {
                    sendJsonResponse(403, "error", "Forbidden: Your API key lacks 'write_postings' permission.");
                }

                $svcNo = trim($inputJSON['serviceNo'] ?? '');
                $newPosting = trim($inputJSON['newPosting'] ?? $inputJSON['posting_location'] ?? '');
                $postingType = trim($inputJSON['posting_type'] ?? 'Routine');
                $postingDate = trim($inputJSON['datePosted'] ?? $inputJSON['posting_date'] ?? date('Y-m-d'));

                if (empty($svcNo) || empty($newPosting)) {
                    sendJsonResponse(422, "error", "Validation Error: serviceNo and newPosting (or posting_location) are required parameters.");
                }

                // Check Disciplinary Guard if enabled
                if (getSystemSetting('disciplinary_guard_enabled', '1') === '1') {
                    $stmtDisc = $pdo->prepare("SELECT disciplinary_status, disciplinary_remarks FROM tbl_employment WHERE serviceNo = ?");
                    $stmtDisc->execute([$svcNo]);
                    $discRow = $stmtDisc->fetch(PDO::FETCH_ASSOC);

                    if ($discRow && !empty($discRow['disciplinary_status']) && strtolower($discRow['disciplinary_status']) !== 'clean') {
                        sendJsonResponse(422, "error", "POSTING BLOCKED (Disciplinary Guard): Officer $svcNo is currently under disciplinary action ('" . $discRow['disciplinary_status'] . "'). Posting prohibited under NIS Regulations.");
                    }
                }

                // Get current posting
                $stmtCurr = $pdo->prepare("SELECT presentPosting FROM tbl_employment WHERE serviceNo = ?");
                $stmtCurr->execute([$svcNo]);
                $currPosting = $stmtCurr->fetchColumn() ?: 'Unassigned';

                // Transactional update
                $pdo->beginTransaction();

                // 1. Insert into posting_history
                $stmtHist = $pdo->prepare("INSERT INTO posting_history (serviceNo, posting_location, posting_date, posting_type) VALUES (?, ?, ?, ?)");
                $stmtHist->execute([$svcNo, $newPosting, $postingDate, $postingType]);

                // 2. Update tbl_employment
                $stmtEmp = $pdo->prepare("UPDATE tbl_employment SET presentPosting = ? WHERE serviceNo = ?");
                $stmtEmp->execute([$newPosting, $svcNo]);

                // 3. Insert posting notification if bell alerts enabled
                if (getSystemSetting('inapp_bell_alerts_enabled', '1') === '1') {
                    $stmtNotif = $pdo->prepare("INSERT INTO posting_notifications (serviceNo, posting_location, posting_zone, status, created_at) VALUES (?, ?, ?, 'pending', NOW())");
                    $stmtNotif->execute([$svcNo, $newPosting, $newPosting]);
                }

                $pdo->commit();

                $responseMs = round((microtime(true) - $startTime) * 1000, 2);
                logAPIRequest($keyRecord['id'], '/api/v1/postings', $method, $clientIp, 200, $responseMs, "Executed global posting for $svcNo to $newPosting");

                sendJsonResponse(200, "success", "Posting order for $svcNo executed successfully via Global API.", [
                    "serviceNo" => $svcNo,
                    "previous_posting" => $currPosting,
                    "newPosting" => $newPosting,
                    "posting_type" => $postingType,
                    "datePosted" => $postingDate
                ]);
            }
            break;

        // ============================================
        // 4. DISCIPLINARY ENDPOINTS (SHARE & RECEIVE)
        // ============================================
        case 'disciplinary':
            if ($method === 'GET') {
                if (!in_array('read_disciplinary', $permissions) && !in_array('*', $permissions)) {
                    sendJsonResponse(403, "error", "Forbidden: Your API key lacks 'read_disciplinary' permission.");
                }

                $stmt = $pdo->query("SELECT e.serviceNo, CONCAT(COALESCE(p.surname,''), ' ', COALESCE(p.firstName,'')) as officer_name, 
                                            e.currentRank, e.presentPosting, e.disciplinary_status, e.disciplinary_remarks, e.updated_at
                                     FROM tbl_employment e
                                     LEFT JOIN tbl_emppersonal p ON e.serviceNo = p.serviceNo
                                     WHERE e.disciplinary_status IS NOT NULL AND e.disciplinary_status != '' AND e.disciplinary_status != 'Clean'
                                     ORDER BY e.updated_at DESC");
                $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $responseMs = round((microtime(true) - $startTime) * 1000, 2);
                logAPIRequest($keyRecord['id'], '/api/v1/disciplinary', $method, $clientIp, 200, $responseMs, "Retrieved " . count($records) . " active disciplinary records");

                sendJsonResponse(200, "success", "Active disciplinary case records retrieved successfully.", $records);
            }
            elseif ($method === 'POST') {
                if (!in_array('write_disciplinary', $permissions) && !in_array('*', $permissions)) {
                    sendJsonResponse(403, "error", "Forbidden: Your API key lacks 'write_disciplinary' permission.");
                }

                $svcNo = trim($inputJSON['serviceNo'] ?? '');
                $status = trim($inputJSON['disciplinary_status'] ?? 'Under Query');
                $remarks = trim($inputJSON['disciplinary_remarks'] ?? '');

                if (empty($svcNo)) {
                    sendJsonResponse(422, "error", "Validation Error: serviceNo is required.");
                }

                $stmt = $pdo->prepare("UPDATE tbl_employment SET disciplinary_status = ?, disciplinary_remarks = ? WHERE serviceNo = ?");
                $stmt->execute([$status, $remarks, $svcNo]);

                $responseMs = round((microtime(true) - $startTime) * 1000, 2);
                logAPIRequest($keyRecord['id'], '/api/v1/disciplinary', $method, $clientIp, 200, $responseMs, "Updated disciplinary status for $svcNo to $status");

                sendJsonResponse(200, "success", "Disciplinary status for $svcNo updated to '$status'.", [
                    "serviceNo" => $svcNo,
                    "disciplinary_status" => $status,
                    "disciplinary_remarks" => $remarks
                ]);
            }
            break;

        default:
            sendJsonResponse(404, "error", "Endpoint '$route' not found in NIS-PPMS Global API Gateway.");
            break;
    }
} catch (Exception $e) {
    $responseMs = round((microtime(true) - $startTime) * 1000, 2);
    logAPIRequest($keyRecord['id'], '/api/v1/' . $route, $method, $clientIp, 500, $responseMs, "Internal Exception: " . $e->getMessage());
    sendJsonResponse(500, "error", "Internal Server Error. Please contact the system administrator.");
}
