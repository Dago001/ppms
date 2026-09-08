<?php
session_start();
require_once 'includes/config.php';
require_once 'includes/auth.php';
require_once 'includes/permissions.php';
require_once 'includes/formation_helper.php';
require_once 'includes/NotificationService.php';

if (!isLoggedIn()) { header('Location: login'); exit(); }

if (!canInitiatePosting() || !hasPermission('bulk_posting')) {
    header("HTTP/1.1 403 Forbidden");
    echo "<div style='font-family:sans-serif; text-align:center; padding:3rem;'>
            <h1 style='color:#dc2626;'>403 Forbidden - Access Denied</h1>
            <p>You do not have permission to initiate bulk batch postings. User role accounts are restricted to Viewing and Downloading reports only.</p>
            <a href='reports' style='display:inline-block; margin-top:1rem; padding:0.5rem 1rem; background:#1a5632; color:#fff; text-decoration:none; border-radius:6px;'>View Reports</a>
          </div>";
    exit();
}

// Get logged in user details
$user = [];
try {
    $stmt = $pdo->prepare("SELECT u.*, r.name as role_name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $user = ['username' => $_SESSION['username'] ?? 'User', 'full_name' => 'User', 'role_name' => 'User'];
}
if (empty($user)) {
    $user = ['username' => $_SESSION['username'] ?? 'User', 'full_name' => $_SESSION['username'] ?? 'User', 'role_name' => 'User'];
}

$notificationService = new NotificationService($pdo);
$userRole = $user['role_name'] ?? '';
$userId = $_SESSION['user_id'] ?? 0;
$error = '';
$success = '';
$step = $_GET['step'] ?? 'upload';
$csrfToken = generateCSRFToken();

// Clear preview session if user requests a new upload
if (isset($_GET['action']) && $_GET['action'] === 'clear') {
    unset($_SESSION['bulk_posting_preview']);
    header('Location: bulk?step=upload');
    exit();
}

// NIS Formations Mapping
$nis_formations = [
    'SHQ - Service Headquarters' => [
        'NIS HQ Abuja' => 'Service Headquarters',
        'CGIS OFFICE' => 'CGIS Office',
        'VRD' => 'Visa and Residency Directorate',
        'POTD' => 'Passport and OTD Directorate',
        'FAD' => 'Finance and Account Directorate',
        'PRSD' => 'PRS Directorate',
        'ICD' => 'I & C Directorate',
        'MD' => 'Migration Directorate',
        'BMD' => 'Border Management Directorate',
        'HRMD' => 'Human Resource Management',
        'WLD' => 'Works and Logistics Directorate',
        'ICTD' => 'ICT and Cyber Security Directorate'
    ],
    'ZONE A' => [
        'Zone A HQ Ikeja' => 'Zone A Lagos',
        'LASC' => 'Lagos State Command',
        'OGSC' => 'Ogun State Command',
        'LASPC' => 'Lagos Seaport & Marine Command',
        'SEME' => 'Seme Border Command',
        'IDBC' => 'Idiroko Border Command',
        'MMIA' => 'MMIA',
        'LABPC' => 'Lagos Border Patrol Command',
        'LAPC' => 'Lagos Passport Command',
        'Ikoyi PC' => 'Ikoyi Passport Command'
    ],
    'ZONE B' => [
        'Zone B HQ Kaduna' => 'Zone B Kaduna',
        'KNSC' => 'Kano State Command',
        'KDSC' => 'Kaduna State Command',
        'KTSC' => 'Katsina State Command',
        'ZMSC' => 'Zamfara State Command',
        'SOSC' => 'Sokoto State Command',
        'JGSC' => 'Jigawa State Command',
        'MAKIA' => 'MAKIA',
        'ILBC' => 'Illela Border Command',
        'JIBC' => 'Jibiya Border Command',
        'ITSK' => 'ITSK',
        'ICSC' => 'ICSC'
    ],
    'ZONE C' => [
        'Zone C HQ Bauchi' => 'Zone C Bauchi',
        'ADSC' => 'Adamawa State Command',
        'BASC' => 'Bauchi State Command',
        'BOSC' => 'Borno State Command',
        'GMSC' => 'Gombe State Command',
        'PLSC' => 'Plateau State Command',
        'YBSC' => 'Yobe State Command'
    ],
    'ZONE D' => [
        'Zone D HQ Minna' => 'Zone D Minna',
        'FCTC' => 'FCT Command',
        'NGSC' => 'Niger State Command',
        'KBSC' => 'Kebbi State Command',
        'RMAT' => 'Regional Migration Academy, Tuga',
        'KWSC' => 'Kwara State Command'
    ],
    'ZONE E' => [
        'Zone E HQ Owerri' => 'Zone E Owerri',
        'Abia State Command' => 'Abia State Command',
        'Imo State Command' => 'Imo State Command',
        'Rivers State Command' => 'Rivers State Command',
        'Cross River State Command' => 'Cross River State Command',
        'Ebonyi State Command' => 'Ebonyi State Command',
        'Akwa Ibom State Command' => 'Akwa Ibom State Command',
        'Nigeria Immigration Training School Orlu' => 'Nigeria Immigration Training School Orlu',
        'Rivers Marine Command Onne' => 'Rivers Marine Command Onne',
        'Mfum Border Command' => 'Mfum Border Command',
        'Nigeria Immigration Training School Ahoada' => 'Nigeria Immigration Training School Ahoada'
    ],
    'ZONE F' => [
        'Zone F HQ Ibadan' => 'Zone F Ibadan',
        'Oyo State Command' => 'Oyo State Command',
        'Ekiti State Command' => 'Ekiti State Command',
        'Ondo State Command' => 'Ondo State Command',
        'Osun State Command' => 'Osun State Command'
    ],
    'ZONE G' => [
        'Zone G HQ Benin' => 'Zone G Benin City',
        'Edo State Command' => 'Edo State Command',
        'Anambra State Command' => 'Anambra State Command',
        'Delta State Command' => 'Delta State Command',
        'Enugu State Command' => 'Enugu State Command',
        'Bayelsa State Command' => 'Bayelsa State Command'
    ],
    'ZONE H' => [
        'Zone H HQ Makurdi' => 'Zone H Makurdi',
        'Nasarawa State Command' => 'Nasarawa State Command',
        'Benue State Command' => 'Benue State Command',
        'Kogi State Command' => 'Kogi State Command',
        'Taraba State Command' => 'Taraba State Command'
    ]
];

// Zone/Command-scoped users (e.g. Command Admin) may only post officers to
// destinations within their own assigned Command/Zone. Null = unrestricted (Admin/SHQ Admin).
$allowedPostingDestinations = getUserAllowedPostingDestinations($pdo, $userId, $nis_formations);

// Helper: Canonical Location Matcher
function resolveCanonicalLocation($inputLocation, $nis_formations) {
    $trimmed = trim($inputLocation);
    if (empty($trimmed)) return '';

    foreach ($nis_formations as $zone => $commands) {
        foreach ($commands as $code => $name) {
            if (strcasecmp($name, $trimmed) === 0 || strcasecmp($code, $trimmed) === 0) {
                return $name;
            }
            if (stripos($name, $trimmed) !== false || stripos($trimmed, $name) !== false) {
                return $name;
            }
            if (stripos($code, $trimmed) !== false || stripos($trimmed, $code) !== false) {
                return $name;
            }
        }
    }

    $stopwords = ['state', 'command', 'hq', 'hqtrs', 'headquarters', 'border', 'passport', 'seaport', 'marine', 'patrol'];
    $inputWords = array_values(array_diff(explode(' ', strtolower(preg_replace('/[^a-z0-9 ]/i', '', $trimmed))), $stopwords));
    $inputCore = implode(' ', $inputWords);

    if (!empty($inputCore)) {
        foreach ($nis_formations as $zone => $commands) {
            foreach ($commands as $code => $name) {
                $nameWords = array_values(array_diff(explode(' ', strtolower(preg_replace('/[^a-z0-9 ]/i', '', $name))), $stopwords));
                $nameCore = implode(' ', $nameWords);
                if (!empty($nameCore) && (stripos($nameCore, $inputCore) !== false || stripos($inputCore, $nameCore) !== false)) {
                    return $name;
                }
            }
        }
    }

    return $trimmed;
}

// ============================================
// HANDLE BATCH DELETION FROM HISTORY
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_batch']) && !validateCSRFToken($_POST['csrf_token'] ?? '')) {
    $error = "Security validation failed. Please refresh the page and try again.";
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_batch'])) {
    $batchIdToDelete = intval($_POST['batch_id']);
    try {
        $pdo->prepare("DELETE FROM bulk_posting_details WHERE batch_id = ?")->execute([$batchIdToDelete]);
        $pdo->prepare("DELETE FROM bulk_posting_batches WHERE id = ? AND created_by = ?")->execute([$batchIdToDelete, $userId]);
        $success = "Bulk posting batch history record deleted successfully!";
    } catch (Exception $e) {
        $error = "Error deleting batch record: " . $e->getMessage();
    }
}

