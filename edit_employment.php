<?php
// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start session
session_start();

require_once 'includes/config.php';
require_once 'includes/auth.php';
require_once 'includes/permissions.php';
require_once 'includes/security.php';

// Check if user is logged in
if (!isLoggedIn()) {
    header('Location: login');
    exit();
}

// This edits disciplinary status, promotion eligibility, and posting for any
// service number - same posting-initiation privilege required elsewhere.
if (!canInitiatePosting()) {
    header('Location: dashboard');
    exit();
}

$error = '';
$success = '';
$csrfToken = generateCSRFToken();
$employment_data = [];
$personal_data = [];
$current_posting_data = [];
$postingNote = '';

// Define Nigeria Immigration Service formations
$nis_formations = [
    'NIS Headquarters, Abuja',
    'Zone A Headquarters, Lagos',
    'Zone B Headquarters, Kano',
    'Zone C Headquarters, Port Harcourt',
    'Zone D Headquarters, Sokoto',
    'Zone E Headquarters, Enugu',
    'Zone F Headquarters, Ilorin',
    'Murtala Mohammed International Airport, Lagos',
    'Nnamdi Azikiwe International Airport, Abuja',
    'Port Harcourt International Airport',
    'Mallam Aminu Kano International Airport',
    'Akanu Ibiam International Airport, Enugu',
    'Seme Border Command',
    'Idiroko Border Command',
    'Katsina Border Command',
    'Maiduguri Border Command',
    'Calabar Border Command',
    'Training School, Kano',
    'Training School, Ahoada',
    'Passport Office, Lagos',
    'Passport Office, Abuja',
    'Passport Office, Port Harcourt',
    'Passport Office, Kano',
    'Marine Command, Lagos',
    'Investigation and Enforcement Directorate',
    'Planning, Research and Statistics Directorate',
    'Finance and Accounts Directorate',
    'Administration and Human Resources Directorate'
];

require_once 'includes/security.php';

// Get serviceNo from URL parameter
if (!isset($_GET['serviceNo'])) {
    header('Location: search');
    exit();
}

$serviceNo = $_GET['serviceNo'];
$token = $_GET['token'] ?? $_POST['token'] ?? '';

// Check User Role & IDOR Cryptographic Token
$userRole = $_SESSION['role_name'] ?? '';
$isUserRole = (strcasecmp(trim($userRole), 'User') === 0);

if ($isUserRole || !verifyRecordToken($serviceNo, $token)) {
    if (function_exists('logActivity')) {
        logActivity($pdo, $_SESSION['user_id'] ?? 0, 'Security Violation', "URL Tampering Blocked (IDOR): Invalid token for edit_employment.php?serviceNo=" . $serviceNo);
    }
    die("<div style='max-width:650px;margin:5rem auto;padding:2.5rem;background:#fff5f5;border:1px solid #fecaca;border-radius:12px;text-align:center;font-family:sans-serif;box-shadow:0 10px 25px rgba(220,38,38,0.1);'><div style='width:46px;height:46px;border-radius:50%;background:#fee2e2;color:#dc2626;display:flex;align-items:center;justify-content:center;font-size:1.25rem;margin:0 auto 1rem;'>&times;</div><h2 style='color:#991b1b;margin-bottom:0.5rem;'>403 Forbidden: Security Violation</h2><p style='color:#7f1d1d;line-height:1.5;'>Direct modification of URL parameters is strictly prohibited. Please access officer records through the official search panel.</p><a href='search' style='display:inline-block;margin-top:1.25rem;padding:0.6rem 1.25rem;background:#1a5632;color:#fff;border-radius:8px;text-decoration:none;font-weight:600;'>Return to Personnel Search</a></div>");
}


// Create database connection
$pdo_idcard = null;

