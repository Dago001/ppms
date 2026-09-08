<?php
// Enable error reporting at the very top

ini_set('display_startup_errors', 1);


// Start session
session_start();

// Include files with error handling
try {
    require_once 'includes/config.php';
    require_once 'includes/auth.php';
    require_once 'includes/permissions.php';
} catch (Exception $e) {
    die("Error loading required files: " . $e->getMessage());
}

// Check if user is logged in
if (!isLoggedIn()) {
    header('Location: login');
    exit();
}

// Initialize variables with default values
$user = [];
$totalPersonnel = 0;
$totalUsers = 0;
$totalRetirees = 0;
$avgAge = 0;
$activePersonnel = 0;
$recentActivities = [];
$error = null;

// Initialize statistics arrays
$commandStats = [];
$rankDistributionData = [];
$genderStats = [];
$ageDistributionData = [];
$serviceYearsData = [];
$departmentStats = [];
$locationStats = [];

// ============================================
// POSTING ANALYTICS VARIABLES
// ============================================
$todayPostings = 0;
$weekPostings = 0;
$monthPostings = 0;
$totalNotificationPostings = 0;
$statusStats = ['pending' => 0, 'admitted' => 0, 'reported' => 0, 'auto_reported' => 0];
$postingTrendData = [];
$retirementAlerts = [];
$retirementCount = 0;
$serviceRetirementAlerts = [];
$serviceRetirementCount = 0;
$recentPostings = [];

if (!function_exists('isDataOnlyNotSpecified')) {
    function isDataOnlyNotSpecified($dataset, $labelKey) {
        if (empty($dataset) || !is_array($dataset)) return true;
        foreach ($dataset as $row) {
            $label = trim($row[$labelKey] ?? '');
            $val = (int)($row['count'] ?? 0);
            if ($label !== 'Not Specified' && $label !== 'No Data' && $val > 0) {
                return false;
            }
        }
        return true;
    }
}

// ============================================
// ROLE-BASED FILTERING - GET USER'S ZONES & COMMANDS
// ============================================
$userRole = $_SESSION['role_name'] ?? '';
$userId = $_SESSION['user_id'] ?? 0;
$userAssignedZoneNames = [];
$filterSQL = "";
$filterParams = [];

// Load the helper function (add this near the top of dashboard.php)
require_once __DIR__ . '/includes/formation_helper.php';

// ... then in the role-based filtering section:
if (!in_array($userRole, ['admin', 'Service HQ', 'Super Admin'])) {
    // Get NIS formations for the filter
    $nis_formations_for_filter = [
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
            'Zone A HQ Ikeja' => 'Zone A Lagos', 'LASC' => 'Lagos State Command', 'OGSC' => 'Ogun State Command',
            'LASPC' => 'Lagos Seaport & Marine Command', 'SEME' => 'Seme Border Command', 'IDBC' => 'Idiroko Border Command',
            'MMIA' => 'MMIA', 'LABPC' => 'Lagos Border Patrol Command', 'LAPC' => 'Lagos Passport Command', 'Ikoyi PC' => 'Ikoyi Passport Command'
        ],
        'ZONE B' => [
            'Zone B HQ Kaduna' => 'Zone B Kaduna', 'KNSC' => 'Kano State Command', 'KDSC' => 'Kaduna State Command',
            'KTSC' => 'Katsina State Command', 'ZMSC' => 'Zamfara State Command', 'SOSC' => 'Sokoto State Command',
            'JGSC' => 'Jigawa State Command', 'MAKIA' => 'MAKIA', 'ILBC' => 'Illela Border Command',
            'JIBC' => 'Jibiya Border Command', 'ITSK' => 'ITSK', 'ICSC' => 'ICSC'
        ],
        'ZONE C' => [
            'Zone C HQ Bauchi' => 'Zone C Bauchi', 'ADSC' => 'Adamawa State Command', 'BASC' => 'Bauchi State Command',
            'BOSC' => 'Borno State Command', 'GMSC' => 'Gombe State Command', 'PLSC' => 'Plateau State Command', 'YBSC' => 'Yobe State Command'
        ],
        'ZONE D' => [
            'Zone D HQ Minna' => 'Zone D Minna', 'FCTC' => 'FCT Command', 'NGSC' => 'Niger State Command',
            'KBSC' => 'Kebbi State Command', 'RMAT' => 'Regional Migration Academy, Tuga', 'KWSC' => 'Kwara State Command'
        ],
        'ZONE E' => [
            'Zone E HQ Owerri' => 'Zone E Owerri', 'IMSC' => 'Imo State Command', 'RVSC' => 'Rivers State Command',
            'CRSC' => 'Cross River State Command', 'EBSC' => 'Ebonyi State Command', 'AKSC' => 'Akwa Ibom State Command',
            'NITSOL' => 'NITSOL', 'RVMC' => 'River Marine Command', 'MFBC' => 'Mfum Border Command',
            'NITSA' => 'NITSA', 'PHIA' => 'PHIA', 'AIIA' => 'AIIA'
        ],
        'ZONE F' => [
            'Zone F HQ Ibadan' => 'Zone F Ibadan', 'OYSC' => 'Oyo State Command', 'EKSC' => 'Ekiti State Command',
            'ODSC' => 'Ondo State Command', 'OSSC' => 'Osun State Command'
        ],
        'ZONE G' => [
            'Zone G HQ Benin' => 'Zone G Benin City', 'EDSC' => 'Edo State Command', 'ANSC' => 'Anambra State Command',
            'DTSC' => 'Delta State Command', 'ENSC' => 'Enugu State Command', 'BYSC' => 'Bayelsa State Command'
        ],
        'ZONE H' => [
            'Zone H HQ Makurdi' => 'Zone H Makurdi', 'NASC' => 'Nasarawa State Command', 'BNSC' => 'Benue State Command',
            'KGSC' => 'Kogi State Command', 'TRSC' => 'Taraba State Command'
        ]
    ];
    
    $zoneFilter = buildUserFilter($pdo, $userId, $nis_formations_for_filter, 'e', 'presentPosting');
    $filterSQL = $zoneFilter['sql'];
    $filterParams = $zoneFilter['params'];
    $userAssignedZoneNames = $zoneFilter['zone_names'];
    $userSearchTerms = !empty($zoneFilter['search_terms']) ? $zoneFilter['search_terms'] : $userAssignedZoneNames;
}
else {
    $userSearchTerms = [];
}
// ============================================

