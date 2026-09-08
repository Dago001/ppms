<?php
session_start();
require_once 'includes/config.php';
require_once 'includes/auth.php';
require_once 'includes/permissions.php';
require_once 'includes/NotificationService.php';

if (!isLoggedIn()) { header('Location: login.php'); exit(); }

if (!hasPermission('bulk_posting')) {
    header("HTTP/1.1 403 Forbidden");
    echo "<div style='font-family:sans-serif; text-align:center; padding:3rem;'>
            <h1 style='color:#dc2626;'>403 Forbidden - Access Denied</h1>
            <p>You do not have permission ('bulk_posting') to perform bulk CSV batch postings.</p>
            <a href='dashboard' style='display:inline-block; margin-top:1rem; padding:0.5rem 1rem; background:#1a5632; color:#fff; text-decoration:none; border-radius:6px;'>Return to Dashboard</a>
          </div>";
    exit();
}

// Get user info
$user = [];
try {
    $stmt = $pdo->prepare("SELECT u.*, r.name as role_name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
} catch (Exception $e) {
    $user = ['username' => $_SESSION['username'] ?? 'User', 'full_name' => 'User', 'role_name' => 'User'];
}
if (empty($user)) {
    $user = ['username' => $_SESSION['username'] ?? 'User', 'full_name' => $_SESSION['username'] ?? 'User', 'role_name' => 'User'];
}

// Initialize NotificationService
$notificationService = new NotificationService($pdo);

$userRole = $user['role_name'] ?? '';
$userId = $_SESSION['user_id'] ?? 0;
$error = '';
$success = '';
$step = $_GET['step'] ?? 'upload'; // upload, preview, process, result

// Get user zones for zone assignment
$userZones = [];
if (in_array($userRole, ['admin', 'Service HQ', 'Super Admin'])) {
    try {
        $userZones = $pdo->query("SELECT id, zone_name, zone_code FROM zones ORDER BY zone_name")->fetchAll();
    } catch (Exception $e) { $userZones = []; }
} else {
    try {
        $stmt = $pdo->prepare("SELECT z.id, z.zone_name, z.zone_code FROM user_zones uz JOIN zones z ON uz.zone_id = z.id WHERE uz.user_id = ?");
        $stmt->execute([$userId]);
        $userZones = $stmt->fetchAll();
    } catch (Exception $e) { $userZones = []; }
}

// Get NIS formations list - matched with enrollment portal values
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
        'IMSC' => 'Imo State Command',
        'RVSC' => 'Rivers State Command',
        'CRSC' => 'Cross River State Command',
        'EBSC' => 'Ebonyi State Command',
        'AKSC' => 'Akwa Ibom State Command',
        'NITSOL' => 'NITSOL',
        'RVMC' => 'River Marine Command',
        'MFBC' => 'Mfum Border Command',
        'NITSA' => 'NITSA',
        'PHIA' => 'PHIA',
        'AIIA' => 'AIIA'
    ],
    'ZONE F' => [
        'Zone F HQ Ibadan' => 'Zone F Ibadan',
        'OYSC' => 'Oyo State Command',
        'EKSC' => 'Ekiti State Command',
        'ODSC' => 'Ondo State Command',
        'OSSC' => 'Osun State Command'
    ],
    'ZONE G' => [
        'Zone G HQ Benin' => 'Zone G Benin City',
        'EDSC' => 'Edo State Command',
        'ANSC' => 'Anambra State Command',
        'DTSC' => 'Delta State Command',
        'ENSC' => 'Enugu State Command',
        'BYSC' => 'Bayelsa State Command',
        'NAIA' => 'NAIA'
    ],
    'ZONE H' => [
        'Zone H HQ Makurdi' => 'Zone H Makurdi',
        'NASC' => 'Nasarawa State Command',
        'BNSC' => 'Benue State Command',
        'KGSC' => 'Kogi State Command',
        'TRSC' => 'Taraba State Command',
        'Abia SC' => 'Abia State Command'
    ]
];

