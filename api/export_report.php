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

require_once __DIR__ . '/../includes/settings_helper.php';
require_once __DIR__ . '/../includes/formation_helper.php';

if (!$isAdmin) {
    $nis_formations_filter = getNISFormations();
    $zoneFilter = buildUserFilter($pdo, $userId, $nis_formations_filter, 'e', 'presentPosting');
    $filterSQL = $zoneFilter['sql'];
    $filterParams = $zoneFilter['params'];
}

// FILTERS (same report filter panel used on reports.php)
$selectedZone   = trim($_GET['zone'] ?? '');
$selectedRank   = trim($_GET['rank'] ?? '');
$selectedGender = trim($_GET['gender'] ?? '');
$selectedStatus = trim($_GET['status'] ?? '');
$dateFrom       = trim($_GET['date_from'] ?? '');
$dateTo         = trim($_GET['date_to'] ?? '');
$searchQuery    = trim($_GET['q'] ?? '');
$action         = trim($_GET['action'] ?? 'csv');
$report         = trim($_GET['report'] ?? 'retirement');

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

if ($report === 'overdue') {
    $overstayThreshold = max(intval(getSystemSetting('overstay_years_threshold', '5')), 1);
    $reportTitle = "Officers Overdue For Posting Rotation (Staid > $overstayThreshold Years)";
    $csvHeaders = ['#', 'Service No', 'Officer Name', 'Rank', 'Present Location', 'Posting Start Date', 'Years at Station'];
    $sql = "
        SELECT
            p.serviceNo,
            p.surname,
            p.firstName,
            p.middleName,
            e.currentRank,
            e.presentPosting,
            e.dopa,
            e.dofa,
            CASE
                WHEN pn.latest_posting_date IS NOT NULL AND YEAR(pn.latest_posting_date) > 1970 THEN pn.latest_posting_date
                WHEN e.dopa IS NOT NULL AND e.dopa != '0000-00-00' AND YEAR(e.dopa) >= 1970 THEN e.dopa
                WHEN e.dofa IS NOT NULL AND e.dofa != '0000-00-00' AND YEAR(e.dofa) >= 1970 THEN e.dofa
                ELSE NULL
            END as clean_start_date,
            TIMESTAMPDIFF(YEAR,
                CASE
                    WHEN pn.latest_posting_date IS NOT NULL AND YEAR(pn.latest_posting_date) > 1970 THEN pn.latest_posting_date
                    WHEN e.dopa IS NOT NULL AND e.dopa != '0000-00-00' AND YEAR(e.dopa) >= 1970 THEN e.dopa
                    WHEN e.dofa IS NOT NULL AND e.dofa != '0000-00-00' AND YEAR(e.dofa) >= 1970 THEN e.dofa
                    ELSE NULL
                END,
                CURDATE()
            ) as years_in_formation
        FROM tbl_employment e
        INNER JOIN tbl_emppersonal p ON e.serviceNo = p.serviceNo
        LEFT JOIN (
            SELECT serviceNo, MAX(posting_date) as latest_posting_date
            FROM posting_notifications
            WHERE status IN ('admitted', 'reported') OR status IS NULL
            GROUP BY serviceNo
        ) pn ON e.serviceNo = pn.serviceNo
        WHERE e.presentPosting IS NOT NULL AND e.presentPosting != '' $filterSQL
        HAVING clean_start_date IS NOT NULL AND years_in_formation >= $overstayThreshold
        ORDER BY years_in_formation DESC, surname ASC";
    $queryParamsForStmt = $filterParams;
} else {
    $report = 'retirement';
    $selectedRetirementYear = trim($_GET['ryear'] ?? '');
    $reportTitle = "Statutory Retirement Watchlist & Retired Officers Ledger" . ($selectedRetirementYear !== '' ? " (Year $selectedRetirementYear)" : " (All Forecast & History)");
    $csvHeaders = ['#', 'Service No', 'Officer Name', 'Rank', 'Present Command', 'Date of Birth', 'DOFA', 'Retirement Date', 'Retirement Year', 'Retirement Status'];

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

    $queryParamsForStmt = $dynamicParams;
    $yearFilterSql = '';
    if ($selectedRetirementYear !== '' && ctype_digit($selectedRetirementYear)) {
        $yearFilterSql = " WHERE t.retirement_year = ?";
        $queryParamsForStmt[] = (int)$selectedRetirementYear;
    }
    $sql = "SELECT * FROM ($retirementBaseSql) t $yearFilterSql ORDER BY t.retirement_date ASC";
}

// Stream rows from MySQL instead of buffering the entire (potentially several
// thousand row) result set in PHP memory - the same fix already applied to the
// Nominal Roll export.
set_time_limit(120);
$pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
$stmt = $pdo->prepare($sql);
$stmt->execute($queryParamsForStmt);

