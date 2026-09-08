<?php
// recent.php
// NIS Personnel Posting Management System (NIS-PPMS)
// Recent Postings Tracking Engine with Role-Based Scoping & Live Search

session_start();
require_once 'includes/config.php';
require_once 'includes/auth.php';
require_once 'includes/permissions.php';
require_once 'includes/formation_helper.php';

if (!isLoggedIn()) {
    header('Location: login');
    exit();
}

// Fetch user details & role
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

$userRole = $user['role_name'] ?? '';
$userId = $_SESSION['user_id'] ?? 0;
$userZoneNames = [];
$filterSQL = "";
$filterParams = [];

// Determine Scoping: Global (Admin / Super Admin / Service HQ / SHQ Admin / User) vs Zone/Command specific
$isGlobalAdmin = in_array(strtolower($userRole), ['admin', 'service hq', 'shq admin', 'super admin', 'user']);

if (!$isGlobalAdmin) {
    // Get NIS formations for the user's assigned zones/commands
    $nis_formations_for_filter = [
        'SHQ - Service Headquarters' => [
            'NIS HQ Abuja' => 'Service Headquarters', 'CGIS OFFICE' => 'CGIS Office', 'VRD' => 'Visa and Residency Directorate',
            'POTD' => 'Passport and OTD Directorate', 'FAD' => 'Finance and Account Directorate', 'PRSD' => 'PRS Directorate',
            'ICD' => 'I & C Directorate', 'MD' => 'Migration Directorate', 'BMD' => 'Border Management Directorate',
            'HRMD' => 'Human Resource Management', 'WLD' => 'Works and Logistics Directorate', 'ICTD' => 'ICT and Cyber Security Directorate'
        ],
        'ZONE A' => ['Zone A HQ Ikeja' => 'Zone A Lagos', 'LASC' => 'Lagos State Command', 'OGSC' => 'Ogun State Command', 'LASPC' => 'Lagos Seaport & Marine Command', 'SEME' => 'Seme Border Command', 'IDBC' => 'Idiroko Border Command', 'MMIA' => 'MMIA', 'LABPC' => 'Lagos Border Patrol Command', 'LAPC' => 'Lagos Passport Command', 'Ikoyi PC' => 'Ikoyi Passport Command'],
        'ZONE B' => ['Zone B HQ Kaduna' => 'Zone B Kaduna', 'KNSC' => 'Kano State Command', 'KDSC' => 'Kaduna State Command', 'KTSC' => 'Katsina State Command', 'ZMSC' => 'Zamfara State Command', 'SOSC' => 'Sokoto State Command', 'JGSC' => 'Jigawa State Command', 'MAKIA' => 'MAKIA', 'ILBC' => 'Illela Border Command', 'JIBC' => 'Jibiya Border Command', 'ITSK' => 'ITSK', 'ICSC' => 'ICSC'],
        'ZONE C' => ['Zone C HQ Bauchi' => 'Zone C Bauchi', 'ADSC' => 'Adamawa State Command', 'BASC' => 'Bauchi State Command', 'BOSC' => 'Borno State Command', 'GMSC' => 'Gombe State Command', 'PLSC' => 'Plateau State Command', 'YBSC' => 'Yobe State Command'],
        'ZONE D' => ['Zone D HQ Minna' => 'Zone D Minna', 'FCTC' => 'FCT Command', 'NGSC' => 'Niger State Command', 'KBSC' => 'Kebbi State Command', 'RMAT' => 'Regional Migration Academy, Tuga', 'KWSC' => 'Kwara State Command'],
        'ZONE E' => ['Zone E HQ Owerri' => 'Zone E Owerri', 'Abia State Command' => 'Abia State Command', 'Imo State Command' => 'Imo State Command', 'Rivers State Command' => 'Rivers State Command', 'Cross River State Command' => 'Cross River State Command', 'Ebonyi State Command' => 'Ebonyi State Command', 'Akwa Ibom State Command' => 'Akwa Ibom State Command', 'Nigeria Immigration Training School Orlu' => 'Nigeria Immigration Training School Orlu', 'Rivers Marine Command Onne' => 'Rivers Marine Command Onne', 'Mfum Border Command' => 'Mfum Border Command', 'Nigeria Immigration Training School Ahoada' => 'Nigeria Immigration Training School Ahoada'],
        'ZONE F' => ['Zone F HQ Ibadan' => 'Zone F Ibadan', 'Oyo State Command' => 'Oyo State Command', 'Ekiti State Command' => 'Ekiti State Command', 'Ondo State Command' => 'Ondo State Command', 'Osun State Command' => 'Osun State Command'],
        'ZONE G' => ['Zone G HQ Benin' => 'Zone G Benin City', 'Edo State Command' => 'Edo State Command', 'Anambra State Command' => 'Anambra State Command', 'Delta State Command' => 'Delta State Command', 'Enugu State Command' => 'Enugu State Command', 'Bayelsa State Command' => 'Bayelsa State Command'],
        'ZONE H' => ['Zone H HQ Makurdi' => 'Zone H Makurdi', 'Nasarawa State Command' => 'Nasarawa State Command', 'Benue State Command' => 'Benue State Command', 'Kogi State Command' => 'Kogi State Command', 'Taraba State Command' => 'Taraba State Command']
    ];
    
    $zoneFilter = buildUserFilter($pdo, $userId, $nis_formations_for_filter, 'ph', 'posting_location');
    $filterSQL = $zoneFilter['sql'];
    $filterParams = $zoneFilter['params'];
    $userZoneNames = $zoneFilter['zone_names'];
    
    if (empty($userZoneNames)) {
        try {
            $stmt = $pdo->prepare("SELECT z.zone_name FROM user_zones uz JOIN zones z ON uz.zone_id = z.id WHERE uz.user_id = ?");
            $stmt->execute([$userId]);
            $userZoneNames = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $ex) {}
    }
}