// ============================================
// HANDLE BATCH DELETION
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_batch'])) {
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
// HANDLE FILE UPLOAD
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_file'])) {
    $parsedRows = [];
    $rawBatchName = trim($_POST['batch_name'] ?? '');
    if (empty($rawBatchName) || preg_match('/^(.)\1+$/', $rawBatchName)) {
        $batchName = 'Bulk Posting - ' . date('d M Y H:i');
    } else {
        $batchName = sanitizeInput($rawBatchName);
    }
    $fileName = 'Bulk Posting';

    // 1. Process Manual Direct Text Input (if provided)
    $manualText = trim($_POST['manual_text'] ?? '');
    if (!empty($manualText)) {
        $fileName = 'Direct Manual Input';
        $lines = explode("\n", $manualText);
        $seenServiceNumbers = []; // Track unique service numbers
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            $parts = preg_split('/[,|\t]/', $line);
            $serviceNo = trim($parts[0] ?? '');
            $newLocation = trim($parts[1] ?? '');
            
            if (empty($serviceNo) || empty($newLocation)) continue;
            if (strcasecmp($serviceNo, 'SERVICE NO') === 0 || strcasecmp($serviceNo, 'SERVICENO') === 0 || strcasecmp($serviceNo, 'SERVICE_NO') === 0 || strcasecmp($serviceNo, 'S/N') === 0) continue;
            if (strcasecmp($newLocation, 'NEW POSTING') === 0 || strcasecmp($newLocation, 'NEW_POSTING') === 0) continue;
            
            // Check for duplicate service number
            $serviceNoKey = strtoupper(trim($serviceNo));
            if (isset($seenServiceNumbers[$serviceNoKey])) {
                continue; // Skip duplicate
            }
            $seenServiceNumbers[$serviceNoKey] = true;
            
            $parsedRows[] = [
                'serviceNo' => $serviceNo,
                'new_location' => $newLocation,
                'new_zone' => trim($parts[2] ?? ''),
                'posting_date' => date('Y-m-d'),
                'posting_type' => 'Transfer'
            ];
        }
    } 
    // 2. Process CSV File Upload (if provided)
    elseif (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['csv_file'];
        $fileName = $file['name'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (!in_array($ext, ['csv', 'txt'])) {
            $error = "Only CSV files are allowed.";
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
                    
                    foreach ($headers as $i => $h) {
                        $cleanH = strtolower(trim(str_replace([' ', '-', '.', '/', '_'], '', $h)));
                        if (($cleanH === 'serviceno' || $cleanH === 'servicen' || strpos($cleanH, 'service') !== false) && strpos($cleanH, 'present') === false && strpos($cleanH, 'current') === false && $cleanH !== 'sn') {
                            $serviceNoIndex = $i;
                        } elseif (strpos($cleanH, 'newposting') !== false || strpos($cleanH, 'newlocation') !== false || ($cleanH === 'newposting')) {
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
                        $error = "Missing required headers. Your CSV must have columns for 'SERVICE NO' (or 'SERVICE N') and 'NEW POSTING'.";
                    } else {
                        $headerNames = ['sn', 's/n', 'service no', 'service_no', 'serviceno', 'service n', 'service', 'name', 'rank', 'present posting', 'new posting', 'posting location', 'posting date'];
                        $seenServiceNumbers = []; // Track unique service numbers from CSV
                        
                        while (($row = fgetcsv($handle)) !== false) {
                            if (empty(array_filter($row, function($v) { return trim($v) !== ''; }))) continue;
                            
                            $serviceNo = trim($row[$serviceNoIndex] ?? '');
                            $newLocation = trim($row[$newLocationIndex] ?? '');
                            if (empty($newLocation) && isset($row[$newLocationIndex + 1])) {
                                $newLocation = trim($row[$newLocationIndex + 1]);
                            }
                            
                            if (empty($serviceNo) || empty($newLocation)) continue;
                            
                            // Skip header values or invalid short strings
                            $cleanVal = strtolower(trim($serviceNo));
                            if (in_array($cleanVal, $headerNames) || strlen(preg_replace('/[^a-zA-Z0-9]/', '', $serviceNo)) < 2) continue;
                            
                            $cleanLoc = strtolower(trim($newLocation));
                            if (in_array($cleanLoc, $headerNames)) continue;
                            
                            // Check for duplicate service number - THIS IS THE KEY FIX
                            $serviceNoKey = strtoupper(trim($serviceNo));
                            if (isset($seenServiceNumbers[$serviceNoKey])) {
                                continue; // Skip duplicate service number
                            }
                            $seenServiceNumbers[$serviceNoKey] = true;
                            
                            // Parse posting date
                            $postingDate = date('Y-m-d'); // Default to today
                            if ($postingDateIndex !== null && !empty(trim($row[$postingDateIndex] ?? ''))) {
                                $rawDate = trim($row[$postingDateIndex]);
                                // Try to validate and format the date
                                $timestamp = strtotime($rawDate);
                                if ($timestamp !== false) {
                                    $postingDate = date('Y-m-d', $timestamp);
                                }
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
                        
                        if (empty($parsedRows)) {
                            $error = "No valid data rows found in the CSV file.";
                        } else {
                            // Create batch
                            try {
                                $pdo->beginTransaction();
                                
                                $stmt = $pdo->prepare("INSERT INTO bulk_posting_batches (batch_name, file_name, total_rows, created_by) VALUES (?, ?, ?, ?)");
                                $stmt->execute([$batchName, $fileName, count($parsedRows), $userId]);
                                $batchId = $pdo->lastInsertId();
                                
                                // Insert all rows for preview
                                $stmt = $pdo->prepare("INSERT INTO bulk_posting_details (batch_id, serviceNo, new_location, new_zone, posting_date, posting_type) VALUES (?, ?, ?, ?, ?, ?)");
                                foreach ($parsedRows as $row) {
                                    $stmt->execute([$batchId, $row['serviceNo'], $row['new_location'], $row['new_zone'], $row['posting_date'], $row['posting_type']]);
                                }
                                
                                // Validate each row against database using exact search.php & edit_posting.php lookup logic
                                $updateStmt = $pdo->prepare("UPDATE bulk_posting_details SET officer_name = ?, officer_rank = ?, current_location = ?, status = ?, error_message = ? WHERE id = ?");
                                
                                $detailsStmt = $pdo->prepare("SELECT bd.* FROM bulk_posting_details bd WHERE bd.batch_id = ?");
                                $detailsStmt->execute([$batchId]);
                                $detailsRows = $detailsStmt->fetchAll();
                                
                                $validCount = 0;
                                $invalidCount = 0;
                                
                                foreach ($detailsRows as $detail) {
                                    // Reset all loop variables to prevent state leakage between rows
                                    $person = null;
                                    $emp = null;
                                    $validationError = '';
                                    $officerName = '';
                                    $officerRank = '';
                                    $currentLocation = '';
                                    
                                    $serviceNoClean = trim($detail['serviceNo']);
                                    
                                    // 1. Fetch personal details using exact search.php & edit_posting.php query
                                    $pQuery = $pdo->prepare("SELECT surname, firstName, middleName FROM tbl_emppersonal WHERE serviceNo = ? OR TRIM(serviceNo) = ? LIMIT 1");
                                    $pQuery->execute([$serviceNoClean, $serviceNoClean]);
                                    $pResult = $pQuery->fetch(PDO::FETCH_ASSOC);
                                    if (is_array($pResult) && !empty($pResult)) {
                                        $person = $pResult;
                                    }
                                    
                                    // 2. Fetch employment details using exact search.php & edit_posting.php query
                                    $eQuery = $pdo->prepare("SELECT currentRank, presentPosting, empStatus FROM tbl_employment WHERE serviceNo = ? OR TRIM(serviceNo) = ? LIMIT 1");
                                    $eQuery->execute([$serviceNoClean, $serviceNoClean]);
                                    $eResult = $eQuery->fetch(PDO::FETCH_ASSOC);
                                    if (is_array($eResult) && !empty($eResult)) {
                                        $emp = $eResult;
                                    }
                                    
                                    if (!is_array($person) && !is_array($emp)) {
                                        $validationError = "Officer with Service No '{$detail['serviceNo']}' not found in database.";
                                    } else {
                                        $surname = is_array($person) ? ($person['surname'] ?? '') : '';
                                        $firstName = is_array($person) ? ($person['firstName'] ?? '') : '';
                                        $middleName = is_array($person) ? ($person['middleName'] ?? '') : '';
                                        
                                        $officerName = trim("$surname $firstName $middleName");
                                        if (empty($officerName)) $officerName = "Officer (NIS {$serviceNoClean})";
                                        
                                        $officerRank = is_array($emp) && !empty($emp['currentRank']) ? $emp['currentRank'] : 'N/A';
                                        $currentLocation = is_array($emp) && !empty($emp['presentPosting']) ? $emp['presentPosting'] : 'N/A';
                                        $empStatus = is_array($emp) ? trim($emp['empStatus'] ?? '') : '';
                                        
                                        // Validate new location exists in NIS formations (with smart fuzzy matching)
                                        $locationFound = false;
                                        $canonicalLocation = $detail['new_location'];

                                        foreach ($nis_formations as $zone => $commands) {
                                            foreach ($commands as $code => $name) {
                                                if (strcasecmp($name, $detail['new_location']) === 0 || strcasecmp($code, $detail['new_location']) === 0) {
                                                    $locationFound = true;
                                                    $canonicalLocation = $name;
                                                    break 2;
                                                }
                                                if (stripos($name, $detail['new_location']) !== false || stripos($detail['new_location'], $name) !== false) {
                                                    $locationFound = true;
                                                    $canonicalLocation = $name;
                                                    break 2;
                                                }
                                                if (stripos($code, $detail['new_location']) !== false || stripos($detail['new_location'], $code) !== false) {
                                                    $locationFound = true;
                                                    $canonicalLocation = $name;
                                                    break 2;
                                                }
                                            }
                                        }

                                        if (!$locationFound) {
                                            // Advanced fuzzy match for terms like "LAGOS COMMAND", "KANO COMMAND", "RIVERS COMMAND", etc.
                                            $stopwords = ['state', 'command', 'hq', 'hqtrs', 'headquarters', 'border', 'passport', 'seaport', 'marine', 'patrol'];
                                            $inputWords = array_values(array_diff(explode(' ', strtolower(preg_replace('/[^a-z0-9 ]/i', '', $detail['new_location']))), $stopwords));
                                            $inputCore = implode(' ', $inputWords);
                                            
                                            if (!empty($inputCore)) {
                                                foreach ($nis_formations as $zone => $commands) {
                                                    foreach ($commands as $code => $name) {
                                                        $nameWords = array_values(array_diff(explode(' ', strtolower(preg_replace('/[^a-z0-9 ]/i', '', $name))), $stopwords));
                                                        $nameCore = implode(' ', $nameWords);
                                                        
                                                        if (!empty($nameCore) && (stripos($nameCore, $inputCore) !== false || stripos($inputCore, $nameCore) !== false)) {
                                                            $locationFound = true;
                                                            $canonicalLocation = $name;
                                                            break 2;
                                                        }
                                                    }
                                                }
                                            }
                                        }

                                        if (!$locationFound) {
                                            if (!empty(trim($detail['new_location']))) {
                                                $canonicalLocation = trim($detail['new_location']);
                                                $locationFound = true;
                                                $detail['new_location'] = $canonicalLocation;
                                                $pdo->prepare("UPDATE bulk_posting_details SET new_location = ? WHERE id = ?")->execute([$canonicalLocation, $detail['id']]);
                                            } else {
                                                $validationError = "Location '{$detail['new_location']}' not found in NIS formations list";
                                            }
                                        } else {
                                            $detail['new_location'] = $canonicalLocation;
                                            $pdo->prepare("UPDATE bulk_posting_details SET new_location = ? WHERE id = ?")->execute([$canonicalLocation, $detail['id']]);
                                        }
                                        
                                        // Check if officer is retired/dismissed/inactive
                                        if (!empty($empStatus) && in_array(strtolower($empStatus), ['retired', 'dismissed', 'deceased', 'inactive'])) {
                                            $validationError = "Officer is not active (Status: " . ucfirst($empStatus) . ")";
                                        }
                                        
                                        // Check Disciplinary Guard
                                        require_once __DIR__ . '/includes/security.php';
                                        $eligibility = checkOfficerPostingEligibility($pdo, $detail['serviceNo']);
                                        if (!$eligibility['can_post']) {
                                            $validationError = "POSTING BLOCKED (Disciplinary Guard): Officer is currently under disciplinary action ('" . htmlspecialchars($eligibility['disciplinary_status']) . "').";
                                        }
                                        
                                        // Check zone permission for non-admin users
                                        if (empty($validationError) && !in_array($userRole, ['admin', 'Service HQ', 'Super Admin']) && !empty($userZones)) {
                                            // Get user's assigned commands for granular permission check
                                            $userAssignedCommands = [];
                                            try {
                                                $cmdStmt = $pdo->prepare("SELECT uz.assigned_command, z.zone_name FROM user_zones uz JOIN zones z ON uz.zone_id = z.id WHERE uz.user_id = ?");
                                                $cmdStmt->execute([$userId]);
                                                $userCmdAssignments = $cmdStmt->fetchAll(PDO::FETCH_ASSOC);
                                                
                                                $zoneOk = false;
                                                foreach ($userCmdAssignments as $assignment) {
                                                    if (!empty($assignment['assigned_command'])) {
                                                        // Check if new location matches specific command
                                                        if (stripos($detail['new_location'], $assignment['assigned_command']) !== false ||
                                                            stripos($assignment['assigned_command'], $detail['new_location']) !== false) {
                                                            $zoneOk = true;
                                                            break;
                                                        }
                                                    } else {
                                                        // All commands in zone - check if location is in this zone
                                                        // Expand zone to all commands
                                                        $zoneCommands = [];
                                                        foreach ($nis_formations as $zoneKey => $commands) {
                                                            if (stripos($zoneKey, $assignment['zone_name']) !== false || 
                                                                stripos($assignment['zone_name'], $zoneKey) !== false) {
                                                                $zoneCommands = array_values($commands);
                                                                break;
                                                            }
                                                        }
                                                        foreach ($zoneCommands as $cmd) {
                                                            if (stripos($detail['new_location'], $cmd) !== false ||
                                                                stripos($cmd, $detail['new_location']) !== false) {
                                                                $zoneOk = true;
                                                                break 2;
                                                            }
                                                        }
                                                        // Also check zone name directly
                                                        if (stripos($detail['new_location'], $assignment['zone_name']) !== false) {
                                                            $zoneOk = true;
                                                            break;
                                                        }
                                                    }
                                                }
                                            } catch (Exception $e) {
                                                $zoneOk = true; // Allow on error to prevent blocking
                                            }
                                            
                                            if (!$zoneOk) {
                                                $validationError = "You don't have permission to post to this location. Your assigned formations don't include '{$detail['new_location']}'.";
                                            }
                                        }
                                    }
                                    
                                    $status = empty($validationError) ? 'pending' : 'failed';
                                    if ($status === 'pending') $validCount++; else $invalidCount++;
                                    
                                    $updateStmt->execute([
                                        trim($officerName) ?: null,
                                        $officerRank ?: null,
                                        $currentLocation ?: null,
                                        $status,
                                        $validationError ?: null,
                                        $detail['id']
                                    ]);
                                }
                                
                                // Update batch counts
                                $pdo->prepare("UPDATE bulk_posting_batches SET success_count = ?, failed_count = ?, status = 'pending' WHERE id = ?")
                                    ->execute([$validCount, $invalidCount, $batchId]);
                                
                                $pdo->commit();
                                
                                header("Location: bulk_posting.php?step=preview&batch_id=$batchId");
                                exit();
                                
                            } catch (Exception $e) {
                                $pdo->rollBack();
                                $error = "Error processing file: " . $e->getMessage();
                            }
                        }
                    }
                }
            }
        }
    }
}

// Auto-migration check for bulk_posting_batches document columns
try {
    $pdo->exec("ALTER TABLE bulk_posting_batches ADD COLUMN document_name VARCHAR(255) NULL, ADD COLUMN document_path VARCHAR(255) NULL");
} catch (Exception $e) {}

// ============================================
// HANDLE PROCESS BATCH (Execute Postings with Mandatory Master Posting Order Document)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_batch'])) {
    $batchId = intval($_POST['batch_id']);
    
    // Mandatory Posting Order Document Validation for Bulk Posting
    if (!isset($_FILES['bulk_posting_document']) || $_FILES['bulk_posting_document']['error'] !== UPLOAD_ERR_OK) {
        $error = "POSTING BLOCKED: A master Posting Order Document (PDF, JPG, JPEG, or DOCX) is strictly required to execute bulk posting.";
        $step = 'document';
    } else {
        $docFile = $_FILES['bulk_posting_document'];
        $docExt = strtolower(pathinfo($docFile['name'], PATHINFO_EXTENSION));
        if (!in_array($docExt, ['pdf', 'jpg', 'jpeg', 'docx'])) {
            $error = "Invalid file format. Allowed: PDF, JPG, JPEG, DOCX.";
            $step = 'document';
        } elseif ($docFile['size'] > 10 * 1024 * 1024) {
            $error = "Posting Order Document size must be less than 10MB.";
            $step = 'document';
        } else {
            $uploadDir = __DIR__ . '/uploads/post_orders/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            
            $uniqueDocName = 'bulk_batch_' . $batchId . '_' . time() . '_' . uniqid() . '.' . $docExt;
            $docPathOnDisk = $uploadDir . $uniqueDocName;
            $relativeDocPath = 'uploads/post_orders/' . $uniqueDocName;
            
            if (!move_uploaded_file($docFile['tmp_name'], $docPathOnDisk)) {
                $error = "Failed to upload master Posting Order Document to server.";
                $step = 'document';
            } else {
                try {
                    $pdo->beginTransaction();
                    
                    // Update batch with document info and status
                    $pdo->prepare("UPDATE bulk_posting_batches SET document_name = ?, document_path = ?, status = 'processing' WHERE id = ?")
                        ->execute([$docFile['name'], $relativeDocPath, $batchId]);
                    
                    // Get batch title
                    $bStmt = $pdo->prepare("SELECT batch_name FROM bulk_posting_batches WHERE id = ?");
                    $bStmt->execute([$batchId]);
                    $batchInfo = $bStmt->fetch();
                    $batchTitle = $batchInfo['batch_name'] ?? ('Batch #' . $batchId);

                    // Get all pending details
                    $stmt = $pdo->prepare("SELECT * FROM bulk_posting_details WHERE batch_id = ? AND status = 'pending'");
                    $stmt->execute([$batchId]);
                    $pendingDetails = $stmt->fetchAll();
                    
                    $successCount = 0;
                    $failedCount = 0;
                    $smsSentCount = 0;
                    $emailSentCount = 0;
                    
                    foreach ($pendingDetails as $detail) {
                        try {
                            // Check Disciplinary Guard
                            $eligibility = checkOfficerPostingEligibility($pdo, $detail['serviceNo']);
                            if (!$eligibility['can_post']) {
                                $discStatus = $eligibility['disciplinary_status'];
                                $pdo->prepare("UPDATE bulk_posting_details SET status = 'failed', error_message = ? WHERE id = ?")
                                    ->execute(["Posting Blocked: Officer is UNDER DISCIPLINARY ACTION ($discStatus)", $detail['id']]);
                                $failedCount++;
                                continue;
                            }

                            // Get officer info for notification
                            $serviceNoClean = trim($detail['serviceNo']);
                            $pStmt = $pdo->prepare("SELECT surname, firstName FROM tbl_emppersonal WHERE TRIM(serviceNo) = ? OR serviceNo = ? LIMIT 1");
                            $pStmt->execute([$serviceNoClean, $serviceNoClean]);
                            $person = $pStmt->fetch(PDO::FETCH_ASSOC);
                            
                            $eStmt = $pdo->prepare("SELECT currentRank FROM tbl_employment WHERE TRIM(serviceNo) = ? OR serviceNo = ? LIMIT 1");
                            $eStmt->execute([$serviceNoClean, $serviceNoClean]);
                            $emp = $eStmt->fetch(PDO::FETCH_ASSOC);
                            
                            $officerName = trim(($person['surname'] ?? '') . ' ' . ($person['firstName'] ?? ''));
                            if (empty($officerName)) $officerName = $detail['officer_name'] ?? ("Officer (NIS " . $serviceNoClean . ")");
                            $officerRank = !empty($emp['currentRank']) ? $emp['currentRank'] : ($detail['officer_rank'] ?? 'N/A');
                            
                            // Determine zone from location
                            $postingZone = $detail['new_zone'] ?: '';
                            if (empty($postingZone)) {
                                foreach ($nis_formations as $zone => $commands) {
                                    foreach ($commands as $code => $name) {
                                        if (stripos($detail['new_location'], $name) !== false || stripos($detail['new_location'], $code) !== false) {
                                            $postingZone = $zone;
                                            break 2;
                                        }
                                    }
                                }
                            }
                            
                            // Insert into posting_history
                            $stmt = $pdo->prepare("INSERT INTO posting_history (serviceNo, posting_location, posting_date, posting_type) VALUES (?, ?, ?, ?)");
                            $stmt->execute([$detail['serviceNo'], $detail['new_location'], $detail['posting_date'], strtolower($detail['posting_type'] ?? 'transfer')]);
                            
                            // Update current_posting
                            $checkStmt = $pdo->prepare("SELECT id FROM current_posting WHERE serviceNo = ?");
                            $checkStmt->execute([$detail['serviceNo']]);
                            if ($checkStmt->fetch()) {
                                $pdo->prepare("UPDATE current_posting SET posting_location = ?, updated_at = NOW() WHERE serviceNo = ?")
                                    ->execute([$detail['new_location'], $detail['serviceNo']]);
                            } else {
                                $pdo->prepare("INSERT INTO current_posting (serviceNo, posting_location) VALUES (?, ?)")
                                    ->execute([$detail['serviceNo'], $detail['new_location']]);
                            }
                            
                            // Update tbl_employment presentPosting
                            $pdo->prepare("UPDATE tbl_employment SET presentPosting = ? WHERE serviceNo = ?")
                                ->execute([$detail['new_location'], $detail['serviceNo']]);
                            
                            // ============================================
                            // AUTO-ATTACH MASTER DOCUMENT TO OFFICER RECORD
                            // ============================================
                            try {
                                $pdo->prepare("INSERT INTO post_order_documents (serviceNo, file_name, file_path, file_type, file_size, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)")
                                    ->execute([
                                        $detail['serviceNo'],
                                        "Bulk Order ($batchTitle): " . $docFile['name'],
                                        $relativeDocPath,
                                        $docExt,
                                        $docFile['size'],
                                        $user['full_name'] ?? $user['username']
                                    ]);
                            } catch (Exception $de) {}

                            // Create notification
                            $postedBy = $user['full_name'] ?? $user['username'];
                            $message = "Officer $officerName ($officerRank) with NIS No: {$detail['serviceNo']} has been posted to {$detail['new_location']}.";
                            
                            $pdo->prepare("INSERT INTO posting_notifications (serviceNo, officer_name, officer_rank, posting_location, posting_zone, posted_by, posting_date, message, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')")
                                ->execute([$detail['serviceNo'], $officerName, $officerRank, $detail['new_location'], $postingZone, $postedBy, $detail['posting_date'], $message]);
                            
                            // Send SMS to officer
                            $smsResult = $notificationService->sendPostingSMS(
                                $detail['serviceNo'],
                                $officerName,
                                $detail['new_location'],
                                $detail['posting_date']
                            );
                            if ($smsResult['success']) $smsSentCount++;
                            
                            // Send email to officer
                            $emailResult = $notificationService->sendPostingEmail(
                                $detail['serviceNo'],
                                $officerName,
                                $officerRank,
                                $detail['new_location'],
                                $detail['posting_date']
                            );
                            if ($emailResult['success']) $emailSentCount++;

                            // Send Meta WhatsApp
                            $notificationService->sendPostingWhatsApp(
                                $detail['serviceNo'],
                                $officerName,
                                $officerRank,
                                $detail['new_location'],
                                $detail['posting_date']
                            );
                            
                            // Update detail status
                            $pdo->prepare("UPDATE bulk_posting_details SET status = 'success' WHERE id = ?")->execute([$detail['id']]);
                            $successCount++;
                            
                        } catch (Exception $e) {
                            $pdo->prepare("UPDATE bulk_posting_details SET status = 'failed', error_message = ? WHERE id = ?")
                                ->execute([$e->getMessage(), $detail['id']]);
                            $failedCount++;
                        }
                    }
                    
                    // Update batch with notification counts
                    $pdo->prepare("UPDATE bulk_posting_batches SET status = 'completed', success_count = ?, failed_count = ?, completed_at = NOW() WHERE id = ?")
                        ->execute([$successCount, $failedCount, $batchId]);
                    
                    $pdo->commit();
                    if (class_exists('CacheManager')) {
                        CacheManager::delete('dashboard_analytics');
                    }
                    if (file_exists(__DIR__ . '/includes/performance.php')) {
                        require_once __DIR__ . '/includes/performance.php';
                        $sc = new SimpleCache();
                        $sc->clear();
                    }
                    
                    header("Location: bulk_posting.php?step=result&batch_id=$batchId&sms=$smsSentCount&email=$emailSentCount");
                    exit();
                    
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $error = "Error processing batch: " . $e->getMessage();
                }
            }
        }
    }
}