// ============================================
// STEP 1: UPLOAD & VALIDATE (EPHEMERAL PREVIEW IN SESSION)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_file']) && !validateCSRFToken($_POST['csrf_token'] ?? '')) {
    $error = "Security validation failed. Please refresh the page and try again.";
    $step = 'upload';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_file'])) {
    $parsedRows = [];
    $rawBatchName = trim($_POST['batch_name'] ?? '');
    if (empty($rawBatchName) || preg_match('/^(.)\1+$/i', $rawBatchName)) {
        $batchName = 'Bulk Posting - ' . date('d M Y H:i');
    } else {
        $batchName = sanitizeInput($rawBatchName);
    }
    $fileName = 'Bulk Posting';

    $manualText = trim($_POST['manual_text'] ?? '');
    if (!empty($manualText)) {
        $fileName = 'Direct Manual Input';
        $lines = explode("\n", $manualText);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            $parts = preg_split('/[,|\t]/', $line);
            $svcNo = trim($parts[0] ?? '');
            $nLoc = trim($parts[1] ?? '');

            if (empty($svcNo) || empty($nLoc)) continue;

            $cleanSvc = strtolower($svcNo);
            if (in_array($cleanSvc, ['sn', 's/n', 'service no', 'service_no', 'serviceno', 'service n', 'service'])) continue;

            $parsedRows[] = [
                'serviceNo' => $svcNo,
                'new_location' => $nLoc,
                'new_zone' => trim($parts[2] ?? ''),
                'posting_date' => date('Y-m-d'),
                'posting_type' => 'Transfer'
            ];
        }
    } elseif (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['csv_file'];
        $fileName = $file['name'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, ['csv', 'txt'])) {
            $error = "Only CSV or TXT files are allowed.";
        } elseif ($file['size'] > 10 * 1024 * 1024) {
            $error = "File size must be less than 10MB.";
        } else {
            $handle = fopen($file['tmp_name'], 'r');
            if ($handle === false) {
                $error = "Could not read the uploaded file.";
            } else {
                $headers = fgetcsv($handle);
                if (!$headers) {
                    $error = "The CSV file appears to be empty.";
                } else {
                    $serviceNoIndex = null;
                    $newLocationIndex = null;
                    $postingDateIndex = null;
                    $postingTypeIndex = null;
                    $newZoneIndex = null;
                    $firstRowIsData = false;

                    foreach ($headers as $i => $h) {
                        $cleanH = strtolower(trim(str_replace([' ', '-', '.', '/', '_'], '', $h)));
                        if (($cleanH === 'serviceno' || $cleanH === 'servicen' || strpos($cleanH, 'service') !== false) && strpos($cleanH, 'present') === false && strpos($cleanH, 'current') === false && $cleanH !== 'sn') {
                            $serviceNoIndex = $i;
                        } elseif (strpos($cleanH, 'newposting') !== false || strpos($cleanH, 'newlocation') !== false || $cleanH === 'newposting') {
                            $newLocationIndex = $i;
                        } elseif (strpos($cleanH, 'postingdate') !== false || (strpos($cleanH, 'date') !== false && strpos($cleanH, 'update') === false)) {
                            $postingDateIndex = $i;
                        } elseif (strpos($cleanH, 'postingtype') !== false || strpos($cleanH, 'type') !== false) {
                            $postingTypeIndex = $i;
                        } elseif (strpos($cleanH, 'zone') !== false) {
                            $newZoneIndex = $i;
                        }
                    }

                    if ($newLocationIndex === null) {
                        foreach ($headers as $i => $h) {
                            $cleanH = strtolower(trim(str_replace([' ', '-', '.', '/', '_'], '', $h)));
                            if ((strpos($cleanH, 'posting') !== false || strpos($cleanH, 'location') !== false) && 
                                strpos($cleanH, 'present') === false && strpos($cleanH, 'current') === false &&
                                $i !== $serviceNoIndex) {
                                $newLocationIndex = $i;
                                break;
                            }
                        }
                    }

                    if ($serviceNoIndex === null || $newLocationIndex === null) {
                        if (isset($headers[0]) && preg_match('/^[0-9A-Za-z]{2,20}$/', trim($headers[0])) && isset($headers[1])) {
                            $serviceNoIndex = 0;
                            $newLocationIndex = count($headers) > 5 ? 5 : 1;
                            $firstRowIsData = true;
                        }
                    }

                    if ($serviceNoIndex === null || $newLocationIndex === null) {
                        $error = "Missing required headers. Your CSV must have columns for 'SERVICE NO' (or 'SERVICE N') and 'NEW POSTING'.";
                    } else {
                        $headerNames = ['sn', 's/n', 'service no', 'service_no', 'serviceno', 'service n', 'service', 'name', 'rank', 'present posting', 'new posting', 'posting location', 'posting date'];

                        if ($firstRowIsData && !empty($headers)) {
                            $sNo = trim($headers[$serviceNoIndex] ?? '');
                            $nLoc = trim($headers[$newLocationIndex] ?? '');
                            if (!empty($sNo) && !empty($nLoc)) {
                                $parsedRows[] = [
                                    'serviceNo' => $sNo,
                                    'new_location' => $nLoc,
                                    'new_zone' => '',
                                    'posting_date' => date('Y-m-d'),
                                    'posting_type' => 'Transfer'
                                ];
                            }
                        }

                        while (($row = fgetcsv($handle)) !== false) {
                            if (empty(array_filter($row, function($v) { return trim($v) !== ''; }))) continue;

                            $serviceNo = trim($row[$serviceNoIndex] ?? '');
                            $newLocation = trim($row[$newLocationIndex] ?? '');
                            if (empty($newLocation) && isset($row[$newLocationIndex + 1])) {
                                $newLocation = trim($row[$newLocationIndex + 1]);
                            }

                            if (empty($serviceNo) || empty($newLocation)) continue;

                            $cleanVal = strtolower(trim($serviceNo));
                            if (in_array($cleanVal, $headerNames) || strlen(preg_replace('/[^a-zA-Z0-9]/', '', $serviceNo)) < 2) continue;

                            $cleanLoc = strtolower(trim($newLocation));
                            if (in_array($cleanLoc, $headerNames)) continue;

                            $postingDate = date('Y-m-d');
                            if ($postingDateIndex !== null && !empty(trim($row[$postingDateIndex] ?? ''))) {
                                $timestamp = strtotime(trim($row[$postingDateIndex]));
                                if ($timestamp !== false) $postingDate = date('Y-m-d', $timestamp);
                            }

                            $rawType = $postingTypeIndex !== null ? trim($row[$postingTypeIndex] ?? 'Transfer') : 'Transfer';
                            if (stripos($rawType, 'redeploy') !== false) {
                                $cleanType = 'Redeployment';
                            } elseif (stripos($rawType, 'init') !== false) {
                                $cleanType = 'Initial';
                            } else {
                                $cleanType = 'Transfer';
                            }

                            $parsedRows[] = [
                                'serviceNo' => $serviceNo,
                                'new_location' => $newLocation,
                                'new_zone' => $newZoneIndex !== null ? trim($row[$newZoneIndex] ?? '') : '',
                                'posting_date' => $postingDate,
                                'posting_type' => $cleanType
                            ];
                        }
                        fclose($handle);
                    }
                }
            }
        }
    } else {
        $error = "Please upload a valid CSV file or type Service Numbers into the manual input box.";
    }

    if (empty($error)) {
        if (empty($parsedRows)) {
            $error = "No valid data rows found in your input or file.";
        } else {
            // Deduplicate parsed rows by Service Number (Each Service Number appears ONCE per batch)
            $uniqueParsedRows = [];
            $seenServiceNumbers = [];
            foreach ($parsedRows as $r) {
                $sNo = trim($r['serviceNo']);
                if (!empty($sNo) && !in_array($sNo, $seenServiceNumbers)) {
                    $seenServiceNumbers[] = $sNo;
                    $uniqueParsedRows[] = $r;
                }
            }
            $parsedRows = $uniqueParsedRows;

            require_once __DIR__ . '/includes/security.php';

            $previewDetails = [];
            $validCount = 0;
            $invalidCount = 0;

            foreach ($parsedRows as $index => $row) {
                // RESET ALL LOOP VARIABLES ON EVERY SINGLE ITERATION
                $person = null;
                $emp = null;
                $validationError = '';
                $officerName = '';
                $officerRank = '';
                $currentLocation = '';

                $serviceNoClean = trim($row['serviceNo']);

                // 1. Fetch personal details strictly by exact serviceNo string
                $pQuery = $pdo->prepare("SELECT surname, firstName, middleName FROM tbl_emppersonal WHERE serviceNo = ? OR TRIM(serviceNo) = ? LIMIT 1");
                $pQuery->execute([$serviceNoClean, $serviceNoClean]);
                $pResult = $pQuery->fetch(PDO::FETCH_ASSOC);
                if (is_array($pResult) && !empty($pResult)) {
                    $person = $pResult;
                }

                // 2. Fetch employment details strictly by exact serviceNo string
                $eQuery = $pdo->prepare("SELECT currentRank, presentPosting, empStatus FROM tbl_employment WHERE serviceNo = ? OR TRIM(serviceNo) = ? LIMIT 1");
                $eQuery->execute([$serviceNoClean, $serviceNoClean]);
                $eResult = $eQuery->fetch(PDO::FETCH_ASSOC);
                if (is_array($eResult) && !empty($eResult)) {
                    $emp = $eResult;
                }

                if (!is_array($person) && !is_array($emp)) {
                    $validationError = "Officer with Service No '{$serviceNoClean}' not found in database.";
                } else {
                    $surname = is_array($person) ? ($person['surname'] ?? '') : '';
                    $firstName = is_array($person) ? ($person['firstName'] ?? '') : '';
                    $middleName = is_array($person) ? ($person['middleName'] ?? '') : '';

                    $officerName = trim("$surname $firstName $middleName");
                    if (empty($officerName)) $officerName = "Officer (NIS {$serviceNoClean})";

                    $officerRank = is_array($emp) && !empty($emp['currentRank']) ? $emp['currentRank'] : 'N/A';
                    $currentLocation = is_array($emp) && !empty($emp['presentPosting']) ? $emp['presentPosting'] : 'N/A';
                    $empStatus = is_array($emp) ? trim($emp['empStatus'] ?? '') : '';

                    $canonicalLocation = resolveCanonicalLocation($row['new_location'], $nis_formations);
                    if (!empty($canonicalLocation)) {
                        $row['new_location'] = $canonicalLocation;
                    }

                    if (!empty($empStatus) && in_array(strtolower($empStatus), ['retired', 'dismissed', 'deceased', 'inactive'])) {
                        $validationError = "Officer is not active (Status: " . ucfirst($empStatus) . ")";
                    }

                    if (empty($validationError) && function_exists('checkOfficerPostingEligibility')) {
                        $eligibility = checkOfficerPostingEligibility($pdo, $serviceNoClean);
                        if (!$eligibility['can_post']) {
                            $validationError = "POSTING BLOCKED (Disciplinary Guard): Officer is under disciplinary action ('" . htmlspecialchars($eligibility['disciplinary_status']) . "').";
                        }
                    }

                    if (empty($validationError) && !isDestinationAllowed($row['new_location'], $allowedPostingDestinations)) {
                        $validationError = "POSTING BLOCKED: You are not authorized to post officers to '" . htmlspecialchars($row['new_location']) . "'. Your account can only post within your assigned Command/Zone.";
                    }
                }

                $status = empty($validationError) ? 'pending' : 'failed';
                if ($status === 'pending') $validCount++; else $invalidCount++;

                $previewDetails[] = [
                    'id' => $index + 1,
                    'serviceNo' => $serviceNoClean,
                    'officer_name' => trim($officerName) ?: ("Officer (NIS " . $serviceNoClean . ")"),
                    'officer_rank' => $officerRank ?: 'N/A',
                    'current_location' => $currentLocation ?: 'N/A',
                    'new_location' => $row['new_location'],
                    'new_zone' => $row['new_zone'] ?? '',
                    'posting_date' => $row['posting_date'],
                    'posting_type' => $row['posting_type'],
                    'status' => $status,
                    'error_message' => $validationError
                ];
            }

            // STORE PREVIEW EPHEMERALLY IN SESSION (ZERO DATABASE HISTORY TOUCHED)
            $_SESSION['bulk_posting_preview'] = [
                'batch_name' => $batchName,
                'file_name' => $fileName,
                'total_rows' => count($previewDetails),
                'success_count' => $validCount,
                'failed_count' => $invalidCount,
                'details' => $previewDetails,
                'created_at' => date('Y-m-d H:i:s')
            ];

            header("Location: bulk?step=preview");
            exit();
        }
    }
}