try {
    // Create connection to niimscom_idcard database
    $pdo_idcard = new PDO(
        "mysql:host={$db_config['niimscom_idcard']['host']};dbname={$db_config['niimscom_idcard']['dbname']};charset=utf8mb4",
        $db_config['niimscom_idcard']['username'],
        $db_config['niimscom_idcard']['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
    
    // Fetch employment data from niimscom_idcard database
    $stmt = $pdo_idcard->prepare("
        SELECT 
            e.serviceNo,
            e.dofa,
            e.dopa,
            e.presentPosting,
            e.currentRank,
            e.empStatus
        FROM tbl_employment e
        WHERE e.serviceNo = ?
    ");
    $stmt->execute([$serviceNo]);
    $employment_data = $stmt->fetch();
    
    if (!$employment_data) {
        $error = "Employment record not found for service number: " . htmlspecialchars($serviceNo);
    } else {
        // Create a local current_posting table in niimscom_idcard to simulate personnel_db
        try {
            // Create current_posting table if it doesn't exist
            $createTableSQL = "
                CREATE TABLE IF NOT EXISTS `current_posting` (
                    `id` INT(11) NOT NULL AUTO_INCREMENT,
                    `serviceNo` VARCHAR(50) NOT NULL,
                    `posting_location` TEXT NOT NULL,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `unique_serviceNo` (`serviceNo`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ";
            $pdo_idcard->exec($createTableSQL);
            
            // Try to fetch current posting from the local table
            $postingStmt = $pdo_idcard->prepare("
                SELECT posting_location, updated_at 
                FROM current_posting 
                WHERE serviceNo = ?
            ");
            $postingStmt->execute([$serviceNo]);
            $current_posting_data = $postingStmt->fetch();
            
            // If posting exists in local table, show comparison
            if ($current_posting_data) {
                if ($employment_data['presentPosting'] !== $current_posting_data['posting_location']) {
                    $postingNote = "<div class='alert alert-warning'>
                        <i class='fas fa-exclamation-triangle'></i> <strong>Posting Mismatch Detected!</strong><br>
                        • Employment record shows: <strong>" . htmlspecialchars($employment_data['presentPosting']) . "</strong><br>
                        • Current posting database shows: <strong>" . htmlspecialchars($current_posting_data['posting_location']) . "</strong><br>
                        • Last updated in posting DB: " . $current_posting_data['updated_at'] . "
                    </div>";
                } else {
                    $postingNote = "<div class='alert alert-success'>
                        <i class='fas fa-check-circle'></i> Posting information is synchronized between records.
                        Last updated: " . $current_posting_data['updated_at'] . "
                    </div>";
                }
            } else {
                // No record in current_posting table
                $postingNote = "<div class='alert alert-info'>
                    <i class='fas fa-info-circle'></i> No posting record found in current posting database for this service number.
                    A new record will be created when you update the posting.
                </div>";
            }
            
        } catch (PDOException $e) {
            $postingNote = "<div class='alert alert-danger'>
                <i class='fas fa-exclamation-circle'></i> Error accessing posting database: " . htmlspecialchars($e->getMessage()) . "
            </div>";
        }
    }
    
    // Also fetch personal data for display from same database
    $stmt = $pdo_idcard->prepare("
        SELECT surname, firstName, middleName 
        FROM tbl_emppersonal 
        WHERE serviceNo = ?
    ");
    $stmt->execute([$serviceNo]);
    $personal_data = $stmt->fetch();
    
} catch (PDOException $e) {
    $error = "Error fetching records: " . $e->getMessage();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !validateCSRFToken($_POST['csrf_token'] ?? '')) {
    $error = "Security validation failed. Please refresh the page and try again.";
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dofa = sanitizeInput($_POST['dofa']);
    $dopa = sanitizeInput($_POST['dopa']);
    $presentPosting = sanitizeInput($_POST['presentPosting']);
    $currentRank = sanitizeInput($_POST['currentRank']);
    $empStatus = sanitizeInput($_POST['empStatus']);
    
    // Check if custom posting is used
    if (isset($_POST['presentPosting']) && $_POST['presentPosting'] === 'other' && isset($_POST['customPosting'])) {
        $presentPosting = sanitizeInput($_POST['customPosting']);
    }

    try {
        // Re-establish connection if not already established
        if (!$pdo_idcard) {
            $pdo_idcard = new PDO(
                "mysql:host={$db_config['niimscom_idcard']['host']};dbname={$db_config['niimscom_idcard']['dbname']};charset=utf8mb4",
                $db_config['niimscom_idcard']['username'],
                $db_config['niimscom_idcard']['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        }
        
        // Store old values for comparison
        $oldPosting = '';
        if ($current_posting_data) {
            $oldPosting = $current_posting_data['posting_location'];
        } elseif ($employment_data) {
            $oldPosting = $employment_data['presentPosting'];
        }

        // **ONLY UPDATE the current_posting table (simulating personnel_db)**
        // DO NOT update tbl_employment.presentPosting
        
        $postingUpdated = false;
        $postingAction = '';
        $transactionStarted = false;
        
        try {
            // Start transaction for data consistency
            $pdo_idcard->beginTransaction();
            $transactionStarted = true;
            
            // Ensure current_posting table exists
            $createTableSQL = "
                CREATE TABLE IF NOT EXISTS `current_posting` (
                    `id` INT(11) NOT NULL AUTO_INCREMENT,
                    `serviceNo` VARCHAR(50) NOT NULL,
                    `posting_location` TEXT NOT NULL,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `unique_serviceNo` (`serviceNo`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ";
            $pdo_idcard->exec($createTableSQL);
            
            // Check if record exists
            $checkStmt = $pdo_idcard->prepare("
                SELECT COUNT(*) as count FROM current_posting WHERE serviceNo = ?
            ");
            $checkStmt->execute([$serviceNo]);
            $result = $checkStmt->fetch();
            
            if ($result['count'] > 0) {
                // Update existing record
                $updateStmt = $pdo_idcard->prepare("
                    UPDATE current_posting 
                    SET posting_location = ?, updated_at = NOW()
                    WHERE serviceNo = ?
                ");
                $updateStmt->execute([$presentPosting, $serviceNo]);
                $postingUpdated = true;
                $postingAction = "updated in current posting database";
            } else {
                // Insert new record
                $insertStmt = $pdo_idcard->prepare("
                    INSERT INTO current_posting (serviceNo, posting_location, created_at, updated_at)
                    VALUES (?, ?, NOW(), NOW())
                ");
                $insertStmt->execute([$serviceNo, $presentPosting]);
                $postingUpdated = true;
                $postingAction = "created in current posting database";
            }
            
            // Commit transaction if everything was successful
            if ($transactionStarted) {
                $pdo_idcard->commit();
                $transactionStarted = false;
            }
            
            // Update Disciplinary & Promotion fields in tbl_employment
            $disciplinary_status = sanitizeInput($_POST['disciplinary_status'] ?? 'Clean');
            $disciplinary_remarks = sanitizeInput($_POST['disciplinary_remarks'] ?? '');
            $promotion_eligibility = sanitizeInput($_POST['promotion_eligibility'] ?? 'Eligible');
            $promotion_remarks = sanitizeInput($_POST['promotion_remarks'] ?? '');

            $updateEmpStmt = $pdo->prepare("UPDATE tbl_employment SET disciplinary_status = ?, disciplinary_remarks = ?, promotion_eligibility = ?, promotion_remarks = ? WHERE serviceNo = ?");
            $updateEmpStmt->execute([$disciplinary_status, $disciplinary_remarks, $promotion_eligibility, $promotion_remarks, $serviceNo]);
            
            
        } catch (PDOException $personnelError) {
            // Rollback only if transaction was started
            if ($transactionStarted) {
                try {
                    $pdo_idcard->rollBack();
                    $transactionStarted = false;
                } catch (PDOException $rollbackError) {
                    // Ignore rollback errors
                }
            }
            
            $postingUpdated = false;
            $postingAction = "failed to update posting database";
            $postingError = $personnelError->getMessage();
            
            throw new Exception("Failed to update posting database: " . $postingError);
        }

        // Success message - only about posting database update
        if (!empty($oldPosting) && $oldPosting !== $presentPosting) {
            if ($postingUpdated) {
                $success = "Present posting changed from '" . 
                           htmlspecialchars($oldPosting) . "' to '" . 
                           htmlspecialchars($presentPosting) . "'.<br>";
                $success .= "Posting record " . $postingAction . ".";
            } else {
                $success = "Could not update posting database.<br>";
                $success .= "Error: " . htmlspecialchars($postingError ?? 'Unknown error');
            }
        } else {
            if ($postingUpdated) {
                $success = "Posting record " . $postingAction . ".";
            } else {
                $success = "No changes to posting location.";
            }
        }

        // Refresh the data
        // Refresh employment data
        $stmt = $pdo_idcard->prepare("
            SELECT 
                e.serviceNo,
                e.dofa,
                e.dopa,
                e.presentPosting,
                e.currentRank,
                e.empStatus
            FROM tbl_employment e
            WHERE e.serviceNo = ?
        ");
        $stmt->execute([$serviceNo]);
        $employment_data = $stmt->fetch();

        // Refresh current posting data
        $postingStmt = $pdo_idcard->prepare("
            SELECT posting_location, updated_at 
            FROM current_posting 
            WHERE serviceNo = ?
        ");
        $postingStmt->execute([$serviceNo]);
        $current_posting_data = $postingStmt->fetch();

        // Update posting note
        if ($current_posting_data) {
            if ($employment_data['presentPosting'] !== $current_posting_data['posting_location']) {
                $postingNote = "<div class='alert alert-warning'>
                    <i class='fas fa-exclamation-triangle'></i> <strong>Posting Mismatch Detected!</strong><br>
                    • Employment record shows: <strong>" . htmlspecialchars($employment_data['presentPosting']) . "</strong><br>
                    • Current posting database shows: <strong>" . htmlspecialchars($current_posting_data['posting_location']) . "</strong><br>
                    • Last updated in posting DB: " . $current_posting_data['updated_at'] . "
                </div>";
            } else {
                $postingNote = "<div class='alert alert-success'>
                    <i class='fas fa-check-circle'></i> Posting information is synchronized between records.
                    Last updated: " . $current_posting_data['updated_at'] . "
                </div>";
            }
        }

    } catch (PDOException $e) {
        $error = "Error updating records: " . $e->getMessage();
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NIS Personnel Posting Management System - Edit Employment</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Bootstrap CSS for responsive grid -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        /* CSS Reset */
        * { 
            margin: 0; 
            padding: 0; 
            box-sizing: border-box; 
        }
        
        /* Body styling */
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: 
                linear-gradient(135deg, rgba(44, 62, 80, 0.9) 0%, rgba(39, 174, 96, 0.9) 100%),
                url('assets/images/tech_building.jpg') center/cover no-repeat fixed;
            min-height: 100vh;
            color: #333;
            padding: 15px;
        }
        
        /* Header styling */
        .header-container {
            background: rgba(255, 255, 255, 0.98);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            padding: 0.8rem 2rem;
            position: sticky;
            top: 0;
            z-index: 1000;
            backdrop-filter: blur(5px);
            border-bottom: 2px solid #27ae60;
            margin: -15px -15px 20px -15px;
        }
        
        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .logo-title-container {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .header-logo {
            height: 50px;
            width: auto;
            object-fit: contain;
        }
        
        .system-title {
            color: #2c3e50;
            font-size: 1.4rem;
            font-weight: 800;
            margin: 0;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .user-profile {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #2c3e50 0%, #27ae60 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 1.1rem;
        }
        
        /* Main content container */
        .content-container {
            background: rgba(255, 255, 255, 0.98);
            border-radius: 12px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
            padding: 2rem;
            max-width: 1200px;
            margin: 0 auto;
            backdrop-filter: blur(5px);
            border: 1px solid rgba(39, 174, 96, 0.1);
        }
        
        /* Page header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .page-title-section {
            display: flex;
            align-items: center;
            gap: 1rem;
            flex: 1;
        }
        
        .back-to-dashboard {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.6rem 1.2rem;
            background: #27ae60;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            white-space: nowrap;
        }
        
        .back-to-dashboard:hover {
            background: #219a52;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(39, 174, 96, 0.3);
        }
        
        .page-title {
            color: #2c3e50;
            font-size: 1.8rem;
            font-weight: 700;
            margin: 0;
        }
        
        /* Cards */
        .card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            margin-bottom: 1.5rem;
            overflow: hidden;
            border: 1px solid rgba(39, 174, 96, 0.1);
        }
        
        .card-header {
            background: linear-gradient(135deg, #2c3e50 0%, #27ae60 100%);
            color: white;
            padding: 1.2rem 1.5rem;
            font-weight: 600;
            font-size: 1.2rem;
            border: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .card-body {
            padding: 1.5rem;
        }
        
        /* Personal info box */
        .personal-info {
            background: rgba(39, 174, 96, 0.05);
            border-left: 4px solid #27ae60;
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 2rem;
        }
        
        .info-title {
            color: #2c3e50;
            font-size: 1.3rem;
            font-weight: 600;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        /* Database info box */
        .database-info {
            background: rgba(52, 152, 219, 0.05);
            border-left: 4px solid #3498db;
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 2rem;
        }
        
        .db-source {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
        }
        
        /* Form styling */
        .form-label {
            font-weight: 600;
            color: #495057;
            margin-bottom: 0.5rem;
            display: block;
        }
        
        .required::after {
            content: " *";
            color: #dc3545;
        }
        
        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: white;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #27ae60;
            box-shadow: 0 0 0 3px rgba(39, 174, 96, 0.2);
        }
        
        .form-control::placeholder {
            color: #999;
        }
        
        select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            background-size: 1em;
            padding-right: 2.5rem;
        }
        
        .mb-3 {
            margin-bottom: 1rem;
        }
        
        .mt-2 {
            margin-top: 0.5rem;
        }
        
        .mt-4 {
            margin-top: 1.5rem;
        }
        
        .row {
            display: flex;
            flex-wrap: wrap;
            margin-right: -10px;
            margin-left: -10px;
        }
        
        .col-md-4, .col-md-6, .col-md-12 {
            position: relative;
            width: 100%;
            padding-right: 10px;
            padding-left: 10px;
        }
        
        @media (min-width: 768px) {
            .col-md-4 {
                flex: 0 0 33.333333%;
                max-width: 33.333333%;
            }
            .col-md-6 {
                flex: 0 0 50%;
                max-width: 50%;
            }
            .col-md-12 {
                flex: 0 0 100%;
                max-width: 100%;
            }
        }
        
        /* Buttons */
        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            text-align: center;
            white-space: nowrap;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 3px 10px rgba(108, 117, 125, 0.3);
        }
        
        .btn-primary {
            background: #3498db;
            color: white;
        }
        
        .btn-primary:hover {
            background: #2980b9;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 3px 10px rgba(52, 152, 219, 0.3);
        }
        
        .btn-warning {
            background: #f39c12;
            color: white;
        }
        
        .btn-warning:hover {
            background: #d68910;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 3px 10px rgba(243, 156, 18, 0.3);
        }
        
        .btn-success {
            background: #27ae60;
            color: white;
        }
        
        .btn-success:hover {
            background: #219a52;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 3px 10px rgba(39, 174, 96, 0.3);
        }
        
        /* Alerts */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            border-left: 4px solid;
            display: flex;
            align-items: center;
            gap: 0.8rem;
        }
        
        .alert-danger {
            background: rgba(220, 53, 69, 0.1);
            color: #721c24;
            border-left-color: #dc3545;
        }
        
        .alert-success {
            background: rgba(39, 174, 96, 0.1);
            color: #155724;
            border-left-color: #27ae60;
        }
        
        .alert-warning {
            background: rgba(255, 193, 7, 0.1);
            color: #856404;
            border-left-color: #ffc107;
        }
        
        .alert-info {
            background: rgba(23, 162, 184, 0.1);
            color: #0c5460;
            border-left-color: #17a2b8;
        }
        
        /* Helper text */
        .text-muted {
            color: #6c757d !important;
            font-size: 0.85rem;
        }
        
        .text-center {
            text-align: center;
        }
        
        /* Add some spacing for database indicators */
        .db-indicator {
            font-size: 0.8rem;
            color: #27ae60;
            font-weight: 600;
            margin-top: 0.3rem;
        }
        
        .db-indicator-warning {
            font-size: 0.8rem;
            color: #f39c12;
            font-weight: 600;
            margin-top: 0.3rem;
        }
        
        /* Flex utilities */
        .d-flex {
            display: flex !important;
        }
        
        .justify-content-between {
            justify-content: space-between !important;
        }
        
        .flex-wrap {
            flex-wrap: wrap !important;
        }
        
        .align-items-center {
            align-items: center !important;
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            body {
                padding: 10px;
            }
            
            .header-container {
                padding: 0.8rem 1rem;
                margin: -10px -10px 15px -10px;
            }
            
            .header-logo {
                height: 40px;
            }
            
            .system-title {
                font-size: 1.1rem;
            }
            
            .content-container {
                padding: 1.2rem;
            }
            
            .page-title {
                font-size: 1.5rem;
            }
            
            .card-body {
                padding: 1.2rem;
            }
            
            .personal-info {
                padding: 1.2rem;
            }
            
            .info-title {
                font-size: 1.1rem;
            }
            
            .btn {
                padding: 0.6rem 1.2rem;
                font-size: 0.9rem;
            }
        }
        
        @media (max-width: 576px) {
            .header-content {
                flex-direction: column;
                align-items: stretch;
            }
            
            .logo-title-container {
                flex-direction: column;
                align-items: center;
                text-align: center;
                gap: 0.5rem;
            }
            
            .system-title {
                font-size: 1rem;
            }
            
            .user-info {
                justify-content: center;
                margin-top: 0.5rem;
            }
            
            .content-container {
                padding: 1rem;
            }
            
            .page-header {
                gap: 1rem;
            }
            
            .page-title-section {
                flex-direction: column;
                align-items: stretch;
                gap: 0.8rem;
            }
            
            .back-to-dashboard {
                align-self: flex-start;
            }
            
            .personal-info .row > div {
                margin-bottom: 1rem;
            }
            
            .d-flex {
                flex-direction: column;
                gap: 1rem;
            }
            
            .d-flex .btn {
                width: 100%;
                justify-content: center;
            }
            
            .btn-group {
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .btn-group .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="header-container">
        <div class="header-content">
            <div class="logo-title-container">
                <h1 class="system-title">NIS PERSONNEL POSTING MANAGEMENT SYSTEM</h1>
            </div>
            <div class="user-info">
                <div class="user-profile">
                    <?php 
                    $username = $_SESSION['username'] ?? 'User';
                    echo strtoupper(substr($username, 0, 1)); 
                    ?>
                </div>
                <div>
                    <strong><?php echo htmlspecialchars($username); ?></strong>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="content-container">
        <!-- Page Header -->
        <div class="page-header">
            <div class="page-title-section">
                <a href="dashboard" class="back-to-dashboard">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
                <h2 class="page-title">Edit Employment Record</h2>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">
                <i class="fas fa-briefcase"></i> Employment Information
            </div>
            <div class="card-body">
                <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                    </div>
                <?php endif; ?>

                <?php if ($employment_data && $personal_data): ?>
                    <!-- Personal Information Display -->
                    <div class="personal-info">
                        <h3 class="info-title">
                            <i class="fas fa-user"></i> Personnel Details
                        </h3>
                        <div class="row">
                            <div class="col-md-4">
                                <strong>Service Number:</strong><br>
                                <?php echo htmlspecialchars($employment_data['serviceNo']); ?>
                            </div>
                            <div class="col-md-4">
                                <strong>Full Name:</strong><br>
                                <?php 
                                echo htmlspecialchars($personal_data['surname']) . ' ' . 
                                     htmlspecialchars($personal_data['firstName']) . ' ' . 
                                     htmlspecialchars($personal_data['middleName']);
                                ?>
                            </div>
                            <div class="col-md-4">
                                <strong>Current Rank:</strong><br>
                                <?php echo htmlspecialchars($employment_data['currentRank'] ?? 'N/A'); ?>
                            </div>
                        </div>
                    </div>

                    <!-- Database Information -->
                    <div class="database-info">
                        <h3 class="info-title">
                            <i class="fas fa-database"></i> Data Sources
                        </h3>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="db-source">
                                    <i class="fas fa-address-card text-primary"></i>
                                    <strong>Employment Record (tbl_employment):</strong>
                                </div>
                                <div class="mt-2">
                                    <strong>Present Posting:</strong> 
                                    <?php echo htmlspecialchars($employment_data['presentPosting'] ?? 'Not set'); ?><br>
                                    <small class="text-muted">Original employment data</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="db-source">
                                    <i class="fas fa-map-marker-alt text-success"></i>
                                    <strong>Current Posting Database:</strong>
                                </div>
                                <div class="mt-2">
                                    <?php if ($current_posting_data): ?>
                                        <strong>Current Posting:</strong> 
                                        <?php echo htmlspecialchars($current_posting_data['posting_location']); ?><br>
                                        <strong>Last Updated:</strong> 
                                        <?php echo $current_posting_data['updated_at']; ?><br>
                                        <small class="text-muted">Managed posting information</small>
                                    <?php else: ?>
                                        <em class="text-muted">No posting record found</em><br>
                                        <small class="text-muted">Will be created on update</small>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if ($postingNote): ?>
                        <?php echo $postingNote; ?>
                    <?php endif; ?>

                    <!-- Edit Form -->
                    <form method="POST" id="employmentForm">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label required">Date of First Appointment</label>
                                    <input type="date" class="form-control" name="dofa" 
                                           value="<?php echo htmlspecialchars($employment_data['dofa'] ?? ''); ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label required">Date of Present Appointment</label>
                                    <input type="date" class="form-control" name="dopa" 
                                           value="<?php echo htmlspecialchars($employment_data['dopa'] ?? ''); ?>" required>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label required">Current Posting Location</label>
                                    <select class="form-control" name="presentPosting" id="presentPostingSelect" required>
                                        <option value="">Select Formation/Posting Location</option>
                                        <?php foreach ($nis_formations as $formation): ?>
                                            <option value="<?php echo htmlspecialchars($formation); ?>"
                                                <?php 
                                                // Show current posting from posting database if available
                                                $currentPostingValue = '';
                                                if ($current_posting_data) {
                                                    $currentPostingValue = $current_posting_data['posting_location'];
                                                } else {
                                                    $currentPostingValue = $employment_data['presentPosting'];
                                                }
                                                echo ($currentPostingValue == $formation) ? 'selected' : ''; 
                                                ?>>
                                                <?php echo htmlspecialchars($formation); ?>
                                            </option>
                                        <?php endforeach; ?>
                                        <!-- Custom option for other postings -->
                                        <option value="other" 
                                            <?php 
                                            $currentPostingValue = '';
                                            if ($current_posting_data) {
                                                $currentPostingValue = $current_posting_data['posting_location'];
                                            } else {
                                                $currentPostingValue = $employment_data['presentPosting'];
                                            }
                                            echo (!in_array($currentPostingValue, $nis_formations) && !empty($currentPostingValue)) ? 'selected' : ''; 
                                            ?>>
                                            Other (Specify below)
                                        </option>
                                    </select>
                                    <div class="mt-2" id="customPostingContainer" 
                                         style="<?php 
                                         $currentPostingValue = '';
                                         if ($current_posting_data) {
                                             $currentPostingValue = $current_posting_data['posting_location'];
                                         } else {
                                             $currentPostingValue = $employment_data['presentPosting'];
                                         }
                                         echo (!in_array($currentPostingValue, $nis_formations) && !empty($currentPostingValue)) ? '' : 'display: none;'; 
                                         ?>">
                                        <input type="text" class="form-control" id="customPosting" name="customPosting"
                                               placeholder="Enter custom posting location" 
                                               value="<?php 
                                               $currentPostingValue = '';
                                               if ($current_posting_data) {
                                                   $currentPostingValue = $current_posting_data['posting_location'];
                                               } else {
                                                   $currentPostingValue = $employment_data['presentPosting'];
                                               }
                                               echo (!in_array($currentPostingValue, $nis_formations) && !empty($currentPostingValue)) ? htmlspecialchars($currentPostingValue) : ''; 
                                               ?>">
                                    </div>
                                    <div class="db-indicator">
                                        <i class="fas fa-sync-alt"></i> This will ONLY update the Current Posting Database
                                    </div>
                                    <div class="db-indicator-warning">
                                        <i class="fas fa-exclamation-triangle"></i> The Employment Record (tbl_employment) will NOT be modified
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label required">Current Rank</label>
                                    <input type="text" class="form-control" name="currentRank" 
                                           value="<?php echo htmlspecialchars($employment_data['currentRank'] ?? ''); ?>" 
                                           placeholder="Enter current rank" required>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label required">Employment Status</label>
                                    <select class="form-control" name="empStatus" required>
                                        <option value="">Select Status</option>
                                        <option value="Active" <?php echo ($employment_data['empStatus'] ?? '') == 'Active' ? 'selected' : ''; ?>>Active</option>
                                        <option value="Inactive" <?php echo ($employment_data['empStatus'] ?? '') == 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                                        <option value="Retired" <?php echo ($employment_data['empStatus'] ?? '') == 'Retired' ? 'selected' : ''; ?>>Retired</option>
                                        <option value="Suspended" <?php echo ($employment_data['empStatus'] ?? '') == 'Suspended' ? 'selected' : ''; ?>>Suspended</option>
                                        <option value="Transfered" <?php echo ($employment_data['empStatus'] ?? '') == 'Transfered' ? 'selected' : ''; ?>>Transfered</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Disciplinary Action & Promotion Eligibility Section -->
                        <div class="card mt-3 mb-4" style="border:1px solid #cbd5e1; border-radius:10px; overflow:hidden;">
                            <div class="card-header" style="background:#0f172a; color:#ffffff; font-weight:700; font-size:0.875rem; padding:0.75rem 1rem; display:flex; align-items:center; gap:0.5rem;">
                                <i class="fas fa-gavel"></i> Disciplinary Status & Sensitive Posting Guards
                            </div>
                            <div class="card-body" style="padding:1.25rem; background:#f8fafc;">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label required" style="font-weight:600;">Disciplinary Status</label>
                                            <select class="form-control" name="disciplinary_status" required style="font-weight:600;">
                                                <option value="Clean" <?php echo ($employment_data['disciplinary_status'] ?? 'Clean') == 'Clean' ? 'selected' : ''; ?>>🟢 Clean (Eligible for Posting)</option>
                                                <option value="Under Query" <?php echo ($employment_data['disciplinary_status'] ?? '') == 'Under Query' ? 'selected' : ''; ?>>⚠️ Under Query (POSTING BLOCKED)</option>
                                                <option value="Under Interdiction" <?php echo ($employment_data['disciplinary_status'] ?? '') == 'Under Interdiction' ? 'selected' : ''; ?>>⛔ Under Interdiction (POSTING BLOCKED)</option>
                                                <option value="Under Suspension" <?php echo ($employment_data['disciplinary_status'] ?? '') == 'Under Suspension' ? 'selected' : ''; ?>>🚫 Under Suspension (POSTING BLOCKED)</option>
                                                <option value="Pending Disciplinary Action" <?php echo ($employment_data['disciplinary_status'] ?? '') == 'Pending Disciplinary Action' ? 'selected' : ''; ?>>⚖️ Pending Disciplinary Action (POSTING BLOCKED)</option>
                                                <option value="Dismissed" <?php echo ($employment_data['disciplinary_status'] ?? '') == 'Dismissed' ? 'selected' : ''; ?>>❌ Dismissed (POSTING BLOCKED)</option>
                                            </select>
                                            <small class="form-text text-muted" style="font-size:0.725rem; color:#dc2626; display:block; margin-top:4px;"><i class="fas fa-shield-alt"></i> Any officer undergoing disciplinary action is strictly blocked from receiving new postings.</small>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label" style="font-weight:600;">Promotion Eligibility</label>
                                            <select class="form-control" name="promotion_eligibility">
                                                <option value="Eligible" <?php echo ($employment_data['promotion_eligibility'] ?? 'Eligible') == 'Eligible' ? 'selected' : ''; ?>>Eligible for Promotion</option>
                                                <option value="Not Eligible" <?php echo ($employment_data['promotion_eligibility'] ?? '') == 'Not Eligible' ? 'selected' : ''; ?>>Not Eligible (Disciplinary / Exam Failed)</option>
                                                <option value="Under Review" <?php echo ($employment_data['promotion_eligibility'] ?? '') == 'Under Review' ? 'selected' : ''; ?>>Under Board Promotion Review</option>
                                                <option value="Promoted" <?php echo ($employment_data['promotion_eligibility'] ?? '') == 'Promoted' ? 'selected' : ''; ?>>Promoted</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label" style="font-weight:600;">Disciplinary Case Remarks / Query Reference</label>
                                            <textarea class="form-control" name="disciplinary_remarks" rows="2" placeholder="e.g., Query Ref: NIS/HQ/DISC/2026/04 - Unauthorized Absence at Seme Border"><?php echo htmlspecialchars($employment_data['disciplinary_remarks'] ?? ''); ?></textarea>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label" style="font-weight:600;">Promotion Remarks</label>
                                            <textarea class="form-control" name="promotion_remarks" rows="2" placeholder="e.g., Promotion Exam Score: 78% - Recommended for CIS Rank"><?php echo htmlspecialchars($employment_data['promotion_remarks'] ?? ''); ?></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="row mt-4">
                            <div class="col-md-12">
                                <div class="btn-action-group d-flex justify-content-between flex-wrap" style="gap: 1rem;">
                                     <a href="search" class="btn btn-secondary">
                                         <i class="fas fa-arrow-left"></i> Back to Search
                                     </a>
                                     <div class="btn-action-group" style="gap: 0.5rem;">
                                         <button type="reset" class="btn btn-warning">
                                             <i class="fas fa-redo"></i> Reset
                                         </button>
                                         <button type="submit" class="btn btn-success">
                                             <i class="fas fa-save"></i> Update Current Posting
                                         </button>
                                     </div>
                                 </div>
                            </div>
                        </div>
                    </form>

                <?php else: ?>
                    <div class="alert alert-warning text-center">
                        <i class="fas fa-exclamation-triangle"></i> No employment data found for this service number.
                    </div>
                    <div class="text-center">
                        <a href="search" class="btn btn-primary">
                            <i class="fas fa-search"></i> Back to Search
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('employmentForm');
        const presentPostingSelect = document.getElementById('presentPostingSelect');
        const customPostingContainer = document.getElementById('customPostingContainer');
        const customPostingInput = document.getElementById('customPosting');
        
        let originalPresentPosting = presentPostingSelect ? presentPostingSelect.value : '';
        
        // Handle custom posting visibility
        if (presentPostingSelect && customPostingContainer) {
            // Initial check
            if (presentPostingSelect.value === 'other') {
                customPostingContainer.style.display = 'block';
                if (customPostingInput.value.trim() === '') {
                    customPostingInput.required = true;
                }
            }
            
            presentPostingSelect.addEventListener('change', function() {
                if (this.value === 'other') {
                    customPostingContainer.style.display = 'block';
                    customPostingInput.required = true;
                } else {
                    customPostingContainer.style.display = 'none';
                    customPostingInput.required = false;
                }
            });
        }
        
        // Show confirmation when present posting is changed
        if (presentPostingSelect) {
            presentPostingSelect.addEventListener('change', function() {
                if (this.value !== originalPresentPosting && this.value !== '') {
                    if (confirm('Update Current Posting Location?\n\nThis will update ONLY the Current Posting Database.\n\nThe Employment Record will remain unchanged.')) {
                        // Update original value
                        originalPresentPosting = this.value;
                    } else {
                        this.value = originalPresentPosting;
                    }
                }
            });
        }
        
        // Handle form submission for custom posting
        if (form && customPostingInput) {
            form.addEventListener('submit', function(e) {
                const postingSelect = document.getElementById('presentPostingSelect');
                if (postingSelect && postingSelect.value === 'other' && customPostingInput) {
                    // Ensure custom posting has a value
                    if (customPostingInput.value.trim() === '') {
                        e.preventDefault();
                        alert('Please enter a custom posting location.');
                        customPostingInput.focus();
                        return false;
                    }
                }
                
                // Show final confirmation
                if (!confirm('Final Confirmation:\n\nThis will update ONLY the Current Posting Database.\n\nThe Employment Record in tbl_employment will NOT be modified.\n\nDo you want to proceed?')) {
                    e.preventDefault();
                    return false;
                }
                
                return true;
            });
        }
    });
    </script>
    
    <!-- Bootstrap JS (optional, for some components) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>