// Search Query Filter
$searchQuery = trim($_GET['search'] ?? '');
$searchSQL = "";
$searchParams = [];

if (!empty($searchQuery)) {
    $searchSQL = " AND (ph.serviceNo LIKE ? OR ep.surname LIKE ? OR ep.firstName LIKE ? OR ep.middleName LIKE ? OR ph.posting_location LIKE ?)";
    $term = "%" . $searchQuery . "%";
    $searchParams = [$term, $term, $term, $term, $term];
}

// Combine Query Parameters safely
$whereClause = "WHERE 1=1";
$queryParams = [];

if (!$isGlobalAdmin && !empty($filterSQL)) {
    $whereClause .= " " . $filterSQL;
    $queryParams = array_merge($queryParams, $filterParams);
}
if (!empty($searchSQL)) {
    $whereClause .= $searchSQL;
    $queryParams = array_merge($queryParams, $searchParams);
}

// Pagination setup
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 25;
$offset = ($page - 1) * $limit;

// Fetch Total Count
$totalRecords = 0;
try {
    $countSql = "
        SELECT COUNT(*) 
        FROM posting_history ph 
        LEFT JOIN tbl_emppersonal ep ON ph.serviceNo = ep.serviceNo 
        LEFT JOIN tbl_employment te ON ph.serviceNo = te.serviceNo 
        $whereClause
    ";
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($queryParams);
    $totalRecords = (int)$stmt->fetchColumn();
} catch (Exception $e) {
    $totalRecords = 0;
}

// Fetch Recent Postings List
$recentPostingsList = [];
try {
    $sql = "
        SELECT 
            ph.*, 
            CONCAT(COALESCE(ep.surname, ''), ' ', COALESCE(ep.firstName, ''), ' ', COALESCE(ep.middleName, '')) as officer_name,
            COALESCE(te.currentRank, 'N/A') as officer_rank,
            COALESCE(te.empStatus, 'Active') as emp_status,
            COALESCE(te.presentPosting, ph.posting_location) as current_present_posting,
            u.full_name as creator_user_full_name,
            u.username as creator_user_username
        FROM posting_history ph 
        LEFT JOIN tbl_emppersonal ep ON ph.serviceNo = ep.serviceNo 
        LEFT JOIN tbl_employment te ON ph.serviceNo = te.serviceNo 
        LEFT JOIN users u ON ph.created_by = u.id
        $whereClause
        ORDER BY ph.posting_date DESC, ph.id DESC
        LIMIT $limit OFFSET $offset
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($queryParams);
    $recentPostingsList = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $recentPostingsList = [];
}

$fetchedCount = count($recentPostingsList);
if ($totalRecords < ($offset + $fetchedCount)) {
    $totalRecords = $offset + $fetchedCount;
}

