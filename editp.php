<?php


// Start session
session_start();

// Include required files
require_once 'includes/config.php';
require_once 'includes/auth.php';
require_once 'includes/permissions.php';

if (!isLoggedIn()) {
    header('Location: login');
    exit();
}

if (!canInitiatePosting() || !hasPermission('edit_posting')) {
    header("HTTP/1.1 403 Forbidden");
    echo "<div style='font-family:sans-serif; text-align:center; padding:3rem;'>
            <h1 style='color:#dc2626;'>403 Forbidden - Access Denied</h1>
            <p>You do not have permission to execute or modify posting movement orders. User role accounts are restricted to Viewing and Downloading reports only.</p>
            <a href='reports' style='display:inline-block; margin-top:1rem; padding:0.5rem 1rem; background:#1a5632; color:#fff; text-decoration:none; border-radius:6px;'>View Reports</a>
          </div>";
    exit();
}

// Get user information for sidebar
$user = [];
$totalPersonnel = 0;
$totalUsers = 0;

try {
    if (isset($_SESSION['user_id'])) {
        $stmt = $pdo->prepare("SELECT u.*, r.name as role_name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
    }
    $totalPersonnel = $pdo->query("SELECT COUNT(*) as count FROM tbl_emppersonal")->fetch()['count'];
    $totalUsers = $pdo->query("SELECT COUNT(*) as count FROM users")->fetch()['count'];
} catch (Exception $e) {
    error_log("Error getting user/sidebar data: " . $e->getMessage());
}

if (empty($user)) {
    $user = ['username' => $_SESSION['username'] ?? 'User', 'full_name' => $_SESSION['username'] ?? 'User', 'role_name' => 'User'];
}

require_once __DIR__ . '/includes/security.php';
$csrfToken = generateCSRFToken();

// Initialize variables
$serviceNo = '';
$current_posting_data = null;
$employment_data = [];
$personal_data = [];
$error = '';
$success = '';
$posting_history = [];
$upload_error = '';
$upload_success = '';
$post_order_documents = [];
$securityViolation = null;

if (!isset($_GET['serviceNo']) || trim((string)$_GET['serviceNo']) === '') { header('Location: search'); exit(); }
$serviceNo = sanitizeInput($_GET['serviceNo']);
$token = $_GET['token'] ?? $_POST['token'] ?? '';

// 1. Prohibit standard User role from accessing editp.php
$currentUserRole = $_SESSION['role_name'] ?? $user['role_name'] ?? '';
$isUserRole = (strcasecmp(trim($currentUserRole), 'User') === 0);

if ($isUserRole) {
    $securityViolation = "403 Access Denied: User role accounts do not have administrative permission to modify officer postings.";
}

// 2. Cryptographic Token Verification (IDOR & URL Tampering Protection)
if (empty($securityViolation) && !verifyRecordToken($serviceNo, $token)) {
    if (function_exists('logActivity')) {
        logActivity($pdo, $_SESSION['user_id'] ?? 0, 'Security Violation', "URL Tampering Blocked (IDOR): Invalid token for serviceNo " . $serviceNo);
    }
    $securityViolation = "Security Violation (IDOR Protection): Direct modification of URL query parameters is strictly prohibited. Please search and select personnel records through the official Personnel Search panel.";
}

try {
    $pdo_idcard = $pdo;
    
    $pdo_idcard->exec("CREATE TABLE IF NOT EXISTS `current_posting` (`id` INT AUTO_INCREMENT PRIMARY KEY, `serviceNo` VARCHAR(50) UNIQUE, `posting_location` TEXT, `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP, `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB");
    $pdo_idcard->exec("CREATE TABLE IF NOT EXISTS `posting_history` (`id` INT AUTO_INCREMENT PRIMARY KEY, `serviceNo` VARCHAR(50), `posting_location` TEXT, `posting_date` DATE, `posting_type` VARCHAR(50) DEFAULT 'transfer', `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
    $pdo_idcard->exec("CREATE TABLE IF NOT EXISTS `post_order_documents` (`id` INT AUTO_INCREMENT PRIMARY KEY, `serviceNo` VARCHAR(50), `file_name` VARCHAR(255), `file_path` VARCHAR(500), `file_type` VARCHAR(50), `file_size` INT, `uploaded_by` VARCHAR(100), `uploaded_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
    
    // Auto-migration to allow string types (transfer, redeployment, initial, current)
    try { $pdo_idcard->exec("ALTER TABLE posting_history MODIFY COLUMN posting_type VARCHAR(50) DEFAULT 'transfer'"); } catch (Exception $e) {}
} catch (PDOException $e) { 
    error_log("Edit posting DB setup error: " . $e->getMessage());
    $error = "A database error occurred. Please try again."; 
}

if (empty($error)) {
    try {
        $stmt = $pdo_idcard->prepare("SELECT posting_location, updated_at FROM current_posting WHERE serviceNo = ?");
        $stmt->execute([$serviceNo]); $current_posting_data = $stmt->fetch();
        $historyStmt = $pdo_idcard->prepare("SELECT * FROM posting_history WHERE serviceNo = ? ORDER BY posting_date DESC, created_at DESC");
        $historyStmt->execute([$serviceNo]); $posting_history = $historyStmt->fetchAll();
        $documentsStmt = $pdo_idcard->prepare("SELECT * FROM post_order_documents WHERE serviceNo = ? ORDER BY uploaded_at DESC");
        $documentsStmt->execute([$serviceNo]); $post_order_documents = $documentsStmt->fetchAll();
    } catch (PDOException $e) { $error = "Error fetching posting data: " . $e->getMessage(); }
}

try {
    $stmt = $pdo->prepare("SELECT * FROM tbl_employment WHERE serviceNo = ?");
    $stmt->execute([$serviceNo]); $employment_data = $stmt->fetch();
    if (!$employment_data) { $error = "Employment record not found."; }
    else {
        $stmt = $pdo->prepare("SELECT surname, firstName, middleName, email, phone FROM tbl_emppersonal WHERE serviceNo = ?");
        $stmt->execute([$serviceNo]); $pData = $stmt->fetch();
        $personal_data = $pData ?: ['surname' => '', 'firstName' => '', 'middleName' => '', 'email' => '', 'phone' => ''];
        
        // Disciplinary & Retirement Posting Guard Check
        $officerEligibility = checkOfficerPostingEligibility($pdo, $serviceNo);
        if (empty($securityViolation) && !$officerEligibility['can_post']) {
            if (!empty($officerEligibility['is_retired'])) {
                $reason = htmlspecialchars($officerEligibility['retirement_reason']);
                $securityViolation = "POSTING BLOCKED (Statutory Retirement): Officer " . htmlspecialchars($serviceNo) . " is RETIRED / INACTIVE ($reason). Under Nigeria Immigration Service Regulations, officers who have reached retirement threshold (35 years of service or 60 years of age) are strictly prohibited from receiving new postings.";
            } else {
                $discStatus = htmlspecialchars($officerEligibility['disciplinary_status']);
                $discRemarks = htmlspecialchars($officerEligibility['disciplinary_remarks']);
                $securityViolation = "POSTING BLOCKED (Disciplinary Guard): Officer " . htmlspecialchars($serviceNo) . " is currently " . strtoupper($discStatus) . ($discRemarks ? " (" . $discRemarks . ")" : "") . ". Under Nigeria Immigration Service Regulations, officers undergoing active disciplinary action are strictly prohibited from receiving new postings.";
            }
        }
    }
} catch (PDOException $e) { $error = "Error fetching employment records: " . $e->getMessage(); }

// NIS Formations
$nis_formations = [
    'SHQ' => ['SHQ'=>'NIS Headquarters, Abuja','CGIS'=>'Comptroller General of Immigration Office','VRD'=>'Visa and Residency Directorate','POTD'=>'Passport and Other Travel Document Directorate','FAD'=>'Finance and Account Directorate','PRSD'=>'Planning Research and Statistics Directorate','ICD'=>'Investigation & Compliance Directorate','MD'=>'Migration Directorate','BMD'=>'Border Management Directorate','HRMD'=>'Human Resource Management Directorate','WLD'=>'Works And Logistics Directorate','ICTD'=>'ICT And Cyber Security Directorate'],
    'ZONE A' => ['Zone A HQ'=>'Zone A Headquarters, Ikeja','LASC'=>'Lagos State Command','OGSC'=>'Ogun State Command','LASPC'=>'Lagos Seaport and Marine Command','SEME'=>'Seme Border Command','IDBC'=>'Idiroko Border Command','MMIA'=>'Murtala Muhamed International Airport Command','LABPC'=>'Lagos State Border Patrol Command, Seme','LAPC'=>'Lagos Passport Command'],
    'ZONE B' => ['Zone B HQ'=>'Zone B Headquarters, Kaduna','KNSC'=>'Kano State Command','KDSC'=>'Kaduna State Command','KTSC'=>'Katsina State Command','ZMSC'=>'Zamfara State Command','SOSC'=>'Sokoto State Command','JGSC'=>'Jigawa State Command','MAKIA'=>'Mallam Aminu Kano International Airport Command','ILBC'=>'Illela Border Command','JIBC'=>'Jibia Border Command','ITSK'=>'Immigration Training School Kano','ICSC'=>'Immigration Command and Staff College'],
    'ZONE C' => ['Zone C HQ'=>'Zone C Headquarters, Bauchi','ADSC'=>'Adamawa State Command','BASC'=>'Bauchi State Command','BOSC'=>'Borno State Command','GMSC'=>'Gombe State Command','PLSC'=>'Plateau State Command','YBSC'=>'Yobe State Command'],
    'ZONE D' => ['Zone D HQ'=>'Zone D Headquarters, Minna','FCTC'=>'FCT Command','NGSC'=>'Niger State Command','KBSC'=>'Kebbi State Command','RMAT'=>'Regional Migration Academy, Tuga','KWSC'=>'Kwara State Command'],
    'ZONE E' => ['Zone E HQ'=>'Zone E Headquarters, Owerri','ABSC'=>'Abia State Command','IMSC'=>'Imo State Command','RVSC'=>'Rivers State Command','CRSC'=>'Cross River State Command','EBSC'=>'Ebonyi State Command','AKSC'=>'Akwa Ibom State Command','NITSOL'=>'Nigeria Immigration Training School Orlu','RVMC'=>'Rivers Marine Command Onne','MFBC'=>'Mfum Border Command','NITSA'=>'Nigeria Immigration Training School Ahoada'],
    'ZONE F' => ['Zone F HQ'=>'Zone F Headquarters, Ibadan','OYSC'=>'Oyo State Command','EKSC'=>'Ekiti State Command','ODSC'=>'Ondo State Command','OSSC'=>'Osun State Command'],
    'ZONE G' => ['Zone G HQ'=>'Zone G Headquarters, Benin','EDSC'=>'Edo State Command','ANSC'=>'Anambra State Command','DTSC'=>'Delta State Command','ENSC'=>'Enugu State Command','BYSC'=>'Bayelsa State Command'],
    'ZONE H' => ['Zone H HQ'=>'Zone H Headquarters, Makurdi','NASC'=>'Nasarawa State Command','BNSC'=>'Benue State Command','KGSC'=>'Kogi State Command','TRSC'=>'Taraba State Command']
];

$nis_formations_flat = [];
foreach ($nis_formations as $zone => $formations) { foreach ($formations as $code => $name) { $nis_formations_flat[$code] = $name; } }

// Role-based access with command support
require_once __DIR__ . '/includes/formation_helper.php';
$userRole = $_SESSION['role_name'] ?? $user['role_name'] ?? '';
$userIdForRole = $_SESSION['user_id'] ?? 0;
$canEditPosting = false;
$allowedFormations = $nis_formations;
$userAssignedZoneNames = [];
$userAssignedCommands = []; // Track specific commands

// Get user's zone assignments with command support
try {
    $hasAssignedCommand = false;
    $columns = $pdo->query("SHOW COLUMNS FROM user_zones LIKE 'assigned_command'")->fetchAll();
    if (count($columns) > 0) $hasAssignedCommand = true;
    
    if ($hasAssignedCommand) {
        $stmt = $pdo->prepare("SELECT uz.assigned_command, z.zone_name FROM user_zones uz JOIN zones z ON uz.zone_id = z.id WHERE uz.user_id = ?");
        $stmt->execute([$userIdForRole]);
        $userAssignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($userAssignments as $assignment) {
            $userAssignedZoneNames[] = $assignment['zone_name'];
            if (!empty($assignment['assigned_command'])) {
                $userAssignedCommands[] = $assignment['assigned_command'];
            }
        }
    } else {
        $stmt = $pdo->prepare("SELECT z.zone_name FROM user_zones uz JOIN zones z ON uz.zone_id = z.id WHERE uz.user_id = ?");
        $stmt->execute([$userIdForRole]);
        $userAssignedZoneNames = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (PDOException $e) { $userAssignedZoneNames = []; }

if (in_array(strtolower($userRole), ['admin', 'service hq', 'shq admin', 'super admin', 'user'])) {
    $canEditPosting = true;
    // Admin sees all formations
    $allowedFormations = $nis_formations;
} elseif (!empty($userAssignedZoneNames) && !empty($userAssignedCommands)) {
    // User has SPECIFIC commands assigned - only show those commands
    $canEditPosting = true;
    $allowedFormations = [];
    foreach ($nis_formations as $zoneName => $formations) {
        foreach ($userAssignedZoneNames as $assignedZone) {
            if (stripos($zoneName, $assignedZone) !== false || stripos($assignedZone, $zoneName) !== false) {
                // Filter to only show assigned commands or whole zone if no specific command
                if (!empty($userAssignedCommands)) {
                    $filteredFormations = [];
                    foreach ($formations as $code => $name) {
                        foreach ($userAssignedCommands as $cmd) {
                            if (stripos($name, $cmd) !== false || stripos($cmd, $name) !== false ||
                                stripos($code, $cmd) !== false || stripos($cmd, $code) !== false) {
                                $filteredFormations[$code] = $name;
                                break;
                            }
                        }
                    }
                    if (!empty($filteredFormations)) {
                        $allowedFormations[$zoneName] = $filteredFormations;
                    }
                } else {
                    $allowedFormations[$zoneName] = $formations;
                }
                break;
            }
        }
    }
    if (empty($allowedFormations)) $allowedFormations = $nis_formations;
} elseif (!empty($userAssignedZoneNames)) {
    // User has zone-wide access (All Commands) - show all formations in their zones
    $canEditPosting = true;
    $allowedFormations = [];
    foreach ($nis_formations as $zoneName => $formations) {
        foreach ($userAssignedZoneNames as $assignedZone) {
            if (stripos($zoneName, $assignedZone) !== false || stripos($assignedZone, $zoneName) !== false) {
                $allowedFormations[$zoneName] = $formations;
                break;
            }
        }
    }
    if (empty($allowedFormations)) $allowedFormations = $nis_formations;
} else {
    $canEditPosting = false;
}

if (!empty($securityViolation)) {
    $canEditPosting = false;
}

// Build the optional LGA-picker data: for each visible State Command formation,
// look up its Local Government Areas so the UI can offer them once that
// command is selected. Formations with no LGA breakdown (HQs, border posts,
// airports, directorates) simply won't appear as a key here.
$lgasByCommand = [];
foreach ($allowedFormations as $zoneName => $formations) {
    foreach ($formations as $code => $name) {
        $lgas = getLGAsForCommand($name);
        if (!empty($lgas)) {
            $lgasByCommand[$name] = $lgas;
        }
    }
}

// Handle document upload FIRST (so document is saved before checking posting requirements)
if (empty($securityViolation) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['post_order_document']) && $_FILES['post_order_document']['error'] === UPLOAD_ERR_OK && $canEditPosting && !validateCSRFToken($_POST['csrf_token'] ?? '')) {
    $upload_error = "Security validation failed. Please refresh the page and try again.";
} elseif (empty($securityViolation) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['post_order_document']) && $_FILES['post_order_document']['error'] === UPLOAD_ERR_OK && $canEditPosting) {
    $file = $_FILES['post_order_document'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'docx'])) { 
        $upload_error = "Invalid file type. Allowed: PDF, JPG, JPEG, DOCX."; 
    } elseif ($file['size'] > 10*1024*1024) { 
        $upload_error = "Document file too large (Max: 10MB)."; 
    } else {
        $upload_dir = __DIR__ . '/uploads/post_orders/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        $unique_name = $serviceNo . '_' . time() . '_' . uniqid() . '.' . $ext;
        if (move_uploaded_file($file['tmp_name'], $upload_dir . $unique_name)) {
            $pdo_idcard->prepare("INSERT INTO post_order_documents (serviceNo, file_name, file_path, file_type, file_size, uploaded_by) VALUES (?,?,?,?,?,?)")->execute([$serviceNo, $file['name'], 'uploads/post_orders/'.$unique_name, $ext, $file['size'], $user['full_name']??$user['username']]);
            $upload_success = "Post Order Document uploaded successfully!";
            
            // Refresh document list
            $documentsStmt = $pdo_idcard->prepare("SELECT * FROM post_order_documents WHERE serviceNo = ? ORDER BY uploaded_at DESC");
            $documentsStmt->execute([$serviceNo]); 
            $post_order_documents = $documentsStmt->fetchAll();
        }
    }
}

// Handle form submission for Updating Posting Location
if (empty($securityViolation) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['presentPosting']) && $canEditPosting && !validateCSRFToken($_POST['csrf_token'] ?? '')) {
    $error = "Security validation failed. Please refresh the page and try again.";
} elseif (empty($securityViolation) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['presentPosting']) && $canEditPosting) {
    $presentPosting = sanitizeInput($_POST['presentPosting']);
    $posting_date = sanitizeInput($_POST['posting_date']);
    
    // Strict Enforcement: Post Order Document is REQUIRED
    if (empty($post_order_documents)) {
        $error = "POSTING BLOCKED: A Post Order Document (PDF, JPG, JPEG, or DOCX) is strictly required before a posting order can be implemented. Please attach or upload an official Post Order Document.";
    } elseif (empty($posting_date)) { 
        $error = "Posting date is required."; 
    } elseif (isset($_POST['presentPosting']) && $_POST['presentPosting'] === 'other' && isset($_POST['customPosting'])) {
        $presentPosting = sanitizeInput($_POST['customPosting']);
    }

    // Optional: append the selected Local Government Area for extra precision.
    // Not mandatory - many valid destinations (HQs, border posts, directorates,
    // airports) have no LGA breakdown, and admins can also pick "Other" instead.
    if (empty($error) && !empty($_POST['lga']) && $_POST['presentPosting'] !== 'other') {
        $selectedLga = sanitizeInput($_POST['lga']);
        $validLgasForCommand = getLGAsForCommand($presentPosting);
        if (in_array($selectedLga, $validLgasForCommand, true)) {
            $presentPosting = $presentPosting . ' - ' . $selectedLga . ' LGA';
        }
    }

    // Enforce the zone/command scope server-side - the dropdown above only filters what's
    // shown in the UI, it does not by itself stop a tampered request or the "other" custom
    // field from submitting a destination outside the user's assigned Command/Zone.
    if (empty($error)) {
        $allowedPostingDestinations = getUserAllowedPostingDestinations($pdo, $userIdForRole, $nis_formations);
        if (!isDestinationAllowed($presentPosting, $allowedPostingDestinations)) {
            $error = "POSTING BLOCKED: You are not authorized to post officers to '" . htmlspecialchars($presentPosting) . "'. Your account can only post within your assigned Command/Zone.";
        }
    }

    if (empty($error)) {
        try {
            $pdo_idcard->beginTransaction();
            $checkStmt = $pdo_idcard->prepare("SELECT COUNT(*) as count FROM current_posting WHERE serviceNo = ?");
            $checkStmt->execute([$serviceNo]); $result = $checkStmt->fetch();
            if ($result['count'] > 0) {
                $pdo_idcard->prepare("UPDATE current_posting SET posting_location = ?, updated_at = NOW() WHERE serviceNo = ?")->execute([$presentPosting, $serviceNo]);
            } else {
                $pdo_idcard->prepare("INSERT INTO current_posting (serviceNo, posting_location) VALUES (?, ?)")->execute([$serviceNo, $presentPosting]);
            }
            $oldPosting = $current_posting_data ? $current_posting_data['posting_location'] : ($employment_data ? $employment_data['presentPosting'] : '');
            $rawPostingType = strtolower(sanitizeInput($_POST['posting_type'] ?? 'transfer'));
            $posting_type = in_array($rawPostingType, ['transfer', 'redeployment', 'initial']) ? $rawPostingType : (empty($posting_history) ? 'initial' : 'transfer');

            if (empty($oldPosting) || $oldPosting !== $presentPosting) {
                $postedByName = $_SESSION['full_name'] ?? $user['full_name'] ?? $_SESSION['username'] ?? 'System Administrator';
                $createdById = $_SESSION['user_id'] ?? null;
                $pdo_idcard->prepare("INSERT INTO posting_history (serviceNo, posting_location, posting_date, posting_type, created_by, posted_by) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$serviceNo, $presentPosting, $posting_date, $posting_type, $createdById, $postedByName]);
            }
            $pdo_idcard->commit();
            $success = "Posting updated successfully!";
            
            // Notification + SMS + Email (Send immediately when posting is created)
            if (!empty($presentPosting)) {
                $sName = $personal_data['surname'] ?? '';
                $fName = $personal_data['firstName'] ?? '';
                $mName = $personal_data['middleName'] ?? '';
                $officerFullName = trim("$sName $fName $mName");
                if (empty($officerFullName)) { $officerFullName = 'Officer #' . $serviceNo; }
                $officerRank = $employment_data['currentRank'] ?? 'N/A';
                $postedByName = $user['full_name'] ?? $user['username'] ?? 'System';
                require_once 'includes/notification_helpers.php';
                $postingZone = detectPostingZone($presentPosting);
                createPostingNotification($serviceNo, $officerFullName, $officerRank, $presentPosting, $postingZone, $postedByName, $posting_date);
                logSystemActivity('officer_posted', "Officer #$serviceNo ($officerFullName, Rank: $officerRank) posted to '$presentPosting' by $postedByName", $createdById);
                
                // ============================================
                // SEND REAL-TIME SMS & EMAIL IMMEDIATELY TO OFFICER
                // ============================================
                require_once __DIR__ . '/includes/NotificationService.php';
                $notificationService = new NotificationService($pdo);
                
                $officerPhone = $personal_data['phone'] ?? null;
                $officerEmail = $personal_data['email'] ?? null;

                // Send SMS in real-time
                $smsResult = $notificationService->sendPostingSMS(
                    $serviceNo,
                    $officerFullName,
                    $presentPosting,
                    $posting_date,
                    $officerPhone
                );
                
                // Send Email in real-time
                $emailResult = $notificationService->sendPostingEmail(
                    $serviceNo,
                    $officerFullName,
                    $officerRank,
                    $presentPosting,
                    $posting_date,
                    $officerEmail
                );
                
                // Send Meta WhatsApp in real-time
                $whatsAppResult = $notificationService->sendPostingWhatsApp(
                    $serviceNo,
                    $officerFullName,
                    $officerRank,
                    $presentPosting,
                    $posting_date,
                    $officerPhone
                );
                
                // Real-time status feedback for SMS, Email, and WhatsApp dispatches
                $notifMsg = [];
                if (!empty($smsResult['success'])) {
                    $notifMsg[] = "Real-time SMS Dispatched";
                }
                if (!empty($emailResult['success'])) {
                    $notifMsg[] = "Real-time Email Dispatched";
                }
                if (!empty($whatsAppResult['success'])) {
                    $notifMsg[] = "Real-time WhatsApp Dispatched";
                }
                
                if (!empty($notifMsg)) {
                    $success .= " (" . implode(" & ", $notifMsg) . ")";
                }

                // Invalidate dashboard analytics cache for instant data sync
                if (file_exists(__DIR__ . '/includes/performance.php')) {
                    require_once __DIR__ . '/includes/performance.php';
                    $sc = new SimpleCache();
                    $sc->clear();
                }
            }
            
            $stmt = $pdo_idcard->prepare("SELECT posting_location, updated_at FROM current_posting WHERE serviceNo = ?");
            $stmt->execute([$serviceNo]); $current_posting_data = $stmt->fetch();
            $historyStmt = $pdo_idcard->prepare("SELECT * FROM posting_history WHERE serviceNo = ? ORDER BY posting_date DESC");
            $historyStmt->execute([$serviceNo]); $posting_history = $historyStmt->fetchAll();
        } catch (PDOException $e) { try { $pdo_idcard->rollBack(); } catch (PDOException $re) {} $error = "Error: " . $e->getMessage(); }
    }
}

// Handle document deletion - POST only (a state-changing action must never be
// reachable via a plain GET, which could be triggered by e.g. an <img> tag).
$deleteDocId = ($_SERVER['REQUEST_METHOD'] === 'POST') ? ($_POST['delete_doc_id'] ?? null) : null;
if (isset($deleteDocId) && $deleteDocId !== '' && is_numeric($deleteDocId) && $canEditPosting && validateCSRFToken($_POST['csrf_token'] ?? '')) {
    $docId = (int)$deleteDocId;
    $stmt = $pdo_idcard->prepare("SELECT file_path FROM post_order_documents WHERE id = ? AND serviceNo = ?");
    $stmt->execute([$docId, $serviceNo]);
    if ($doc = $stmt->fetch()) {
        $filePathOnDisk = __DIR__ . '/' . $doc['file_path'];
        if (file_exists($filePathOnDisk)) {
            @unlink($filePathOnDisk);
        }
        $delStmt = $pdo_idcard->prepare("DELETE FROM post_order_documents WHERE id = ? AND serviceNo = ?");
        $delStmt->execute([$docId, $serviceNo]);
        $upload_success = "Document deleted successfully!";
        
        $documentsStmt = $pdo_idcard->prepare("SELECT * FROM post_order_documents WHERE serviceNo = ? ORDER BY uploaded_at DESC");
        $documentsStmt->execute([$serviceNo]);
        $post_order_documents = $documentsStmt->fetchAll();
    }
}
?>

<?php include 'includes/header.php'; ?>

<!-- Edit Posting specific styles -->
<style>
    strong, b { font-weight: 500; color: #334155; }
    .card { background: #fff; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 1.25rem; border: 1px solid #e2e8f0; }
    .card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
    .card-header { background: #fff; padding: 0.875rem 1.25rem; font-weight: 500; font-size: 0.85rem; border-bottom: 1px solid #e2e8f0; color: #1e293b; }
    .card-header i { margin-right: 0.5rem; color: #1a5632; }
    .card-body { padding: 1.25rem; }
    .personal-info { background: rgba(26,86,50,0.05); border-left: ; padding: 1rem; border-radius: 8px; margin-bottom: 1rem; }
    .info-title { font-size: 0.9rem; font-weight: 500; color: #334155; margin-bottom: 0.75rem; display: flex; align-items: center; gap: 0.5rem; }
    .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; }
    .posting-status-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem; }
    .status-box { padding: 1rem; border-radius: 8px; }
    .status-box h4 { font-size: 0.85rem; margin-bottom: 0.5rem; font-weight: 500; }
    .employment-box { background: rgba(52,152,219,0.1); border-left: ; }
    .current-box { background: rgba(243,156,18,0.1); border-left: ; }
    .form-label { font-weight: 500; color: #475569; margin-bottom: 0.5rem; display: block; font-size: 0.8rem; }
    .required::after { content: " *"; color: #dc3545; }
    .form-control { width: 100%; padding: 0.6rem 0.75rem; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 0.85rem; background: #fff; }
    .form-control:focus { outline: none; border-color: #1a5632; box-shadow: 0 0 0 3px rgba(26,86,50,0.1); }
    select.form-control { appearance: none; background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e"); background-repeat: no-repeat; background-position: right 0.75rem center; background-size: 1rem; padding-right: 2rem; }
    .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem; margin-bottom: 1.5rem; }
    .date-container { background: rgba(241,196,15,0.08); border: 1px solid rgba(241,196,15,0.2); border-radius: 8px; padding: 1rem; }
    .date-container h4 { color: #d97706; font-size: 0.85rem; margin-bottom: 0.75rem; font-weight: 500; }
    .btn { padding: 0.5rem 1rem; border-radius: 8px; font-size: 0.8rem; font-weight: 500; text-decoration: none; display: inline-flex; align-items: center; gap: 0.5rem; border: none; cursor: pointer; }
    .btn-secondary { background: #f1f5f9; color: #475569; }
    .btn-secondary:hover { background: #e2e8f0; }
    .btn-success { background: #1a5632; color: #fff; }
    .btn-success:hover { background: #1f6b3e; }
    .btn-danger { background: #e74c3c; color: #fff; }
    .btn-view { background: #1a5632; color: #fff; padding: 0.3rem 0.6rem; font-size: 0.75rem; border-radius: 4px; text-decoration: none; display: inline-flex; align-items: center; gap: 0.3rem; cursor: pointer; }
    .btn-delete { background: #e74c3c; color: #fff; padding: 0.3rem 0.6rem; font-size: 0.75rem; border-radius: 4px; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 0.3rem; }
    .alert { padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1rem; border-left: 4px solid; font-size: 0.85rem; display: flex; align-items: center; gap: 0.5rem; }
    .alert-danger { background: rgba(220,53,69,0.1); color: #991b1b; border-left-color: #dc3545; }
    .alert-success { background: rgba(26,86,50,0.1); color: #155724; border-left-color: #1a5632; }
    .alert-info { background: rgba(59,130,246,0.1); color: #1e40af; border-left-color: #3b82f6; }
    .posting-history-box { background: rgba(155,89,182,0.05); border-left: ; padding: 1rem; border-radius: 8px; margin-top: 1rem; }
    .history-table { width: 100%; border-collapse: collapse; font-size: 0.8rem; }
    .history-table th { background: rgba(155,89,182,0.1); padding: 0.6rem; text-align: left; font-weight: 500; }
    .history-table td { padding: 0.6rem; border-bottom: 1px solid #e2e8f0; }
    .badge { display: inline-block; padding: 0.2rem 0.5rem; font-size: 0.7rem; font-weight: 500; border-radius: 12px; }
    .badge-success { background: #1a5632; color: #fff; }
    .badge-primary { background: #1a5632; color: #fff; }
    .badge-info { background: #16a34a; color: #fff; }
    .badge-purple { background: #7e22ce; color: #fff; }
    .documents-table { width: 100%; border-collapse: collapse; font-size: 0.8rem; }
    .documents-table th { background: rgba(41,128,185,0.08); padding: 0.6rem; font-weight: 500; }
    .documents-table td { padding: 0.6rem; border-bottom: 1px solid #e2e8f0; }
    .upload-area { background: rgba(41,128,185,0.05); border: 1px solid #2980b9; border-radius: 8px; padding: 1rem; margin-bottom: 1rem; display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; }
    .upload-area input[type="file"] { flex: 1; padding: 0.5rem; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 0.8rem; }
    .text-muted { color: #64748b !important; font-size: 0.75rem; }
    .text-center { text-align: center; }
    .mt-2 { margin-top: 0.5rem; }
    .modal { display: none; position: fixed; z-index: 9999; left:0; top:0; width:100%; height:100%; background: rgba(0,0,0,0.8); }
    .modal-content { position: relative; background: #fff; margin:5% auto; width:90%; max-width:1000px; height:85%; border-radius:12px; display:flex; flex-direction:column; }
    .modal-header { padding:1rem 1.5rem; background:linear-gradient(135deg,#1a5632,#1f6b3e); color:#fff; border-radius:12px 12px 0 0; display:flex; justify-content:space-between; align-items:center; }
    .close-modal { background:none; border:none; color:#fff; font-size:1.5rem; cursor:pointer; }
    .modal-body { flex:1; padding:1rem; overflow-y:auto; background:#f8fafc; }
    @media (max-width:768px) { .form-grid { grid-template-columns:1fr; } .info-grid { grid-template-columns:1fr; } .posting-status-grid { grid-template-columns:1fr; } }
    @media (max-width:576px) { .upload-area { flex-direction:column; } }
</style>

<div class="card"><div class="card-header"><i class="fas fa-edit"></i> Update Posting - Service No: <?php echo htmlspecialchars($serviceNo); ?></div></div>

<?php if (!empty($securityViolation)): ?>
    <div class="card" style="margin-top: 1.5rem; border: 1px solid #fecaca; background: #fff5f5; border-radius: 12px; padding: 2.5rem; text-align: center; max-width: 650px; margin-left: auto; margin-right: auto; box-shadow: 0 10px 25px rgba(220, 38, 38, 0.08);">
        <div style="width: 52px; height: 52px; border-radius: 50%; background: #fee2e2; color: #dc2626; display: flex; align-items: center; justify-content: center; font-size: 1.4rem; margin: 0 auto 1.25rem; border: 1px solid #fca5a5;">
            <i class="fas fa-user-shield"></i>
        </div>
        <h2 style="color: #991b1b; font-size: 1.25rem; font-weight: 700; margin-bottom: 0.5rem;">Access Denied &bull; Security Violation</h2>
        <p style="color: #7f1d1d; font-size: 0.85rem; line-height: 1.5; margin-bottom: 1.5rem;">
            <?php echo htmlspecialchars($securityViolation); ?>
        </p>
        <div style="display: flex; justify-content: center; gap: 0.75rem;">
            <a href="search" class="btn btn-success" style="background: #1a5632; border: none; padding: 0.6rem 1.25rem; border-radius: 8px; font-weight: 600; text-decoration: none; color: #fff;">
                <i class="fas fa-search"></i> Return to Official Search
            </a>
            <a href="dashboard" class="btn btn-secondary" style="background: #e2e8f0; color: #475569; padding: 0.6rem 1.25rem; border-radius: 8px; font-weight: 600; text-decoration: none;">
                <i class="fas fa-home"></i> Go to Dashboard
            </a>
        </div>
    </div>
<?php else: ?>

<?php if ($error): ?><div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div><?php endif; ?>
<?php if ($upload_error): ?><div class="alert alert-danger"><?php echo $upload_error; ?></div><?php endif; ?>
<?php if ($upload_success): ?><div class="alert alert-success"><?php echo $upload_success; ?></div><?php endif; ?>

<?php if (!empty($employment_data)): ?>
    <div class="personal-info">
        <h3 class="info-title"><i class="fas fa-user"></i> Personnel Details</h3>
        <div class="info-grid">
            <div><strong>Service Number:</strong><br><?php echo htmlspecialchars($employment_data['serviceNo']); ?></div>
            <div><strong>Full Name:</strong><br><?php echo htmlspecialchars(trim(($personal_data['surname'] ?? '') . ' ' . ($personal_data['firstName'] ?? '') . ' ' . ($personal_data['middleName'] ?? ''))); ?></div>
            <div><strong>Current Rank:</strong><br><?php echo htmlspecialchars($employment_data['currentRank'] ?? 'N/A'); ?></div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><i class="fas fa-map-marker-alt"></i> Current Posting Status</div>
        <div class="card-body">
            <div class="posting-status-grid">
                <div class="status-box employment-box">
                    <h4><i class="fas fa-address-card"></i> Employment Record</h4>
                    <strong>Original Posting:</strong><br><?php echo htmlspecialchars($employment_data['presentPosting'] ?? 'Not set'); ?>
                </div>
                <div class="status-box current-box">
                    <h4><i class="fas fa-sync-alt"></i> Current Posting</h4>
                    <?php if ($current_posting_data): ?>
                        <strong>Current Location:</strong><br><?php echo htmlspecialchars($current_posting_data['posting_location']); ?><br>
                        <small class="text-muted"><i class="fas fa-clock"></i> Updated: <?php echo $current_posting_data['updated_at']; ?></small>
                    <?php else: ?>
                        <em class="text-muted">No current posting record</em>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- POST ORDER DOCUMENTS (COMPACT & BEFORE UPDATE POSTING) -->
    <div class="card" id="postOrderDocumentsCard" style="max-width: 800px;">
        <div class="card-header"><i class="fas fa-file-upload"></i> Post Order Documents <span class="text-muted">(<?php echo count($post_order_documents); ?>)</span></div>
        <div class="card-body">
            <div class="upload-area" style="max-width: 100%;">
                <form method="POST" enctype="multipart/form-data" style="display:flex;flex-wrap:wrap;gap:0.5rem;width:100%;align-items:center;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="file" name="post_order_document" accept=".pdf,.jpg,.jpeg,.docx" style="flex:1;min-width:200px;">
                    <button type="submit" class="btn btn-success"><i class="fas fa-upload"></i> Upload Document</button>
                </form>
                <div class="text-muted" style="width:100%;font-size:0.75rem;margin-top:0.35rem;"><i class="fas fa-info-circle"></i> Allowed: PDF, JPG, JPEG, DOCX | Max Size: 10MB</div>
            </div>
            <?php if (!empty($post_order_documents)): ?>
                <div style="overflow-x:auto">
                    <table class="documents-table">
                        <thead><tr><th>#</th><th>File Name</th><th>Type</th><th>Size</th><th>Uploaded By</th><th>Date</th><th>Action</th></tr></thead>
                        <tbody>
                            <?php $dc=1; foreach($post_order_documents as $doc): ?>
                            <tr>
                                <td><?php echo $dc++; ?></td>
                                <td><strong><?php echo htmlspecialchars($doc['file_name']); ?></strong></td>
                                <td><span class="badge badge-info"><?php echo strtoupper($doc['file_type']); ?></span></td>
                                <td><?php echo round($doc['file_size']/1024,1).' KB'; ?></td>
                                <td><?php echo htmlspecialchars($doc['uploaded_by']); ?></td>
                                <td><?php echo date('d/m/Y', strtotime($doc['uploaded_at'])); ?></td>
                                <td>
                                    <button onclick="viewDocument('<?php echo htmlspecialchars($doc['file_path']); ?>','<?php echo $doc['file_type']; ?>','<?php echo htmlspecialchars(addslashes($doc['file_name'])); ?>')" class="btn-view" title="View Document"><i class="fas fa-eye"></i> View</button>
                                    <?php if ($canEditPosting): ?>
                                    <!-- <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this document?');">
                                        <input type="hidden" name="delete_doc_id" value="<?php echo $doc['id']; ?>">
                                        <button type="submit" class="btn-delete" title="Delete Document"><i class="fas fa-trash"></i> Delete</button>
                                    </form> -->
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?><div class="text-center" style="padding:1rem;color:#64748b;font-size:0.8rem;"><p style="margin:0;"><i class="fas fa-folder-open" style="font-size:1.2rem;display:block;margin-bottom:0.25rem;color:#cbd5e1;"></i> No post order documents uploaded yet.</p></div><?php endif; ?>
        </div>
    </div>

    <!-- UPDATE POSTING LOCATION -->
    <div class="card">
        <div class="card-header">
            <i class="fas fa-edit"></i> Update Posting Location
            <?php if (!$canEditPosting): ?><span class="badge badge-info" style="margin-left:0.5rem;">View Only (<?php echo htmlspecialchars($userRole); ?>)</span><?php endif; ?>
        </div>
        <div class="card-body">
            <?php if (!$canEditPosting): ?><div class="alert alert-info"><i class="fas fa-info-circle"></i> You have <strong>view-only</strong> access.</div><?php endif; ?>
            <form method="POST" id="postingForm" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <div class="form-grid">
                    <div>
                        <label class="form-label required"><i class="fas fa-map-marker-alt"></i> New Posting Location</label>
                        <select class="form-control" name="presentPosting" id="presentPostingSelect" required <?php echo !$canEditPosting?'disabled':''; ?>>
                            <option value="">Select Formation</option>
                            <?php foreach ($allowedFormations as $zone => $formations): ?>
                                <optgroup label="<?php echo htmlspecialchars($zone); ?>">
                                    <?php foreach ($formations as $code => $name): ?>
                                        <option value="<?php echo htmlspecialchars($name); ?>" <?php echo (($current_posting_data?$current_posting_data['posting_location']:$employment_data['presentPosting']??'')==$name)?'selected':''; ?>><?php echo htmlspecialchars($name); ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                            <optgroup label="Other"><option value="other">Other (Specify)</option></optgroup>
                        </select>
                        <div class="mt-2" id="customPostingContainer" style="display:none">
                            <input type="text" class="form-control" id="customPosting" name="customPosting" placeholder="Enter custom location" <?php echo !$canEditPosting?'disabled':''; ?>>
                        </div>
                        <div class="mt-2" id="lgaContainer" style="display:none">
                            <label class="form-label" style="font-weight:500; font-size:0.8rem;">Local Government Area <span style="font-weight:400; color:#64748b;">(optional)</span></label>
                            <select class="form-control" id="lgaSelect" name="lga" <?php echo !$canEditPosting?'disabled':''; ?>>
                                <option value="">-- Not applicable / leave blank --</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="form-label required"><i class="fas fa-random"></i> Movement Type</label>
                        <select class="form-control" name="posting_type" required <?php echo !$canEditPosting?'disabled':''; ?>>
                            <option value="transfer" selected>Transfer</option>
                            <option value="redeployment">Redeployment</option>
                            <option value="initial">Initial Deployment (First Posting)</option>
                        </select>
                    </div>
                    <div>
                        <div class="date-container">
                            <h4><i class="fas fa-calendar-alt"></i> Posting Date</h4>
                            <label class="form-label required">Effective Date</label>
                            <input type="date" class="form-control" name="posting_date" id="posting_date" required value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>" <?php echo !$canEditPosting?'disabled':''; ?>>
                        </div>
                    </div>
                </div>

                <div id="documentRequiredNotice" class="alert alert-danger" style="display:none; margin-top:1rem;"><i class="fas fa-exclamation-triangle"></i> A <strong>Post Order Document</strong> must be uploaded before this posting can be updated. Please upload one in the "Post Order Documents" section above.</div>

                <div class="btn-action-group" style="margin-top:1.25rem">
                    <a href="search?serviceNo=<?php echo urlencode($serviceNo); ?>" class="btn btn-secondary"><i class="fas fa-times"></i> Cancel</a>
                    <?php if ($canEditPosting): ?><button type="submit" class="btn btn-success" id="updatePostingBtn" <?php echo empty($post_order_documents) ? 'style="opacity:0.6;" title="A Post Order Document is required first"' : ''; ?>><i class="fas fa-save"></i> Update Posting</button><?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <div class="posting-history-box">
        <h3 class="info-title"><i class="fas fa-history"></i> Posting History <span class="text-muted">(<?php echo count($posting_history); ?>)</span></h3>
        <?php if (!empty($posting_history)): ?>
            <div style="overflow-x:auto">
                <table class="history-table">
                    <thead><tr><th>#</th><th>Location</th><th>Date</th><th>Type</th><th>Recorded</th></tr></thead>
                    <tbody>
                        <?php $hc=1; foreach($posting_history as $index => $h): 
                            $isMostRecentRow = ($index === 0);
                            $isCurrentActive = $isMostRecentRow && $current_posting_data && ($h['posting_location'] === $current_posting_data['posting_location']);
                            $displayType = $isMostRecentRow ? strtolower($h['posting_type'] ?? 'transfer') : ($h['posting_type'] === 'current' ? 'transfer' : strtolower($h['posting_type'] ?? 'transfer'));
                            $typeBadgeStyle = $displayType === 'initial' ? 'badge-primary' : ($displayType === 'redeployment' ? 'badge-purple' : ($displayType === 'current' ? 'badge-success' : 'badge-info'));
                        ?>
                        <tr>
                            <td><?php echo $hc++; ?></td>
                            <td>
                                <?php echo htmlspecialchars($h['posting_location']); ?> 
                                <?php if($isCurrentActive): ?><span class="badge badge-success">Current</span><?php endif; ?>
                            </td>
                            <td><?php echo date('d/m/Y', strtotime($h['posting_date'])); ?></td>
                            <td><span class="badge <?php echo $typeBadgeStyle; ?>"><?php echo ucfirst($displayType); ?></span></td>
                            <td><?php echo date('d/m/Y H:i', strtotime($h['created_at'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?><div class="text-center" style="padding:1.5rem;color:#64748b"><p>No posting history.</p></div><?php endif; ?>
    </div>
<?php else: ?>
    <div class="alert alert-warning text-center"><i class="fas fa-exclamation-triangle"></i> No personnel data found.</div>
<?php endif; ?>
<?php endif; ?>

<!-- Native In-App Document Rendering Engine (PDF.js & Mammoth.js) -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/mammoth/1.6.0/mammoth.browser.min.js"></script>

<div id="documentModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><span id="modalTitle"><i class="fas fa-file-alt"></i> Document Viewer</span></h3>
            <button class="close-modal" onclick="closeModal()">&times;</button>
        </div>
        <div class="modal-body" id="modalBody"></div>
    </div>
</div>

<script>
function escapeHtml(text) {
    var map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
    return String(text || '').replace(/[&<>"']/g, function(m) { return map[m]; });
}

function viewDocument(filePath, fileType, fileName) {
    fileType = (fileType || '').toLowerCase();
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-file-alt"></i> ' + escapeHtml(fileName);
    
    var modalBody = document.getElementById('modalBody');
    modalBody.innerHTML = '<div style="text-align:center; padding:3rem;"><i class="fas fa-spinner fa-spin" style="font-size:2rem; color:#1a5632;"></i><p style="margin-top:0.75rem; color:#64748b; font-weight:600;">Opening in-app document preview...</p></div>';

    var toolbarHtml = '<div style="display:flex; align-items:center; justify-content:space-between; background:#e2e8f0; padding:0.6rem 1rem; border-radius:8px; margin-bottom:1rem; flex-wrap:wrap; gap:0.5rem;">'
        + '<div style="font-size:0.8rem; color:#334155; font-weight:600;"><i class="fas fa-paperclip"></i> ' + escapeHtml(fileName) + ' <span class="badge" style="margin-left:6px; background:#1a5632; color:#fff; font-size:0.7rem; padding:2px 7px; border-radius:10px;">' + fileType.toUpperCase() + '</span></div>'
        + '<div style="display:flex; gap:0.5rem;">'
        + '<a href="' + filePath + '" target="_blank" class="btn btn-sm" style="background:#475569; color:#fff; font-size:0.75rem; padding:0.35rem 0.75rem; border-radius:6px; text-decoration:none;"><i class="fas fa-external-link-alt"></i> Open Full Tab</a>'
        + '<a href="' + filePath + '" download class="btn btn-sm" style="background:#16a34a; color:#fff; font-size:0.75rem; padding:0.35rem 0.75rem; border-radius:6px; text-decoration:none;"><i class="fas fa-download"></i> Download</a>'
        + '</div>'
        + '</div>';

    // 1. PDF FILES: Render via PDF.js Canvas (No iframe, 100% immune to "Content is blocked" errors)
    if (fileType === 'pdf') {
        modalBody.innerHTML = toolbarHtml + '<div id="pdfCanvasContainer" style="width:100%; max-height:75vh; overflow-y:auto; background:#334155; padding:1.5rem 1rem; border-radius:8px; text-align:center;">'
            + '<div style="text-align:center; padding:3rem; color:#fff;"><i class="fas fa-spinner fa-spin" style="font-size:2rem; color:#2ecc71;"></i><p style="margin-top:0.75rem; font-weight:600;">Rendering PDF pages in-app...</p></div>'
            + '</div>';

        if (window.pdfjsLib) {
            pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.worker.min.js';
            pdfjsLib.getDocument(filePath).promise.then(function(pdf) {
                var container = document.getElementById('pdfCanvasContainer');
                container.innerHTML = '';
                
                for (var pageNum = 1; pageNum <= pdf.numPages; pageNum++) {
                    (function(pNum) {
                        pdf.getPage(pNum).then(function(page) {
                            var scale = 1.35;
                            var viewport = page.getViewport({ scale: scale });
                            var canvas = document.createElement('canvas');
                            canvas.style.display = 'block';
                            canvas.style.margin = '0 auto 1.5rem auto';
                            canvas.style.maxWidth = '100%';
                            canvas.style.borderRadius = '4px';
                            canvas.style.boxShadow = '0 8px 24px rgba(0,0,0,0.3)';

                            var context = canvas.getContext('2d');
                            canvas.height = viewport.height;
                            canvas.width = viewport.width;
                            container.appendChild(canvas);

                            page.render({ canvasContext: context, viewport: viewport });
                        });
                    })(pageNum);
                }
            }).catch(function(err) {
                var container = document.getElementById('pdfCanvasContainer');
                container.innerHTML = '<embed src="' + filePath + '" type="application/pdf" style="width:100%; height:550px; border-radius:8px;">';
            });
        } else {
            var container = document.getElementById('pdfCanvasContainer');
            container.innerHTML = '<embed src="' + filePath + '" type="application/pdf" style="width:100%; height:550px; border-radius:8px;">';
        }
    } 
    // 2. IMAGE FILES: JPG, JPEG, PNG
    else if (fileType === 'jpg' || fileType === 'jpeg' || fileType === 'png') {
        modalBody.innerHTML = toolbarHtml + '<div style="text-align:center; padding:1rem; background:#fff; border:1px solid #e2e8f0; border-radius:8px; max-height:75vh; overflow:auto;">'
            + '<img src="' + filePath + '" alt="Document Image" style="max-width:100%; max-height:70vh; object-fit:contain; border-radius:4px; box-shadow:0 4px 12px rgba(0,0,0,0.1);">'
            + '</div>';
    } 
    // 3. WORD DOCUMENTS: DOCX, DOC (Client-side HTML parsing via Mammoth.js)
    else if (fileType === 'docx' || fileType === 'doc') {
        fetch(filePath)
            .then(function(res) { return res.arrayBuffer(); })
            .then(function(arrayBuffer) {
                if (window.mammoth) {
                    mammoth.convertToHtml({ arrayBuffer: arrayBuffer })
                        .then(function(result) {
                            var docContent = result.value || '<p style="color:#64748b;">No text content found in document.</p>';
                            modalBody.innerHTML = toolbarHtml + '<div style="background:#fff; padding:2rem; border:1px solid #cbd5e1; border-radius:8px; line-height:1.6; font-family:Segoe UI, sans-serif; color:#1e293b; max-height:70vh; overflow-y:auto; box-shadow:inset 0 1px 3px rgba(0,0,0,0.05);">'
                                + '<div style="border-bottom:2px solid #1a5632; padding-bottom:0.5rem; margin-bottom:1.5rem; font-weight:700; color:#1a5632; font-size:1.05rem;"><i class="fas fa-file-word"></i> Official Post Order Document</div>'
                                + docContent
                                + '</div>';
                        })
                        .catch(function(err) {
                            renderCleanFallback(filePath, toolbarHtml, fileName);
                        });
                } else {
                    renderCleanFallback(filePath, toolbarHtml, fileName);
                }
            })
            .catch(function(err) {
                renderCleanFallback(filePath, toolbarHtml, fileName);
            });
    } else {
        renderCleanFallback(filePath, toolbarHtml, fileName);
    }

    document.getElementById('documentModal').style.display = 'block';
    document.body.style.overflow = 'hidden';
}

function renderCleanFallback(filePath, toolbarHtml, fileName) {
    var modalBody = document.getElementById('modalBody');
    modalBody.innerHTML = toolbarHtml + '<div style="text-align:center; padding:3rem; background:#fff; border-radius:8px; border:1px solid #e2e8f0;">'
        + '<div style="width:60px; height:60px; border-radius:50%; background:#f1f5f9; display:flex; align-items:center; justify-content:center; margin:0 auto 1rem; color:#1a5632; font-size:1.5rem;"><i class="fas fa-file-word"></i></div>'
        + '<h4 style="color:#1e293b; margin-bottom:0.5rem;">' + escapeHtml(fileName) + '</h4>'
        + '<p style="color:#64748b; font-size:0.85rem; margin-bottom:1.5rem;">Click below to open or download this official document.</p>'
        + '<div style="display:flex; justify-content:center; gap:0.75rem;">'
        + '<a href="' + filePath + '" target="_blank" class="btn btn-secondary" style="background:#475569; color:#fff; padding:0.6rem 1.25rem; text-decoration:none; border-radius:6px;"><i class="fas fa-external-link-alt"></i> Open Document</a>'
        + '<a href="' + filePath + '" download class="btn btn-success" style="background:#16a34a; color:#fff; padding:0.6rem 1.25rem; text-decoration:none; border-radius:6px;"><i class="fas fa-download"></i> Download Document</a>'
        + '</div>'
        + '</div>';
}

function closeModal() {
    document.getElementById('documentModal').style.display = 'none';
    document.body.style.overflow = '';
}

window.onclick = function(e) {
    if (e.target === document.getElementById('documentModal')) closeModal();
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeModal();
});

var lgasByCommand = <?php echo json_encode($lgasByCommand); ?>;

function refreshLgaOptions() {
    var s = document.getElementById('presentPostingSelect');
    var lgaContainer = document.getElementById('lgaContainer');
    var lgaSelect = document.getElementById('lgaSelect');
    if (!s || !lgaContainer || !lgaSelect) return;

    var lgas = lgasByCommand[s.value];
    if (s.value !== 'other' && lgas && lgas.length > 0) {
        lgaSelect.innerHTML = '<option value="">-- Not applicable / leave blank --</option>' +
            lgas.map(function(lga) { return '<option value="' + lga.replace(/"/g, '&quot;') + '">' + lga + '</option>'; }).join('');
        lgaContainer.style.display = 'block';
    } else {
        lgaSelect.innerHTML = '<option value="">-- Not applicable / leave blank --</option>';
        lgaContainer.style.display = 'none';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    var s = document.getElementById('presentPostingSelect'), c = document.getElementById('customPostingContainer');
    if (s && c) {
        s.addEventListener('change', function() {
            c.style.display = this.value === 'other' ? 'block' : 'none';
            refreshLgaOptions();
        });
        refreshLgaOptions();
    }
    var d = document.getElementById('posting_date');
    if (d) { d.addEventListener('change', function() { if (new Date(this.value) > new Date()) { alert('Date cannot be in the future.'); this.value = new Date().toISOString().split('T')[0]; } }); }

    var hasPostOrderDocument = <?php echo !empty($post_order_documents) ? 'true' : 'false'; ?>;
    var postingForm = document.getElementById('postingForm');
    var docNotice = document.getElementById('documentRequiredNotice');
    var docCard = document.getElementById('postOrderDocumentsCard');
    if (postingForm) {
        postingForm.addEventListener('submit', function(e) {
            if (!hasPostOrderDocument) {
                e.preventDefault();
                if (docNotice) docNotice.style.display = 'block';
                if (docCard) {
                    docCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    docCard.style.transition = 'box-shadow 0.3s ease';
                    docCard.style.boxShadow = '0 0 0 3px #dc2626';
                    setTimeout(function() { docCard.style.boxShadow = ''; }, 2000);
                }
            }
        });
    }
});
</script>

</div><!-- close main-content -->
</div><!-- close content-wrapper -->
</div><!-- close dashboard-container -->


</body>
</html>
<?php include 'includes/footer.php'; ?>