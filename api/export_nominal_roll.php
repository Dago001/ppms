<?php
ini_set('display_errors', 0);
session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';

if (!isLoggedIn()) {
    http_response_code(401);
    die("Unauthorized access");
}

$user = [];
try {
    $stmt = $pdo->prepare("SELECT u.*, r.name as role_name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
} catch (Exception $e) {
    $user = ['username' => $_SESSION['username'] ?? 'User', 'role_name' => 'User'];
}

$userRole = $user['role_name'] ?? '';
$userId = $_SESSION['user_id'] ?? 0;

$isAdmin = in_array(strtolower($userRole), ['admin', 'service hq', 'shq admin', 'super admin', 'user']);
$filterSQL = "";
$filterParams = [];

require_once __DIR__ . '/../includes/formation_helper.php';

if (!$isAdmin) {
    $nis_formations_filter = getNISFormations();
    $zoneFilter = buildUserFilter($pdo, $userId, $nis_formations_filter, 'e', 'presentPosting');
    $filterSQL = $zoneFilter['sql'];
    $filterParams = $zoneFilter['params'];
}

// FILTERS
$selectedZone   = trim($_GET['zone'] ?? '');
$selectedRank   = trim($_GET['rank'] ?? '');
$selectedGender = trim($_GET['gender'] ?? '');
$selectedStatus = trim($_GET['status'] ?? '');
$dateFrom       = trim($_GET['date_from'] ?? '');
$dateTo         = trim($_GET['date_to'] ?? '');
$searchQuery    = trim($_GET['q'] ?? '');
$action         = trim($_GET['action'] ?? 'csv');

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

$activeCondition = " AND (e.empStatus = 'Active' OR e.empStatus IS NULL OR e.empStatus != 'Retired')
                     AND (p.dob IS NULL OR p.dob = '0000-00-00' OR YEAR(p.dob) < 1940 OR DATE_ADD(p.dob, INTERVAL 60 YEAR) > NOW())
                     AND (e.dofa IS NULL OR e.dofa = '0000-00-00' OR YEAR(e.dofa) < 1960 OR DATE_ADD(e.dofa, INTERVAL 35 YEAR) > NOW())";

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
        p.surname ASC";

// Stream rows from MySQL instead of buffering the entire result set in PHP memory,
// which is what was exhausting memory on large (~29k row) exports.
set_time_limit(120);
$pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
$stmt = $pdo->prepare($sql);
$stmt->execute($dynamicParams);

if ($action === 'print') {
    // STREAM PRINTABLE HTML
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Comprehensive Officer Nominal Roll - Nigeria Immigration Service</title>
        <style>
            body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; padding: 20px; color: #1e293b; }
            h2 { font-size: 18px; margin-bottom: 4px; color: #1a5632; }
            p { font-size: 12px; color: #64748b; margin-top: 0; margin-bottom: 15px; }
            table { width: 100%; border-collapse: collapse; font-size: 11px; }
            th, td { border: 1px solid #cbd5e1; padding: 6px 8px; text-align: left; }
            th { background: #f8fafc; font-weight: 600; color: #475569; }
            tr:nth-child(even) { background: #f8fafc; }
            code { font-family: monospace; background: #f1f5f9; padding: 1px 4px; border-radius: 3px; }
        </style>
    </head>
    <body>
        <h2>Nigeria Immigration Service - Comprehensive Officer Nominal Roll</h2>
        <p>Official Database Export &bull; Date Printed: <?php echo date('d M Y, H:i'); ?></p>
        
        <table>
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
                <?php
                $sn = 1;
                while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $fullName = trim(($r['surname'] ?? '') . ' ' . ($r['firstName'] ?? '') . ' ' . ($r['middleName'] ?? ''));
                    $dofaStr = (!empty($r['dofa']) && $r['dofa'] !== '0000-00-00') ? date('d M Y', strtotime($r['dofa'])) : 'N/A';
                    echo "<tr>";
                    echo "<td>" . $sn++ . "</td>";
                    echo "<td><code>" . htmlspecialchars($r['serviceNo']) . "</code></td>";
                    echo "<td><strong>" . htmlspecialchars($fullName) . "</strong></td>";
                    echo "<td>" . htmlspecialchars($r['currentRank'] ?? 'N/A') . "</td>";
                    echo "<td>" . htmlspecialchars($r['presentPosting'] ?? 'N/A') . "</td>";
                    echo "<td>" . htmlspecialchars($r['gender'] ?? 'N/A') . "</td>";
                    echo "<td>" . $dofaStr . "</td>";
                    echo "<td>" . htmlspecialchars($r['empStatus'] ?? 'Active') . "</td>";
                    echo "</tr>";
                    if ($sn % 500 === 0) {
                        if (ob_get_level() > 0) { @ob_flush(); }
                        @flush();
                    }
                }
                if ($sn === 1) {
                    echo "<tr><td colspan='8' style='text-align:center; padding:20px; color:#94a3b8;'>No personnel records found.</td></tr>";
                }
                ?>
            </tbody>
        </table>
        <script>
            window.onload = function() {
                window.print();
            };
        </script>
    </body>
    </html>
    <?php
    exit();
} else {
    // STREAM DIRECT CSV DOWNLOAD
    $filename = "Comprehensive_Nominal_Roll_" . date('Y-m-d') . ".csv";
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['#', 'Service No', 'Surname', 'First Name', 'Middle Name', 'Rank', 'Present Location', 'Gender', 'Appointment Date (DOFA)', 'Status']);

    $sn = 1;
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $dofaStr = (!empty($r['dofa']) && $r['dofa'] !== '0000-00-00') ? $r['dofa'] : 'N/A';
        fputcsv($output, [
            $sn++,
            $r['serviceNo'] ?? '',
            $r['surname'] ?? '',
            $r['firstName'] ?? '',
            $r['middleName'] ?? '',
            $r['currentRank'] ?? 'N/A',
            $r['presentPosting'] ?? 'N/A',
            $r['gender'] ?? 'N/A',
            $dofaStr,
            $r['empStatus'] ?? 'Active'
        ]);
        if ($sn % 1000 === 0) {
            if (ob_get_level() > 0) { @ob_flush(); }
            @flush();
        }
    }
    fclose($output);
    exit();
}