try {
    $stmt = $pdo->prepare("SELECT u.*, r.name as role_name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user) {
        // Stale/invalid session (deleted user, reset database, etc.) - log out
        // cleanly and send back to login instead of rendering a broken dashboard
        // full of undefined-variable warnings.
        session_unset();
        session_destroy();
        header('Location: login');
        exit();
    }

    require_once __DIR__ . '/includes/performance.php';
    $dashCache = new SimpleCache();
    $cacheKey = 'dash_analytics_' . md5($userRole . '_' . serialize($filterParams) . '_' . serialize($userSearchTerms));
    $cachedDashData = $dashCache->get($cacheKey);

    if ($cachedDashData !== null) {
        extract($cachedDashData);
    } else {
    // Total Personnel
    try {
        if (!empty($filterSQL)) {
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tbl_emppersonal p INNER JOIN tbl_employment e ON p.serviceNo = e.serviceNo WHERE 1=1 $filterSQL");
            $stmt->execute($filterParams);
            $totalPersonnel = $stmt->fetch()['count'];
        } else {
            $totalPersonnel = $pdo->query("SELECT COUNT(*) as count FROM tbl_emppersonal")->fetch()['count'];
        }
    } catch (Exception $e) { $totalPersonnel = 0; }

    // Total Users
    try { $totalUsers = $pdo->query("SELECT COUNT(*) as count FROM users")->fetch()['count']; } catch (Exception $e) { $totalUsers = 0; }

    // Total Retirees (empStatus = 'Retired' OR age >= 60 OR service >= 35)
    $totalRetirees = 0;
    try {
        if (!in_array($userRole, ['admin', 'Service HQ', 'Super Admin']) && !empty($userSearchTerms)) {
            $zoneConditions = [];
            $retireeParams = [];
            foreach ($userSearchTerms as $zn) {
                $zoneConditions[] = "e.presentPosting LIKE ?";
                $retireeParams[] = "%" . $zn . "%";
            }
            $retireeZoneSQL = " AND (" . implode(" OR ", $zoneConditions) . ")";
            
            $retireeQuery = "
                SELECT COUNT(DISTINCT p.serviceNo) 
                FROM tbl_emppersonal p 
                LEFT JOIN tbl_employment e ON p.serviceNo = e.serviceNo 
                WHERE ((e.empStatus = 'Retired' OR LOWER(e.empStatus) LIKE '%retir%')
                   OR (p.dob IS NOT NULL AND p.dob != '0000-00-00' AND TIMESTAMPDIFF(YEAR, p.dob, CURDATE()) >= 60)
                   OR (e.dofa IS NOT NULL AND e.dofa != '0000-00-00' AND TIMESTAMPDIFF(YEAR, e.dofa, CURDATE()) >= 35))
                   $retireeZoneSQL
            ";
            $stmt = $pdo->prepare($retireeQuery);
            $stmt->execute($retireeParams);
            $totalRetirees = $stmt->fetchColumn() ?? 0;
        } else {
            $totalRetirees = $pdo->query("
                SELECT COUNT(DISTINCT p.serviceNo) 
                FROM tbl_emppersonal p 
                LEFT JOIN tbl_employment e ON p.serviceNo = e.serviceNo 
                WHERE (e.empStatus = 'Retired' OR LOWER(e.empStatus) LIKE '%retir%')
                   OR (p.dob IS NOT NULL AND p.dob != '0000-00-00' AND TIMESTAMPDIFF(YEAR, p.dob, CURDATE()) >= 60)
                   OR (e.dofa IS NOT NULL AND e.dofa != '0000-00-00' AND TIMESTAMPDIFF(YEAR, e.dofa, CURDATE()) >= 35)
            ")->fetchColumn() ?? 0;
        }
    } catch (Exception $e) { $totalRetirees = 0; }

    // Average Age (using dob)
    try {
        if (!empty($filterSQL)) {
            $stmt = $pdo->prepare("SELECT AVG(YEAR(CURDATE()) - YEAR(p.dob)) as avg_age FROM tbl_emppersonal p INNER JOIN tbl_employment e ON p.serviceNo = e.serviceNo WHERE p.dob IS NOT NULL AND p.dob != '0000-00-00' AND p.dob != '' $filterSQL");
            $stmt->execute($filterParams);
            $avgAgeResult = $stmt->fetch();
        } else {
            $avgAgeResult = $pdo->query("SELECT AVG(YEAR(CURDATE()) - YEAR(dob)) as avg_age FROM tbl_emppersonal WHERE dob IS NOT NULL AND dob != '0000-00-00' AND dob != ''")->fetch();
        }
        $avgAge = $avgAgeResult['avg_age'] ? round($avgAgeResult['avg_age'], 1) : 0;
    } catch (Exception $e) { $avgAge = 0; }

    // Active Personnel
    try {
        if (!empty($filterSQL)) {
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tbl_emppersonal p INNER JOIN tbl_employment e ON p.serviceNo = e.serviceNo WHERE e.empStatus = 'Active' $filterSQL");
            $stmt->execute($filterParams);
            $activePersonnel = $stmt->fetch()['count'];
        } else {
            $activePersonnel = $pdo->query("SELECT COUNT(*) as count FROM tbl_employment WHERE empStatus = 'Active'")->fetch()['count'];
        }
    } catch (Exception $e) { $activePersonnel = $totalPersonnel; }

    // Recent Activities
    try { $recentActivities = $pdo->query("SELECT al.*, u.username FROM activity_logs al JOIN users u ON al.user_id = u.id ORDER BY al.created_at DESC LIMIT 5")->fetchAll(); } catch (Exception $e) { $recentActivities = []; }

    // Gender Statistics
    try {
        if (!empty($filterSQL)) {
            $stmt = $pdo->prepare("SELECT CASE WHEN p.gender IS NULL OR TRIM(p.gender) = '' THEN 'Not Specified' ELSE p.gender END as gender, COUNT(*) as count FROM tbl_emppersonal p INNER JOIN tbl_employment e ON p.serviceNo = e.serviceNo WHERE 1=1 $filterSQL GROUP BY gender ORDER BY count DESC");
            $stmt->execute($filterParams);
            $genderStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $genderStats = $pdo->query("SELECT CASE WHEN gender IS NULL OR TRIM(gender) = '' THEN 'Not Specified' ELSE gender END as gender, COUNT(*) as count FROM tbl_emppersonal GROUP BY gender ORDER BY count DESC")->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) { $genderStats = []; }
    
    // Rank Distribution
    try {
        if (!empty($filterSQL)) {
            $stmt = $pdo->prepare("SELECT COALESCE(NULLIF(TRIM(e.currentRank), ''), 'Not Specified') as rank_name, COUNT(*) as count FROM tbl_employment e INNER JOIN tbl_emppersonal p ON e.serviceNo = p.serviceNo WHERE e.currentRank IS NOT NULL AND e.currentRank != '' $filterSQL GROUP BY rank_name ORDER BY count DESC LIMIT 15");
            $stmt->execute($filterParams);
            $rankDistributionData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $rankDistributionData = $pdo->query("SELECT COALESCE(NULLIF(TRIM(currentRank), ''), 'Not Specified') as rank_name, COUNT(*) as count FROM tbl_employment WHERE currentRank IS NOT NULL AND currentRank != '' GROUP BY rank_name ORDER BY count DESC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) { $rankDistributionData = [['rank_name' => 'Not Specified', 'count' => $totalPersonnel]]; }

    // Age Distribution (using dob)
    try {
        if (!empty($filterSQL)) {
            $stmt = $pdo->prepare("SELECT CASE WHEN p.dob IS NULL OR p.dob = '0000-00-00' OR p.dob = '' THEN 'Not Specified' WHEN TIMESTAMPDIFF(YEAR, p.dob, CURDATE()) < 20 THEN 'Under 20' WHEN TIMESTAMPDIFF(YEAR, p.dob, CURDATE()) BETWEEN 20 AND 29 THEN '20-29' WHEN TIMESTAMPDIFF(YEAR, p.dob, CURDATE()) BETWEEN 30 AND 39 THEN '30-39' WHEN TIMESTAMPDIFF(YEAR, p.dob, CURDATE()) BETWEEN 40 AND 49 THEN '40-49' WHEN TIMESTAMPDIFF(YEAR, p.dob, CURDATE()) >= 50 THEN '50+' ELSE 'Not Specified' END as age_group, COUNT(*) as count FROM tbl_emppersonal p INNER JOIN tbl_employment e ON p.serviceNo = e.serviceNo WHERE 1=1 $filterSQL GROUP BY age_group ORDER BY CASE WHEN age_group = 'Under 20' THEN 1 WHEN age_group = '20-29' THEN 2 WHEN age_group = '30-39' THEN 3 WHEN age_group = '40-49' THEN 4 WHEN age_group = '50+' THEN 5 ELSE 6 END");
            $stmt->execute($filterParams);
            $ageDistributionData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        if (empty($filterSQL) && isDataOnlyNotSpecified($ageDistributionData, 'age_group')) {
            $ageDistributionData = $pdo->query("SELECT CASE WHEN dob IS NULL OR dob = '0000-00-00' OR dob = '' THEN 'Not Specified' WHEN TIMESTAMPDIFF(YEAR, dob, CURDATE()) < 20 THEN 'Under 20' WHEN TIMESTAMPDIFF(YEAR, dob, CURDATE()) BETWEEN 20 AND 29 THEN '20-29' WHEN TIMESTAMPDIFF(YEAR, dob, CURDATE()) BETWEEN 30 AND 39 THEN '30-39' WHEN TIMESTAMPDIFF(YEAR, dob, CURDATE()) BETWEEN 40 AND 49 THEN '40-49' WHEN TIMESTAMPDIFF(YEAR, dob, CURDATE()) >= 50 THEN '50+' ELSE 'Not Specified' END as age_group, COUNT(*) as count FROM tbl_emppersonal GROUP BY age_group ORDER BY CASE WHEN age_group = 'Under 20' THEN 1 WHEN age_group = '20-29' THEN 2 WHEN age_group = '30-39' THEN 3 WHEN age_group = '40-49' THEN 4 WHEN age_group = '50+' THEN 5 ELSE 6 END")->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) { $ageDistributionData = []; }

    // Service Years Distribution (using dofa)
    try {
        if (!empty($filterSQL)) {
            $stmt = $pdo->prepare("SELECT CASE WHEN e.dofa IS NULL OR e.dofa = '0000-00-00' OR e.dofa = '' THEN 'Not Specified' WHEN TIMESTAMPDIFF(YEAR, e.dofa, CURDATE()) < 5 THEN '0-4 years' WHEN TIMESTAMPDIFF(YEAR, e.dofa, CURDATE()) BETWEEN 5 AND 9 THEN '5-9 years' WHEN TIMESTAMPDIFF(YEAR, e.dofa, CURDATE()) BETWEEN 10 AND 14 THEN '10-14 years' WHEN TIMESTAMPDIFF(YEAR, e.dofa, CURDATE()) BETWEEN 15 AND 19 THEN '15-19 years' WHEN TIMESTAMPDIFF(YEAR, e.dofa, CURDATE()) >= 20 THEN '20+ years' ELSE 'Not Specified' END as service_years, COUNT(*) as count FROM tbl_emppersonal p INNER JOIN tbl_employment e ON p.serviceNo = e.serviceNo WHERE 1=1 $filterSQL GROUP BY service_years ORDER BY CASE WHEN service_years = '0-4 years' THEN 1 WHEN service_years = '5-9 years' THEN 2 WHEN service_years = '10-14 years' THEN 3 WHEN service_years = '15-19 years' THEN 4 WHEN service_years = '20+ years' THEN 5 ELSE 6 END");
            $stmt->execute($filterParams);
            $serviceYearsData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        if (empty($filterSQL) && isDataOnlyNotSpecified($serviceYearsData, 'service_years')) {
            $serviceYearsData = $pdo->query("SELECT CASE WHEN e.dofa IS NULL OR e.dofa = '0000-00-00' OR e.dofa = '' THEN 'Not Specified' WHEN TIMESTAMPDIFF(YEAR, e.dofa, CURDATE()) < 5 THEN '0-4 years' WHEN TIMESTAMPDIFF(YEAR, e.dofa, CURDATE()) BETWEEN 5 AND 9 THEN '5-9 years' WHEN TIMESTAMPDIFF(YEAR, e.dofa, CURDATE()) BETWEEN 10 AND 14 THEN '10-14 years' WHEN TIMESTAMPDIFF(YEAR, e.dofa, CURDATE()) BETWEEN 15 AND 19 THEN '15-19 years' WHEN TIMESTAMPDIFF(YEAR, e.dofa, CURDATE()) >= 20 THEN '20+ years' ELSE 'Not Specified' END as service_years, COUNT(*) as count FROM tbl_employment e INNER JOIN tbl_emppersonal p ON e.serviceNo = p.serviceNo GROUP BY service_years ORDER BY CASE WHEN service_years = '0-4 years' THEN 1 WHEN service_years = '5-9 years' THEN 2 WHEN service_years = '10-14 years' THEN 3 WHEN service_years = '15-19 years' THEN 4 WHEN service_years = '20+ years' THEN 5 ELSE 6 END")->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) { $serviceYearsData = []; }

    // Location Distribution
    try {
        if (!empty($filterSQL)) {
            $stmt = $pdo->prepare("SELECT COALESCE(NULLIF(TRIM(e.presentPosting), ''), 'Not Specified') as location_name, COUNT(*) as count FROM tbl_employment e INNER JOIN tbl_emppersonal p ON e.serviceNo = p.serviceNo WHERE e.presentPosting IS NOT NULL $filterSQL GROUP BY location_name ORDER BY count DESC LIMIT 10");
            $stmt->execute($filterParams);
            $locationStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $locationStats = $pdo->query("SELECT COALESCE(NULLIF(TRIM(presentPosting), ''), 'Not Specified') as location_name, COUNT(*) as count FROM tbl_employment WHERE presentPosting IS NOT NULL GROUP BY location_name ORDER BY count DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) { $locationStats = [['location_name' => 'Not Specified', 'count' => 0]]; }

    // Command Distribution
    try {
        if (!empty($filterSQL)) {
            $stmt = $pdo->prepare("SELECT COALESCE(NULLIF(TRIM(e.presentPosting), ''), 'Not Specified') as command_name, COUNT(*) as count FROM tbl_employment e INNER JOIN tbl_emppersonal p ON e.serviceNo = p.serviceNo WHERE e.presentPosting IS NOT NULL AND e.presentPosting != '' $filterSQL GROUP BY command_name ORDER BY count DESC LIMIT 10");
            $stmt->execute($filterParams);
            $commandStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $commandStats = $pdo->query("SELECT COALESCE(NULLIF(TRIM(presentPosting), ''), 'Not Specified') as command_name, COUNT(*) as count FROM tbl_employment WHERE presentPosting IS NOT NULL AND presentPosting != '' GROUP BY command_name ORDER BY count DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) { $commandStats = [['command_name' => 'No Data', 'count' => 0]]; }

    // Department/Zone Distribution
    try {
        if (!empty($filterSQL)) {
            $stmt = $pdo->prepare("SELECT COALESCE(pn.posting_zone, 'Unassigned') as department_name, COUNT(DISTINCT pn.serviceNo) as count FROM posting_notifications pn INNER JOIN tbl_employment e ON pn.serviceNo = e.serviceNo WHERE 1=1 $filterSQL GROUP BY department_name ORDER BY count DESC LIMIT 10");
            $stmt->execute($filterParams);
            $departmentStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $departmentStats = $pdo->query("SELECT COALESCE(posting_zone, 'Unassigned') as department_name, COUNT(DISTINCT serviceNo) as count FROM posting_notifications GROUP BY posting_zone ORDER BY count DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
        }
        if (empty($departmentStats)) {
            $departmentStats = [['department_name' => 'No Data', 'count' => 0]];
        }
    } catch (Exception $e) { $departmentStats = [['department_name' => 'No Data', 'count' => 0]]; }

    // ============================================
    // POSTING ANALYTICS QUERIES
    // ============================================
    $notifFilterSQL = "";
    $notifFilterParams = [];

    if (!in_array($userRole, ['admin', 'Service HQ', 'Super Admin']) && !empty($userSearchTerms)) {
        $notifConds = [];
        foreach ($userSearchTerms as $term) {
            $notifConds[] = "(posting_location LIKE ? OR posting_zone LIKE ?)";
            $notifFilterParams[] = "%" . $term . "%";
            $notifFilterParams[] = "%" . $term . "%";
        }
        if (!empty($notifConds)) {
            $notifFilterSQL = " AND (" . implode(" OR ", $notifConds) . ")";
        }
    }
    
    // Today's postings
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM posting_notifications WHERE DATE(created_at) = CURDATE() AND (status = 'pending' OR status IS NULL) $notifFilterSQL");
        $stmt->execute($notifFilterParams);
        $todayPostings = $stmt->fetch()['count'] ?? 0;
    } catch (Exception $e) { $todayPostings = 0; }

    // This week's postings
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM posting_notifications WHERE YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1) AND (status = 'pending' OR status IS NULL) $notifFilterSQL");
        $stmt->execute($notifFilterParams);
        $weekPostings = $stmt->fetch()['count'] ?? 0;
    } catch (Exception $e) { $weekPostings = 0; }

    // This month's postings
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM posting_notifications WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE()) $notifFilterSQL");
        $stmt->execute($notifFilterParams);
        $monthPostings = $stmt->fetch()['count'] ?? 0;
    } catch (Exception $e) { $monthPostings = 0; }

    // Total notification postings
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM posting_notifications WHERE 1=1 $notifFilterSQL");
        $stmt->execute($notifFilterParams);
        $totalNotificationPostings = (int)($stmt->fetch()['count'] ?? 0);
        if ($totalNotificationPostings === 0) {
            $totalNotificationPostings = (int)$pdo->query("SELECT COUNT(*) FROM posting_notifications")->fetchColumn();
        }
    } catch (Exception $e) { $totalNotificationPostings = 0; }

    // Posting status distribution
    try {
        $stmt = $pdo->prepare("SELECT COALESCE(status, 'pending') as status, COUNT(*) as count FROM posting_notifications WHERE 1=1 $notifFilterSQL GROUP BY status");
        $stmt->execute($notifFilterParams);
        while ($row = $stmt->fetch()) {
            $statusStats[$row['status']] = (int)$row['count'];
        }
        if (array_sum($statusStats) === 0) {
            $allStatsStmt = $pdo->query("SELECT COALESCE(status, 'pending') as status, COUNT(*) as count FROM posting_notifications GROUP BY status");
            while ($row = $allStatsStmt->fetch()) {
                $statusStats[$row['status']] = (int)$row['count'];
            }
        }
    } catch (Exception $e) {}

    // Monthly posting trend
    try {
        $stmt = $pdo->prepare("SELECT DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as total, SUM(CASE WHEN COALESCE(status, 'pending') = 'admitted' THEN 1 ELSE 0 END) as admitted, SUM(CASE WHEN COALESCE(status, 'pending') = 'pending' THEN 1 ELSE 0 END) as pending FROM posting_notifications WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) $notifFilterSQL GROUP BY DATE_FORMAT(created_at, '%Y-%m') ORDER BY month ASC");
        $stmt->execute($notifFilterParams);
        $postingTrendData = $stmt->fetchAll();
    } catch (Exception $e) { $postingTrendData = []; }

        // ============================================
    // AGE-BASED RETIREMENT ALERTS (60 Years from dob - Next 6 Months)
    // ============================================
    $retirementAlerts = [];
    $retirementCount = 0;
    try {
        $sql = "SELECT 
                    ep.serviceNo,
                    CONCAT(COALESCE(ep.surname, ''), ' ', COALESCE(ep.firstName, ''), ' ', COALESCE(ep.middleName, '')) as officer_name,
                    ep.dob,
                    COALESCE(te.currentRank, 'N/A') as currentRank,
                    COALESCE(te.presentPosting, 'N/A') as current_location,
                    TIMESTAMPDIFF(YEAR, ep.dob, CURDATE()) as current_age,
                    DATE_ADD(ep.dob, INTERVAL 60 YEAR) as age_retirement_date,
                    DATEDIFF(DATE_ADD(ep.dob, INTERVAL 60 YEAR), CURDATE()) as days_to_age_retirement
                FROM tbl_emppersonal ep
                LEFT JOIN tbl_employment te ON ep.serviceNo = te.serviceNo
                WHERE ep.dob IS NOT NULL 
                AND ep.dob != '0000-00-00'
                AND DATE_ADD(ep.dob, INTERVAL 60 YEAR) <= DATE_ADD(CURDATE(), INTERVAL 6 MONTH)
                AND DATE_ADD(ep.dob, INTERVAL 60 YEAR) >= CURDATE()";
        
        // ROLE-BASED FILTERING for age retirement (using prepared statements)
        $retireParams = [];
        if (!in_array($userRole, ['admin', 'Service HQ', 'Super Admin']) && !empty($userSearchTerms)) {
            $zoneConditions = [];
            foreach ($userSearchTerms as $zoneName) {
                $zoneConditions[] = "te.presentPosting LIKE ?";
                $retireParams[] = "%" . $zoneName . "%";
            }
            $sql .= " AND (" . implode(" OR ", $zoneConditions) . ")";
        }
        
        $sql .= " ORDER BY days_to_age_retirement ASC LIMIT 10";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($retireParams);
        $retirementAlerts = $stmt->fetchAll();
        $retirementCount = count($retirementAlerts);
    } catch (Exception $e) { 
        $retirementAlerts = []; 
        $retirementCount = 0; 
    }

        // ============================================
    // SERVICE RETIREMENT ALERTS (35 Years from dofa - Next 12 Months)
    // ============================================
    $serviceRetirementAlerts = [];
    $serviceRetirementCount = 0;
    try {
        $sql = "SELECT 
                    ep.serviceNo,
                    CONCAT(COALESCE(ep.surname, ''), ' ', COALESCE(ep.firstName, '')) as officer_name,
                    te.dofa,
                    COALESCE(te.currentRank, 'N/A') as currentRank,
                    COALESCE(te.presentPosting, 'N/A') as current_location,
                    TIMESTAMPDIFF(YEAR, te.dofa, CURDATE()) as years_of_service,
                    DATE_ADD(te.dofa, INTERVAL 35 YEAR) as service_retirement_date,
                    DATEDIFF(DATE_ADD(te.dofa, INTERVAL 35 YEAR), CURDATE()) as days_to_service_retirement
                FROM tbl_employment te
                INNER JOIN tbl_emppersonal ep ON te.serviceNo = ep.serviceNo
                WHERE te.dofa IS NOT NULL 
                AND te.dofa != '0000-00-00'
                AND DATE_ADD(te.dofa, INTERVAL 35 YEAR) <= DATE_ADD(CURDATE(), INTERVAL 12 MONTH)
                AND DATE_ADD(te.dofa, INTERVAL 35 YEAR) >= CURDATE()
                AND TIMESTAMPDIFF(YEAR, ep.dob, CURDATE()) < 60";
        
        // ROLE-BASED FILTERING for service retirement (using prepared statements)
        $serviceRetireParams = [];
        if (!in_array($userRole, ['admin', 'Service HQ', 'Super Admin']) && !empty($userSearchTerms)) {
            $zoneConditions = [];
            foreach ($userSearchTerms as $zoneName) {
                $zoneConditions[] = "te.presentPosting LIKE ?";
                $serviceRetireParams[] = "%" . $zoneName . "%";
            }
            $sql .= " AND (" . implode(" OR ", $zoneConditions) . ")";
        }
        
        $sql .= " ORDER BY days_to_service_retirement ASC LIMIT 10";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($serviceRetireParams);
        $serviceRetirementAlerts = $stmt->fetchAll();
        $serviceRetirementCount = count($serviceRetirementAlerts);
    } catch (Exception $e) { 
        $serviceRetirementAlerts = []; 
        $serviceRetirementCount = 0; 
    }
    // Recent postings from posting_history
    try {
        $recentPostingsSQL = "SELECT ph.*, CONCAT(COALESCE(ep.surname, ''), ' ', COALESCE(ep.firstName, '')) as officer_name, COALESCE(e.currentRank, 'N/A') as officer_rank, u.full_name as creator_user_full_name, u.username as creator_user_username FROM posting_history ph LEFT JOIN tbl_emppersonal ep ON ph.serviceNo = ep.serviceNo LEFT JOIN tbl_employment e ON ph.serviceNo = e.serviceNo LEFT JOIN users u ON ph.created_by = u.id WHERE 1=1 $filterSQL ORDER BY ph.created_at DESC, ph.id DESC LIMIT 8";
        $stmt = $pdo->prepare($recentPostingsSQL);
        $stmt->execute($filterParams);
        $recentPostings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $recentPostings = []; }

    // Cache pre-aggregated analytics for 3 minutes (180s) to eliminate rendering delay
    $dashCache->set($cacheKey, compact(
        'totalPersonnel', 'totalUsers', 'totalRetirees', 'avgAge', 'activePersonnel', 'recentActivities',
        'genderStats', 'rankDistributionData', 'ageDistributionData', 'serviceYearsData', 'locationStats',
        'commandStats', 'departmentStats', 'todayPostings', 'weekPostings', 'monthPostings',
        'statusStats', 'postingTrendData', 'recentPostings', 'retirementAlerts', 'retirementCount',
        'serviceRetirementAlerts', 'serviceRetirementCount'
    ), 180);
    } // End SimpleCache block

    // Failsafe Guarantee: Ensure Posting Status Data is ALWAYS populated with contents
    if (empty($statusStats) || array_sum($statusStats) === 0) {
        $statusStats = ['pending' => 0, 'admitted' => 0, 'reported' => 0, 'auto_reported' => 0];
        try {
            $stmt = $pdo->query("SELECT COALESCE(status, 'pending') as status, COUNT(*) as count FROM posting_notifications GROUP BY status");
            while ($row = $stmt->fetch()) {
                $statusStats[$row['status']] = (int)$row['count'];
            }
        } catch (Exception $e) {}
    }
    if (empty($totalNotificationPostings) || $totalNotificationPostings == 0) {
        $totalNotificationPostings = array_sum($statusStats);
    }

    // Failsafe Guarantee: Ensure Age Distribution is ALWAYS populated with real contents.
    // Only for unrestricted (empty $filterSQL) users - a zone-scoped user with genuinely
    // no data in their own scope must see that honestly, not nationwide data as a fallback.
    if (empty($filterSQL) && isDataOnlyNotSpecified($ageDistributionData, 'age_group')) {
        try {
            $ageDistributionData = $pdo->query("SELECT CASE WHEN dob IS NULL OR dob = '0000-00-00' OR dob = '' THEN 'Not Specified' WHEN TIMESTAMPDIFF(YEAR, dob, CURDATE()) < 20 THEN 'Under 20' WHEN TIMESTAMPDIFF(YEAR, dob, CURDATE()) BETWEEN 20 AND 29 THEN '20-29' WHEN TIMESTAMPDIFF(YEAR, dob, CURDATE()) BETWEEN 30 AND 39 THEN '30-39' WHEN TIMESTAMPDIFF(YEAR, dob, CURDATE()) BETWEEN 40 AND 49 THEN '40-49' WHEN TIMESTAMPDIFF(YEAR, dob, CURDATE()) >= 50 THEN '50+' ELSE 'Not Specified' END as age_group, COUNT(*) as count FROM tbl_emppersonal GROUP BY age_group ORDER BY CASE WHEN age_group = 'Under 20' THEN 1 WHEN age_group = '20-29' THEN 2 WHEN age_group = '30-39' THEN 3 WHEN age_group = '40-49' THEN 4 WHEN age_group = '50+' THEN 5 ELSE 6 END")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}
    }

    // Failsafe Guarantee: Ensure Service Years is ALWAYS populated with real contents.
    // Same scoping caveat as Age Distribution above.
    if (empty($filterSQL) && isDataOnlyNotSpecified($serviceYearsData, 'service_years')) {
        try {
            $serviceYearsData = $pdo->query("SELECT CASE WHEN e.dofa IS NULL OR e.dofa = '0000-00-00' OR e.dofa = '' THEN 'Not Specified' WHEN TIMESTAMPDIFF(YEAR, e.dofa, CURDATE()) < 5 THEN '0-4 years' WHEN TIMESTAMPDIFF(YEAR, e.dofa, CURDATE()) BETWEEN 5 AND 9 THEN '5-9 years' WHEN TIMESTAMPDIFF(YEAR, e.dofa, CURDATE()) BETWEEN 10 AND 14 THEN '10-14 years' WHEN TIMESTAMPDIFF(YEAR, e.dofa, CURDATE()) BETWEEN 15 AND 19 THEN '15-19 years' WHEN TIMESTAMPDIFF(YEAR, e.dofa, CURDATE()) >= 20 THEN '20+ years' ELSE 'Not Specified' END as service_years, COUNT(*) as count FROM tbl_employment e INNER JOIN tbl_emppersonal p ON e.serviceNo = p.serviceNo GROUP BY service_years ORDER BY CASE WHEN service_years = '0-4 years' THEN 1 WHEN service_years = '5-9 years' THEN 2 WHEN service_years = '10-14 years' THEN 3 WHEN service_years = '15-19 years' THEN 4 WHEN service_years = '20+ years' THEN 5 ELSE 6 END")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}
    }

} catch (Exception $e) {
    $error = "Error loading dashboard data: " . $e->getMessage();
}

if (empty($user)) {
    $user = ['username' => $_SESSION['username'] ?? 'User', 'full_name' => $_SESSION['username'] ?? 'User', 'role_name' => 'User'];
}

function safeJsonEncode($data, $field1, $field2) {
    if (empty($data) || !is_array($data)) { return ['[]', '[]']; }
    $labels = []; $values = [];
    foreach ($data as $item) { 
        if (isset($item[$field1]) && isset($item[$field2])) { 
            $labels[] = $item[$field1]; 
            $values[] = $item[$field2]; 
        } 
    }
    if (empty($labels) || empty($values)) { return ['[]', '[]']; }
    return [json_encode($labels), json_encode($values)];
}

list($rankLabels, $rankValues) = safeJsonEncode($rankDistributionData, 'rank_name', 'count');
list($genderLabels, $genderValues) = safeJsonEncode($genderStats, 'gender', 'count');
list($ageLabels, $ageValues) = safeJsonEncode($ageDistributionData, 'age_group', 'count');
list($serviceYearsLabels, $serviceYearsValues) = safeJsonEncode($serviceYearsData, 'service_years', 'count');
list($locationLabels, $locationValues) = safeJsonEncode($locationStats, 'location_name', 'count');
list($commandLabels, $commandValues) = safeJsonEncode($commandStats, 'command_name', 'count');
list($departmentLabels, $departmentValues) = safeJsonEncode($departmentStats, 'department_name', 'count');
?>

<?php include 'includes/header.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>

<style>
    *{margin:0;padding:0;box-sizing:border-box}
    body{font-family:'Segoe UI',-apple-system,BlinkMacSystemFont,sans-serif;background:#f8fafc;min-height:100vh;color:#1e293b;font-size:13px;line-height:1.4}
    
    .stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:0.85rem;margin-bottom:1.25rem}
    .stat-card{background:#fff;border-radius:10px;padding:0.85rem 1rem;display:flex;justify-content:space-between;align-items:center;box-shadow:0 1px 3px rgba(0,0,0,0.04);border:1px solid #e2e8f0;transition:transform 0.15s ease,box-shadow 0.15s ease}
    .stat-card:hover{transform:translateY(-2px);box-shadow:0 4px 10px rgba(0,0,0,0.05)}
    .stat-card.primary{background:#fff;border:1px solid #e2e8f0}
    .stat-card.primary .stat-content h3{font-size:1.65rem !important;font-weight:600 !important;color:#1a5632 !important;line-height:1.1;margin-bottom:0.25rem}
    .stat-card.primary .stat-content p{font-size:0.7rem !important;color:#64748b !important;text-transform:uppercase;letter-spacing:0.4px;font-weight:500 !important}
    .stat-card.primary .stat-icon{background:#f1f5f9;color:#1a5632}
    .stat-card.info{background:#fff;border:1px solid #e2e8f0}
    .stat-card.info .stat-content h3{font-size:1.65rem !important;font-weight:600 !important;color:#1a5632 !important;line-height:1.1;margin-bottom:0.25rem}
    .stat-card.info .stat-content p{font-size:0.7rem !important;color:#64748b !important;text-transform:uppercase;letter-spacing:0.4px;font-weight:500 !important}
    .stat-card.info .stat-icon{background:#f1f5f9;color:#1a5632}
    .stat-content{flex:1}
    .stat-content h3{font-size:1.65rem !important;font-weight:600 !important;color:#1a5632;line-height:1.1;margin-bottom:0.25rem}
    .stat-content p{font-size:0.7rem !important;color:#64748b;text-transform:uppercase;letter-spacing:0.4px;font-weight:500 !important}
    
    .charts-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:1rem;margin-bottom:1.25rem}
    .chart-container{position:relative;height:250px;width:100%}
    .bar-chart-container{height:260px;position:relative;width:100%}
    
    .card{background:#fff;border-radius:10px;box-shadow:0 1px 3px rgba(0,0,0,0.04);margin-bottom:1rem;border:1px solid #e2e8f0}
    .card-header{background:#fff;padding:0.7rem 1rem;font-weight:600;font-size:0.85rem;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center}
    .card-header h5{font-size:0.85rem;margin:0;font-weight:600;color:#1a5632}
    .card-header h5 i{margin-right:0.4rem}
    .card-body{padding:1rem}
    
    .chart-controls{display:flex;justify-content:space-between;align-items:center;margin-bottom:0.8rem;flex-wrap:wrap;gap:0.5rem}
    .chart-actions{display:flex;gap:0.4rem}
    .chart-action-btn{background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:0.25rem 0.5rem;cursor:pointer;font-size:0.7rem;display:flex;align-items:center;gap:0.25rem;color:#475569}
    .chart-action-btn:hover{background:#1a5632;border-color:#1a5632;color:#fff}
    .chart-type-selector{display:flex;gap:0.4rem;margin-bottom:0.8rem}
    .chart-type-btn{padding:0.25rem 0.6rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;cursor:pointer;font-size:0.7rem;color:#475569}
    .chart-type-btn.active{background:#1a5632;color:#fff;border-color:#1a5632}
    
    .small-charts-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;margin-bottom:1.25rem}
    
    .stat-card.info form{width:100%}
    .stat-card.info form div{display:flex;gap:0.3rem}
    .stat-card.info input[type="text"]{flex:1;min-width:100px;padding:0.4rem 0.5rem;border:1px solid rgba(255,255,255,0.3);border-radius:6px;background:rgba(255,255,255,0.95);font-size:0.75rem;font-weight:500}
    .stat-card.info button[type="submit"]{background:#fff;color:#1a5632;border:none;padding:0.4rem 0.8rem;border-radius:6px;cursor:pointer;font-size:0.75rem;font-weight:500;white-space:nowrap}
    
    .zone-badge{display:inline-block;background:#3b82f6;color:#fff;padding:0.15rem 0.4rem;border-radius:12px;font-size:0.65rem;margin-left:0.3rem;font-weight:500}
    .alert{padding:0.6rem 0.8rem;border-radius:8px;margin-bottom:0.8rem;border-left:3px solid;font-size:0.75rem;display:flex;align-items:center;gap:0.4rem}
    
    .posting-stats-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:0.85rem;margin-bottom:1.25rem}
    .posting-stat-mini{background:#fff;border-radius:8px;padding:0.95rem;text-align:center;border:1px solid #e2e8f0;box-shadow:0 1px 3px rgba(0,0,0,0.03)}
    .posting-stat-mini .mini-value{font-size:1.35rem;font-weight:600;color:#1a5632;line-height:1.1}
    .posting-stat-mini .mini-label{font-size:0.7rem;color:#64748b;text-transform:uppercase;letter-spacing:0.4px;font-weight:500;margin-top:0.35rem}
    
    .status-dot{width:10px;height:10px;border-radius:50%;display:inline-block;margin-right:0.3rem}
    .status-dot.pending{background:#f39c12}
    .status-dot.admitted{background:#27ae60}
    .status-dot.reported{background:#e74c3c}
    
    .retirement-table,.recent-table{width:100%;border-collapse:collapse;font-size:0.7rem}
    .retirement-table th,.recent-table th{background:#f8fafc;padding:0.5rem 0.6rem;text-align:left;font-weight:500;border-bottom:1px solid #e2e8f0;font-size:0.65rem;text-transform:uppercase;color:#64748b;white-space:nowrap}
    .retirement-table td,.recent-table td{padding:0.5rem 0.6rem;border-bottom:1px solid #f1f5f9}
    .retirement-table tbody tr:hover,.recent-table tbody tr:hover{background:#f8fafc}
    
    .urgency-badge{padding:0.15rem 0.4rem;border-radius:10px;font-size:0.6rem;font-weight:500;white-space:nowrap}
    .urgency-critical{background:#fef2f2;color:#991b1b}
    .urgency-warning{background:#fffbeb;color:#92400e}
    .urgency-notice{background:#f0fdf4;color:#166534}
    
    .tabs{display:flex;border-bottom:1px solid #e2e8f0;margin-bottom:0.8rem}
    .tab-btn{padding:0.4rem 0.8rem;background:none;border:none;cursor:pointer;font-size:0.7rem;color:#64748b;font-weight:500;border-bottom:2px solid transparent;transition:all 0.2s}
    .tab-btn.active{color:#1a5632;border-bottom-color:#1a5632;font-weight:600}
    .tab-btn:hover{color:#1a5632}
    .tab-content{display:none}
    .tab-content.active{display:block}
    
    .empty-state{text-align:center;padding:2rem;color:#64748b}
    .empty-state i{font-size:2rem;display:block;margin-bottom:0.5rem}
    .empty-state h4{margin-bottom:0.3rem;font-size:0.85rem}
    .empty-state p{font-size:0.7rem}
    
    .realtime-indicator{position:fixed;bottom:20px;right:20px;background:#1a5632;color:#fff;padding:0.4rem 0.8rem;border-radius:20px;font-size:0.7rem;display:flex;align-items:center;gap:0.4rem;z-index:1000;box-shadow:0 4px 12px rgba(26,86,50,0.4);animation:pulse 2s infinite}
    @keyframes pulse{0%{box-shadow:0 0 0 0 rgba(26,86,50,0.5)}70%{box-shadow:0 0 0 10px rgba(26,86,50,0)}100%{box-shadow:0 0 0 0 rgba(26,86,50,0)}}
    
    @media(max-width:480px){.stat-card{flex-direction:column;text-align:center}.stat-icon{margin-left:0;margin-top:0.4rem}}
</style>

<?php if ($error): ?>
<div class="alert" style="background:#fee2e2;color:#991b1b;border-left:3px solid #dc2626;">
    <strong>Notice:</strong> <?php echo htmlspecialchars($error); ?>
</div>
<?php endif; ?>

<!-- ============================================ -->
<!-- MAIN STATS CARDS -->
<!-- ============================================ -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-content">
            <h3 class="counter" id="totalPersonnel"><?php echo number_format($totalPersonnel); ?></h3>
            <p>Total Personnel
                <?php if (!in_array($userRole, ['admin', 'Service HQ', 'Super Admin']) && !empty($userAssignedZoneNames)): ?>
                    <span class="zone-badge"><?php echo implode(', ', $userAssignedZoneNames); ?></span>
                <?php endif; ?>
            </p>
        </div>
        <div class="stat-icon"><i class="fas fa-users"></i></div>
    </div>
    
    <?php if (in_array($userRole, ['admin', 'Service HQ', 'Super Admin'])): ?>
    <div class="stat-card primary">
        <div class="stat-content">
            <h3 class="counter" id="totalUsers"><?php echo number_format($totalUsers); ?></h3>
            <p>System Users</p>
        </div>
        <div class="stat-icon"><i class="fas fa-user-shield"></i></div>
    </div>
    <?php endif; ?>
    
    <div class="stat-card info">
        <div class="stat-content">
            <h3 class="counter" id="totalRetirees"><?php echo number_format($totalRetirees); ?></h3>
            <p>Total Retirees</p>
        </div>
        <div class="stat-icon"><i class="fas fa-user-clock"></i></div>
    </div>
</div>

<!-- Zone Filter Indicator for non-admin users -->
<?php if (!in_array($userRole, ['admin', 'Service HQ', 'Super Admin']) && !empty($userAssignedZoneNames)): ?>

<?php endif; ?>
<!-- ============================================ -->
<!-- POSTING STATISTICS ROW (Admin / Service HQ / Super Admin Only) -->
<!-- ============================================ -->
<?php if (in_array($userRole, ['admin', 'Service HQ', 'Super Admin'])): ?>
<div class="posting-stats-row">
    <div class="posting-stat-mini">
        <div class="mini-value"><?php echo $todayPostings; ?></div>
        <div class="mini-label"><i class="fas fa-calendar-day"></i> Today</div>
    </div>
    <div class="posting-stat-mini">
        <div class="mini-value"><?php echo $weekPostings; ?></div>
        <div class="mini-label"><i class="fas fa-calendar-week"></i> This Week</div>
    </div>
    <div class="posting-stat-mini">
        <div class="mini-value"><?php echo $monthPostings; ?></div>
        <div class="mini-label"><i class="fas fa-calendar-alt"></i> This Month</div>
    </div>
    <div class="posting-stat-mini">
        <div class="mini-value"><?php echo $totalNotificationPostings; ?></div>
        <div class="mini-label"><i class="fas fa-bell"></i> Total</div>
    </div>
    <div class="posting-stat-mini">
        <div class="mini-value" style="color:#f39c12;"><?php echo $statusStats['pending']; ?></div>
        <div class="mini-label"><span class="status-dot pending"></span> Pending</div>
    </div>
    <div class="posting-stat-mini">
        <div class="mini-value" style="color:#27ae60;"><?php echo $statusStats['admitted']; ?></div>
        <div class="mini-label"><span class="status-dot admitted"></span> Admitted</div>
    </div>
    <div class="posting-stat-mini">
        <div class="mini-value" style="color:#e74c3c;"><?php echo $statusStats['reported'] + $statusStats['auto_reported']; ?></div>
        <div class="mini-label"><span class="status-dot reported"></span> Not Reported</div>
    </div>
</div>
<?php endif; ?>

<!-- ============================================ -->
<!-- POSTING ANALYTICS CHARTS -->
<!-- ============================================ -->
<div class="charts-grid">
    <div class="card">
        <div class="card-header"><h5><i class="fas fa-chart-pie"></i> Posting Status</h5><span style="font-size:0.65rem;color:#64748b">Total: <?php echo max($totalNotificationPostings, array_sum($statusStats)); ?></span></div>
        <div class="card-body">
            <div style="display:flex;gap:1rem;margin-bottom:0.8rem;flex-wrap:wrap;font-size:0.7rem;">
                <span><span class="status-dot pending"></span> Pending: <?php echo $statusStats['pending']; ?></span>
                <span><span class="status-dot admitted"></span> Admitted: <?php echo $statusStats['admitted']; ?></span>
                <span><span class="status-dot reported"></span> Not Reported: <?php echo $statusStats['reported'] + $statusStats['auto_reported']; ?></span>
            </div>
            <div class="chart-container"><canvas id="postingStatusChart"></canvas></div>
        </div>
    </div>
    
    <div class="card">
        <div class="card-header"><h5><i class="fas fa-chart-line"></i> Posting Trend (6 Months)</h5></div>
        <div class="card-body">
            <?php if (!empty($postingTrendData)): ?>
            <div class="chart-container"><canvas id="postingTrendChart"></canvas></div>
            <?php else: ?>
            <div class="empty-state"><i class="fas fa-chart-line"></i><p>No trend data yet</p></div>
            <?php endif; ?>
        </div>
    </div>
</div>


<!-- ============================================ -->
<!-- PERSONNEL ANALYTICS CHARTS -->
<!-- ============================================ -->
<div class="charts-grid">
    <div class="card">
        <div class="card-header"><h5><i class="fas fa-star"></i> Rank Distribution</h5></div>
        <div class="card-body">
            <div class="chart-controls">
                <div class="chart-type-selector">
                    <button class="chart-type-btn active" onclick="changeChartType('rankDistributionChart','bar')">Bar</button>
                    <button class="chart-type-btn" onclick="changeChartType('rankDistributionChart','horizontal')">Horizontal</button>
                </div>
                <div class="chart-actions">
                    <button class="chart-action-btn" onclick="exportChartData('rankDistributionChart','rank_distribution')"><i class="fas fa-download"></i> Export</button>
                </div>
            </div>
            <div class="bar-chart-container"><canvas id="rankDistributionChart"></canvas></div>
        </div>
    </div>
    <div class="card">
        <div class="card-header"><h5><i class="fas fa-venus-mars"></i> Gender Distribution</h5></div>
        <div class="card-body">
            <div class="chart-controls">
                <div class="chart-type-selector">
                    <button class="chart-type-btn active" onclick="changeChartType('genderChart','pie')">Pie</button>
                    <button class="chart-type-btn" onclick="changeChartType('genderChart','doughnut')">Donut</button>
                </div>
                <div class="chart-actions">
                    <button class="chart-action-btn" onclick="exportChartData('genderChart','gender_distribution')"><i class="fas fa-download"></i> Export</button>
                </div>
            </div>
            <div class="chart-container"><canvas id="genderChart"></canvas></div>
        </div>
    </div>
</div>

<div class="small-charts-grid">
    <div class="card"><div class="card-header"><h5><i class="fas fa-calendar-alt"></i> Age Distribution</h5></div><div class="card-body"><div class="chart-container"><canvas id="ageDistributionChart"></canvas></div></div></div>
    <div class="card"><div class="card-header"><h5><i class="fas fa-clock"></i> Service Years</h5></div><div class="card-body"><div class="chart-container"><canvas id="serviceYearsChart"></canvas></div></div></div>
    <div class="card"><div class="card-header"><h5><i class="fas fa-sitemap"></i>Command/Zone</h5></div><div class="card-body"><div class="chart-container"><canvas id="departmentChart"></canvas></div></div></div>
</div>

<div class="card">
    <div class="card-header"><h5><i class="fas fa-map-marker-alt"></i> Present Posting Distribution</h5></div>
    <div class="card-body">
        <div class="chart-controls">
            <div class="chart-type-selector">
                <button class="chart-type-btn active" onclick="changeChartType('locationDistributionChart','bar')">Bar</button>
                <button class="chart-type-btn" onclick="changeChartType('locationDistributionChart','horizontal')">Horizontal</button>
            </div>
            <div class="chart-actions">
                <button class="chart-action-btn" onclick="exportChartData('locationDistributionChart','posting_distribution')"><i class="fas fa-download"></i> Export</button>
            </div>
        </div>
        <div class="bar-chart-container"><canvas id="locationDistributionChart"></canvas></div>
    </div>
</div>

<!-- ============================================ -->
<!-- RETIREMENT ALERTS TABS (ROLE-BASED) -->
<!-- ============================================ -->
<div class="card" style="margin-bottom:1.5rem">
    <div class="card-header">
        <span><i class="fas fa-clock"></i> Retirement & Service Alerts
            <?php if (!in_array($userRole, ['admin', 'Service HQ', 'Super Admin']) && !empty($userAssignedZoneNames)): ?>
                <span class="zone-badge"><?php echo implode(', ', $userAssignedZoneNames); ?></span>
            <?php endif; ?>
        </span>
        <div style="display:flex;gap:0.5rem">
            <span class="urgency-badge urgency-critical"><?php echo $retirementCount; ?> Age-Based</span>
            <?php if ($serviceRetirementCount > 0): ?>
            <span class="urgency-badge urgency-warning"><?php echo $serviceRetirementCount; ?> Service-Based</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body">
        <div class="tabs">
            <button class="tab-btn active" onclick="switchTab('ageTab', this)">Age Retirement (60 Years) - Next 6 Months</button>
            <button class="tab-btn" onclick="switchTab('serviceTab', this)">Service Retirement (35 Years) - Next 12 Months</button>
        </div>
        
        <!-- Age-Based Retirement -->
        <div id="ageTab" class="tab-content active">
            <?php if (!empty($retirementAlerts)): ?>
            <div style="overflow-x:auto;">
                <table class="retirement-table">
                    <thead>
                        <tr>
                            <th>NIS No</th>
                            <th>Name</th>
                            <th>Rank</th>
                            <th>Current Location</th>
                            <th>Age</th>
                            <th>Retirement Date</th>
                            <th>Days Left</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($retirementAlerts as $ra): 
                            $daysLeft = $ra['days_to_age_retirement'] ?? 0;
                            $urgencyClass = $daysLeft <= 30 ? 'urgency-critical' : ($daysLeft <= 90 ? 'urgency-warning' : 'urgency-notice');
                        ?>
                        <tr>
                            <td><code style="background:#f1f5f9;padding:2px 6px;border-radius:4px"><?php echo htmlspecialchars($ra['serviceNo']); ?></code></td>
                            <td><strong><?php echo htmlspecialchars($ra['officer_name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($ra['currentRank']); ?></td>
                            <td><?php echo htmlspecialchars($ra['current_location']); ?></td>
                            <td><?php echo $ra['current_age']; ?> yrs</td>
                            <td><?php echo date('d M Y', strtotime($ra['age_retirement_date'])); ?></td>
                            <td>
                                <strong style="color:<?php echo $daysLeft <= 30 ? '#e74c3c' : ($daysLeft <= 90 ? '#f39c12' : '#27ae60'); ?>">
                                    <?php echo $daysLeft; ?> days
                                </strong>
                            </td>
                            <td><span class="urgency-badge <?php echo $urgencyClass; ?>"><?php echo $daysLeft <= 30 ? 'Critical' : ($daysLeft <= 90 ? 'Warning' : 'Upcoming'); ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-check-circle" style="color:#27ae60"></i>
                <h4>No Upcoming Age-Based Retirements</h4>
                <?php if (!in_array($userRole, ['admin', 'Service HQ', 'Super Admin']) && !empty($userAssignedZoneNames)): ?>
                    <p>No officers in <strong><?php echo implode(', ', $userAssignedZoneNames); ?></strong> are due for retirement (60 years) within the next 6 months.</p>
                <?php else: ?>
                    <p>No officers are due for retirement (60 years) within the next 6 months.</p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Service-Based Retirement -->
        <div id="serviceTab" class="tab-content">
            <?php if (!empty($serviceRetirementAlerts)): ?>
            <div style="overflow-x:auto;">
                <table class="retirement-table">
                    <thead>
                        <tr>
                            <th>NIS No</th>
                            <th>Name</th>
                            <th>Rank</th>
                            <th>Current Location</th>
                            <th>Years of Service</th>
                            <th>35-Yr Date</th>
                            <th>Days Left</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($serviceRetirementAlerts as $sra): 
                            $daysLeft = $sra['days_to_service_retirement'] ?? 0;
                            $urgencyClass = $daysLeft <= 30 ? 'urgency-critical' : ($daysLeft <= 90 ? 'urgency-warning' : 'urgency-notice');
                        ?>
                        <tr>
                            <td><code style="background:#f1f5f9;padding:2px 6px;border-radius:4px"><?php echo htmlspecialchars($sra['serviceNo']); ?></code></td>
                            <td><strong><?php echo htmlspecialchars($sra['officer_name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($sra['currentRank']); ?></td>
                            <td><?php echo htmlspecialchars($sra['current_location']); ?></td>
                            <td><?php echo $sra['years_of_service']; ?> yrs</td>
                            <td><?php echo date('d M Y', strtotime($sra['service_retirement_date'])); ?></td>
                            <td>
                                <strong style="color:<?php echo $daysLeft <= 30 ? '#e74c3c' : ($daysLeft <= 90 ? '#f39c12' : '#27ae60'); ?>">
                                    <?php echo $daysLeft; ?> days
                                </strong>
                            </td>
                            <td><span class="urgency-badge <?php echo $urgencyClass; ?>"><?php echo $daysLeft <= 30 ? 'Critical' : ($daysLeft <= 90 ? 'Warning' : 'Upcoming'); ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-check-circle" style="color:#27ae60"></i>
                <h4>No Upcoming Service-Based Retirements</h4>
                <?php if (!in_array($userRole, ['admin', 'Service HQ', 'Super Admin']) && !empty($userAssignedZoneNames)): ?>
                    <p>No officers in <strong><?php echo implode(', ', $userAssignedZoneNames); ?></strong> are reaching 35 years of service within the next 12 months.</p>
                <?php else: ?>
                    <p>No officers are reaching 35 years of service within the next 12 months.</p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- RECENT POSTINGS (ROLE-BASED) -->
<!-- ============================================ -->
<div class="card">
    <div class="card-header">
        <h5><i class="fas fa-history"></i> Recent Postings</h5>
        <span style="font-size:0.65rem;color:#64748b">
            Last 8 
            <?php if (!in_array($userRole, ['admin', 'Service HQ', 'Super Admin']) && !empty($userAssignedZoneNames)): ?>
                | Zone: <?php echo implode(', ', $userAssignedZoneNames); ?>
            <?php endif; ?>
        </span>
    </div>
    <div class="card-body">
        <?php 
        $filteredRecentPostings = [];
        if (!in_array($userRole, ['admin', 'Service HQ', 'Super Admin']) && !empty($userAssignedZoneNames)) {
            foreach ($recentPostings as $rp) {
                $postingLocation = strtolower($rp['posting_location'] ?? '');
                foreach ($userAssignedZoneNames as $zoneName) {
                    if (stripos($postingLocation, strtolower($zoneName)) !== false) {
                        $filteredRecentPostings[] = $rp;
                        break;
                    }
                }
            }
        } else {
            $filteredRecentPostings = $recentPostings;
        }
        ?>
        
        <?php if (!empty($filteredRecentPostings)): ?>
        <div style="overflow-x:auto;">
            <table class="recent-table">
                <thead>
                    <tr>
                        <th>NIS No</th>
                        <th>Name</th>
                        <th>Rank</th>
                        <th>Posted To</th>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Posted By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($filteredRecentPostings as $rp): 
                        $postedBy = 'System Administrator';
                        if (!empty(trim($rp['posted_by'] ?? '')) && $rp['posted_by'] !== 'Service HQ') {
                            $postedBy = trim($rp['posted_by']);
                        } elseif (!empty(trim($rp['creator_user_full_name'] ?? ''))) {
                            $postedBy = trim($rp['creator_user_full_name']);
                        } elseif (!empty(trim($rp['creator_user_username'] ?? ''))) {
                            $postedBy = trim($rp['creator_user_username']);
                        }
                    ?>
                    <tr>
                        <td><code style="background:#f1f5f9;padding:2px 4px;border-radius:3px;font-size:0.65rem;"><?php echo htmlspecialchars($rp['serviceNo']); ?></code></td>
                        <td><strong><?php echo htmlspecialchars($rp['officer_name']); ?></strong></td>
                        <td><?php echo htmlspecialchars($rp['officer_rank']); ?></td>
                        <td><?php echo htmlspecialchars($rp['posting_location']); ?></td>
                        <td><?php echo date('d M Y', strtotime($rp['posting_date'])); ?></td>
                        <td><span style="background:#d4edda;color:#155724;padding:2px 6px;border-radius:10px;font-size:0.6rem;"><?php echo htmlspecialchars($rp['posting_type'] ?? 'Posting'); ?></span></td>
                        <td style="font-size:0.7rem;color:#334155;font-weight:600;"><?php echo htmlspecialchars($postedBy); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php elseif (!in_array($userRole, ['admin', 'Service HQ', 'Super Admin']) && !empty($userAssignedZoneNames)): ?>
        <div class="empty-state">
            <i class="fas fa-history"></i>
            <p>No recent postings for: <strong><?php echo implode(', ', $userAssignedZoneNames); ?></strong></p>
        </div>
        <?php else: ?>
        <div class="empty-state"><i class="fas fa-history"></i><p>No recent postings available.</p></div>
        <?php endif; ?>
    </div>
</div>

<div id="realtimeIndicator" class="realtime-indicator" style="display:none"><i class="fas fa-sync fa-spin"></i><span>Updating...</span></div>

<script>
const chartColors = ['#1a5632','#1f6b3e','#27ae60','#2ecc71','#3cb371','#66cdaa','#90ee90','#006400','#4caf50','#8bc34a'];
const barChartColors = ['rgba(26,86,50,0.85)','rgba(31,107,62,0.85)','rgba(39,174,96,0.85)','rgba(46,204,113,0.85)'];
let charts = {};

function switchTab(tabId, btn) {
    const card = btn.closest('.card');
    if (card) {
        card.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
        card.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    } else {
        document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    }
    document.getElementById(tabId).classList.add('active');
    btn.classList.add('active');
}

function animateCounter(element, target) {
    const counter = document.getElementById(element);
    if (!counter) return;
    let current = parseInt(counter.textContent.replace(/,/g, '')) || 0;
    if (current === target) return;
    const increment = (target - current) / 50;
    const timer = setInterval(() => {
        current += increment;
        if ((increment > 0 && current >= target) || (increment < 0 && current <= target)) { current = target; clearInterval(timer); }
        counter.textContent = Math.floor(current).toLocaleString();
    }, 20);
}

function createOrUpdateChart(elementId, data, type = 'pie', options = {}) {
    const ctx = document.getElementById(elementId);
    if (!ctx) return null;
    if (!data || !data.labels || data.labels.length === 0) { if (charts[elementId]) charts[elementId].destroy(); return null; }
    const isBarChart = type === 'bar' || type === 'horizontal';
    const isPieChart = type === 'pie' || type === 'doughnut';
    const defaultOptions = {
        responsive: true, maintainAspectRatio: false,
        plugins: {
            legend: { position: isPieChart ? 'right' : 'top', labels: { font: { size: 10 }, padding: 8, boxWidth: 10 } },
            tooltip: { callbacks: { label: function(c) { const v = c.raw || 0; const t = c.dataset.data.reduce((a,b) => a+b, 0); const p = t > 0 ? Math.round((v/t)*100) : 0; return `${c.label}: ${v.toLocaleString()} (${p}%)`; } } }
        }
    };
    if (isBarChart) defaultOptions.scales = { y: { beginAtZero: true, ticks: { font: { size: 10 } } }, x: { ticks: { font: { size: 9 }, maxRotation: 45, minRotation: 0 } } };
    const chartConfig = { type: (type === 'horizontal') ? 'bar' : type, data: { labels: data.labels, datasets: [{ label: data.label || 'Count', data: data.values, backgroundColor: isBarChart ? barChartColors : chartColors, borderColor: '#ffffff', borderWidth: 1 }] }, options: Object.assign({}, defaultOptions, options) };
    if (charts[elementId]) charts[elementId].destroy();
    charts[elementId] = new Chart(ctx, chartConfig);
    return charts[elementId];
}

function changeChartType(chartId, newType) {
    if (charts[chartId]) {
        const chart = charts[chartId];
        chart.config.type = (newType === 'horizontal') ? 'bar' : newType;
        if (newType === 'horizontal') chart.options.indexAxis = 'y';
        else if (newType === 'bar') chart.options.indexAxis = 'x';
        else delete chart.options.indexAxis;
        const isBarChart = newType === 'bar' || newType === 'horizontal';
        chart.options.plugins.legend.position = isBarChart ? 'top' : 'right';
        if (isBarChart) chart.options.scales = { y: { beginAtZero: true }, x: { ticks: { maxRotation: 45 } } };
        else delete chart.options.scales;
        chart.update();
        document.querySelectorAll(`[onclick*="${chartId}"]`).forEach(b => b.classList.remove('active'));
    }
}

function exportChartData(chartId, filename) {
    if (charts[chartId]) {
        const labels = charts[chartId].data.labels;
        const data = charts[chartId].data.datasets[0].data;
        let csv = "Label,Value\n";
        labels.forEach((l, i) => csv += `"${l}",${data[i]}\n`);
        const link = document.createElement('a');
        link.href = 'data:text/csv;charset=utf-8,' + encodeURI(csv);
        link.download = `${filename}_${new Date().toISOString().slice(0,10)}.csv`;
        link.click();
    }
}

function showRealtimeIndicator() {
    const i = document.getElementById('realtimeIndicator');
    if (i) { i.style.display = 'flex'; setTimeout(() => { i.style.display = 'none'; }, 2000); }
}

document.addEventListener('DOMContentLoaded', function() {
        animateCounter('totalPersonnel', <?php echo $totalPersonnel; ?>);
    <?php if (in_array($userRole, ['admin', 'Service HQ', 'Super Admin'])): ?>
    animateCounter('totalUsers', <?php echo $totalUsers; ?>);
    <?php endif; ?>
    
    createOrUpdateChart('rankDistributionChart', { label: 'Personnel', labels: <?php echo $rankLabels; ?>, values: <?php echo $rankValues; ?> }, 'bar');
    createOrUpdateChart('genderChart', { labels: <?php echo $genderLabels; ?>, values: <?php echo $genderValues; ?> }, 'pie');
    createOrUpdateChart('ageDistributionChart', { labels: <?php echo $ageLabels; ?>, values: <?php echo $ageValues; ?> }, 'pie');
    createOrUpdateChart('serviceYearsChart', { labels: <?php echo $serviceYearsLabels; ?>, values: <?php echo $serviceYearsValues; ?> }, 'doughnut');
    createOrUpdateChart('departmentChart', { labels: <?php echo $departmentLabels; ?>, values: <?php echo $departmentValues; ?> }, 'doughnut');
    createOrUpdateChart('locationDistributionChart', { label: 'Personnel', labels: <?php echo $locationLabels; ?>, values: <?php echo $locationValues; ?> }, 'bar');
    
    createOrUpdateChart('postingStatusChart', {
        labels: ['Pending', 'Admitted', 'Not Reported'],
        values: [<?php echo $statusStats['pending']; ?>, <?php echo $statusStats['admitted']; ?>, <?php echo $statusStats['reported'] + $statusStats['auto_reported']; ?>],
        label: 'Postings'
    }, 'doughnut', { plugins: { legend: { position: 'bottom', labels: { padding: 8, font: { size: 9 }, boxWidth: 8 } } } });
    if (charts['postingStatusChart']) {
        charts['postingStatusChart'].data.datasets[0].backgroundColor = ['#f39c12', '#27ae60', '#e74c3c'];
        charts['postingStatusChart'].update();
    }
    
    <?php if (!empty($postingTrendData)): ?>
    (function() {
        const ctx = document.getElementById('postingTrendChart')?.getContext('2d');
        if (!ctx) return;
        const months = [<?php echo implode(',', array_map(function($m) { return "'".date('M Y', strtotime($m['month'].'-01'))."'"; }, $postingTrendData)); ?>];
        const totals = [<?php echo implode(',', array_column($postingTrendData, 'total')); ?>];
        const admitted = [<?php echo implode(',', array_column($postingTrendData, 'admitted')); ?>];
        const pending = [<?php echo implode(',', array_column($postingTrendData, 'pending')); ?>];
        charts['postingTrendChart'] = new Chart(ctx, {
            type: 'line',
            data: {
                labels: months,
                datasets: [
                    { label: 'Total', data: totals, borderColor: '#1a5632', backgroundColor: 'rgba(26,86,50,0.1)', fill: true, tension: 0.4, pointRadius: 3 },
                    { label: 'Admitted', data: admitted, borderColor: '#27ae60', backgroundColor: 'rgba(39,174,96,0.1)', fill: true, tension: 0.4, pointRadius: 3 },
                    { label: 'Pending', data: pending, borderColor: '#f39c12', backgroundColor: 'rgba(243,156,18,0.1)', fill: true, tension: 0.4, pointRadius: 3 }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { font: { size: 9 }, boxWidth: 8, padding: 10 } } },
                scales: { y: { beginAtZero: true, ticks: { font: { size: 9 } }, grid: { color: '#f1f5f9' } }, x: { ticks: { font: { size: 9 } }, grid: { display: false } } },
                interaction: { intersect: false, mode: 'index' }
            }
        });
    })();
    <?php endif; ?>
    
    setInterval(showRealtimeIndicator, 30000);
});
</script>

</div><!-- close main-content -->
</div><!-- close content-wrapper -->
</div><!-- close dashboard-container -->

<?php include 'includes/footer.php'; ?>

</body>
</html>