// Auto-migration check for bulk_posting_batches document columns
try {
    $pdo->exec("ALTER TABLE bulk_posting_batches ADD COLUMN document_name VARCHAR(255) NULL, ADD COLUMN document_path VARCHAR(255) NULL");
} catch (Exception $e) {}

// ============================================
// STEP 3 (MOVEMENT TYPE): SET MOVEMENT TYPE FOR BATCH
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_movement_type']) && !validateCSRFToken($_POST['csrf_token'] ?? '')) {
    $error = "Security validation failed. Please refresh the page and try again.";
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_movement_type'])) {
    $chosenType = strtolower(trim($_POST['batch_posting_type'] ?? 'transfer'));
    if (!in_array($chosenType, ['transfer', 'redeployment', 'initial'])) {
        $chosenType = 'transfer';
    }
    if (isset($_SESSION['bulk_posting_preview']['details'])) {
        foreach ($_SESSION['bulk_posting_preview']['details'] as &$d) {
            $d['posting_type'] = ucfirst($chosenType);
        }
        unset($d);
    }
    header("Location: bulk?step=document");
    exit();
}

// ============================================
// STEP 3: PROCESS BATCH (MANDATORY DOCUMENT & SUCCESSFUL EXECUTION)
// ONLY NOW IS THE BATCH SAVED TO DATABASE & HISTORY!
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_batch']) && !validateCSRFToken($_POST['csrf_token'] ?? '')) {
    $error = "Security validation failed. Please refresh the page and try again.";
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_batch'])) {
    $previewData = $_SESSION['bulk_posting_preview'] ?? null;

    if (!$previewData || empty($previewData['details'])) {
        $error = "No active bulk posting preview session found. Please start a new bulk posting upload.";
        $step = 'upload';
    } elseif (!isset($_FILES['bulk_posting_document']) || $_FILES['bulk_posting_document']['error'] !== UPLOAD_ERR_OK) {
        $error = "POSTING BLOCKED: Posting Order Document (PDF, JPG, JPEG, or DOCX) is strictly required to execute bulk posting.";
        $step = 'document';
    } else {
        $docFile = $_FILES['bulk_posting_document'];
        $docExt = strtolower(pathinfo($docFile['name'], PATHINFO_EXTENSION));

        if (!in_array($docExt, ['pdf', 'jpg', 'jpeg', 'docx'])) {
            $error = "Invalid file format. Allowed formats: PDF, JPG, JPEG, DOCX.";
            $step = 'document';
        } elseif ($docFile['size'] > 10 * 1024 * 1024) {
            $error = "Posting Order Document size must be less than 10MB.";
            $step = 'document';
        } else {
            $uploadDir = __DIR__ . '/uploads/post_orders/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

            $uniqueDocName = 'bulk_batch_' . time() . '_' . uniqid() . '.' . $docExt;
            $docPathOnDisk = $uploadDir . $uniqueDocName;
            $relativeDocPath = 'uploads/post_orders/' . $uniqueDocName;

            if (!move_uploaded_file($docFile['tmp_name'], $docPathOnDisk)) {
                $error = "Failed to upload Posting Order Document to server.";
                $step = 'document';
            } else {
                try {
                    $pdo->beginTransaction();

                    $batchName = $previewData['batch_name'] ?? ('Bulk Posting - ' . date('d M Y H:i'));
                    $fileName = $previewData['file_name'] ?? 'Bulk Posting';

                    // NOW AND ONLY NOW SAVE BATCH TO DATABASE (AFTER SUCCESSFUL EXECUTION & DOCUMENT)
                    $stmt = $pdo->prepare("INSERT INTO bulk_posting_batches (batch_name, file_name, total_rows, success_count, failed_count, status, document_name, document_path, created_by, completed_at) VALUES (?, ?, ?, ?, ?, 'completed', ?, ?, ?, NOW())");
                    $stmt->execute([
                        $batchName,
                        $fileName,
                        $previewData['total_rows'],
                        0, // Updated below
                        0, // Updated below
                        $docFile['name'],
                        $relativeDocPath,
                        $userId
                    ]);
                    $batchId = $pdo->lastInsertId();

                    // Purge any pre-existing leftover details for this batch ID
                    $pdo->prepare("DELETE FROM bulk_posting_details WHERE batch_id = ?")->execute([$batchId]);

                    $pendingDetails = array_filter($previewData['details'], function($d) { return $d['status'] === 'pending'; });

                    $successCount = 0;
                    $failedCount = count($previewData['details']) - count($pendingDetails);
                    $smsSentCount = 0;
                    $emailSentCount = 0;

                    require_once __DIR__ . '/includes/security.php';

                    $detailInsertStmt = $pdo->prepare("INSERT INTO bulk_posting_details (batch_id, serviceNo, officer_name, officer_rank, current_location, new_location, new_zone, posting_date, posting_type, status, error_message) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

                    // 1. Log already failed rows to database details
                    foreach ($previewData['details'] as $detail) {
                        if ($detail['status'] !== 'pending') {
                            $detailInsertStmt->execute([
                                $batchId,
                                $detail['serviceNo'],
                                $detail['officer_name'],
                                $detail['officer_rank'],
                                $detail['current_location'],
                                $detail['new_location'],
                                $detail['new_zone'] ?? '',
                                $detail['posting_date'],
                                $detail['posting_type'],
                                'failed',
                                $detail['error_message']
                            ]);
                        }
                    }

                    // 2. Execute postings for valid pending rows
                    foreach ($pendingDetails as $detail) {
                        try {
                            $svcNoClean = trim($detail['serviceNo']);

                            if (function_exists('checkOfficerPostingEligibility')) {
                                $eligibility = checkOfficerPostingEligibility($pdo, $svcNoClean);
                                if (!$eligibility['can_post']) {
                                    $discStatus = $eligibility['disciplinary_status'];
                                    $detailInsertStmt->execute([
                                        $batchId, $svcNoClean, $detail['officer_name'], $detail['officer_rank'], $detail['current_location'],
                                        $detail['new_location'], $detail['new_zone'] ?? '', $detail['posting_date'], $detail['posting_type'],
                                        'failed', "Posting Blocked: Officer is under disciplinary action ($discStatus)"
                                    ]);
                                    $failedCount++;
                                    continue;
                                }
                            }

                            $pStmt = $pdo->prepare("SELECT surname, firstName FROM tbl_emppersonal WHERE serviceNo = ? OR TRIM(serviceNo) = ? LIMIT 1");
                            $pStmt->execute([$svcNoClean, $svcNoClean]);
                            $person = $pStmt->fetch(PDO::FETCH_ASSOC);

                            $eStmt = $pdo->prepare("SELECT currentRank FROM tbl_employment WHERE serviceNo = ? OR TRIM(serviceNo) = ? LIMIT 1");
                            $eStmt->execute([$svcNoClean, $svcNoClean]);
                            $emp = $eStmt->fetch(PDO::FETCH_ASSOC);

                            $officerName = trim(($person['surname'] ?? '') . ' ' . ($person['firstName'] ?? ''));
                            if (empty($officerName)) $officerName = $detail['officer_name'];
                            $officerRank = !empty($emp['currentRank']) ? $emp['currentRank'] : $detail['officer_rank'];

                            $postedByName = $_SESSION['full_name'] ?? $user['full_name'] ?? $_SESSION['username'] ?? 'System Administrator';
                            $createdById = $_SESSION['user_id'] ?? null;

                            // Insert history & update current posting
                            $pdo->prepare("INSERT INTO posting_history (serviceNo, posting_location, posting_date, posting_type, created_by, posted_by) VALUES (?, ?, ?, ?, ?, ?)")
                                ->execute([$svcNoClean, $detail['new_location'], $detail['posting_date'], strtolower($detail['posting_type'] ?? 'transfer'), $createdById, $postedByName]);

                            $checkStmt = $pdo->prepare("SELECT id FROM current_posting WHERE serviceNo = ?");
                            $checkStmt->execute([$svcNoClean]);
                            if ($checkStmt->fetch()) {
                                $pdo->prepare("UPDATE current_posting SET posting_location = ?, updated_at = NOW() WHERE serviceNo = ?")
                                    ->execute([$detail['new_location'], $svcNoClean]);
                            } else {
                                $pdo->prepare("INSERT INTO current_posting (serviceNo, posting_location) VALUES (?, ?)")
                                    ->execute([$svcNoClean, $detail['new_location']]);
                            }

                            $pdo->prepare("UPDATE tbl_employment SET presentPosting = ? WHERE serviceNo = ?")
                                ->execute([$detail['new_location'], $svcNoClean]);

                            try {
                                $pdo->prepare("INSERT INTO post_order_documents (serviceNo, file_name, file_path, file_type, file_size, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)")
                                    ->execute([
                                        $svcNoClean,
                                        "Bulk Order ($batchName): " . $docFile['name'],
                                        $relativeDocPath,
                                        $docExt,
                                        $docFile['size'],
                                        $user['full_name'] ?? $user['username']
                                    ]);
                            } catch (Exception $e) {}

                            // Real-Time Real-World Dispatch: SMS, Email & WhatsApp
                            $smsResult = $notificationService->sendPostingSMS($svcNoClean, $officerName, $detail['new_location'], $detail['posting_date']);
                            if ($smsResult['success']) $smsSentCount++;

                            $emailResult = $notificationService->sendPostingEmail($svcNoClean, $officerName, $officerRank, $detail['new_location'], $detail['posting_date']);
                            if ($emailResult['success']) $emailSentCount++;

                            $notificationService->sendPostingWhatsApp($svcNoClean, $officerName, $officerRank, $detail['new_location'], $detail['posting_date']);

                            $detailInsertStmt->execute([
                                $batchId, $svcNoClean, $officerName, $officerRank, $detail['current_location'],
                                $detail['new_location'], $detail['new_zone'] ?? '', $detail['posting_date'], $detail['posting_type'],
                                'success', null
                            ]);
                            $successCount++;

                        } catch (Exception $e) {
                            $detailInsertStmt->execute([
                                $batchId, $detail['serviceNo'], $detail['officer_name'], $detail['officer_rank'], $detail['current_location'],
                                $detail['new_location'], $detail['new_zone'] ?? '', $detail['posting_date'], $detail['posting_type'],
                                'failed', $e->getMessage()
                            ]);
                            $failedCount++;
                        }
                    }

                    $pdo->prepare("UPDATE bulk_posting_batches SET success_count = ?, failed_count = ? WHERE id = ?")
                        ->execute([$successCount, $failedCount, $batchId]);

                    $pdo->commit();

                    logSystemActivity('bulk_posting_executed', "Bulk posting batch '$batchName' (File: $fileName) executed: $successCount officers posted successfully ($failedCount failed) by $postedByName", $userId);

                    // Clear session preview once successfully completed
                    unset($_SESSION['bulk_posting_preview']);

                    if (file_exists(__DIR__ . '/includes/performance.php')) {
                        require_once __DIR__ . '/includes/performance.php';
                        $sc = new SimpleCache();
                        $sc->clear();
                    }

                    header("Location: bulk?step=result&batch_id=$batchId&sms=$smsSentCount&email=$emailSentCount");
                    exit();

                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $error = "Error executing batch: " . $e->getMessage();
                }
            }
        }
    }
}