if ($action === 'print') {
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title><?php echo htmlspecialchars($reportTitle); ?> - Nigeria Immigration Service</title>
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
        <h2>Nigeria Immigration Service - <?php echo htmlspecialchars($reportTitle); ?></h2>
        <p>Official Database Export &bull; Date Printed: <?php echo date('d M Y, H:i'); ?></p>

        <table>
            <thead>
                <tr><?php foreach ($csvHeaders as $h) echo "<th>" . htmlspecialchars($h) . "</th>"; ?></tr>
            </thead>
            <tbody>
                <?php
                $sn = 1;
                while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $fullName = trim(($r['surname'] ?? '') . ' ' . ($r['firstName'] ?? '') . ' ' . ($r['middleName'] ?? ''));
                    echo "<tr>";
                    echo "<td>" . $sn++ . "</td>";
                    echo "<td><code>" . htmlspecialchars($r['serviceNo']) . "</code></td>";
                    echo "<td><strong>" . htmlspecialchars($fullName) . "</strong></td>";
                    echo "<td>" . htmlspecialchars($r['currentRank'] ?? 'N/A') . "</td>";
                    echo "<td>" . htmlspecialchars($r['presentPosting'] ?? 'N/A') . "</td>";
                    if ($report === 'overdue') {
                        $startStr = (!empty($r['clean_start_date'])) ? date('d M Y', strtotime($r['clean_start_date'])) : 'N/A';
                        echo "<td>" . $startStr . "</td>";
                        echo "<td>" . intval($r['years_in_formation']) . " Years</td>";
                    } else {
                        $dobStr = (!empty($r['dob']) && $r['dob'] !== '0000-00-00') ? date('d M Y', strtotime($r['dob'])) : 'N/A';
                        $dofaStr = (!empty($r['dofa']) && $r['dofa'] !== '0000-00-00') ? date('d M Y', strtotime($r['dofa'])) : 'N/A';
                        $retDateStr = (!empty($r['retirement_date']) && $r['retirement_date'] !== '9999-12-31') ? date('d M Y', strtotime($r['retirement_date'])) : 'Statutory';
                        $isRetired = ($r['empStatus'] === 'Retired' || strtolower($r['empStatus'] ?? '') === 'retired' || (is_numeric($r['days_to_retirement']) && $r['days_to_retirement'] <= 0));
                        echo "<td>" . $dobStr . "</td>";
                        echo "<td>" . $dofaStr . "</td>";
                        echo "<td>" . $retDateStr . "</td>";
                        echo "<td>" . ($r['retirement_year'] > 1970 ? $r['retirement_year'] : 'N/A') . "</td>";
                        echo "<td>" . ($isRetired ? 'RETIRED' : ('Retiring in ' . number_format($r['days_to_retirement']) . ' days')) . "</td>";
                    }
                    echo "</tr>";
                    if ($sn % 500 === 0) {
                        if (ob_get_level() > 0) { @ob_flush(); }
                        @flush();
                    }
                }
                if ($sn === 1) {
                    echo "<tr><td colspan='" . count($csvHeaders) . "' style='text-align:center; padding:20px; color:#94a3b8;'>No records found.</td></tr>";
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
    $filenamePrefix = $report === 'overdue' ? 'Overdue_Rotation' : 'Statutory_Retirement_Watchlist';
    $filename = $filenamePrefix . "_" . date('Y-m-d') . ".csv";
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    fputcsv($output, $csvHeaders);

    $sn = 1;
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $fullName = trim(($r['surname'] ?? '') . ' ' . ($r['firstName'] ?? '') . ' ' . ($r['middleName'] ?? ''));
        if ($report === 'overdue') {
            $startStr = (!empty($r['clean_start_date'])) ? date('Y-m-d', strtotime($r['clean_start_date'])) : 'N/A';
            fputcsv($output, [
                $sn++,
                $r['serviceNo'] ?? '',
                $fullName,
                $r['currentRank'] ?? 'N/A',
                $r['presentPosting'] ?? 'N/A',
                $startStr,
                intval($r['years_in_formation'])
            ]);
        } else {
            $dobStr = (!empty($r['dob']) && $r['dob'] !== '0000-00-00') ? $r['dob'] : 'N/A';
            $dofaStr = (!empty($r['dofa']) && $r['dofa'] !== '0000-00-00') ? $r['dofa'] : 'N/A';
            $retDateStr = (!empty($r['retirement_date']) && $r['retirement_date'] !== '9999-12-31') ? $r['retirement_date'] : 'Statutory';
            $isRetired = ($r['empStatus'] === 'Retired' || strtolower($r['empStatus'] ?? '') === 'retired' || (is_numeric($r['days_to_retirement']) && $r['days_to_retirement'] <= 0));
            fputcsv($output, [
                $sn++,
                $r['serviceNo'] ?? '',
                $fullName,
                $r['currentRank'] ?? 'N/A',
                $r['presentPosting'] ?? 'N/A',
                $dobStr,
                $dofaStr,
                $retDateStr,
                $r['retirement_year'] > 1970 ? $r['retirement_year'] : 'N/A',
                $isRetired ? 'RETIRED' : ('Retiring in ' . intval($r['days_to_retirement']) . ' days')
            ]);
        }
        if ($sn % 1000 === 0) {
            if (ob_get_level() > 0) { @ob_flush(); }
            @flush();
        }
    }
    fclose($output);
    exit();
}