$fromRecord = ($totalRecords > 0) ? ($offset + 1) : 0;
$toRecord = min($offset + $fetchedCount, $totalRecords);
$totalPages = max(1, ceil($totalRecords / $limit));
?>

<?php include 'includes/header.php'; ?>

<style>
    /* Executive Senior Developer UI for NIS-PPMS Recent Postings */
    .page-title {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 0.85rem 1.25rem;
        margin-bottom: 1.25rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        box-shadow: 0 1px 3px rgba(0,0,0,0.03);
    }
    .page-title h1 {
        font-size: 1.15rem;
        font-weight: 700;
        color: #1e293b;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 0.6rem;
    }
    .page-title h1 i { color: #1a5632; }

    /* Filter & Search Bar */
    .toolbar-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 0.75rem 1rem;
        margin-bottom: 1.25rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 0.75rem;
        box-shadow: 0 1px 3px rgba(0,0,0,0.03);
    }

    .search-box-form {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        flex-wrap: wrap;
        width: 100%;
        max-width: 540px;
    }

    .search-input-group {
        position: relative;
        flex: 1;
        display: flex;
        align-items: center;
    }
    .search-input-group i {
        position: absolute;
        left: 0.75rem;
        color: #94a3b8;
        font-size: 0.85rem;
    }
    .search-control {
        width: 100%;
        padding: 0.45rem 0.85rem 0.45rem 2.2rem;
        font-size: 0.825rem;
        border-radius: 8px;
        border: 1px solid #cbd5e1;
        outline: none;
        transition: all 0.15s ease;
    }
    .search-control:focus {
        border-color: #1a5632;
        box-shadow: 0 0 0 3px rgba(26, 86, 50, 0.12);
    }

    .btn-submit-search {
        padding: 0.45rem 1rem;
        font-size: 0.8rem;
        font-weight: 600;
        background: #1a5632;
        color: #ffffff;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        transition: background 0.15s;
    }
    .btn-submit-search:hover { background: #154628; }
    .btn-reset-search {
        padding: 0.45rem 0.85rem;
        font-size: 0.8rem;
        font-weight: 600;
        background: #f1f5f9;
        color: #475569;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
    }
    .btn-reset-search:hover { background: #e2e8f0; color: #0f172a; }

    /* Table Container */
    .table-card {
        background: #ffffff;
        border-radius: 12px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 1px 3px rgba(0,0,0,0.03);
        overflow: hidden;
    }
    .table-card-header {
        padding: 0.85rem 1.25rem;
        background: #fafafa;
        border-bottom: 1px solid #e2e8f0;
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-weight: 700;
        font-size: 0.85rem;
        color: #1a5632;
    }
    .table-responsive { width: 100%; overflow-x: auto; }
    .custom-table {
        width: 100%;
        border-collapse: collapse;
        text-align: left;
        font-size: 0.825rem;
    }
    .custom-table th {
        background: #f8fafc;
        padding: 0.75rem 1rem;
        font-weight: 700;
        color: #475569;
        border-bottom: 1px solid #e2e8f0;
        white-space: nowrap;
        text-transform: uppercase;
        font-size: 0.7rem;
        letter-spacing: 0.03em;
    }
    .custom-table td {
        padding: 0.85rem 1rem;
        border-bottom: 1px solid #f1f5f9;
        color: #1e293b;
        vertical-align: middle;
    }
    .custom-table tbody tr:hover { background: #f8fafc; }

    .service-no-pill {
        font-family: monospace;
        font-weight: 700;
        background: #f1f5f9;
        color: #1e293b;
        padding: 0.15rem 0.5rem;
        border-radius: 6px;
        border: 1px solid #e2e8f0;
        display: inline-block;
    }

    .location-badge {
        display: inline-block;
        background: #e6f4ea;
        color: #1a5632;
        padding: 0.2rem 0.55rem;
        border-radius: 6px;
        font-size: 0.75rem;
        font-weight: 600;
        border: 1px solid #c6e7ce;
    }

    .btn-view-officer {
        padding: 0.35rem 0.75rem;
        font-size: 0.75rem;
        font-weight: 600;
        background: #ffffff;
        color: #1a5632;
        border: 1px solid #1a5632;
        border-radius: 6px;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        transition: all 0.15s ease;
    }
    .btn-view-officer:hover {
        background: #1a5632;
        color: #ffffff;
        box-shadow: 0 2px 4px rgba(26, 86, 50, 0.2);
    }

    .badge-scope {
        display: inline-block;
        background: #1a5632;
        color: #ffffff;
        padding: 0.25rem 0.65rem;
        border-radius: 12px;
        font-size: 0.725rem;
        font-weight: 700;
    }
    .badge-global {
        display: inline-block;
        background: #1a5632;
        color: #ffffff;
        padding: 0.25rem 0.65rem;
        border-radius: 12px;
        font-size: 0.725rem;
        font-weight: 700;
    }

    .pagination-bar {
        padding: 0.85rem 1.25rem;
        background: #ffffff;
        border-top: 1px solid #e2e8f0;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 0.5rem;
        font-size: 0.8rem;
        color: #64748b;
    }
    .pagination-links { display: flex; gap: 0.3rem; }
    .page-link-btn {
        padding: 0.35rem 0.7rem;
        border: 1px solid #cbd5e1;
        background: #ffffff;
        color: #475569;
        border-radius: 6px;
        text-decoration: none;
        font-weight: 600;
        font-size: 0.75rem;
    }
    .page-link-btn:hover { background: #f1f5f9; color: #0f172a; }
    .page-link-btn.active { background: #1a5632; color: #ffffff; border-color: #1a5632; }

    .empty-state { text-align: center; padding: 3rem 1rem; color: #64748b; }
    .empty-state i { font-size: 2.8rem; color: #cbd5e1; margin-bottom: 0.75rem; display: block; }
</style>

<div class="page-title">
    <h1><i class="fas fa-history"></i> Recent Postings History</h1>
    <!-- <div>
        <?php if ($isGlobalAdmin): ?>
            <span class="badge-global"><i class="fas fa-globe"></i> Scope: Global Nationwide</span>
        <?php else: ?>
            <span class="badge-scope"><i class="fas fa-building"></i> Scope: Zonal / Command Restricted</span>
        <?php endif; ?>
    </div> -->
</div>

<!-- Search & Filter Toolbar -->
<div class="toolbar-card">
    <form method="GET" action="recent" class="search-box-form">
        <div class="search-input-group">
            <i class="fas fa-search"></i>
            <input type="text" 
                   name="search" 
                   id="recentSearchInput"
                   class="search-control" 
                   placeholder="Search by Service No or Officer Name..." 
                   value="<?php echo htmlspecialchars($searchQuery); ?>"
                   onkeyup="filterClientTable()">
        </div>
        <button type="submit" class="btn-submit-search"><i class="fas fa-filter"></i> Search</button>
        <?php if (!empty($searchQuery)): ?>
            <a href="recent" class="btn-reset-search"><i class="fas fa-times"></i> Clear</a>
        <?php endif; ?>
    </form>
    <div style="font-size:0.775rem; color:#64748b; font-weight:600;">
        Max <?php echo $limit; ?> records per page
    </div>
</div>

<!-- Main Postings Table Card -->
<div class="table-card">
    <div class="table-card-header">
        <span><i class="fas fa-table"></i> Officer Posting Stream</span>
        <span>Showing <?php echo $fromRecord; ?> &ndash; <?php echo $toRecord; ?> of <?php echo number_format($totalRecords); ?> entries</span>
    </div>
    <div class="table-responsive">
        <?php if (empty($recentPostingsList)): ?>
            <div class="empty-state">
                <i class="fas fa-folder-open"></i>
                <h3 style="font-size:1.05rem; font-weight:700; color:#1e293b; margin-bottom:0.25rem;">No Recent Postings Found</h3>
                <p style="font-size:0.8rem;">No posting records matched your current query or assigned formation scope.</p>
            </div>
        <?php else: ?>
            <table class="custom-table" id="recentPostingsTable">
                <thead>
                    <tr>
                        <th>NIS Number</th>
                        <th>Officer Name</th>
                        <th>Rank</th>
                        <th>Posting Location</th>
                        <th>Posting Date</th>
                        <th>Posted By</th>
                        <th>Status</th>
                        <th style="text-align:right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentPostingsList as $row): 
                        $officerName = !empty(trim($row['officer_name'])) ? $row['officer_name'] : 'Officer (NIS ' . $row['serviceNo'] . ')';
                        $postingDate = !empty($row['posting_date']) ? date('d M Y', strtotime($row['posting_date'])) : 'N/A';
                        
                        $postedBy = 'System Administrator';
                        if (!empty(trim($row['posted_by'] ?? '')) && $row['posted_by'] !== 'Service HQ') {
                            $postedBy = trim($row['posted_by']);
                        } elseif (!empty(trim($row['creator_user_full_name'] ?? ''))) {
                            $postedBy = trim($row['creator_user_full_name']);
                        } elseif (!empty(trim($row['creator_user_username'] ?? ''))) {
                            $postedBy = trim($row['creator_user_username']);
                        }
                        
                        $rawType = strtolower(trim($row['posting_type'] ?? ''));
                        if (in_array($rawType, ['initial', 'first posting', 'first_posting', 'first'])) {
                            $statusText = 'Initial Deployment';
                            $statusStyle = 'background:#e0e7ff; color:#3730a3; border:1px solid #c7d2fe;';
                        } elseif (in_array($rawType, ['redeployment', 're-deployment'])) {
                            $statusText = 'Redeployment';
                            $statusStyle = 'background:#fef3c7; color:#92400e; border:1px solid #fde68a;';
                        } else {
                            $statusText = 'Transfer';
                            $statusStyle = 'background:#d1fae5; color:#065f46; border:1px solid #a7f3d0;';
                        }
                    ?>
                    <tr class="posting-row" data-search="<?php echo htmlspecialchars(strtolower($row['serviceNo'] . ' ' . $officerName . ' ' . $row['posting_location'])); ?>">
                        <td><span class="service-no-pill"><?php echo htmlspecialchars($row['serviceNo']); ?></span></td>
                        <td><strong><?php echo htmlspecialchars($officerName); ?></strong></td>
                        <td><?php echo htmlspecialchars($row['officer_rank']); ?></td>
                        <td><span class="location-badge"><i class="fas fa-location-dot"></i> <?php echo htmlspecialchars($row['posting_location']); ?></span></td>
                        <td><i class="far fa-calendar-alt" style="color:#94a3b8; margin-right:0.25rem;"></i> <?php echo $postingDate; ?></td>
                        <td><span style="font-size:0.775rem; color:#475569; font-weight:600;"><?php echo htmlspecialchars($postedBy); ?></span></td>
                        <td><span class="status-badge" style="<?php echo $statusStyle; ?> font-size:0.7rem; font-weight:700; padding:0.2rem 0.55rem; border-radius:6px;"><?php echo htmlspecialchars($statusText); ?></span></td>
                        <td style="text-align:right;">
                            <a href="search?serviceNo=<?php echo urlencode($row['serviceNo']); ?>" class="btn-view-officer" title="View Officer's Comprehensive Details">
                                <i class="fas fa-eye"></i> View Profile
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- Pagination Footer -->
    <?php if ($totalPages > 1 || $page > 1 || $fetchedCount >= $limit): ?>
    <div class="pagination-bar">
        <div>
            Page <strong><?php echo $page; ?></strong> of <strong><?php echo $totalPages; ?></strong>
        </div>
        <div class="pagination-links">
            <?php if ($page > 1): ?>
                <a href="recent?page=<?php echo ($page - 1); ?>&search=<?php echo urlencode($searchQuery); ?>" class="page-link-btn">&laquo; Prev</a>
            <?php endif; ?>
            
            <?php
            $startPage = max(1, $page - 2);
            $endPage = min($totalPages, $page + 2);
            for ($p = $startPage; $p <= $endPage; $p++):
            ?>
                <a href="recent?page=<?php echo $p; ?>&search=<?php echo urlencode($searchQuery); ?>" class="page-link-btn <?php echo $p == $page ? 'active' : ''; ?>"><?php echo $p; ?></a>
            <?php endfor; ?>

            <?php if ($page < $totalPages || $fetchedCount >= $limit): ?>
                <a href="recent?page=<?php echo ($page + 1); ?>&search=<?php echo urlencode($searchQuery); ?>" class="page-link-btn">Next &raquo;</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

</div><!-- close main-content -->
</div><!-- close content-wrapper -->
</div><!-- close dashboard-container -->

<script>
function filterClientTable() {
    const q = document.getElementById('recentSearchInput').value.toLowerCase().trim();
    const rows = document.querySelectorAll('.posting-row');
    rows.forEach(row => {
        const text = row.getAttribute('data-search') || '';
        row.style.display = (q === '' || text.includes(q)) ? '' : 'none';
    });
}
</script>

<?php include 'includes/footer.php'; ?>

</body>
</html>