// ============================================
// HANDLE BATCH RECALL / REVERSION (Super Admin & Service HQ Only)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['recall_batch_id'])) {
    $batchIdToRecall = intval($_POST['recall_batch_id']);
    
    if (in_array($userRole, ['admin', 'Service HQ', 'Super Admin'])) {
        try {
            $pdo->beginTransaction();
            
            $bStmt = $pdo->prepare("SELECT * FROM bulk_posting_batches WHERE id = ?");
            $bStmt->execute([$batchIdToRecall]);
            $targetBatch = $bStmt->fetch();
            
            if ($targetBatch && $targetBatch['status'] !== 'recalled') {
                $dStmt = $pdo->prepare("SELECT * FROM bulk_posting_details WHERE batch_id = ? AND status = 'success'");
                $dStmt->execute([$batchIdToRecall]);
                $revertedCount = 0;
                
                while ($detail = $dStmt->fetch()) {
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
                
                if (function_exists('logActivity')) {
                    logActivity($pdo, $_SESSION['user_id'], 'Batch Recall', "Recalled Bulk Posting Batch #{$batchIdToRecall} ({$targetBatch['batch_name']}) - Reverted $revertedCount officers");
                }
                
                $_SESSION['success_msg'] = "Batch #{$batchIdToRecall} ('{$targetBatch['batch_name']}') recalled successfully! Reverted $revertedCount officer postings.";
                header('Location: bulk_posting.php');
                exit();
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = "Error recalling batch: " . $e->getMessage();
        }
    } else {
        $error = "Unauthorized: Only Super Admin and Service HQ can recall bulk posting batches.";
    }
}

// ============================================
// GET BATCH HISTORY
// ============================================
$batches = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM bulk_posting_batches WHERE created_by = ? ORDER BY created_at DESC LIMIT 20");
    $stmt->execute([$userId]);
    $batches = $stmt->fetchAll();
} catch (Exception $e) { $batches = []; }

