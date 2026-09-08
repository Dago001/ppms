<?php
@ini_set('display_errors', 1);
@ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
@ini_set('memory_limit', '512M');
@ini_set('max_execution_time', '300');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'includes/config.php';
@$pdo->exec("SET SESSION sql_mode=''");
require_once 'includes/auth.php';
require_once 'includes/permissions.php';
require_once __DIR__ . '/includes/cache.php';

if (!isLoggedIn()) { header('Location: login'); exit(); }

$user = [];
try {
    $stmt = $pdo->prepare("SELECT u.*, r.name as role_name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
} catch (Throwable $e) {
    $user = ['username' => $_SESSION['username'] ?? 'User', 'full_name' => 'User', 'role_name' => 'User'];
}

$userRole = $user['role_name'] ?? '';
$userId = $_SESSION['user_id'] ?? 0;

// Roles that see ALL personnel globally (Admin, Service HQ, SHQ Admin, Super Admin, User)
$isAdmin = in_array(strtolower($userRole), ['admin', 'service hq', 'shq admin', 'super admin', 'user']);
$filterSQL = "";
$filterParams = [];
$userZoneNames = [];

require_once __DIR__ . '/includes/formation_helper.php';

if (!$isAdmin) {
    $nis_formations_filter = getNISFormations();
    $zoneFilter = buildUserFilter($pdo, $userId, $nis_formations_filter, 'e', 'presentPosting');
    $filterSQL = $zoneFilter['sql'];
    $filterParams = $zoneFilter['params'];
    $userZoneNames = $zoneFilter['zone_names'];
}

// --------------------------------------------
// REPORT FILTERS
// --------------------------------------------
$selectedZone   = trim($_GET['zone'] ?? '');
$selectedRank   = trim($_GET['rank'] ?? '');
$selectedGender = trim($_GET['gender'] ?? '');
$selectedStatus = trim($_GET['status'] ?? '');
$dateFrom       = trim($_GET['date_from'] ?? '');
$dateTo         = trim($_GET['date_to'] ?? '');
$searchQuery    = trim($_GET['q'] ?? '');

// Which tab should be active on load - pagination links on the Retirement Watchlist
// and Overdue Rotation tabs carry this so a page-2 click doesn't bounce the user
// back to the Nominal Roll tab.
$validTabs = ['nominalTab', 'retirementWatchlistTab', 'overdueTab', 'heatmapTab', 'zoneSummaryTab', 'genderTab', 'rankTab', 'ageTab', 'serviceTab', 'postingStatusTab'];
$activeTab = in_array($_GET['tab'] ?? '', $validTabs) ? $_GET['tab'] : 'nominalTab';

$dynamicFilter = $filterSQL;
$dynamicParams = $filterParams;

if (!empty($selectedZone)) {
    $dynamicFilter .= " AND e.presentPosting LIKE ?";
    $dynamicParams[] = "%$selectedZone%";
}
if (!empty($selectedRank)) {
    $dynamicFilter .= " AND e.currentRank LIKE ?";
    $dynamicParams[] = "%$selectedRank%";
}
if (!empty($selectedGender)) {
    $dynamicFilter .= " AND p.gender = ?";
    $dynamicParams[] = $selectedGender;
}
if (!empty($selectedStatus)) {
    $dynamicFilter .= " AND e.empStatus = ?";
    $dynamicParams[] = $selectedStatus;
}
if (!empty($dateFrom)) {
    $dynamicFilter .= " AND e.dofa >= ?";
    $dynamicParams[] = $dateFrom;
}
if (!empty($dateTo)) {
    $dynamicFilter .= " AND e.dofa <= ?";
    $dynamicParams[] = $dateTo;
}
if (!empty($searchQuery)) {
    $dynamicFilter .= " AND (p.serviceNo LIKE ? OR p.surname LIKE ? OR p.firstName LIKE ? OR e.presentPosting LIKE ?)";
    $dynamicParams[] = "%$searchQuery%";
    $dynamicParams[] = "%$searchQuery%";
    $dynamicParams[] = "%$searchQuery%";
    $dynamicParams[] = "%$searchQuery%";
}

// 1. Detailed Officer Nominal Roll (High-Speed 50-Row Screen Pagination - ACTIVE OFFICERS ONLY)
$nominalPage = isset($_GET['npage']) ? max(1, intval($_GET['npage'])) : 1;
$nominalLimit = 50;
$nominalOffset = ($nominalPage - 1) * $nominalLimit;

$nominalRollReport = [];
$totalNominalRecords = 0;
$totalNominalPages = 1;

$activeCondition = " AND (e.empStatus = 'Active' OR e.empStatus IS NULL OR e.empStatus != 'Retired')
                     AND (p.dob IS NULL OR p.dob = '' OR p.dob = '0000-00-00' OR YEAR(p.dob) < 1940 OR TIMESTAMPDIFF(YEAR, p.dob, CURDATE()) < 60)
                     AND (e.dofa IS NULL OR e.dofa = '' OR e.dofa = '0000-00-00' OR YEAR(e.dofa) < 1960 OR TIMESTAMPDIFF(YEAR, e.dofa, CURDATE()) < 35)";

try {
    // Count Total Records (ACTIVE OFFICERS ONLY)
    $countSql = "SELECT COUNT(*) FROM tbl_emppersonal p INNER JOIN tbl_employment e ON p.serviceNo = e.serviceNo WHERE 1=1 $activeCondition $dynamicFilter";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($dynamicParams);
    $totalNominalRecords = $countStmt->fetchColumn() ?? 0;
    $totalNominalPages = max(1, ceil($totalNominalRecords / $nominalLimit));

    // Page-specific records (50 per page for high-speed UI sorted from Highest Rank to Lowest Rank - Active Officers Only)
    $sql = "SELECT p.serviceNo, p.surname, p.firstName, p.middleName, p.gender, p.dob, e.currentRank, e.presentPosting, e.dofa, e.empStatus 
            FROM tbl_emppersonal p 
            INNER JOIN tbl_employment e ON p.serviceNo = e.serviceNo 
            WHERE 1=1 $activeCondition $dynamicFilter 
            ORDER BY 
            CASE 
                WHEN e.currentRank LIKE '%Comptroller General%' AND e.currentRank NOT LIKE '%Asst%' AND e.currentRank NOT LIKE '%Dep%' THEN 1
                WHEN e.currentRank LIKE '%Dep%Comptroller General%' THEN 2
                WHEN e.currentRank LIKE '%Asst%Comptroller General%' THEN 3
                WHEN e.currentRank LIKE 'Comptroller%' THEN 4
                WHEN e.currentRank LIKE '%Deputy Comptroller%' THEN 5
                WHEN e.currentRank LIKE '%Assistant Comptroller%' THEN 6
                WHEN e.currentRank LIKE '%Chief Superintendent%' THEN 7
                WHEN e.currentRank LIKE '%Superintendent%' AND e.currentRank NOT LIKE '%Chief%' AND e.currentRank NOT LIKE '%Dep%' AND e.currentRank NOT LIKE '%Asst%' THEN 8
                WHEN e.currentRank LIKE '%Deputy Super%' THEN 9
                WHEN e.currentRank LIKE '%Asst%Superintendent 1%' OR e.currentRank = 'ASI 1' THEN 10
                WHEN e.currentRank LIKE '%Asst%Superintendent 2%' OR e.currentRank LIKE '%ASI%2%' THEN 11
                WHEN e.currentRank LIKE '%Chief Inspector%' THEN 12
                WHEN e.currentRank LIKE '%Inspector%' AND e.currentRank NOT LIKE '%Assistant%' AND e.currentRank NOT LIKE '%Chief%' THEN 13
                WHEN e.currentRank LIKE '%Assistant Inspector%' THEN 14
                WHEN e.currentRank LIKE '%Chief Immigration Asst%' THEN 15
                WHEN e.currentRank LIKE '%Senior Immigration Asst%' THEN 16
                WHEN e.currentRank LIKE '%Immigration Asst. 1%' OR e.currentRank LIKE '%IA-1%' THEN 17
                WHEN e.currentRank LIKE '%Immigration Asst. 2%' OR e.currentRank LIKE '%IA-2%' OR e.currentRank LIKE '%IA2%' THEN 18
                WHEN e.currentRank LIKE '%Immigration Asst. 3%' OR e.currentRank LIKE '%IA-3%' THEN 19
                ELSE 20
            END ASC,
            p.surname ASC 
            LIMIT $nominalLimit OFFSET $nominalOffset";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($dynamicParams);
    $nominalRollReport = $stmt->fetchAll();
} catch (Throwable $e) {}

// Build export query string for full dataset streaming
$exportParams = $_GET;
unset($exportParams['npage']);
$exportQueryStr = http_build_query($exportParams);

// 2. Zone Summary Report
$cacheKeyZone = 'zone_summary_' . md5($dynamicFilter . serialize($dynamicParams));
$zoneSummaryReport = CacheManager::get($cacheKeyZone, 300);
if ($zoneSummaryReport === null) {
    $zoneSummaryReport = [];
    try {
        $sql = "SELECT e.presentPosting as zone_name, 
                       COUNT(*) as total_officers, 
                       SUM(CASE WHEN p.gender='Male' THEN 1 ELSE 0 END) as male_count, 
                       SUM(CASE WHEN p.gender='Female' THEN 1 ELSE 0 END) as female_count, 
                       COUNT(DISTINCT e.currentRank) as rank_variety 
                FROM tbl_employment e 
                INNER JOIN tbl_emppersonal p ON e.serviceNo = p.serviceNo 
                WHERE e.presentPosting IS NOT NULL AND e.presentPosting != '' $dynamicFilter 
                GROUP BY e.presentPosting 
                ORDER BY total_officers DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($dynamicParams);
        $zoneSummaryReport = $stmt->fetchAll();
        CacheManager::set($cacheKeyZone, $zoneSummaryReport);
    } catch (Throwable $e) {}
}

// 3. Gender Distribution Report
$cacheKeyGender = 'gender_report_' . md5($dynamicFilter . serialize($dynamicParams));
$genderReport = CacheManager::get($cacheKeyGender, 300);
if ($genderReport === null) {
    $genderReport = [];
    try {
        $sql = "SELECT COALESCE(NULLIF(TRIM(p.gender),''),'Unspecified') as gender, e.presentPosting, COUNT(*) as count 
                FROM tbl_emppersonal p 
                INNER JOIN tbl_employment e ON p.serviceNo = e.serviceNo 
                WHERE 1=1 $dynamicFilter 
                GROUP BY gender, e.presentPosting 
                ORDER BY count DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($dynamicParams);
        $genderReport = $stmt->fetchAll();
        CacheManager::set($cacheKeyGender, $genderReport);
    } catch (Throwable $e) {}
}

// 4. Rank Distribution Report
$cacheKeyRank = 'rank_report_' . md5($dynamicFilter . serialize($dynamicParams));
$rankReport = CacheManager::get($cacheKeyRank, 300);
if ($rankReport === null) {
    $rankReport = [];
    try {
        $sql = "SELECT e.currentRank, e.presentPosting, COUNT(*) as count 
                FROM tbl_employment e 
                INNER JOIN tbl_emppersonal p ON e.serviceNo = p.serviceNo 
                WHERE e.currentRank IS NOT NULL AND e.currentRank != '' $dynamicFilter 
                GROUP BY e.currentRank, e.presentPosting 
                ORDER BY count DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($dynamicParams);
        $rankReport = $stmt->fetchAll();
        CacheManager::set($cacheKeyRank, $rankReport);
    } catch (Throwable $e) {}
}

// 5. Age Group Report
$cacheKeyAge = 'age_group_report_' . md5($dynamicFilter . serialize($dynamicParams));
$ageGroupReport = CacheManager::get($cacheKeyAge, 300);
if ($ageGroupReport === null) {
    $ageGroupReport = [];
    try {
        $sql = "SELECT 
                    CASE 
                        WHEN p.dob IS NULL OR p.dob = '0000-00-00' OR YEAR(p.dob) < 1940 OR TIMESTAMPDIFF(YEAR, p.dob, CURDATE()) < 18 THEN 'Unspecified'
                        WHEN TIMESTAMPDIFF(YEAR, p.dob, CURDATE()) BETWEEN 18 AND 29 THEN '18-29 yrs'
                        WHEN TIMESTAMPDIFF(YEAR, p.dob, CURDATE()) BETWEEN 30 AND 39 THEN '30-39 yrs'
                        WHEN TIMESTAMPDIFF(YEAR, p.dob, CURDATE()) BETWEEN 40 AND 49 THEN '40-49 yrs'
                        WHEN TIMESTAMPDIFF(YEAR, p.dob, CURDATE()) BETWEEN 50 AND 59 THEN '50-59 yrs'
                        ELSE '60+ yrs'
                    END as age_group, 
                    e.presentPosting, 
                    COUNT(*) as count 
                FROM tbl_emppersonal p 
                INNER JOIN tbl_employment e ON p.serviceNo = e.serviceNo 
                WHERE 1=1 $dynamicFilter 
                GROUP BY age_group, e.presentPosting 
                ORDER BY count DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($dynamicParams);
        $ageGroupReport = $stmt->fetchAll();
        CacheManager::set($cacheKeyAge, $ageGroupReport);
    } catch (Throwable $e) {}
}

// 6. Service Years Report
$cacheKeyService = 'service_years_report_' . md5($dynamicFilter . serialize($dynamicParams));
$serviceYearsReport = CacheManager::get($cacheKeyService, 300);
if ($serviceYearsReport === null) {
    $serviceYearsReport = [];
    try {
        $sql = "SELECT 
                    CASE 
                        WHEN e.dofa IS NULL OR e.dofa = '0000-00-00' OR YEAR(e.dofa) < 1960 THEN 'Unspecified'
                        WHEN TIMESTAMPDIFF(YEAR, e.dofa, CURDATE()) < 5 THEN '0-4 yrs'
                        WHEN TIMESTAMPDIFF(YEAR, e.dofa, CURDATE()) BETWEEN 5 AND 9 THEN '5-9 yrs'
                        WHEN TIMESTAMPDIFF(YEAR, e.dofa, CURDATE()) BETWEEN 10 AND 14 THEN '10-14 yrs'
                        WHEN TIMESTAMPDIFF(YEAR, e.dofa, CURDATE()) BETWEEN 15 AND 19 THEN '15-19 yrs'
                        WHEN TIMESTAMPDIFF(YEAR, e.dofa, CURDATE()) BETWEEN 20 AND 24 THEN '20-24 yrs'
                        WHEN TIMESTAMPDIFF(YEAR, e.dofa, CURDATE()) BETWEEN 25 AND 29 THEN '25-29 yrs'
                        ELSE '30+ yrs'
                    END as service_group, 
                    e.presentPosting, 
                    COUNT(*) as count 
                FROM tbl_employment e 
                INNER JOIN tbl_emppersonal p ON e.serviceNo = p.serviceNo 
                WHERE 1=1 $dynamicFilter 
                GROUP BY service_group, e.presentPosting 
                ORDER BY count DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($dynamicParams);
        $serviceYearsReport = $stmt->fetchAll();
        CacheManager::set($cacheKeyService, $serviceYearsReport);
    } catch (Throwable $e) {}
}

// 7. Posting Operations Report
$cacheKeyStatus = 'posting_status_report';
$postingStatusReport = CacheManager::get($cacheKeyStatus, 300);
if ($postingStatusReport === null) {
    $postingStatusReport = [];
    try {
        $sql = "SELECT COALESCE(status,'pending') as status, posting_zone, COUNT(*) as count FROM posting_notifications WHERE 1=1 GROUP BY status, posting_zone ORDER BY count DESC";
        $postingStatusReport = $pdo->query($sql)->fetchAll();
        CacheManager::set($cacheKeyStatus, $postingStatusReport);
    } catch (Throwable $e) {}
}

// 8. Comprehensive Retirement Watchlist & Forecast Report (Has Retired & Will Soon Retire)
// Paginated 50/page - an unfiltered fetch here returns ~8,200 rows, which rendered
// straight into the page HTML is what made /reports slow/unresponsive to open.
$retirementPage = isset($_GET['rpage']) ? max(1, intval($_GET['rpage'])) : 1;
$retirementLimit = 50;
$retirementOffset = ($retirementPage - 1) * $retirementLimit;
$selectedRetirementYear = trim($_GET['ryear'] ?? '');

$retirementWatchlistReport = [];
$retirementYearsList = [];
$totalRetirementRecords = 0;
$totalRetirementPages = 1;
try {
    $retirementBaseSql = "SELECT p.serviceNo, p.surname, p.firstName, p.middleName, p.gender, p.dob, e.currentRank, e.presentPosting, e.dopa, e.dofa, e.empStatus,
                   CASE
                       WHEN (p.dob IS NOT NULL AND p.dob != '0000-00-00' AND YEAR(p.dob) >= 1940)
                        AND (e.dofa IS NOT NULL AND e.dofa != '0000-00-00' AND YEAR(e.dofa) >= 1960)
                       THEN LEAST(DATE_ADD(p.dob, INTERVAL 60 YEAR), DATE_ADD(e.dofa, INTERVAL 35 YEAR))

                       WHEN (p.dob IS NOT NULL AND p.dob != '0000-00-00' AND YEAR(p.dob) >= 1940)
                       THEN DATE_ADD(p.dob, INTERVAL 60 YEAR)

                       WHEN (e.dofa IS NOT NULL AND e.dofa != '0000-00-00' AND YEAR(e.dofa) >= 1960)
                       THEN DATE_ADD(e.dofa, INTERVAL 35 YEAR)

                       ELSE NULL
                   END as retirement_date,

                   YEAR(
                       CASE
                           WHEN (p.dob IS NOT NULL AND p.dob != '0000-00-00' AND YEAR(p.dob) >= 1940)
                            AND (e.dofa IS NOT NULL AND e.dofa != '0000-00-00' AND YEAR(e.dofa) >= 1960)
                           THEN LEAST(DATE_ADD(p.dob, INTERVAL 60 YEAR), DATE_ADD(e.dofa, INTERVAL 35 YEAR))

                           WHEN (p.dob IS NOT NULL AND p.dob != '0000-00-00' AND YEAR(p.dob) >= 1940)
                           THEN DATE_ADD(p.dob, INTERVAL 60 YEAR)

                           WHEN (e.dofa IS NOT NULL AND e.dofa != '0000-00-00' AND YEAR(e.dofa) >= 1960)
                           THEN DATE_ADD(e.dofa, INTERVAL 35 YEAR)

                           ELSE NULL
                       END
                   ) as retirement_year,

                   DATEDIFF(
                       CASE
                           WHEN (p.dob IS NOT NULL AND p.dob != '0000-00-00' AND YEAR(p.dob) >= 1940)
                            AND (e.dofa IS NOT NULL AND e.dofa != '0000-00-00' AND YEAR(e.dofa) >= 1960)
                           THEN LEAST(DATE_ADD(p.dob, INTERVAL 60 YEAR), DATE_ADD(e.dofa, INTERVAL 35 YEAR))

                           WHEN (p.dob IS NOT NULL AND p.dob != '0000-00-00' AND YEAR(p.dob) >= 1940)
                           THEN DATE_ADD(p.dob, INTERVAL 60 YEAR)

                           WHEN (e.dofa IS NOT NULL AND e.dofa != '0000-00-00' AND YEAR(e.dofa) >= 1960)
                           THEN DATE_ADD(e.dofa, INTERVAL 35 YEAR)

                           ELSE NULL
                       END, 
                       CURDATE()
                   ) as days_to_retirement
            FROM tbl_employment e
            INNER JOIN tbl_emppersonal p ON e.serviceNo = p.serviceNo
            WHERE (
                e.empStatus = 'Retired' 
                OR LOWER(e.empStatus) LIKE '%retir%'
                OR (p.dob IS NOT NULL AND p.dob != '0000-00-00' AND YEAR(p.dob) >= 1940 AND TIMESTAMPDIFF(YEAR, p.dob, CURDATE()) >= 60)
                OR (e.dofa IS NOT NULL AND e.dofa != '0000-00-00' AND YEAR(e.dofa) >= 1960 AND TIMESTAMPDIFF(YEAR, e.dofa, CURDATE()) >= 35)
                OR (p.dob IS NOT NULL AND p.dob != '0000-00-00' AND YEAR(p.dob) >= 1940 AND DATE_ADD(p.dob, INTERVAL 60 YEAR) <= DATE_ADD(NOW(), INTERVAL 15 YEAR))
                OR (e.dofa IS NOT NULL AND e.dofa != '0000-00-00' AND YEAR(e.dofa) >= 1960 AND DATE_ADD(e.dofa, INTERVAL 35 YEAR) <= DATE_ADD(NOW(), INTERVAL 15 YEAR))
            ) $dynamicFilter";

    $currentYr = (int)date('Y');
    $retirementYearsList = range($currentYr - 2, $currentYr + 15);

    $yearFilterSql = '';
    $yearFilterParams = [];
    if ($selectedRetirementYear !== '' && ctype_digit($selectedRetirementYear)) {
        $yearFilterSql = " AND t.retirement_year = ?";
        $yearFilterParams[] = (int)$selectedRetirementYear;
    }

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM ($retirementBaseSql) t WHERE 1=1 $yearFilterSql");
    $countStmt->execute(array_merge($dynamicParams, $yearFilterParams));
    $totalRetirementRecords = $countStmt->fetchColumn() ?? 0;
    $totalRetirementPages = max(1, ceil($totalRetirementRecords / $retirementLimit));

    $stmt = $pdo->prepare("SELECT * FROM ($retirementBaseSql) t WHERE 1=1 $yearFilterSql ORDER BY t.retirement_date ASC LIMIT $retirementLimit OFFSET $retirementOffset");
    $stmt->execute(array_merge($dynamicParams, $yearFilterParams));
    $retirementWatchlistReport = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// 9. Formation vs Rank Heatmap Matrix Data (ALL Formations & ALL Ranks)
$zoneHeatmapData = [];
try {
    if ($filterSQL) {
        $stmt = $pdo->prepare("SELECT e.presentPosting, e.currentRank, COUNT(*) as count FROM tbl_employment e INNER JOIN tbl_emppersonal p ON e.serviceNo = p.serviceNo WHERE e.presentPosting IS NOT NULL AND e.presentPosting != '' AND e.currentRank IS NOT NULL AND e.currentRank != '' $filterSQL GROUP BY e.presentPosting, e.currentRank ORDER BY e.presentPosting ASC");
        $stmt->execute($filterParams);
        $zoneHeatmapData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $zoneHeatmapData = $pdo->query("SELECT e.presentPosting, e.currentRank, COUNT(*) as count FROM tbl_employment e INNER JOIN tbl_emppersonal p ON e.serviceNo = p.serviceNo WHERE e.presentPosting IS NOT NULL AND e.presentPosting != '' AND e.currentRank IS NOT NULL AND e.currentRank != '' GROUP BY e.presentPosting, e.currentRank ORDER BY e.presentPosting ASC")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {}

// 10. Officers Overdue For Posting Rotation (Staid > 5 Years)
require_once __DIR__ . '/includes/settings_helper.php';
$overstayThreshold = max(intval(getSystemSetting('overstay_years_threshold', '5')), 1);

// Paginated 50/page - an unfiltered fetch here returns thousands of rows, another
// major contributor to /reports being slow/unresponsive to open.
$overduePage = isset($_GET['opage']) ? max(1, intval($_GET['opage'])) : 1;
$overdueLimit = 50;
$overdueOffset = ($overduePage - 1) * $overdueLimit;

$overdueOfficersReport = [];
$totalOverdueRecords = 0;
$totalOverduePages = 1;
try {
    $countSql = "SELECT COUNT(*) 
                 FROM tbl_employment e 
                 INNER JOIN tbl_emppersonal p ON e.serviceNo = p.serviceNo 
                 WHERE e.presentPosting IS NOT NULL AND e.presentPosting != ''
                   AND (
                       (e.dopa IS NOT NULL AND e.dopa != '0000-00-00' AND TIMESTAMPDIFF(YEAR, e.dopa, CURDATE()) >= $overstayThreshold)
                       OR
                       ((e.dopa IS NULL OR e.dopa = '0000-00-00') AND e.dofa IS NOT NULL AND e.dofa != '0000-00-00' AND TIMESTAMPDIFF(YEAR, e.dofa, CURDATE()) >= $overstayThreshold)
                   ) $filterSQL";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($filterParams);
    $totalOverdueRecords = $countStmt->fetchColumn() ?? 0;
    $totalOverduePages = max(1, ceil($totalOverdueRecords / $overdueLimit));

    $sql = "SELECT 
                p.serviceNo, p.surname, p.firstName, p.middleName, e.currentRank, e.presentPosting, e.dopa, e.dofa,
                COALESCE(NULLIF(e.dopa, '0000-00-00'), NULLIF(e.dofa, '0000-00-00')) as clean_start_date,
                TIMESTAMPDIFF(YEAR, COALESCE(NULLIF(e.dopa, '0000-00-00'), NULLIF(e.dofa, '0000-00-00')), CURDATE()) as years_in_formation
            FROM tbl_employment e 
            INNER JOIN tbl_emppersonal p ON e.serviceNo = p.serviceNo 
            WHERE e.presentPosting IS NOT NULL AND e.presentPosting != ''
              AND (
                  (e.dopa IS NOT NULL AND e.dopa != '0000-00-00' AND TIMESTAMPDIFF(YEAR, e.dopa, CURDATE()) >= $overstayThreshold)
                  OR
                  ((e.dopa IS NULL OR e.dopa = '0000-00-00') AND e.dofa IS NOT NULL AND e.dofa != '0000-00-00' AND TIMESTAMPDIFF(YEAR, e.dofa, CURDATE()) >= $overstayThreshold)
              ) $filterSQL
            ORDER BY years_in_formation DESC, p.surname ASC 
            LIMIT $overdueLimit OFFSET $overdueOffset";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($filterParams);
    $overdueOfficersReport = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Filter Dropdown Options
$zoneOptions = [];
$rankOptions = [];
try {
    $zoneOptions = $pdo->query("SELECT DISTINCT presentPosting FROM tbl_employment WHERE presentPosting IS NOT NULL AND presentPosting != '' ORDER BY presentPosting")->fetchAll(PDO::FETCH_COLUMN);
    $rankOptions = $pdo->query("SELECT DISTINCT currentRank FROM tbl_employment WHERE currentRank IS NOT NULL AND currentRank != '' ORDER BY currentRank")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {}

// Real-time Posting Type Statistics (Transfer, Redeployment, Initial Deployment)
$postingTypeStats = ['transfer_count' => 0, 'redeployment_count' => 0, 'initial_count' => 0];
try {
    if ($isAdmin) {
        $sql = "SELECT 
                    SUM(CASE WHEN LOWER(posting_type) LIKE '%transfer%' OR posting_type IS NULL OR posting_type = '' THEN 1 ELSE 0 END) as transfer_count,
                    SUM(CASE WHEN LOWER(posting_type) LIKE '%redeploy%' THEN 1 ELSE 0 END) as redeployment_count,
                    SUM(CASE WHEN LOWER(posting_type) LIKE '%initial%' OR LOWER(posting_type) LIKE '%new%' THEN 1 ELSE 0 END) as initial_count
                FROM posting_history";
        $stmt = $pdo->query($sql);
        $postingTypeStats = $stmt->fetch(PDO::FETCH_ASSOC) ?: $postingTypeStats;
    } else {
        $sql = "SELECT 
                    SUM(CASE WHEN LOWER(ph.posting_type) LIKE '%transfer%' OR ph.posting_type IS NULL OR ph.posting_type = '' THEN 1 ELSE 0 END) as transfer_count,
                    SUM(CASE WHEN LOWER(ph.posting_type) LIKE '%redeploy%' THEN 1 ELSE 0 END) as redeployment_count,
                    SUM(CASE WHEN LOWER(ph.posting_type) LIKE '%initial%' OR LOWER(ph.posting_type) LIKE '%new%' THEN 1 ELSE 0 END) as initial_count
                FROM posting_history ph
                INNER JOIN tbl_employment e ON ph.serviceNo = e.serviceNo
                WHERE 1=1 $filterSQL";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($filterParams);
        $postingTypeStats = $stmt->fetch(PDO::FETCH_ASSOC) ?: $postingTypeStats;
    }
} catch (Throwable $e) {}

// Summary KPI Stats
$summaryStats = [];
try {
    $baseCountSQL = "SELECT COUNT(*) FROM tbl_emppersonal p INNER JOIN tbl_employment e ON p.serviceNo = e.serviceNo WHERE 1=1";
    
    if ($isAdmin) {
        $summaryStats['total_personnel'] = $pdo->query("$baseCountSQL")->fetchColumn() ?? 0;
    } else {
        $stmt = $pdo->prepare("$baseCountSQL $filterSQL");
        $stmt->execute($filterParams);
        $summaryStats['total_personnel'] = $stmt->fetchColumn() ?? 0;
    }
    
    $summaryStats['total_zones'] = count($zoneOptions);
    $summaryStats['total_ranks'] = count($rankOptions);
    
    // Filtered count
    $stmt = $pdo->prepare("$baseCountSQL $dynamicFilter");
    $stmt->execute($dynamicParams);
    $summaryStats['filtered_count'] = $stmt->fetchColumn() ?? 0;
} catch (Throwable $e) {
    $summaryStats = ['total_personnel' => 0, 'total_zones' => 0, 'total_ranks' => 0, 'filtered_count' => 0];
}
?>

<?php include 'includes/header.php'; ?>

<style>
    .page-title {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 0.75rem 1.15rem;
        margin-bottom: 1.15rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 0.75rem;
    }
    .page-title h1 {
        font-size: 1rem;
        font-weight: 500;
        color: #1e293b;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    .page-title h1 i { color: #1a5632; }

    .zone-badge {
        display: inline-block;
        background: #eff6ff;
        color: #1d4ed8;
        border: 1px solid #bfdbfe;
        padding: 0.15rem 0.5rem;
        border-radius: 12px;
        font-size: 0.675rem;
        font-weight: 500;
    }

    .kpi-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
        gap: 0.75rem;
        margin-bottom: 1.15rem;
    }
    .kpi-card {
        background: #ffffff;
        border-radius: 8px;
        padding: 0.85rem;
        text-align: center;
        border: 1px solid #e2e8f0;
        box-shadow: 0 1px 3px rgba(0,0,0,0.03);
    }
    .kpi-card .value {
        font-size: 1.35rem;
        font-weight: 600;
        color: #1a5632;
        line-height: 1.1;
    }
    .kpi-card .label {
        font-size: 0.65rem;
        color: #64748b;
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 0.4px;
        margin-top: 0.3rem;
    }

    .filter-card {
        background: #ffffff;
        border-radius: 10px;
        border: 1px solid #e2e8f0;
        padding: 0.85rem 1.15rem;
        margin-bottom: 1.15rem;
    }
    .filter-grid {
        display: flex;
        gap: 0.55rem;
        flex-wrap: wrap;
        align-items: center;
    }
    .filter-grid select, .filter-grid input {
        padding: 0.4rem 0.65rem;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        font-size: 0.775rem;
        font-weight: 500;
        color: #334155;
        background: #f8fafc;
        min-width: 130px;
    }
    .filter-grid select:focus, .filter-grid input:focus {
        outline: none;
        border-color: #1a5632;
        background: #ffffff;
    }
    .btn-apply {
        padding: 0.4rem 0.85rem;
        background: #1a5632;
        color: #ffffff;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-size: 0.775rem;
        font-weight: 500;
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
    }
    .btn-apply:hover { background: #154628; }
    .btn-reset {
        padding: 0.4rem 0.75rem;
        background: #f1f5f9;
        color: #475569;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        cursor: pointer;
        font-size: 0.775rem;
        font-weight: 500;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
    }
    .btn-reset:hover { background: #e2e8f0; }

    .tabs-bar {
        display: flex;
        gap: 0.35rem;
        margin-bottom: 1.15rem;
        border-bottom: 1px solid #e2e8f0;
        padding-bottom: 0.4rem;
        flex-wrap: wrap;
    }
    .tab-btn {
        padding: 0.4rem 0.8rem;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        cursor: pointer;
        font-size: 0.75rem;
        font-weight: 500;
        color: #475569;
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        transition: all 0.15s ease;
    }
    .tab-btn:hover { background: #e2e8f0; color: #1e293b; }
    .tab-btn.active { background: #1a5632; color: #ffffff; border-color: #1a5632; }

    .tab-content { display: none; }
    .tab-content.active { display: block; }

    .card {
        background: #ffffff;
        border-radius: 10px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        border: 1px solid #e2e8f0;
        margin-bottom: 1.15rem;
    }
    .card-header {
        padding: 0.65rem 1rem;
        font-weight: 500;
        font-size: 0.8rem;
        border-bottom: 1px solid #e2e8f0;
        color: #1a5632;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 0.5rem;
    }
    .card-header h3 { font-size: 0.8rem; margin: 0; font-weight: 500; color: #1a5632; display: flex; align-items: center; gap: 0.4rem; }
    .card-body { padding: 0; }

    .table-container { max-height: 520px; overflow-y: auto; -webkit-overflow-scrolling: touch; }
    table { width: 100%; border-collapse: collapse; font-size: 0.775rem; }
    table th { background: #f8fafc; padding: 0.5rem 0.7rem; text-align: left; font-weight: 500; border-bottom: 1px solid #e2e8f0; color: #475569; white-space: nowrap; position: sticky; top: 0; z-index: 2; }
    table td { padding: 0.45rem 0.7rem; border-bottom: 1px solid #f1f5f9; color: #334155; }
    table tbody tr:hover { background: #f8fafc; }

    .badge-count {
        background: #f1f5f9;
        color: #475569;
        padding: 0.15rem 0.5rem;
        border-radius: 12px;
        font-size: 0.675rem;
        font-weight: 500;
        border: 1px solid #e2e8f0;
    }
    .btn-export-sm {
        background: #1a5632;
        color: #ffffff;
        border: none;
        padding: 0.25rem 0.6rem;
        border-radius: 5px;
        font-size: 0.7rem;
        font-weight: 500;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        text-decoration: none;
    }
    .btn-export-sm:hover { background: #154628; }
    .btn-print-sm {
        background: #f1f5f9;
        color: #475569;
        border: 1px solid #cbd5e1;
        padding: 0.25rem 0.6rem;
        border-radius: 5px;
        font-size: 0.7rem;
        font-weight: 500;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        text-decoration: none;
    }
    .btn-print-sm:hover { background: #e2e8f0; }

    .header-search-box {
        position: relative;
        display: inline-block;
    }
    .header-search-box input {
        padding: 0.3rem 0.6rem 0.3rem 1.75rem;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        font-size: 0.75rem;
        outline: none;
        width: 200px;
        background: #ffffff;
    }
    .header-search-box input:focus { border-color: #1a5632; }
    .header-search-box i {
        position: absolute;
        left: 7px;
        top: 50%;
        transform: translateY(-50%);
        color: #94a3b8;
        font-size: 0.7rem;
    }

    /* Heatmap Sticky Formation Location Column */
    .heatmap-table { border-collapse: separate; border-spacing: 0; width: 100%; font-size: 0.725rem; }
    .heatmap-table th, .heatmap-table td { padding: 6px 8px; text-align: center; border: 1px solid #e2e8f0; }
    .heatmap-table .sticky-col {
        position: sticky !important;
        left: 0 !important;
        border-right: 2px solid #cbd5e1 !important;
        box-shadow: 3px 0 6px rgba(0,0,0,0.08);
    }
    .heatmap-table thead .sticky-col {
        z-index: 5 !important;
        background: #1a5632 !important;
        color: #ffffff !important;
    }
    .heatmap-table tbody th.sticky-col {
        z-index: 4 !important;
        background: #ffffff !important;
        color: #1e293b !important;
    }
    .heatmap-table tbody tr:hover th.sticky-col {
        background: #f1f5f9 !important;
    }
    .heat-max { background: #166534 !important; color: #ffffff !important; font-weight: bold; }
    .heat-very-high { background: #22c55e !important; color: #ffffff !important; font-weight: bold; }
    .heat-high { background: #86efac !important; color: #166534 !important; font-weight: bold; }
    .heat-med { background: #dcfce7 !important; color: #166534 !important; }
    .heat-low { background: #f0fdf4 !important; color: #475569 !important; }

    @media (max-width: 768px) {
        .filter-grid { flex-direction: column; align-items: stretch; }
        .filter-grid select, .filter-grid input { width: 100%; min-width: auto; }
        table th, table td { padding: 0.4rem 0.5rem; }
    }
</style>

<div class="page-title">
    <h1>
        <i class="fas fa-file-alt"></i> Official Personnel Reports & Nominal Roll
        <?php if (!empty($userZoneNames)): ?>
            <span class="zone-badge">Zone/Command: <?php echo implode(', ', $userZoneNames); ?></span>
        <?php else: ?>
           
        <?php endif; ?>
    </h1>
    
</div>

<!-- KPI Summary Cards Bar -->
<div class="kpi-row">
    <div class="kpi-card">
        <div class="value"><?php echo number_format($summaryStats['total_personnel']); ?></div>
        <div class="label">Total Personnel</div>
    </div>
    <div class="kpi-card">
        <div class="value"><?php echo number_format($totalNominalRecords); ?></div>
        <div class="label">Active Nominal Roll</div>
    </div>
    <div class="kpi-card">
        <div class="value" style="color:#1d4ed8;"><?php echo number_format($postingTypeStats['transfer_count'] ?? 0); ?></div>
        <div class="label">Transfers</div>
    </div>
    <div class="kpi-card">
        <div class="value" style="color:#7c3aed;"><?php echo number_format($postingTypeStats['redeployment_count'] ?? 0); ?></div>
        <div class="label">Redeployments</div>
    </div>
    <div class="kpi-card">
        <div class="value" style="color:#059669;"><?php echo number_format($postingTypeStats['initial_count'] ?? 0); ?></div>
        <div class="label">Initial Deployments</div>
    </div>
    <div class="kpi-card">
        <div class="value"><?php echo number_format(count($retirementWatchlistReport)); ?></div>
        <div class="label" style="color:#dc2626; font-weight:bold;">Retirees & Watchlist</div>
    </div>
    <div class="kpi-card">
        <div class="value"><?php echo count($overdueOfficersReport); ?></div>
        <div class="label" style="color:#d97706; font-weight:bold;">Overdue (5+ Yrs)</div>
    </div>
</div>

<!-- Dynamic Filter Bar -->
<div class="filter-card">
    <form method="GET" class="filter-grid">
        <span style="font-size:0.75rem; font-weight:500; color:#475569; display:flex; align-items:center; gap:0.3rem;">
            <i class="fas fa-filter"></i> Filters:
        </span>
        <input type="text" name="q" value="<?php echo htmlspecialchars($searchQuery); ?>" placeholder="Search Service No / Name..." style="min-width:160px;">
        
        <select name="zone">
            <option value="">All Formations</option>
            <?php foreach ($zoneOptions as $zo): ?>
                <option value="<?php echo htmlspecialchars($zo); ?>" <?php echo $selectedZone === $zo ? 'selected' : ''; ?>><?php echo htmlspecialchars($zo); ?></option>
            <?php endforeach; ?>
        </select>
        
        <select name="rank">
            <option value="">All Ranks</option>
            <?php foreach ($rankOptions as $ro): ?>
                <option value="<?php echo htmlspecialchars($ro); ?>" <?php echo $selectedRank === $ro ? 'selected' : ''; ?>><?php echo htmlspecialchars($ro); ?></option>
            <?php endforeach; ?>
        </select>
        
        <select name="gender">
            <option value="">All Genders</option>
            <option value="Male" <?php echo $selectedGender === 'Male' ? 'selected' : ''; ?>>Male</option>
            <option value="Female" <?php echo $selectedGender === 'Female' ? 'selected' : ''; ?>>Female</option>
        </select>
        
        <select name="status">
            <option value="">All Statuses</option>
            <option value="Active" <?php echo $selectedStatus === 'Active' ? 'selected' : ''; ?>>Active</option>
            <option value="Retired" <?php echo $selectedStatus === 'Retired' ? 'selected' : ''; ?>>Retired</option>
        </select>
        
        <input type="date" name="date_from" value="<?php echo $dateFrom; ?>" title="DOFA From">
        <input type="date" name="date_to" value="<?php echo $dateTo; ?>" title="DOFA To">
        
        <button type="submit" class="btn-apply"><i class="fas fa-search"></i> Apply</button>
        <a href="reports" class="btn-reset"><i class="fas fa-undo"></i> Reset</a>
    </form>
</div>

<!-- Tabs Bar -->
<?php
function reportTabBtnClass($tabId, $activeTab) {
    return 'tab-btn' . ($tabId === $activeTab ? ' active' : '');
}
?>
<div class="tabs-bar">
    <button class="<?php echo reportTabBtnClass('nominalTab', $activeTab); ?>" onclick="switchTab('nominalTab', this)"><i class="fas fa-address-book"></i> Nominal Roll (Active)</button>
    <button class="<?php echo reportTabBtnClass('retirementWatchlistTab', $activeTab); ?>" onclick="switchTab('retirementWatchlistTab', this)"><i class="fas fa-user-clock"></i> Retirement Watchlist & History</button>
    <button class="<?php echo reportTabBtnClass('overdueTab', $activeTab); ?>" onclick="switchTab('overdueTab', this)"><i class="fas fa-exclamation-triangle"></i> Overdue Rotation (5+ Yrs)</button>
    <button class="<?php echo reportTabBtnClass('heatmapTab', $activeTab); ?>" onclick="switchTab('heatmapTab', this)"><i class="fas fa-th"></i> Formation Matrix Heatmap</button>
    <button class="<?php echo reportTabBtnClass('zoneSummaryTab', $activeTab); ?>" onclick="switchTab('zoneSummaryTab', this)"><i class="fas fa-building"></i> Zone Summary</button>
    <button class="<?php echo reportTabBtnClass('genderTab', $activeTab); ?>" onclick="switchTab('genderTab', this)"><i class="fas fa-venus-mars"></i> Gender Breakdown</button>
    <button class="<?php echo reportTabBtnClass('rankTab', $activeTab); ?>" onclick="switchTab('rankTab', this)"><i class="fas fa-star"></i> Rank Breakdown</button>
    <button class="<?php echo reportTabBtnClass('ageTab', $activeTab); ?>" onclick="switchTab('ageTab', this)"><i class="fas fa-calendar-alt"></i> Age Brackets</button>
    <button class="<?php echo reportTabBtnClass('serviceTab', $activeTab); ?>" onclick="switchTab('serviceTab', this)"><i class="fas fa-history"></i> Service Years</button>
    <button class="<?php echo reportTabBtnClass('postingStatusTab', $activeTab); ?>" onclick="switchTab('postingStatusTab', this)"><i class="fas fa-exchange-alt"></i> Posting Operations</button>
</div>

<!-- TAB 1: NOMINAL ROLL (ACTIVE OFFICERS ONLY) -->
<div id="nominalTab" class="tab-content<?php echo $activeTab === 'nominalTab' ? ' active' : ''; ?>">
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-address-book"></i> Comprehensive Active Officer Nominal Roll</h3>
            <div style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap;">
                <div class="header-search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" placeholder="Search Nominal Roll..." onkeyup="filterReportTable('nominalTable', this.value)">
                </div>
                <span class="badge-count" style="background:#e8f5e9; color:#1a5632; font-weight:bold;">
                    Page <?php echo $nominalPage; ?> of <?php echo $totalNominalPages; ?> (Total <?php echo number_format($totalNominalRecords); ?> active officers)
                </span>
                <a href="api/export_nominal_roll?action=print&<?php echo $exportQueryStr; ?>" target="_blank" class="btn-print-sm"><i class="fas fa-print"></i> Print</a>
                <a href="api/export_nominal_roll?action=csv&<?php echo $exportQueryStr; ?>" class="btn-export-sm"><i class="fas fa-download"></i> CSV</a>
            </div>
        </div>
        <div class="card-body">
            <div class="table-container">
                <table id="nominalTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Service No</th>
                            <th>Officer Name</th>
                            <th>Rank</th>
                            <th>Present Location</th>
                            <th>Gender</th>
                            <th>Appointment Date</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($nominalRollReport)): ?>
                            <tr><td colspan="8" style="text-align:center; padding:2.5rem; color:#94a3b8;">No active personnel records match the specified filters or role scope.</td></tr>
                        <?php else: ?>
                            <?php $nr = $nominalOffset + 1; foreach ($nominalRollReport as $r): 
                                $fullName = trim(($r['surname'] ?? '') . ' ' . ($r['firstName'] ?? '') . ' ' . ($r['middleName'] ?? ''));
                            ?>
                            <tr>
                                <td><?php echo $nr++; ?></td>
                                <td><code style="background:#f1f5f9; padding:2px 6px; border-radius:4px; font-weight:bold;"><?php echo htmlspecialchars($r['serviceNo']); ?></code></td>
                                <td><strong><?php echo htmlspecialchars($fullName); ?></strong></td>
                                <td><?php echo htmlspecialchars($r['currentRank'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($r['presentPosting'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($r['gender'] ?? 'N/A'); ?></td>
                                <td><?php echo (!empty($r['dofa']) && $r['dofa'] !== '0000-00-00') ? date('d M Y', strtotime($r['dofa'])) : 'N/A'; ?></td>
                                <td>
                                    <span style="padding:2px 7px; border-radius:10px; font-size:0.675rem; font-weight:bold; background:#dcfce7; color:#166534;">
                                        Active
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- High-Speed 50-Row Pagination Controls -->
            <div style="display:flex; justify-content:space-between; align-items:center; padding:0.75rem 1rem; background:#f8fafc; border-top:1px solid #e2e8f0; flex-wrap:wrap; gap:0.5rem;">
                <div style="font-size:0.75rem; color:#64748b; font-weight:500;">
                    Showing active officers <strong><?php echo min($nominalOffset + 1, $totalNominalRecords); ?></strong> to <strong><?php echo min($nominalOffset + count($nominalRollReport), $totalNominalRecords); ?></strong> of <strong><?php echo number_format($totalNominalRecords); ?></strong> total active officers (Page <strong><?php echo $nominalPage; ?></strong> of <strong><?php echo $totalNominalPages; ?></strong>)
                </div>
                
                <div style="display:flex; gap:0.3rem; align-items:center;">
                    <?php
                    $queryParams = $_GET;
                    function buildNominalPageUrl($p, $params) {
                        $params['npage'] = $p;
                        return 'reports?' . http_build_query($params);
                    }
                    ?>

                    <?php if ($nominalPage > 1): ?>
                        <a href="<?php echo buildNominalPageUrl(1, $queryParams); ?>" class="btn-print-sm" title="First Page"><i class="fas fa-angle-double-left"></i> First</a>
                        <a href="<?php echo buildNominalPageUrl($nominalPage - 1, $queryParams); ?>" class="btn-print-sm" title="Previous Page"><i class="fas fa-angle-left"></i> Prev</a>
                    <?php endif; ?>

                    <?php
                    $startP = max(1, $nominalPage - 2);
                    $endP = min($totalNominalPages, $nominalPage + 2);
                    for ($p = $startP; $p <= $endP; $p++):
                    ?>
                        <a href="<?php echo buildNominalPageUrl($p, $queryParams); ?>" class="btn-print-sm" style="<?php echo $p == $nominalPage ? 'background:#1a5632; color:#ffffff; font-weight:bold;' : ''; ?>">
                            <?php echo $p; ?>
                        </a>
                    <?php endfor; ?>

                    <?php if ($nominalPage < $totalNominalPages): ?>
                        <a href="<?php echo buildNominalPageUrl($nominalPage + 1, $queryParams); ?>" class="btn-print-sm" title="Next Page">Next <i class="fas fa-angle-right"></i></a>
                        <a href="<?php echo buildNominalPageUrl($totalNominalPages, $queryParams); ?>" class="btn-print-sm" title="Last Page">Last <i class="fas fa-angle-double-right"></i></a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- TAB 2: RETIREMENT WATCHLIST & HISTORY (RETIRED + SOON TO RETIRE) -->
<div id="retirementWatchlistTab" class="tab-content<?php echo $activeTab === 'retirementWatchlistTab' ? ' active' : ''; ?>">
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-user-clock"></i> Statutory Retirement Watchlist & Retired Officers Ledger</h3>
            <div style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap;">
                <div class="header-search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" id="retirementSearchInput" placeholder="Search Watchlist..." onkeyup="applyRetirementWatchlistFilters()">
                </div>

                <select id="retirementYearFilter" onchange="location.href = buildRetirementYearUrl(this.value)" style="padding:0.3rem 0.6rem; border:1px solid #cbd5e1; border-radius:6px; font-size:0.75rem; background:#fff; font-weight:600; color:#1a5632;">
                    <option value="">All Retirement Years</option>
                    <?php foreach ($retirementYearsList as $ry): ?>
                        <option value="<?php echo $ry; ?>" <?php echo ((string)$ry === $selectedRetirementYear) ? 'selected' : ''; ?>>Year <?php echo $ry; ?></option>
                    <?php endforeach; ?>
                </select>

                <span class="badge-count" id="retirementCountBadge" style="background:#fee2e2; color:#991b1b; font-weight:bold;"><?php echo number_format($totalRetirementRecords); ?> total retirees & watchlist</span>
                <?php $retirementExportQs = http_build_query(array_merge($_GET, ['report' => 'retirement'])); ?>
                <a class="btn-print-sm" href="api/export_report.php?action=print&<?php echo $retirementExportQs; ?>" target="_blank"><i class="fas fa-print"></i> Print</a>
                <a class="btn-export-sm" href="api/export_report.php?action=csv&<?php echo $retirementExportQs; ?>"><i class="fas fa-download"></i> CSV</a>
            </div>
        </div>
        <div class="card-body">
            <div class="table-container">
                <table id="retirementWatchlistTable">
                    <thead>
                        <tr>
                            <th>S/N</th>
                            <th>Service No</th>
                            <th>Officer Name</th>
                            <th>Rank</th>
                            <th>Present Command</th>
                            <th>Date of Birth</th>
                            <th>DOFA</th>
                            <th>Retirement Date</th>
                            <th>Retirement Year</th>
                            <th>Retirement Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($retirementWatchlistReport)): ?>
                            <tr><td colspan="10" style="text-align:center; padding:2rem; color:#94a3b8;">No officers found in retirement ledger or forecast.</td></tr>
                        <?php else: ?>
                            <?php foreach ($retirementWatchlistReport as $idx => $r): 
                                $days = $r['days_to_retirement'];
                                $retYear = $r['retirement_year'];
                                $isRetired = ($r['empStatus'] === 'Retired' || strtolower($r['empStatus']) === 'retired' || (is_numeric($days) && $days <= 0));
                                $badgeColor = $isRetired ? '#dc2626' : ($days <= 180 ? '#d97706' : '#2563eb');
                                $bgColor = $isRetired ? '#fef2f2' : ($days <= 180 ? '#fffbeb' : '#eff6ff');
                            ?>
                            <tr data-year="<?php echo $retYear; ?>">
                                <td><?php echo $idx + 1; ?></td>
                                <td><code><?php echo htmlspecialchars($r['serviceNo']); ?></code></td>
                                <td><strong><?php echo htmlspecialchars(trim($r['surname'] . ' ' . $r['firstName'] . ' ' . $r['middleName'])); ?></strong></td>
                                <td><?php echo htmlspecialchars($r['currentRank'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($r['presentPosting'] ?? 'N/A'); ?></td>
                                <td><?php echo !empty($r['dob']) && $r['dob'] !== '0000-00-00' ? date('d M Y', strtotime($r['dob'])) : 'N/A'; ?></td>
                                <td><?php echo !empty($r['dofa']) && $r['dofa'] !== '0000-00-00' ? date('d M Y', strtotime($r['dofa'])) : 'N/A'; ?></td>
                                <td><strong style="color:<?php echo $badgeColor; ?>;"><?php echo !empty($r['retirement_date']) && $r['retirement_date'] !== '9999-12-31' ? date('d M Y', strtotime($r['retirement_date'])) : 'Statutory'; ?></strong></td>
                                <td><span style="background:#f1f5f9; padding:2px 6px; border-radius:4px; font-weight:bold;"><?php echo $retYear > 1970 ? $retYear : 'N/A'; ?></span></td>
                                <td>
                                    <span style="background:<?php echo $bgColor; ?>; color:<?php echo $badgeColor; ?>; padding:2px 8px; border-radius:12px; font-weight:bold; font-size:0.7rem;">
                                        <?php if ($isRetired): ?>
                                            <i class="fas fa-check-circle"></i> RETIRED
                                        <?php else: ?>
                                            <i class="fas fa-clock"></i> Retiring in <?php echo number_format($days); ?> days
                                        <?php endif; ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- 50-Row Pagination Controls -->
            <div style="display:flex; justify-content:space-between; align-items:center; padding:0.75rem 1rem; background:#f8fafc; border-top:1px solid #e2e8f0; flex-wrap:wrap; gap:0.5rem;">
                <div style="font-size:0.75rem; color:#64748b; font-weight:500;">
                    Showing <strong><?php echo min($retirementOffset + 1, $totalRetirementRecords); ?></strong> to <strong><?php echo min($retirementOffset + count($retirementWatchlistReport), $totalRetirementRecords); ?></strong> of <strong><?php echo number_format($totalRetirementRecords); ?></strong> total (Page <strong><?php echo $retirementPage; ?></strong> of <strong><?php echo $totalRetirementPages; ?></strong>)
                </div>

                <div style="display:flex; gap:0.3rem; align-items:center;">
                    <?php
                    function buildRetirementPageUrl($p, $params) {
                        $params['rpage'] = $p;
                        $params['tab'] = 'retirementWatchlistTab';
                        return 'reports?' . http_build_query($params);
                    }
                    ?>

                    <?php if ($retirementPage > 1): ?>
                        <a href="<?php echo buildRetirementPageUrl(1, $queryParams); ?>" class="btn-print-sm" title="First Page"><i class="fas fa-angle-double-left"></i> First</a>
                        <a href="<?php echo buildRetirementPageUrl($retirementPage - 1, $queryParams); ?>" class="btn-print-sm" title="Previous Page"><i class="fas fa-angle-left"></i> Prev</a>
                    <?php endif; ?>

                    <?php
                    $rStartP = max(1, $retirementPage - 2);
                    $rEndP = min($totalRetirementPages, $retirementPage + 2);
                    for ($p = $rStartP; $p <= $rEndP; $p++):
                    ?>
                        <a href="<?php echo buildRetirementPageUrl($p, $queryParams); ?>" class="btn-print-sm" style="<?php echo $p == $retirementPage ? 'background:#1a5632; color:#ffffff; font-weight:bold;' : ''; ?>">
                            <?php echo $p; ?>
                        </a>
                    <?php endfor; ?>

                    <?php if ($retirementPage < $totalRetirementPages): ?>
                        <a href="<?php echo buildRetirementPageUrl($retirementPage + 1, $queryParams); ?>" class="btn-print-sm" title="Next Page">Next <i class="fas fa-angle-right"></i></a>
                        <a href="<?php echo buildRetirementPageUrl($totalRetirementPages, $queryParams); ?>" class="btn-print-sm" title="Last Page">Last <i class="fas fa-angle-double-right"></i></a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- TAB 3: OFFICERS OVERDUE FOR ROTATION (5+ YEARS) -->
<div id="overdueTab" class="tab-content<?php echo $activeTab === 'overdueTab' ? ' active' : ''; ?>">
    <div class="card">
        <div class="card-header" style="background:#fef2f2; border-bottom-color:#fecaca;">
            <h3 style="color:#991b1b;"><i class="fas fa-exclamation-triangle"></i> Officers Overdue For Posting Rotation (Staid &gt; <?php echo $overstayThreshold; ?> Years)</h3>
            <div style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap;">
                <div class="header-search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" placeholder="Search Overdue Officers..." onkeyup="filterReportTable('overdueTable', this.value)">
                </div>
                <span class="badge-count" style="background:#fee2e2; color:#991b1b; border-color:#fca5a5; font-weight:bold;"><?php echo number_format($totalOverdueRecords); ?> overdue officers</span>
                <?php $overdueExportQs = http_build_query(array_merge($_GET, ['report' => 'overdue'])); ?>
                <a class="btn-print-sm" href="api/export_report.php?action=print&<?php echo $overdueExportQs; ?>" target="_blank"><i class="fas fa-print"></i> Print</a>
                <a class="btn-export-sm" style="background:#dc2626;" href="api/export_report.php?action=csv&<?php echo $overdueExportQs; ?>"><i class="fas fa-download"></i> CSV</a>
            </div>
        </div>
        <div class="card-body">
            <div class="table-container">
                <table id="overdueTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Service No</th>
                            <th>Officer Name</th>
                            <th>Rank</th>
                            <th>Present Location</th>
                            <th>Posting Start Date</th>
                            <th>Years at Station</th>
                            <th>Rotation Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($overdueOfficersReport)): ?>
                            <tr><td colspan="8" style="text-align:center; padding:2.5rem; color:#94a3b8;">No officers found with overstay &gt;= <?php echo $overstayThreshold; ?> years. All officers are within normal rotation schedules.</td></tr>
                        <?php else: ?>
                            <?php $ovn = 1; foreach ($overdueOfficersReport as $r): 
                                $fullName = trim(($r['surname'] ?? '') . ' ' . ($r['firstName'] ?? '') . ' ' . ($r['middleName'] ?? ''));
                                $yrs = intval($r['years_in_formation']);
                                $badgeBg = $yrs >= 7 ? '#fee2e2' : '#fffbe6';
                                $badgeClr = $yrs >= 7 ? '#991b1b' : '#d97706';
                            ?>
                            <tr>
                                <td><?php echo $ovn++; ?></td>
                                <td><code style="background:#f1f5f9; padding:2px 6px; border-radius:4px; font-weight:bold;"><?php echo htmlspecialchars($r['serviceNo']); ?></code></td>
                                <td><strong><?php echo htmlspecialchars($fullName); ?></strong></td>
                                <td><?php echo htmlspecialchars($r['currentRank'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($r['presentPosting'] ?? 'N/A'); ?></td>
                                <td><?php echo !empty($r['clean_start_date']) ? date('d M Y', strtotime($r['clean_start_date'])) : 'N/A'; ?></td>
                                <td>
                                    <span style="background:<?php echo $badgeBg; ?>; color:<?php echo $badgeClr; ?>; padding:2px 8px; border-radius:12px; font-weight:bold;">
                                        <?php echo $yrs; ?> Years
                                    </span>
                                </td>
                                <td><span style="color:#dc2626; font-size:0.725rem; font-weight:bold;"><i class="fas fa-arrow-right"></i> Overdue for Routine Rotation</span></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- 50-Row Pagination Controls -->
            <div style="display:flex; justify-content:space-between; align-items:center; padding:0.75rem 1rem; background:#f8fafc; border-top:1px solid #e2e8f0; flex-wrap:wrap; gap:0.5rem;">
                <div style="font-size:0.75rem; color:#64748b; font-weight:500;">
                    Showing <strong><?php echo min($overdueOffset + 1, $totalOverdueRecords); ?></strong> to <strong><?php echo min($overdueOffset + count($overdueOfficersReport), $totalOverdueRecords); ?></strong> of <strong><?php echo number_format($totalOverdueRecords); ?></strong> total (Page <strong><?php echo $overduePage; ?></strong> of <strong><?php echo $totalOverduePages; ?></strong>)
                </div>

                <div style="display:flex; gap:0.3rem; align-items:center;">
                    <?php
                    function buildOverduePageUrl($p, $params) {
                        $params['opage'] = $p;
                        $params['tab'] = 'overdueTab';
                        return 'reports?' . http_build_query($params);
                    }
                    ?>

                    <?php if ($overduePage > 1): ?>
                        <a href="<?php echo buildOverduePageUrl(1, $queryParams); ?>" class="btn-print-sm" title="First Page"><i class="fas fa-angle-double-left"></i> First</a>
                        <a href="<?php echo buildOverduePageUrl($overduePage - 1, $queryParams); ?>" class="btn-print-sm" title="Previous Page"><i class="fas fa-angle-left"></i> Prev</a>
                    <?php endif; ?>

                    <?php
                    $oStartP = max(1, $overduePage - 2);
                    $oEndP = min($totalOverduePages, $overduePage + 2);
                    for ($p = $oStartP; $p <= $oEndP; $p++):
                    ?>
                        <a href="<?php echo buildOverduePageUrl($p, $queryParams); ?>" class="btn-print-sm" style="<?php echo $p == $overduePage ? 'background:#1a5632; color:#ffffff; font-weight:bold;' : ''; ?>">
                            <?php echo $p; ?>
                        </a>
                    <?php endfor; ?>

                    <?php if ($overduePage < $totalOverduePages): ?>
                        <a href="<?php echo buildOverduePageUrl($overduePage + 1, $queryParams); ?>" class="btn-print-sm" title="Next Page">Next <i class="fas fa-angle-right"></i></a>
                        <a href="<?php echo buildOverduePageUrl($totalOverduePages, $queryParams); ?>" class="btn-print-sm" title="Last Page">Last <i class="fas fa-angle-double-right"></i></a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- TAB 4: FORMATION MATRIX HEATMAP -->
<div id="heatmapTab" class="tab-content<?php echo $activeTab === 'heatmapTab' ? ' active' : ''; ?>">
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-th"></i> Formation Location vs Rank Matrix Heatmap</h3>
            <div style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap;">
                <button class="btn-export-sm" onclick="exportHeatmap()"><i class="fas fa-download"></i> Export Heatmap CSV</button>
            </div>
        </div>
        <div class="card-body" style="padding:1rem;">
            <div class="table-container" id="heatmapContainer">
                <!-- Dynamically generated via JS -->
            </div>
        </div>
    </div>
</div>

<!-- TAB 5: ZONE SUMMARY -->
<div id="zoneSummaryTab" class="tab-content<?php echo $activeTab === 'zoneSummaryTab' ? ' active' : ''; ?>">
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-building"></i> Zone & Command Deployment Summary</h3>
            <div style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap;">
                <div class="header-search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" placeholder="Search Zone Summary..." onkeyup="filterReportTable('zoneSummaryTable', this.value)">
                </div>
                <span class="badge-count"><?php echo count($zoneSummaryReport); ?> locations</span>
                <button class="btn-print-sm" onclick="printTable('zoneSummaryTable', 'Zone Summary Report')"><i class="fas fa-print"></i> Print</button>
                <button class="btn-export-sm" onclick="exportTableToCSV('zoneSummaryTable', 'Zone_Summary_Report')"><i class="fas fa-download"></i> CSV</button>
            </div>
        </div>
        <div class="card-body">
            <div class="table-container">
                <table id="zoneSummaryTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Posting Location</th>
                            <th>Total Deployed</th>
                            <th>Male Officers</th>
                            <th>Female Officers</th>
                            <th>Rank Variety</th>
                            <th>Male:Female Ratio</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($zoneSummaryReport)): ?>
                            <tr><td colspan="7" style="text-align:center; padding:2.5rem; color:#94a3b8;">No zone summary records match filters.</td></tr>
                        <?php else: ?>
                            <?php $zn = 1; foreach ($zoneSummaryReport as $r): 
                                $male = intval($r['male_count'] ?? 0);
                                $female = intval($r['female_count'] ?? 0);
                                $ratio = $female > 0 ? round($male / $female, 1) . ':1' : ($male > 0 ? 'All Male' : 'N/A');
                            ?>
                            <tr>
                                <td><?php echo $zn++; ?></td>
                                <td><?php echo htmlspecialchars($r['zone_name'] ?? 'N/A'); ?></td>
                                <td><?php echo number_format($r['total_officers']); ?></td>
                                <td><?php echo number_format($male); ?></td>
                                <td><?php echo number_format($female); ?></td>
                                <td><?php echo $r['rank_variety']; ?> Ranks</td>
                                <td><?php echo $ratio; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- TAB 6: GENDER BREAKDOWN -->
<div id="genderTab" class="tab-content<?php echo $activeTab === 'genderTab' ? ' active' : ''; ?>">
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-venus-mars"></i> Gender Breakdown by Formation</h3>
            <div style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap;">
                <div class="header-search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" placeholder="Search Gender Breakdown..." onkeyup="filterReportTable('genderTable', this.value)">
                </div>
                <span class="badge-count"><?php echo count($genderReport); ?> records</span>
                <button class="btn-export-sm" onclick="exportTableToCSV('genderTable', 'Gender_Breakdown_Report')"><i class="fas fa-download"></i> CSV</button>
            </div>
        </div>
        <div class="card-body">
            <div class="table-container">
                <table id="genderTable">
                    <thead><tr><th>#</th><th>Gender</th><th>Posting Location</th><th>Officer Count</th></tr></thead>
                    <tbody>
                        <?php if (empty($genderReport)): ?>
                            <tr><td colspan="4" style="text-align:center; padding:2.5rem; color:#94a3b8;">No gender distribution records found.</td></tr>
                        <?php else: ?>
                            <?php $gn = 1; foreach ($genderReport as $r): ?>
                            <tr>
                                <td><?php echo $gn++; ?></td>
                                <td><?php echo htmlspecialchars($r['gender'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($r['presentPosting'] ?? 'N/A'); ?></td>
                                <td><?php echo number_format($r['count']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- TAB 7: RANK BREAKDOWN -->
<div id="rankTab" class="tab-content<?php echo $activeTab === 'rankTab' ? ' active' : ''; ?>">
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-star"></i> Rank Breakdown by Formation</h3>
            <div style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap;">
                <div class="header-search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" placeholder="Search Rank Breakdown..." onkeyup="filterReportTable('rankTable', this.value)">
                </div>
                <span class="badge-count"><?php echo count($rankReport); ?> records</span>
                <button class="btn-export-sm" onclick="exportTableToCSV('rankTable', 'Rank_Breakdown_Report')"><i class="fas fa-download"></i> CSV</button>
            </div>
        </div>
        <div class="card-body">
            <div class="table-container">
                <table id="rankTable">
                    <thead><tr><th>#</th><th>Rank</th><th>Posting Location</th><th>Officer Count</th></tr></thead>
                    <tbody>
                        <?php if (empty($rankReport)): ?>
                            <tr><td colspan="4" style="text-align:center; padding:2.5rem; color:#94a3b8;">No rank distribution records found.</td></tr>
                        <?php else: ?>
                            <?php $rn = 1; foreach ($rankReport as $r): ?>
                            <tr>
                                <td><?php echo $rn++; ?></td>
                                <td><?php echo htmlspecialchars($r['currentRank'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($r['presentPosting'] ?? 'N/A'); ?></td>
                                <td><?php echo number_format($r['count']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- TAB 8: AGE BRACKETS -->
<div id="ageTab" class="tab-content<?php echo $activeTab === 'ageTab' ? ' active' : ''; ?>">
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-calendar-alt"></i> Age Brackets Breakdown</h3>
            <div style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap;">
                <div class="header-search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" placeholder="Search Age Brackets..." onkeyup="filterReportTable('ageTable', this.value)">
                </div>
                <span class="badge-count"><?php echo count($ageGroupReport); ?> records</span>
                <button class="btn-export-sm" onclick="exportTableToCSV('ageTable', 'Age_Brackets_Report')"><i class="fas fa-download"></i> CSV</button>
            </div>
        </div>
        <div class="card-body">
            <div class="table-container">
                <table id="ageTable">
                    <thead><tr><th>#</th><th>Age Bracket</th><th>Posting Location</th><th>Officer Count</th></tr></thead>
                    <tbody>
                        <?php if (empty($ageGroupReport)): ?>
                            <tr><td colspan="4" style="text-align:center; padding:2.5rem; color:#94a3b8;">No age group records found.</td></tr>
                        <?php else: ?>
                            <?php $an = 1; foreach ($ageGroupReport as $r): ?>
                            <tr>
                                <td><?php echo $an++; ?></td>
                                <td><?php echo htmlspecialchars($r['age_group'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($r['presentPosting'] ?? 'N/A'); ?></td>
                                <td><?php echo number_format($r['count']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- TAB 9: YEARS OF SERVICE -->
<div id="serviceTab" class="tab-content<?php echo $activeTab === 'serviceTab' ? ' active' : ''; ?>">
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-history"></i> Years of Service Breakdown</h3>
            <div style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap;">
                <div class="header-search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" placeholder="Search Service Years..." onkeyup="filterReportTable('serviceTable', this.value)">
                </div>
                <span class="badge-count"><?php echo count($serviceYearsReport); ?> records</span>
                <button class="btn-export-sm" onclick="exportTableToCSV('serviceTable', 'Years_of_Service_Report')"><i class="fas fa-download"></i> CSV</button>
            </div>
        </div>
        <div class="card-body">
            <div class="table-container">
                <table id="serviceTable">
                    <thead><tr><th>#</th><th>Service Duration</th><th>Posting Location</th><th>Officer Count</th></tr></thead>
                    <tbody>
                        <?php if (empty($serviceYearsReport)): ?>
                            <tr><td colspan="4" style="text-align:center; padding:2.5rem; color:#94a3b8;">No service years records found.</td></tr>
                        <?php else: ?>
                            <?php $sn = 1; foreach ($serviceYearsReport as $r): ?>
                            <tr>
                                <td><?php echo $sn++; ?></td>
                                <td><?php echo htmlspecialchars($r['service_group'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($r['presentPosting'] ?? 'N/A'); ?></td>
                                <td><?php echo number_format($r['count']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- TAB 10: POSTING OPERATIONS -->
<div id="postingStatusTab" class="tab-content<?php echo $activeTab === 'postingStatusTab' ? ' active' : ''; ?>">
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-exchange-alt"></i> Posting Operations Status</h3>
            <div style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap;">
                <div class="header-search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" placeholder="Search Posting Operations..." onkeyup="filterReportTable('postingStatusTable', this.value)">
                </div>
                <span class="badge-count"><?php echo count($postingStatusReport); ?> records</span>
                <button class="btn-export-sm" onclick="exportTableToCSV('postingStatusTable', 'Posting_Operations_Status')"><i class="fas fa-download"></i> CSV</button>
            </div>
        </div>
        <div class="card-body">
            <div class="table-container">
                <table id="postingStatusTable">
                    <thead><tr><th>#</th><th>Status</th><th>Zone / Formation</th><th>Count</th></tr></thead>
                    <tbody>
                        <?php if (empty($postingStatusReport)): ?>
                            <tr><td colspan="4" style="text-align:center; padding:2.5rem; color:#94a3b8;">No posting operations data available.</td></tr>
                        <?php else: ?>
                            <?php $ps = 1; foreach ($postingStatusReport as $r): ?>
                            <tr>
                                <td><?php echo $ps++; ?></td>
                                <td>
                                    <span style="padding:2px 7px; border-radius:10px; font-size:0.675rem; font-weight:500; <?php echo ($r['status'] === 'admitted') ? 'background:#dcfce7; color:#166534;' : (($r['status'] === 'reported' || $r['status'] === 'auto_reported') ? 'background:#fee2e2; color:#991b1b;' : 'background:#fef3c7; color:#92400e;'); ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $r['status'])); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($r['posting_zone'] ?? 'N/A'); ?></td>
                                <td><?php echo number_format($r['count']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
var heatmapData = <?php echo json_encode($zoneHeatmapData); ?>;

function switchTab(tabId, btn) {
    document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById(tabId).classList.add('active');
    btn.classList.add('active');
}

function filterReportTable(tableId, query) {
    var table = document.getElementById(tableId);
    if (!table) return;
    var filter = query.toLowerCase().trim();
    var rows = table.querySelectorAll('tbody tr');

    rows.forEach(row => {
        var text = row.textContent || row.innerText;
        if (filter === '' || text.toLowerCase().indexOf(filter) > -1) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

function applyRetirementWatchlistFilters() {
    // Retirement-year filtering is now applied server-side (paginated), so this
    // only does a quick text search within the current page's rows.
    var searchInput = document.getElementById('retirementSearchInput');
    var searchQuery = searchInput ? searchInput.value.toLowerCase().trim() : '';
    var table = document.getElementById('retirementWatchlistTable');
    if (!table) return;
    var rows = table.querySelectorAll('tbody tr');
    var visibleCount = 0;

    rows.forEach(row => {
        var text = (row.textContent || row.innerText).toLowerCase();
        var matchesSearch = (searchQuery === '' || text.indexOf(searchQuery) > -1);

        if (matchesSearch) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });

    var badge = document.getElementById('retirementCountBadge');
    if (badge) {
        if (!badge.dataset.originalText) {
            badge.dataset.originalText = badge.textContent;
        }
        badge.textContent = searchQuery === '' ? badge.dataset.originalText : (visibleCount + ' matching this page');
    }
}

function buildRetirementYearUrl(year) {
    var params = new URLSearchParams(window.location.search);
    if (year) {
        params.set('ryear', year);
    } else {
        params.delete('ryear');
    }
    params.set('rpage', '1');
    params.set('tab', 'retirementWatchlistTab');
    return 'reports?' + params.toString();
}

function exportHeatmap() {
    var table = document.querySelector('.heatmap-table');
    if (!table) return;
    var csv = [];
    table.querySelectorAll('tr').forEach(row => {
        var cols = [];
        row.querySelectorAll('th, td').forEach(cell => {
            cols.push('"' + cell.textContent.trim().replace(/"/g, '""') + '"');
        });
        csv.push(cols.join(','));
    });
    var blob = new Blob([csv.join('\n')], { type: 'text/csv;charset=utf-8;' });
    var link = document.createElement('a');
    link.download = 'Formation_Rank_Heatmap_' + new Date().toISOString().slice(0,10) + '.csv';
    link.href = URL.createObjectURL(blob);
    link.click();
}

function exportTableToCSV(tableId, filename) {
    var table = document.getElementById(tableId);
    if (!table) return;
    
    var csv = [];
    var rows = table.querySelectorAll('tr');
    rows.forEach(row => {
        if (row.style.display !== 'none') {
            var cols = [];
            row.querySelectorAll('th, td').forEach(cell => {
                var text = cell.textContent.replace(/"/g, '""').trim();
                cols.push('"' + text + '"');
            });
            csv.push(cols.join(','));
        }
    });
    
    var blob = new Blob([csv.join('\n')], { type: 'text/csv;charset=utf-8;' });
    var link = document.createElement('a');
    link.download = filename + '_' + new Date().toISOString().slice(0,10) + '.csv';
    link.href = URL.createObjectURL(blob);
    link.click();
    URL.revokeObjectURL(link.href);
}

function printTable(tableId, reportTitle) {
    var table = document.getElementById(tableId);
    if (!table) return;

    var cloneTable = table.cloneNode(true);
    var rows = cloneTable.querySelectorAll('tbody tr');
    rows.forEach(row => {
        if (row.style.display === 'none') {
            row.remove();
        }
    });

    var printWin = window.open('', '_blank', 'width=900,height=650');
    printWin.document.write('<!DOCTYPE html><html><head><title>' + reportTitle + '</title><style>body{font-family:Segoe UI, sans-serif; padding:20px; color:#1e293b;} h2{font-size:16px; margin-bottom:4px; color:#1a5632;} p{font-size:12px; color:#64748b; margin-top:0; margin-bottom:15px;} table{width:100%; border-collapse:collapse; font-size:11px;} th, td{border:1px solid #cbd5e1; padding:6px 8px; text-align:left;} th{background:#f8fafc; font-weight:600;}</style></head><body><h2>' + reportTitle + ' - Nigeria Immigration Service</h2><p>Official System Report &bull; Date Printed: ' + new Date().toLocaleDateString('en-GB') + '</p>' + cloneTable.outerHTML + '<script>window.onload=function(){window.print();setTimeout(function(){window.close();},500);};<\/script></body></html>');
    printWin.document.close();
}

// Heatmap Generator Script (Captures ALL Formations & ALL Ranks ordered Highest to Lowest)
(function generateHeatmap() {
    var container = document.getElementById('heatmapContainer');
    if (!container) return;
    if (!heatmapData || heatmapData.length === 0) {
        container.innerHTML = '<div style="text-align:center; padding:2.5rem; color:#94a3b8;"><i class="fas fa-th" style="font-size:2rem; margin-bottom:0.5rem; display:block;"></i>No formation matrix data available.</div>';
        return;
    }
    
    var locations = [...new Set(heatmapData.map(d => d.presentPosting))];
    locations.sort(); // Sort all formations alphabetically
    
    function getRankPriority(rankName) {
        var r = (rankName || '').toUpperCase().trim();
        if (r.indexOf('COMPTROLLER GENERAL') !== -1 && r.indexOf('ASST') === -1 && r.indexOf('DEP') === -1) return 1;
        if (r.indexOf('DEP') !== -1 && r.indexOf('COMPTROLLER GENERAL') !== -1) return 2;
        if (r.indexOf('ASST') !== -1 && r.indexOf('COMPTROLLER GENERAL') !== -1) return 3;
        if (r.indexOf('COMPTROLLER') === 0 || r === 'COMPTROLLER') return 4;
        if (r.indexOf('DEPUTY COMPTROLLER') !== -1) return 5;
        if (r.indexOf('ASSISTANT COMPTROLLER') !== -1) return 6;
        if (r.indexOf('CHIEF SUPERINTENDENT') !== -1) return 7;
        if (r.indexOf('SUPERINTENDENT') !== -1 && r.indexOf('CHIEF') === -1 && r.indexOf('DEP') === -1 && r.indexOf('ASST') === -1) return 8;
        if (r.indexOf('DEPUTY SUPER') !== -1) return 9;
        if ((r.indexOf('ASST') !== -1 && r.indexOf('SUPERINTENDENT 1') !== -1) || r === 'ASI 1' || r === 'ASI-1') return 10;
        if ((r.indexOf('ASST') !== -1 && r.indexOf('SUPERINTENDENT 2') !== -1) || r.indexOf('ASI 2') !== -1 || r.indexOf('ASI-2') !== -1) return 11;
        if (r.indexOf('CHIEF INSPECTOR') !== -1) return 12;
        if (r.indexOf('INSPECTOR') !== -1 && r.indexOf('ASSISTANT') === -1 && r.indexOf('CHIEF') === -1) return 13;
        if (r.indexOf('ASSISTANT INSPECTOR') !== -1) return 14;
        if (r.indexOf('CHIEF IMMIGRATION') !== -1) return 15;
        if (r.indexOf('SENIOR IMMIGRATION') !== -1) return 16;
        if (r.indexOf('IMMIGRATION ASST. 1') !== -1 || r.indexOf('IA-1') !== -1 || r === 'IA1') return 17;
        if (r.indexOf('IMMIGRATION ASST. 2') !== -1 || r.indexOf('IA-2') !== -1 || r === 'IA2') return 18;
        if (r.indexOf('IMMIGRATION ASST. 3') !== -1 || r.indexOf('IA-3') !== -1 || r === 'IA3') return 19;
        return 20;
    }

    var ranks = [...new Set(heatmapData.map(d => d.currentRank))];
    ranks.sort((a, b) => getRankPriority(a) - getRankPriority(b));
    
    var matrix = {};
    locations.forEach(loc => { matrix[loc] = {}; ranks.forEach(rank => { matrix[loc][rank] = 0; }); });
    heatmapData.forEach(d => { if (matrix[d.presentPosting] && matrix[d.presentPosting][d.currentRank] !== undefined) { matrix[d.presentPosting][d.currentRank] = parseInt(d.count); } });
    
    var maxVal = 0;
    locations.forEach(loc => { ranks.forEach(rank => { if (matrix[loc][rank] > maxVal) maxVal = matrix[loc][rank]; }); });
    
    var columnTotals = {};
    ranks.forEach(rank => { columnTotals[rank] = 0; heatmapData.forEach(d => { if (d.currentRank === rank) columnTotals[rank] += parseInt(d.count); }); });
    
    var html = '<table class="heatmap-table"><thead><tr><th class="sticky-col" style="min-width:180px; text-align:left; background:#1a5632; color:#ffffff; white-space:nowrap;">Formation Location</th>';
    ranks.forEach(rank => { html += '<th title="'+rank+'" style="white-space:nowrap; padding:6px 10px;">'+rank+'</th>'; });
    html += '<th style="background:#1a5632; color:#ffffff; white-space:nowrap;">Total</th></tr></thead><tbody>';
    
    var grandTotal = 0;
    locations.forEach(loc => {
        html += '<tr><th class="sticky-col" style="text-align:left; background:#ffffff; font-weight:600; white-space:nowrap; padding:6px 10px;">'+loc+'</th>';
        var rowSum = 0;
        ranks.forEach(rank => {
            var val = matrix[loc][rank];
            rowSum += val;
            var cls = 'heat-low';
            if (maxVal > 0 && val > 0) {
                var ratio = val / maxVal;
                if (ratio >= 0.75) cls = 'heat-max';
                else if (ratio >= 0.5) cls = 'heat-very-high';
                else if (ratio >= 0.25) cls = 'heat-high';
                else cls = 'heat-med';
            }
            html += val > 0 ? '<td class="'+cls+'">'+val.toLocaleString()+'</td>' : '<td style="color:#cbd5e1;">-</td>';
        });
        grandTotal += rowSum;
        html += '<td style="font-weight:700; background:#f0fdf4; color:#1a5632;">'+rowSum.toLocaleString()+'</td></tr>';
    });
    
    html += '<tr style="background:#f8fafc; font-weight:700;"><th class="sticky-col" style="text-align:left; background:#1a5632; color:#ffffff; white-space:nowrap; padding:6px 10px;">Total Deployed</th>';
    ranks.forEach(rank => { html += '<td style="background:#e8f5e9; color:#1a5632;">'+columnTotals[rank].toLocaleString()+'</td>'; });
    html += '<td style="background:#1a5632; color:#ffffff; font-weight:700;">'+grandTotal.toLocaleString()+'</td></tr></tbody></table>';
    
    container.innerHTML = html;
})();
</script>

</div><!-- close main-content -->
</div><!-- close content-wrapper -->
</div><!-- close dashboard-container -->

<?php include 'includes/footer.php'; ?>

</body>
</html>