// ============================================
// HANDLE BATCH RECALL
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['recall_batch_id']) && !validateCSRFToken($_POST['csrf_token'] ?? '')) {
    $error = "Security validation failed. Please refresh the page and try again.";
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['recall_batch_id'])) {
    $batchIdToRecall = intval($_POST['recall_batch_id']);
    if (in_array(strtolower($userRole), ['admin', 'service hq', 'shq admin', 'super admin', 'user'])) {
        try {
            $pdo->beginTransaction();
            $bStmt = $pdo->prepare("SELECT * FROM bulk_posting_batches WHERE id = ?");
            $bStmt->execute([$batchIdToRecall]);
            $targetBatch = $bStmt->fetch(PDO::FETCH_ASSOC);

            if ($targetBatch && $targetBatch['status'] !== 'recalled') {
                $dStmt = $pdo->prepare("SELECT * FROM bulk_posting_details WHERE batch_id = ? AND status = 'success'");
                $dStmt->execute([$batchIdToRecall]);
                $revertedCount = 0;

                while ($detail = $dStmt->fetch(PDO::FETCH_ASSOC)) {
                    $svcNo = $detail['serviceNo'];
                    $prevLoc = $detail['current_location'];
                    if (!empty($prevLoc) && $prevLoc !== 'N/A') {
                        $pdo->prepare("UPDATE tbl_employment SET presentPosting = ? WHERE serviceNo = ?")->execute([$prevLoc, $svcNo]);
                        $pdo->prepare("UPDATE current_posting SET posting_location = ?, updated_at = NOW() WHERE serviceNo = ?")->execute([$prevLoc, $svcNo]);
                        $revertedCount++;
                    }
                }

                $pdo->prepare("UPDATE bulk_posting_batches SET status = 'recalled' WHERE id = ?")->execute([$batchIdToRecall]);
                $pdo->prepare("UPDATE bulk_posting_details SET status = 'recalled' WHERE batch_id = ? AND status = 'success'")->execute([$batchIdToRecall]);

                $pdo->commit();
                $_SESSION['success_msg'] = "Batch #{$batchIdToRecall} recalled successfully! Reverted $revertedCount officer postings.";
                header('Location: bulk');
                exit();
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = "Error recalling batch: " . $e->getMessage();
        }
    }
}

// ============================================
// PREPARE VIEW DATA
// ============================================
$sessionPreview = $_SESSION['bulk_posting_preview'] ?? null;
$batchData = null;
$batchDetails = [];

if ($step === 'preview' || $step === 'movement_type' || $step === 'document') {
    if ($sessionPreview) {
        $batchData = [
            'id' => 'Preview',
            'batch_name' => $sessionPreview['batch_name'],
            'file_name' => $sessionPreview['file_name'],
            'total_rows' => $sessionPreview['total_rows'],
            'success_count' => $sessionPreview['success_count'],
            'failed_count' => $sessionPreview['failed_count'],
            'status' => 'pending',
            'created_at' => $sessionPreview['created_at']
        ];
        $batchDetails = $sessionPreview['details'];
    }
} elseif ($step === 'result' && isset($_GET['batch_id'])) {
    $batchId = intval($_GET['batch_id']);
    try {
        $stmt = $pdo->prepare("SELECT * FROM bulk_posting_batches WHERE id = ?");
        $stmt->execute([$batchId]);
        $batchData = $stmt->fetch(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("SELECT * FROM bulk_posting_details WHERE batch_id = ? AND status IN ('success', 'failed', 'recalled') ORDER BY id");
        $stmt->execute([$batchId]);
        $batchDetails = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

// FETCH ONLY COMPLETED / RECALLED BATCHES IN HISTORY
$batches = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM bulk_posting_batches WHERE created_by = ? AND status IN ('completed', 'recalled') ORDER BY completed_at DESC, created_at DESC LIMIT 20");
    $stmt->execute([$userId]);
    $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $batches = []; }

$smsSentCount = intval($_GET['sms'] ?? 0);
$emailSentCount = intval($_GET['email'] ?? 0);
?>

<?php include 'includes/header.php'; ?>

<style>
    .page-title {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 0.85rem 1.25rem;
        margin-bottom: 1.25rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .page-title h1 {
        font-size: 1.15rem;
        font-weight: 700;
        color: #1e293b;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    .page-title h1 i { color: #1a5632; }

    .card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        margin-bottom: 1.25rem;
        overflow: hidden;
    }
    .card-header {
        background: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
        padding: 0.75rem 1.25rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .card-header h3 { font-size: 0.85rem; margin: 0; font-weight: 600; color: #1a5632; }
    .card-body { padding: 1.25rem; }

    .form-group { margin-bottom: 1rem; }
    .form-group label {
        display: block;
        font-weight: 600;
        font-size: 0.8rem;
        color: #475569;
        margin-bottom: 0.35rem;
    }
    .form-control {
        width: 100%;
        padding: 0.5rem 0.75rem;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        font-size: 0.85rem;
        outline: none;
    }
    .form-control:focus { border-color: #1a5632; box-shadow: 0 0 0 3px rgba(26,86,50,0.1); }

    .btn {
        padding: 0.45rem 0.85rem;
        font-size: 0.8rem;
        font-weight: 600;
        border-radius: 8px;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        border: none;
        text-decoration: none;
        transition: all 0.15s ease;
    }
    .btn-success { background: #1a5632; color: #ffffff; }
    .btn-success:hover { background: #154628; }
    .btn-secondary { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }
    .btn-outline { background: #ffffff; color: #1a5632; border: 1px solid #1a5632; }
    .btn-danger { background: #ef4444; color: #ffffff; }

    .upload-area {
        border: 2px dashed #cbd5e1;
        border-radius: 12px;
        padding: 2rem 1.5rem;
        text-align: center;
        background: #f8fafc;
        cursor: pointer;
        transition: all 0.15s ease;
    }
    .upload-area:hover { border-color: #1a5632; background: #f0fdf4; }
    .upload-area i { font-size: 2.25rem; color: #1a5632; margin-bottom: 0.5rem; }
    .upload-area h3 { font-size: 0.9rem; margin-bottom: 0.25rem; color: #1e293b; font-weight: 700; }
    .upload-area p { font-size: 0.775rem; color: #64748b; margin: 0; }

    .steps-nav {
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 0.75rem 1.25rem;
        margin-bottom: 1.25rem;
    }
    .step-item { display: flex; align-items: center; gap: 0.5rem; flex: 1; }
    .step-number {
        width: 26px; height: 26px; border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        font-weight: 700; font-size: 0.75rem; background: #e2e8f0; color: #64748b;
    }
    .step-item.active .step-number { background: #1a5632; color: #ffffff; }
    .step-label { font-size: 0.8rem; font-weight: 600; color: #64748b; }
    .step-item.active .step-label { color: #1a5632; }
    .step-line { flex: 1; height: 2px; background: #e2e8f0; margin: 0 0.75rem; }
    .step-line.completed { background: #1a5632; }

    .data-table { width: 100%; border-collapse: collapse; font-size: 0.8rem; }
    .data-table th { background: #f8fafc; padding: 0.6rem 0.75rem; text-align: left; font-weight: 600; border-bottom: 1px solid #e2e8f0; color: #475569; }
    .data-table td { padding: 0.6rem 0.75rem; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    .data-table tr:hover { background: #f8fafc; }

    .status-badge { padding: 0.15rem 0.5rem; border-radius: 12px; font-size: 0.7rem; font-weight: 700; }
    .status-pending { background: #fef3c7; color: #92400e; }
    .status-success, .status-completed { background: #dcfce7; color: #166534; }
    .status-failed { background: #fee2e2; color: #991b1b; }

    .summary-stats { display: flex; gap: 1rem; margin-bottom: 1.25rem; }
    .summary-stat { flex: 1; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 0.75rem; text-align: center; }
    .summary-stat .value { font-size: 1.25rem; font-weight: 700; color: #1e293b; }
    .summary-stat .label { font-size: 0.725rem; color: #64748b; font-weight: 600; }

    .alert { padding: 0.75rem 1rem; border-radius: 8px; font-size: 0.8rem; margin-bottom: 1.25rem; }
    .alert-danger { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
    .alert-success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }

    .batch-list { display: flex; flex-direction: column; gap: 0.75rem; }
    .batch-card { border: 1px solid #e2e8f0; border-radius: 8px; padding: 0.75rem 1rem; display: flex; justify-content: space-between; align-items: center; background: #ffffff; }
    .batch-info h4 { margin: 0 0 0.2rem 0; font-size: 0.85rem; color: #1e293b; }
    .batch-info p { margin: 0; font-size: 0.75rem; color: #64748b; }
    .batch-stats { display: flex; align-items: center; gap: 1rem; }

    .btn-action-group {
        display: flex;
        gap: 0.75rem;
        align-items: center;
        flex-wrap: wrap;
        margin-top: 1.25rem;
    }
    .btn-upload-main, .btn-download-template {
        font-size: 0.875rem;
        padding: 0.6rem 1.25rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.45rem;
    }

    @media (max-width: 768px) {
        .btn-action-group {
            flex-direction: column;
            align-items: stretch;
            gap: 0.5rem;
            width: 100%;
        }
        .btn-upload-main, .btn-download-template {
            width: 100%;
            text-align: center;
            padding: 0.65rem 1rem;
        }
        .batch-card {
            flex-direction: column;
            align-items: flex-start;
            gap: 0.75rem;
        }
        .batch-stats {
            width: 100%;
            justify-content: space-between;
            flex-wrap: wrap;
        }
        .steps-nav {
            display: flex;
            align-items: center;
            overflow-x: auto;
            white-space: nowrap;
            padding: 0.75rem 0.6rem;
            gap: 0.35rem;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: thin;
        }
        .steps-nav::-webkit-scrollbar {
            height: 4px;
        }
        .steps-nav::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }
        .step-item {
            flex-shrink: 0;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
        }
        .step-line {
            flex: 0 0 16px;
            width: 16px;
            min-width: 16px;
            height: 2px;
            margin: 0 0.2rem;
            background: #e2e8f0;
        }
        .step-label {
            font-size: 0.725rem;
            white-space: nowrap;
        }
        .page-title {
            flex-direction: column;
            align-items: flex-start;
            gap: 0.75rem;
        }
        .page-title-actions {
            display: flex;
            gap: 0.5rem;
            width: 100%;
            flex-wrap: wrap;
        }
        .page-title-actions .btn {
            flex: 1;
            justify-content: center;
            text-align: center;
            white-space: nowrap;
            font-size: 0.8rem;
        }
    }
</style>

<div class="page-title">
    <h1><i class="fas fa-layer-group"></i> Bulk Officer Posting Engine</h1>
    <div class="page-title-actions">
        <a href="bulk?action=clear" class="btn btn-success"><i class="fas fa-plus"></i> New Batch Upload</a>
        <a href="bulk?step=history" class="btn btn-secondary"><i class="fas fa-history"></i> Batch History</a>
    </div>
</div>

<?php if (!empty($_SESSION['success_msg'])): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success_msg']); unset($_SESSION['success_msg']); ?></div>
<?php endif; ?>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<!-- STEPS NAVIGATION BAR -->
<div class="steps-nav">
    <div class="step-item <?php echo $step === 'upload' ? 'active' : ''; ?>">
        <div class="step-number">1</div>
        <span class="step-label">Upload / Input</span>
    </div>
    <div class="step-line <?php echo in_array($step, ['preview', 'movement_type', 'document', 'result']) ? 'completed' : ''; ?>"></div>
    <div class="step-item <?php echo $step === 'preview' ? 'active' : ''; ?>">
        <div class="step-number">2</div>
        <span class="step-label">Preview &amp; Validate</span>
    </div>
    <div class="step-line <?php echo in_array($step, ['movement_type', 'document', 'result']) ? 'completed' : ''; ?>"></div>
    <div class="step-item <?php echo $step === 'movement_type' ? 'active' : ''; ?>">
        <div class="step-number">3</div>
        <span class="step-label">Posting Type</span>
    </div>
    <div class="step-line <?php echo in_array($step, ['document', 'result']) ? 'completed' : ''; ?>"></div>
    <div class="step-item <?php echo $step === 'document' ? 'active' : ''; ?>">
        <div class="step-number">4</div>
        <span class="step-label">Posting Order Document</span>
    </div>
    <div class="step-line <?php echo $step === 'result' ? 'completed' : ''; ?>"></div>
    <div class="step-item <?php echo $step === 'result' ? 'active' : ''; ?>">
        <div class="step-number">5</div>
        <span class="step-label">Results</span>
    </div>
</div>

<!-- ============================================ -->
<!-- STEP 1: UPLOAD / MANUAL INPUT -->
<!-- ============================================ -->
<?php if ($step === 'upload'): ?>
<div class="card">
    <div class="card-header"><h3><i class="fas fa-file-csv"></i> Upload CSV or Input Service Numbers</h3></div>
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <div class="form-group">
                <label><i class="fas fa-tag"></i> Batch Name <small style="color:#64748b; font-weight:normal;">(Optional - Auto-generated if left blank)</small></label>
                <input type="text" name="batch_name" class="form-control" placeholder="e.g., Q3 2026 Rotational Postings">
            </div>

            <div class="upload-area" onclick="document.getElementById('csv_file').click()" id="dropZone">
                <i class="fas fa-cloud-upload-alt"></i>
                <h3>Click to Upload or Drag &amp; Drop CSV File</h3>
                <p>CSV format: <strong>S/N, SERVICE NO, NAME, RANK, PRESENT POSTING, NEW POSTING</strong></p>
                <input type="file" name="csv_file" id="csv_file" accept=".csv,.txt" style="display:none" onchange="updateFileName(this)">
                <p id="fileName" style="color:#1a5632;font-weight:600;margin-top:.5rem;"></p>
            </div>

            <div style="margin-top:1.25rem; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:1rem;">
                <h4 style="font-size:.85rem; margin-bottom:.4rem; color:#1e293b;"><i class="fas fa-keyboard" style="color:#1a5632;"></i> Or Enter / Paste Postings Directly (1, 10, or 100s of Officers)</h4>
                <p style="font-size:.775rem; color:#64748b; margin-bottom:.5rem;">Type or paste Service Number and New Location directly (one entry per line). Format: <code>SERVICE_NO, NEW_POSTING</code></p>
                <textarea name="manual_text" class="form-control" rows="4" style="font-family:monospace; font-size:0.8rem;" placeholder="e.g.&#10;42262, LAGOS&#10;35562, ZONE D&#10;39031, ZONE A"></textarea>
            </div>

            <div class="btn-action-group">
                <button type="submit" name="upload_file" class="btn btn-success btn-upload-main">
                    <i class="fas fa-upload"></i> Upload &amp; Validate Officers
                </button>
                <button type="button" class="btn btn-outline btn-download-template" onclick="downloadTemplate()"><i class="fas fa-download"></i> Download CSV Template</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ============================================ -->
<!-- STEP 2: PREVIEW & VALIDATE (EPHEMERAL UNTIL COMPLETED) -->
<!-- ============================================ -->
<?php if ($step === 'preview' && $batchData): 
    $rawTitle = trim($batchData['batch_name'] ?? '');
    if (empty($rawTitle) || preg_match('/^(.)\1+$/i', $rawTitle)) {
        $displayBatchTitle = 'New Bulk Posting (' . date('d M Y, H:i') . ')';
    } else {
        $displayBatchTitle = $rawTitle;
    }
?>
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-clipboard-check"></i> Preview &amp; Validate Batch <span style="font-size:0.8rem; background:#f0fdf4; color:#166534; padding:0.2rem 0.5rem; border-radius:6px; border:1px solid #bbf7d0; margin-left:0.4rem; font-weight:600;"><?php echo htmlspecialchars($displayBatchTitle); ?></span></h3>
        <span class="status-badge status-pending">Pending</span>
    </div>
    <div class="card-body">
        <div class="summary-stats">
            <div class="summary-stat">
                <div class="value"><?php echo count($batchDetails); ?></div>
                <div class="label">Total Officers</div>
            </div>
            <div class="summary-stat">
                <div class="value" style="color:#166534;"><?php echo $batchData['success_count']; ?></div>
                <div class="label">Valid Officers</div>
            </div>
            <div class="summary-stat">
                <div class="value" style="color:#991b1b;"><?php echo $batchData['failed_count']; ?></div>
                <div class="label">With Errors</div>
            </div>
        </div>

        <div style="overflow-x:auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Service No</th>
                        <th>Officer Name</th>
                        <th>Rank</th>
                        <th>Current Location</th>
                        <th>New Location</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th>Error</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($batchDetails as $i => $bd): 
                        $statusClass = $bd['status'] === 'pending' ? 'status-pending' : 'status-failed';
                        $statusLabel = $bd['status'] === 'pending' ? 'Valid' : 'Failed';
                    ?>
                    <tr>
                        <td><?php echo $i + 1; ?></td>
                        <td><code><?php echo htmlspecialchars($bd['serviceNo']); ?></code></td>
                        <td><strong><?php echo htmlspecialchars($bd['officer_name'] ?? 'Officer (NIS '.$bd['serviceNo'].')'); ?></strong></td>
                        <td><?php echo htmlspecialchars($bd['officer_rank'] ?? 'N/A'); ?></td>
                        <td><span style="color:#64748b;"><?php echo htmlspecialchars($bd['current_location'] ?? 'N/A'); ?></span></td>
                        <td><strong style="color:#1a5632;"><?php echo htmlspecialchars($bd['new_location']); ?></strong></td>
                        <td><?php echo date('d M Y', strtotime($bd['posting_date'])); ?></td>
                        <td><span class="status-badge <?php echo $statusClass; ?>"><?php echo $statusLabel; ?></span></td>
                        <td style="color:#991b1b; font-size:.75rem;"><?php echo htmlspecialchars($bd['error_message'] ?? ''); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="btn-action-group">
            <?php if ($batchData['success_count'] > 0): ?>
            <a href="bulk?step=movement_type" class="btn btn-success" style="font-size:0.85rem; padding:0.55rem 1rem;">
                <i class="fas fa-random"></i> Proceed to Select Posting Type &raquo;
            </a>
            <?php endif; ?>
            <a href="bulk?action=clear" class="btn btn-secondary">Cancel &amp; Start New Upload</a>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ============================================ -->
<!-- STEP 3: MOVEMENT TYPE SELECTION -->
<!-- ============================================ -->
<?php if ($step === 'movement_type' && $batchData): ?>
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-random"></i> Step 3: Select Posting Type for Batch</h3>
        <span class="status-badge status-pending">Pending</span>
    </div>
    <div class="card-body">
        <form method="POST" action="bulk">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <div style="max-width: 600px; margin-bottom: 1.5rem;">
                <div class="form-group">
                    <label style="font-weight: 700; color: #1e293b; font-size: 0.85rem; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.4rem;">
                        <i class="fas fa-exchange-alt" style="color:#1a5632;"></i> Posting Type
                    </label>
                    <select name="batch_posting_type" class="form-control" style="font-size: 0.875rem; padding: 0.6rem 0.85rem;" required>
                        <option value="transfer" selected>Transfer / General Posting</option>
                        <option value="redeployment">Redeployment</option>
                        <option value="initial">Initial Deployment (First Posting)</option>
                    </select>
                    <small style="color: #64748b; font-size: 0.775rem; margin-top: 0.45rem; display: block;">
                        Select the official Posting Type for all valid officer records in this bulk posting batch.
                    </small>
                </div>
            </div>

            <div class="btn-action-group">
                <a href="bulk?step=preview" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> &laquo; Back to Preview</a>
                <button type="submit" name="set_movement_type" class="btn btn-success" style="font-size:0.85rem; padding:0.55rem 1rem;">
                    <i class="fas fa-file-upload"></i> Save Posting Type &amp; Proceed &raquo;
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ============================================ -->
<!-- STEP 4: POSTING ORDER DOCUMENT -->
<!-- ============================================ -->
<?php if ($step === 'document' && $batchData): ?>
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-file-pdf"></i> Step 4: Attach Posting Order Document</h3>
    </div>
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            
            <div style="background:#fff7ed; border:1px solid #ffedd5; border-left:4px solid #f97316; border-radius:8px; padding:1rem; margin-bottom:1.25rem;">
                <h4 style="margin:0 0 .3rem 0; font-size:.85rem; color:#c2410c;"><i class="fas fa-shield-alt"></i> Mandatory</h4>
                <p style="margin:0; font-size:.775rem; color:#9a3412;">Attach an official signed Posting Order Document (PDF, JPG, JPEG, or DOCX).</p>
            </div>

            <div class="form-group">
                <label><i class="fas fa-file-upload"></i> Select Posting Order File</label>
                <input type="file" name="bulk_posting_document" class="form-control" accept=".pdf,.jpg,.jpeg,.docx" required>
            </div>

            <button type="submit" name="process_batch" class="btn btn-success" style="font-size:.9rem; padding:.6rem 1.25rem; margin-top:.5rem;" onclick="return confirm('Confirm Posting Order Document & Execute Bulk Posting Batch? Notifications will be dispatched to officers.');">
                <i class="fas fa-check-double"></i> Confirm &amp; Execute Bulk Posting Batch
            </button>
            <a href="bulk?action=clear" class="btn btn-secondary" style="margin-left:0.5rem;">Cancel</a>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ============================================ -->
<!-- STEP 4: RESULTS -->
<!-- ============================================ -->
<?php if ($step === 'result' && $batchData): ?>
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-check-circle"></i> Bulk Posting Batch #<?php echo $batchData['id']; ?>Results</h3>
        <span class="status-badge status-completed">Completed</span>
    </div>
    <div class="card-body">
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> Bulk Posting Batch #<?php echo $batchData['id']; ?> executed successfully! Postings updated for <?php echo $batchData['success_count']; ?> officers.
        </div>

        <?php if (!empty($batchData['document_name'])): ?>
        <div style="margin-bottom:1rem; padding:0.85rem 1.25rem; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:0.5rem;">
            <div>
                <strong style="color:#166534; font-size:0.85rem;"><i class="fas fa-file-signature"></i> Bulk Posting Order Document:</strong>
                <span style="font-size:0.8rem; color:#334155; margin-left:0.35rem; font-weight:600;"><?php echo htmlspecialchars($batchData['document_name']); ?></span>
            </div>
            <?php if (!empty($batchData['document_path'])): ?>
            <a href="<?php echo htmlspecialchars($batchData['document_path']); ?>" target="_blank" class="btn btn-sm btn-success"><i class="fas fa-file-alt"></i> View Posting Order</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($smsSentCount > 0 || $emailSentCount > 0): ?>
        <div class="alert alert-success" style="background:#f8fafc; border-color:#cbd5e1; color:#334155;">
            <i class="fas fa-bell"></i> <strong>Notifications Summary:</strong> 
            <?php if ($smsSentCount > 0) echo "<span><i class='fas fa-sms'></i> $smsSentCount SMS</span> "; ?>
            <?php if ($emailSentCount > 0) echo "<span><i class='fas fa-envelope'></i> $emailSentCount Emails</span>"; ?>
        </div>
        <?php endif; ?>

        <div style="overflow-x:auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Service No</th>
                        <th>Officer Name</th>
                        <th>New Location</th>
                        <th>Status</th>
                        <th>Error</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($batchDetails as $i => $bd): ?>
                    <tr>
                        <td><?php echo $i + 1; ?></td>
                        <td><code><?php echo htmlspecialchars($bd['serviceNo']); ?></code></td>
                        <td><strong><?php echo htmlspecialchars($bd['officer_name'] ?? 'Officer (NIS '.$bd['serviceNo'].')'); ?></strong></td>
                        <td><strong style="color:#1a5632;"><?php echo htmlspecialchars($bd['new_location']); ?></strong></td>
                        <td><span class="status-badge status-<?php echo $bd['status']; ?>"><?php echo ucfirst($bd['status']); ?></span></td>
                        <td style="color:#991b1b; font-size:.75rem;"><?php echo htmlspecialchars($bd['error_message'] ?? ''); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="btn-action-group">
            <a href="bulk?action=clear" class="btn btn-success"><i class="fas fa-plus"></i> New Bulk Upload</a>
            <a href="bulk?step=history" class="btn btn-secondary"><i class="fas fa-history"></i> View History</a>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ============================================ -->
<!-- BATCH HISTORY VIEW (COMPLETED & RECALLED ONLY) -->
<!-- ============================================ -->
<?php if ($step === 'history' || (empty($batchData) && $step === 'upload' && !empty($batches))): ?>
<div class="card" style="margin-top:1.5rem;">
    <div class="card-header"><h3><i class="fas fa-history"></i> Bulk Posting Batch History</h3></div>
    <div class="card-body">
        <?php if (!empty($batches)): ?>
        <div class="batch-list">
            <?php foreach ($batches as $batch): ?>
            <div class="batch-card">
                <div class="batch-info">
                    <h4>Batch #<?php echo $batch['id']; ?>: <?php echo htmlspecialchars($batch['batch_name']); ?></h4>
                    <p><i class="far fa-file-alt"></i> <?php echo htmlspecialchars($batch['file_name'] ?? 'N/A'); ?> &bull; <?php echo date('d M Y, H:i', strtotime($batch['completed_at'] ?? $batch['created_at'])); ?></p>
                </div>
                <div class="batch-stats">
                    <div style="display:flex; align-items:center; gap:0.4rem; font-size:0.75rem;">
                        <span style="color:#166534; font-weight:600;"><i class="fas fa-check-circle"></i> <?php echo $batch['success_count']; ?></span>
                        <span style="color:#991b1b; font-weight:600;"><i class="fas fa-times-circle"></i> <?php echo $batch['failed_count']; ?></span>
                        <span class="status-badge status-<?php echo $batch['status']; ?>"><?php echo ucfirst($batch['status']); ?></span>
                    </div>
                    <div class="batch-actions-group" style="display:flex; gap:0.35rem;">
                        <a href="bulk?step=result&batch_id=<?php echo $batch['id']; ?>" class="btn btn-secondary" style="font-size:.75rem; padding:0.3rem 0.5rem;" title="View Details">
                            <i class="fas fa-eye"></i> View
                        </a>

                        <?php if (in_array(strtolower($userRole), ['admin', 'service hq', 'shq admin', 'super admin', 'user']) && $batch['status'] === 'completed'): ?>
                        <form method="POST" style="display:inline; margin:0;" onsubmit="return confirm('WARNING: Recalling this batch will revert all officer postings in this batch back to their previous locations. Proceed?');">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                            <input type="hidden" name="recall_batch_id" value="<?php echo $batch['id']; ?>">
                            <button type="submit" class="btn" style="font-size:.75rem; padding:0.3rem 0.5rem; background:#fff7ed; color:#c2410c; border:1px solid #ffedd5;" title="Recall Batch & Revert Postings">
                                <i class="fas fa-undo"></i> Recall
                            </button>
                        </form>
                        <?php endif; ?>

                        <form method="POST" style="display:inline; margin:0;" onsubmit="return confirm('Are you sure you want to delete this bulk posting batch history?');">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                            <input type="hidden" name="batch_id" value="<?php echo $batch['id']; ?>">
                            <button type="submit" name="delete_batch" class="btn btn-danger" style="font-size:.75rem; padding:0.3rem 0.5rem;" title="Delete Batch History">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div style="text-align:center; padding:2rem; color:#64748b;">
            <i class="fas fa-inbox" style="font-size:1.75rem; display:block; margin-bottom:.5rem;"></i>
            <h4>No Bulk Posting History</h4>
            <p>Upload a CSV file or enter Service Numbers to begin bulk posting.</p>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

</div><!-- main-content -->
</div><!-- content-wrapper -->
</div><!-- dashboard-container -->

<script>
function updateFileName(input) {
    const fileName = document.getElementById('fileName');
    if (input.files && input.files[0]) {
        fileName.textContent = 'Selected File: ' + input.files[0].name;
    }
}

const dropZone = document.getElementById('dropZone');
if (dropZone) {
    dropZone.addEventListener('dragover', function(e) {
        e.preventDefault();
        dropZone.style.borderColor = '#1a5632';
        dropZone.style.background = '#f0fdf4';
    });
    dropZone.addEventListener('dragleave', function() {
        dropZone.style.borderColor = '#cbd5e1';
        dropZone.style.background = '#f8fafc';
    });
    dropZone.addEventListener('drop', function(e) {
        e.preventDefault();
        dropZone.style.borderColor = '#cbd5e1';
        dropZone.style.background = '#f8fafc';
        const files = e.dataTransfer.files;
        if (files.length > 0) {
            document.getElementById('csv_file').files = files;
            updateFileName({files: files});
        }
    });
}

function downloadTemplate() {
    const csvContent = "S/N,SERVICE NO,NAME,RANK,PRESENT POSTING,NEW POSTING\n";
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'bulk_posting_template.csv';
    a.click();
    window.URL.revokeObjectURL(url);
}
</script>

<?php include 'includes/footer.php'; ?>

</body>
</html>