// ============================================
// GET PREVIEW/RESULT DATA
// ============================================
$batchData = null;
$batchDetails = [];
if (isset($_GET['batch_id'])) {
    $batchId = intval($_GET['batch_id']);
    try {
        $stmt = $pdo->prepare("SELECT * FROM bulk_posting_batches WHERE id = ?");
        $stmt->execute([$batchId]);
        $batchData = $stmt->fetch();
        
        $stmt = $pdo->prepare("SELECT * FROM bulk_posting_details WHERE batch_id = ? ORDER BY id");
        $stmt->execute([$batchId]);
        $batchDetails = $stmt->fetchAll();
    } catch (Exception $e) { $batchData = null; $batchDetails = []; }
}

// Get notification counts from URL params
$smsSentCount = intval($_GET['sms'] ?? 0);
$emailSentCount = intval($_GET['email'] ?? 0);
?>

<?php include 'includes/header.php'; ?>

<!-- CSS and HTML remain the same as before -->
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
        border-radius: 12px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        margin-bottom: 1.25rem;
        border: 1px solid #e2e8f0;
    }
    .card-header {
        padding: 0.75rem 1.25rem;
        font-weight: 600;
        font-size: 0.85rem;
        border-bottom: 1px solid #e2e8f0;
        color: #1a5632;
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
        color: #475569;
        margin-bottom: 0.35rem;
        font-size: 0.8rem;
    }
    .form-control {
        width: 100%;
        padding: 0.5rem 0.75rem;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        font-size: 0.85rem;
        outline: none;
    }
    .form-control:focus { border-color: #1a5632; }

    .btn {
        padding: 0.45rem 0.85rem;
        border-radius: 6px;
        font-size: 0.8rem;
        font-weight: 600;
        cursor: pointer;
        border: none;
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        text-decoration: none;
        transition: all 0.15s ease;
    }
    .btn-success { background: #1a5632; color: #ffffff; }
    .btn-success:hover { background: #154628; }
    .btn-secondary { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }
    .btn-secondary:hover { background: #e2e8f0; }
    .btn-outline { background: #ffffff; color: #1a5632; border: 1px solid #1a5632; }
    .btn-outline:hover { background: #f0fdf4; }
    .btn-warning { background: #f59e0b; color: #ffffff; }
    .btn-warning:hover { background: #d97706; }

    .alert {
        padding: 0.65rem 0.85rem;
        border-radius: 6px;
        margin-bottom: 1rem;
        font-size: 0.8rem;
        display: flex;
        align-items: center;
        gap: 0.4rem;
    }
    .alert-success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
    .alert-error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

    .upload-area {
        border: 2px dashed #cbd5e1;
        border-radius: 10px;
        padding: 2rem 1.5rem;
        text-align: center;
        cursor: pointer;
        transition: all 0.2s ease;
        background: #f8fafc;
    }
    .upload-area:hover { border-color: #1a5632; background: #f0fdf4; }
    .upload-area i { font-size: 2.25rem; color: #1a5632; margin-bottom: 0.5rem; }
    .upload-area h3 { font-size: 0.9rem; margin-bottom: 0.25rem; color: #1e293b; font-weight: 700; }
    .upload-area p { font-size: 0.775rem; color: #64748b; margin: 0; }

    .step-indicator {
        display: flex;
        align-items: center;
        margin-bottom: 1.25rem;
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 0.75rem 1.25rem;
    }
    .step-item { display: flex; align-items: center; gap: 0.5rem; flex: 1; }
    .step-number {
        width: 26px; height: 26px; border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        font-weight: 700; font-size: 0.75rem; background: #e2e8f0; color: #64748b;
    }
    .step-item.active .step-number { background: #1a5632; color: #ffffff; }
    .step-item.completed .step-number { background: #27ae60; color: #ffffff; }
    .step-label { font-size: 0.8rem; color: #64748b; font-weight: 600; }
    .step-item.active .step-label { color: #1a5632; }
    .step-line { flex: 1; height: 2px; background: #e2e8f0; margin: 0 0.75rem; }
    .step-line.completed { background: #27ae60; }

    .data-table { width: 100%; border-collapse: collapse; font-size: 0.8rem; }
    .data-table th { background: #f8fafc; padding: 0.6rem 0.75rem; text-align: left; font-weight: 600; border-bottom: 1px solid #e2e8f0; color: #475569; }
    .data-table td { padding: 0.6rem 0.75rem; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    .data-table tbody tr:hover { background: #f8fafc; }

    .status-badge { padding: 0.15rem 0.5rem; border-radius: 12px; font-size: 0.7rem; font-weight: 700; }
    .status-pending { background: #fef3c7; color: #92400e; }
    .status-success, .status-completed { background: #dcfce7; color: #166534; }
    .status-failed { background: #fee2e2; color: #991b1b; }

    .summary-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
        gap: 0.85rem;
        margin-bottom: 1rem;
    }
    .summary-stat { text-align: center; padding: 0.75rem; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; }
    .summary-stat .value { font-size: 1.25rem; font-weight: 800; color: #1a5632; }
    .summary-stat .label { font-size: 0.725rem; color: #64748b; font-weight: 600; }

    .batch-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 0.85rem 1rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 0.65rem;
        gap: 0.75rem;
        flex-wrap: wrap;
        transition: all 0.15s ease;
    }
    .batch-card:hover {
        border-color: #cbd5e1;
        box-shadow: 0 2px 5px rgba(0,0,0,0.03);
    }

    .batch-info { flex: 1; min-width: 180px; }
    .batch-info h4 { font-size: 0.85rem; font-weight: 600; margin: 0 0 0.25rem 0; color: #1e293b; line-height: 1.3; }
    .batch-info p { font-size: 0.725rem; color: #64748b; margin: 0; }

    .batch-stats {
        display: flex;
        align-items: center;
        gap: 0.4rem;
        flex-wrap: wrap;
    }

    @media (max-width: 768px) {
        .batch-card {
            flex-direction: column;
            align-items: flex-start;
            padding: 0.85rem;
        }
        .batch-info {
            width: 100%;
            margin-bottom: 0.25rem;
        }
        .batch-stats {
            width: 100%;
            justify-content: space-between;
            padding-top: 0.5rem;
            border-top: 1px solid #f1f5f9;
        }
        .batch-actions-group {
            display: flex;
            align-items: center;
            gap: 0.35rem;
            margin-left: auto;
        }
    }
</style>

<div class="page-title"><h1><i class="fas fa-upload"></i>Bulk Posting Module</h1></div>

<?php if ($error): ?>
<div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
<?php endif; ?>
<?php if ($success): ?>
<div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div>
<?php endif; ?>

<!-- ============================================ -->
<!-- STEP INDICATOR -->
<!-- ============================================ -->
<div class="step-indicator">
    <div class="step-item <?php echo $step === 'upload' ? 'active' : (in_array($step, ['preview','document','result']) ? 'completed' : ''); ?>">
        <div class="step-number">1</div>
        <span class="step-label">Upload CSV</span>
    </div>
    <div class="step-line <?php echo in_array($step, ['preview','document','result']) ? 'completed' : ''; ?>"></div>
    <div class="step-item <?php echo $step === 'preview' ? 'active' : (in_array($step, ['document','result']) ? 'completed' : ''); ?>">
        <div class="step-number">2</div>
        <span class="step-label">Preview & Validate</span>
    </div>
    <div class="step-line <?php echo in_array($step, ['document','result']) ? 'completed' : ''; ?>"></div>
    <div class="step-item <?php echo $step === 'document' ? 'active' : ($step === 'result' ? 'completed' : ''); ?>">
        <div class="step-number">3</div>
        <span class="step-label">Posting Order Document</span>
    </div>
    <div class="step-line <?php echo $step === 'result' ? 'completed' : ''; ?>"></div>
    <div class="step-item <?php echo $step === 'result' ? 'active' : ''; ?>">
        <div class="step-number">4</div>
        <span class="step-label">Results</span>
    </div>
</div>

<!-- ============================================ -->
<!-- STEP 1: UPLOAD -->
<!-- ============================================ -->
<?php if ($step === 'upload'): ?>
<div class="card">
    <div class="card-header"><h3><i class="fas fa-file-csv"></i> Upload CSV File</h3></div>
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data">
            <div class="form-group">
                <label><i class="fas fa-tag"></i> Batch Name <small style="color:#64748b; font-weight:normal;">(Optional - Auto-generated if left blank)</small></label>
                <input type="text" name="batch_name" class="form-control" placeholder="e.g., Q1 2026 Rotational Postings">
            </div>
            
            <div class="upload-area" onclick="document.getElementById('csv_file').click()" id="dropZone">
                <i class="fas fa-cloud-upload-alt"></i>
                <h3>Click to Upload or Drag & Drop CSV File</h3>
                <p>File must be CSV format with columns: <strong>S/N, SERVICE NO, NAME, RANK, PRESENT POSTING, NEW POSTING</strong></p>
                <input type="file" name="csv_file" id="csv_file" accept=".csv,.txt" style="display:none" onchange="updateFileName(this)">
                <p id="fileName" style="color:#1a5632;font-weight:600;margin-top:.5rem;"></p>
            </div>
            
            <div style="margin-top:1.5rem; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:1.1rem;">
                <h4 style="font-size:.85rem; margin-bottom:.5rem; color:#1e293b;"><i class="fas fa-keyboard" style="color:#1a5632;"></i> Or Enter / Paste Postings Directly (1, 10, or 100s of Officers)</h4>
                <p style="font-size:.775rem; color:#64748b; margin-bottom:.6rem;">Type or paste your Service Numbers and New Locations directly (one entry per line). Format: <code>SERVICE_NO, NEW_POSTING</code></p>
                <textarea name="manual_text" class="form-control" rows="4" style="font-family:monospace; font-size:0.8rem;" placeholder="e.g.&#10;10001, Lagos State Command&#10;10002, Rivers State Command&#10;10003, FCT Command"></textarea>
            </div>

            <div style="margin-top:1.5rem;">
                <h4 style="font-size:.85rem;margin-bottom:.8rem;color:#1e293b;"><i class="fas fa-info-circle"></i> Standard CSV Template Structure</h4>
                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:1rem;font-size:.75rem;font-family:monospace;color:#475569;">
                    <strong>Required Columns & Headers:</strong><br>
                    <code>S/N</code> - Serial Number<br>
                    <code>SERVICE NO</code> - Officer's Service Number<br>
                    <code>NAME</code> - Officer's Full Name<br>
                    <code>RANK</code> - Officer's Current Rank<br>
                    <code>PRESENT POSTING</code> - Current Command / Station<br>
                    <code>NEW POSTING</code> - Destination Command / Formation<br><br>
                    
                    <strong>Template Structure Example:</strong><br>
                    <code>S/N,SERVICE NO,NAME,RANK,PRESENT POSTING,NEW POSTING</code><br>
                    <code>1,35562,,,,Rivers State Command</code><br>
                    <code>2,42262,,,,Rivers State Command</code><br><br>
                    <small style="color:#1a5632; font-weight:600;"><i class="fas fa-magic"></i> Automatic Lookup: Enter only the <strong>SERVICE NO</strong> and <strong>NEW POSTING</strong>. The system automatically fetches the real Officer's Name, Rank, and Present Posting from the database for only the inputted Service Numbers!</small>
                </div>
                <button type="button" class="btn btn-outline" style="margin-top:.8rem;" onclick="downloadTemplate()">
                    <i class="fas fa-download"></i> Download CSV Template
                </button>
            </div>
            
            <button type="submit" name="upload_file" class="btn btn-success" style="margin-top:1.5rem;width:100%;justify-content:center;">
                <i class="fas fa-upload"></i> Upload and Validate
            </button>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ============================================ -->
<!-- STEP 2: PREVIEW -->
<!-- ============================================ -->
<?php if ($step === 'preview' && $batchData): 
    $rawTitle = trim($batchData['batch_name'] ?? '');
    if (empty($rawTitle) || preg_match('/^(.)\1+$/i', $rawTitle)) {
        $displayBatchTitle = 'Batch #' . $batchData['id'] . ' (' . date('d M Y, H:i', strtotime($batchData['created_at'] ?? 'now')) . ')';
    } else {
        $displayBatchTitle = $rawTitle;
    }
?>
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-clipboard-check"></i> Preview &amp; Validate Batch <span style="font-size:0.8rem; background:#f0fdf4; color:#166534; padding:0.25rem 0.6rem; border-radius:6px; border:1px solid #bbf7d0; margin-left:0.5rem; font-weight:600;"><?php echo htmlspecialchars($displayBatchTitle); ?></span></h3>
        <span class="status-badge status-<?php echo $batchData['status']; ?>"><?php echo ucfirst($batchData['status']); ?></span>
    </div>
    <div class="card-body">
        <div class="summary-stats">
            <div class="summary-stat">
                <div class="value"><?php echo $batchData['total_rows']; ?></div>
                <div class="label">Total Rows</div>
            </div>
            <div class="summary-stat">
                <div class="value" style="color:#27ae60;"><?php echo $batchData['success_count']; ?></div>
                <div class="label">Valid</div>
            </div>
            <div class="summary-stat">
                <div class="value" style="color:#e74c3c;"><?php echo $batchData['failed_count']; ?></div>
                <div class="label">With Errors</div>
            </div>
        </div>
        
        <?php 
        $validCount = 0; $errorCount = 0;
        foreach ($batchDetails as $bd) {
            if ($bd['status'] === 'pending') $validCount++;
            if ($bd['status'] === 'failed') $errorCount++;
        }
        ?>
        
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
                    <?php foreach ($batchDetails as $i => $bd): ?>
                    <tr>
                        <td><?php echo $i + 1; ?></td>
                        <td><code><?php echo htmlspecialchars($bd['serviceNo']); ?></code></td>
                        <td><?php echo htmlspecialchars($bd['officer_name'] ?? '—'); ?></td>
                        <td><?php echo htmlspecialchars($bd['officer_rank'] ?? '—'); ?></td>
                        <td><?php echo htmlspecialchars($bd['current_location'] ?? '—'); ?></td>
                        <td><strong><?php echo htmlspecialchars($bd['new_location']); ?></strong></td>
                        <td style="white-space:nowrap;"><?php echo date('d M Y', strtotime($bd['posting_date'])); ?></td>
                        <td><span class="status-badge status-<?php echo $bd['status'] === 'pending' ? 'success' : 'failed'; ?>"><?php echo $bd['status'] === 'pending' ? 'Valid' : 'Error'; ?></span></td>
                        <td style="color:#e74c3c;font-size:.7rem;"><?php echo htmlspecialchars($bd['error_message'] ?? ''); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <div style="display:flex;gap:1rem;margin-top:1.5rem;flex-wrap:wrap;">
            <a href="bulk_posting.php?step=upload" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back to Upload</a>
            <?php if ($validCount > 0): ?>
            <a href="bulk_posting.php?step=document&batch_id=<?php echo $batchData['id']; ?>" class="btn btn-success" style="padding:0.6rem 1.25rem;">
                <i class="fas fa-file-upload"></i> Proceed to Posting Order Document (<?php echo $validCount; ?> Officers) &rarr;
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ============================================ -->
<!-- STEP 3: POSTING ORDER DOCUMENT -->
<!-- ============================================ -->
<?php if ($step === 'document' && $batchData): ?>
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-file-upload"></i> Step 3: Upload Bulk Posting Order Document</h3>
        <span class="status-badge status-pending">Batch #<?php echo $batchData['id']; ?></span>
    </div>
    <div class="card-body">
        <div class="alert alert-success" style="margin-bottom:1.5rem; background:rgba(26,86,50,0.06); border:1px solid #1a5632; color:#1a5632; padding:1rem; border-radius:8px;">
            <i class="fas fa-file-signature" style="font-size:1.5rem; color:#1a5632; margin-right:0.5rem;"></i>
            <div>
                <strong style="font-size:0.9rem;">Mandatory Rule: One Master Posting Order Document</strong>
                <p style="margin:0.25rem 0 0 0; font-size:0.8rem; color:#334155; line-height:1.4;">
                    Upload the official master Posting Order Document (PDF, JPG, JPEG, or DOCX) for this entire bulk posting batch (<strong><?php echo htmlspecialchars($batchData['batch_name']); ?></strong>).
                    Upon completion, this single master document will <strong>automatically appear on each officer's individual posting history and document record</strong>.
                </p>
            </div>
        </div>

        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="process_batch" value="1">
            <input type="hidden" name="batch_id" value="<?php echo $batchData['id']; ?>">
            
            <div class="form-group" style="margin-bottom:1.5rem;">
                <label style="font-weight:700; color:#1a5632; font-size:0.875rem;">
                    <i class="fas fa-paperclip"></i> Master Posting Order Document <span style="color:#dc2626;">* (Required)</span>
                </label>
                <div class="upload-area" onclick="document.getElementById('bulk_posting_document').click()" id="bulkDocZone">
                    <i class="fas fa-cloud-upload-alt"></i>
                    <h3>Click to Select or Drag & Drop Master Document</h3>
                    <p>Supported Formats: <strong>PDF, JPG, JPEG, DOCX</strong> | Maximum File Size: <strong>10MB</strong></p>
                    <input type="file" name="bulk_posting_document" id="bulk_posting_document" accept=".pdf,.jpg,.jpeg,.docx" style="display:none" onchange="updateDocFileName(this)" required>
                    <p id="bulkDocFileName" style="color:#1a5632; font-weight:700; font-size:0.9rem; margin-top:0.75rem;"></p>
                </div>
            </div>

            <div style="display:flex; justify-content:space-between; align-items:center; margin-top:1.5rem; flex-wrap:wrap; gap:1rem;">
                <a href="bulk_posting.php?step=preview&batch_id=<?php echo $batchData['id']; ?>" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back to Preview</a>
                <button type="submit" class="btn btn-success" style="padding:0.65rem 1.75rem; font-size:0.875rem;">
                    <i class="fas fa-check-circle"></i> Upload Document & Complete Bulk Posting (SMS + Email)
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function updateDocFileName(input) {
    var p = document.getElementById('bulkDocFileName');
    if (input.files && input.files[0]) {
        p.innerHTML = '<i class="fas fa-check-circle"></i> Selected Document: ' + input.files[0].name + ' (' + (input.files[0].size / 1024 / 1024).toFixed(2) + ' MB)';
    }
}
</script>
<?php endif; ?>

<!-- ============================================ -->
<!-- STEP 3: RESULTS -->
<!-- ============================================ -->
<?php if ($step === 'result' && $batchData): ?>
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-poll-h"></i> Results: <?php echo htmlspecialchars($batchData['batch_name']); ?></h3>
        <span class="status-badge status-<?php echo $batchData['status']; ?>"><?php echo ucfirst($batchData['status']); ?></span>
    </div>
    <div class="card-body">
        <div class="summary-stats">
            <div class="summary-stat">
                <div class="value"><?php echo $batchData['total_rows']; ?></div>
                <div class="label">Total</div>
            </div>
            <div class="summary-stat">
                <div class="value" style="color:#27ae60;"><?php echo $batchData['success_count']; ?></div>
                <div class="label">Successful Postings</div>
            </div>
            <div class="summary-stat">
                <div class="value" style="color:#e74c3c;"><?php echo $batchData['failed_count']; ?></div>
                <div class="label">Failed</div>
            </div>
            <div class="summary-stat">
                <div class="value"><?php echo $batchData['total_rows'] > 0 ? round(($batchData['success_count']/$batchData['total_rows'])*100) : 0; ?>%</div>
                <div class="label">Success Rate</div>
                <div class="progress-bar"><div class="progress-fill" style="width:<?php echo $batchData['total_rows'] > 0 ? round(($batchData['success_count']/$batchData['total_rows'])*100) : 0; ?>%"></div></div>
            </div>
        </div>
        
        <!-- Attached Master Posting Order Document summary -->
        <?php if (!empty($batchData['document_path'])): ?>
        <div style="margin-bottom:1rem; padding:0.85rem 1.25rem; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:0.5rem;">
            <div>
                <strong style="color:#166534; font-size:0.85rem;"><i class="fas fa-file-signature"></i> Master Bulk Posting Order Document:</strong>
                <span style="font-size:0.8rem; color:#334155; margin-left:0.35rem; font-weight:600;"><?php echo htmlspecialchars($batchData['document_name'] ?? 'Master Posting Document'); ?></span>
                <div style="font-size:0.75rem; color:#15803d; margin-top:0.25rem;"><i class="fas fa-check-circle"></i> Automatically attached to all <?php echo $batchData['success_count']; ?> posted officers' individual history records!</div>
            </div>
            <a href="<?php echo htmlspecialchars($batchData['document_path']); ?>" target="_blank" class="btn btn-sm btn-success" style="background:#16a34a; color:#fff; text-decoration:none;"><i class="fas fa-file-alt"></i> View Master Document</a>
        </div>
        <?php endif; ?>

        <!-- Notification summary -->
        <?php if ($smsSentCount > 0 || $emailSentCount > 0): ?>
        <div class="notification-summary" style="margin-bottom:1rem;">
            <i class="fas fa-bell"></i>
            <span><strong>Notifications Sent:</strong></span>
            <?php if ($smsSentCount > 0): ?>
            <span><i class="fas fa-sms"></i> <?php echo $smsSentCount; ?> SMS</span>
            <?php endif; ?>
            <?php if ($emailSentCount > 0): ?>
            <span><i class="fas fa-envelope"></i> <?php echo $emailSentCount; ?> Emails</span>
            <?php endif; ?>
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
                        <td><?php echo htmlspecialchars($bd['officer_name'] ?? '—'); ?></td>
                        <td><strong><?php echo htmlspecialchars($bd['new_location']); ?></strong></td>
                        <td><span class="status-badge status-<?php echo $bd['status']; ?>"><?php echo ucfirst($bd['status']); ?></span></td>
                        <td style="color:#e74c3c;font-size:.7rem;"><?php echo htmlspecialchars($bd['error_message'] ?? ''); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <div style="display:flex;gap:1rem;margin-top:1.5rem;">
            <a href="bulk_posting.php?step=upload" class="btn btn-success"><i class="fas fa-plus"></i> New Bulk Upload</a>
            <a href="bulk_posting.php?step=history" class="btn btn-outline"><i class="fas fa-eye"></i> View History</a>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ============================================ -->
<!-- BATCH HISTORY -->
<!-- ============================================ -->
<?php if ($step === 'history' || (empty($batchData) && $step === 'upload' && !empty($batches))): ?>
<div class="card">
    <div class="card-header"><h3><i class="fas fa-history"></i> Bulk Posting History</h3></div>
    <div class="card-body">
        <?php if (!empty($batches)): ?>
        <div class="batch-list">
            <?php foreach ($batches as $batch): ?>
            <div class="batch-card">
                <div class="batch-info">
                    <h4><?php echo htmlspecialchars($batch['batch_name']); ?></h4>
                    <p><i class="far fa-file-alt"></i> <?php echo htmlspecialchars($batch['file_name'] ?? 'N/A'); ?> &bull; <?php echo date('d M Y, H:i', strtotime($batch['created_at'])); ?></p>
                </div>
                <div class="batch-stats">
                    <div style="display:flex; align-items:center; gap:0.4rem; font-size:0.75rem;">
                        <span style="color:#166534; font-weight:600;"><i class="fas fa-check-circle"></i> <?php echo $batch['success_count']; ?></span>
                        <span style="color:#991b1b; font-weight:600;"><i class="fas fa-times-circle"></i> <?php echo $batch['failed_count']; ?></span>
                        <span class="status-badge status-<?php echo $batch['status']; ?>"><?php echo ucfirst($batch['status']); ?></span>
                    </div>
                    <div class="batch-actions-group">
                        <a href="bulk_posting.php?step=<?php echo $batch['status'] === 'completed' ? 'result' : 'preview'; ?>&batch_id=<?php echo $batch['id']; ?>" class="btn btn-secondary" style="font-size:.75rem; padding:0.35rem 0.65rem;" title="View Details">
                            <i class="fas fa-eye"></i> View
                        </a>

                        <?php if (in_array($userRole, ['admin', 'Service HQ', 'Super Admin']) && $batch['status'] === 'completed'): ?>
                        <form method="POST" style="display:inline; margin:0;" onsubmit="return confirm('WARNING: Recalling this batch will revert all officer postings in this batch back to their previous locations. Proceed?');">
                            <input type="hidden" name="recall_batch_id" value="<?php echo $batch['id']; ?>">
                            <button type="submit" class="btn" style="font-size:.75rem; padding:0.35rem 0.65rem; border-radius:6px; cursor:pointer; background:#fff7ed; color:#c2410c; border:1px solid #ffedd5;" title="Recall Batch & Revert Postings">
                                <i class="fas fa-undo"></i> Recall Batch
                            </button>
                        </form>
                        <?php endif; ?>

                        <form method="POST" style="display:inline; margin:0;" onsubmit="return confirm('Are you sure you want to delete this bulk posting batch history?');">
                            <input type="hidden" name="batch_id" value="<?php echo $batch['id']; ?>">
                            <button type="submit" name="delete_batch" class="btn btn-danger" style="font-size:.75rem; padding:0.35rem 0.65rem; border-radius:6px; cursor:pointer; background:#fee2e2; color:#991b1b; border:1px solid #fecaca;" title="Delete Batch History">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="empty-state" style="text-align:center;padding:2rem;color:#64748b;">
            <i class="fas fa-inbox" style="font-size:1.75rem;display:block;margin-bottom:.75rem;"></i>
            <h4>No Bulk Posting History</h4>
            <p>Upload a CSV file to begin bulk posting.</p>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<script>
function updateFileName(input) {
    const fileName = document.getElementById('fileName');
    if (input.files && input.files[0]) {
        fileName.textContent = 'Selected: ' + input.files[0].name;
    }
}

// Drag and drop
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

</div><!-- close main-content -->
</div><!-- close content-wrapper -->
</div><!-- close dashboard-container -->

<?php include 'includes/footer.php'; ?>

</body>
</html>