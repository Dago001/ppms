<?php
// Start session and include config
session_start();
require_once 'includes/config.php';
require_once 'includes/formation_helper.php';
require_once 'includes/security.php';

$search_results = [];
$search_performed = false;
$all_personnel = [];
$error = '';
$posting_history = [];
$current_posting_data = null;

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
    $totalPersonnel = $pdo->query("SELECT COUNT(*) as count FROM tbl_emppersonal")->fetch()['count'] ?? 0;
    $totalUsers = $pdo->query("SELECT COUNT(*) as count FROM users")->fetch()['count'] ?? 0;
} catch (Exception $e) {
    error_log("Error getting user data: " . $e->getMessage());
}

if (empty($user)) {
    $user = ['username' => $_SESSION['username'] ?? 'User', 'full_name' => $_SESSION['username'] ?? 'User', 'role_name' => 'User'];
}
$generatedByName = !empty($user['full_name']) ? $user['full_name'] : ($user['username'] ?? 'System Administrator');

// ROLE-BASED FILTERING
$userRole = $_SESSION['role_name'] ?? $user['role_name'] ?? '';
$userIdForRole = $_SESSION['user_id'] ?? 0;
$userAssignedZoneNames = [];
$userFilterSQL = "";
$userFilterParams = [];

if (!in_array(strtolower($userRole), ['admin', 'service hq', 'shq admin', 'super admin', 'user'])) {
    $nis_formations_filter = getNISFormations();
    $zoneFilter = buildUserFilter($pdo, $userIdForRole, $nis_formations_filter, 'e', 'presentPosting');
    $userFilterSQL = $zoneFilter['sql'];
    $userFilterParams = $zoneFilter['params'];
    $userAssignedZoneNames = $zoneFilter['zone_names'];
}

$locationFilter = trim($_GET['location'] ?? '');

$pdo_idcard = $pdo;
try {
    $pdo_idcard->exec("CREATE TABLE IF NOT EXISTS `posting_history` (`id` INT(11) NOT NULL AUTO_INCREMENT, `serviceNo` VARCHAR(50) NOT NULL, `posting_location` TEXT NOT NULL, `posting_date` DATE NOT NULL, `posting_type` ENUM('initial','transfer','current') DEFAULT 'transfer', `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (`id`), INDEX `idx_serviceNo` (`serviceNo`), INDEX `idx_posting_date` (`posting_date`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (PDOException $e) { error_log("Posting table setup error: ".$e->getMessage()); }

if (!function_exists('sanitizeInput')) {
    function sanitizeInput($data) { if (is_null($data)) return ''; return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8'); }
}

$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 50;
$offset = ($page - 1) * $limit;
$totalRecords = 0;
$totalPages = 1;

// SINGLE OFFICER SEARCH
if (isset($_GET['serviceNo']) && !empty($_GET['serviceNo'])) {
    $serviceNo = sanitizeInput($_GET['serviceNo']);
    $search_performed = true;
    
    try {
        $searchSQL = "SELECT e.serviceNo, e.dofa, e.dopa, e.presentPosting, e.currentRank, e.empStatus, p.surname, p.firstName, p.middleName FROM tbl_employment e INNER JOIN tbl_emppersonal p ON e.serviceNo = p.serviceNo WHERE e.serviceNo = ?";
        
        if (!in_array(strtolower($userRole), ['admin', 'service hq', 'shq admin', 'super admin', 'user']) && !empty($userFilterSQL)) {
            $cleanFilter = preg_replace('/^\s*AND\s*/', '', $userFilterSQL);
            $searchSQL .= " AND (" . $cleanFilter . ")";
        }
        $searchSQL .= " LIMIT 1";
        
        $stmt = $pdo->prepare($searchSQL);
        $searchParams = [$serviceNo];
        if (!in_array(strtolower($userRole), ['admin', 'service hq', 'shq admin', 'super admin', 'user']) && !empty($userFilterParams)) {
            $searchParams = array_merge($searchParams, $userFilterParams);
        }
        $stmt->execute($searchParams);
        $personnel = $stmt->fetch();
        
        if ($personnel) {
            $search_results = ['personnel' => $personnel];
            
            if ($pdo_idcard) {
                try {
                    $cs = $pdo_idcard->prepare("SELECT posting_location, updated_at FROM current_posting WHERE serviceNo = ? LIMIT 1");
                    $cs->execute([$serviceNo]);
                    $current_posting_data = $cs->fetch();
                    
                    if (!$current_posting_data && !empty($personnel['presentPosting'])) {
                        $current_posting_data = ['posting_location' => $personnel['presentPosting'], 'updated_at' => null];
                    }
                    
                    $hs = $pdo_idcard->prepare("SELECT posting_location, posting_date, posting_type, created_at FROM posting_history WHERE serviceNo = ? ORDER BY posting_date DESC, created_at DESC LIMIT 50");
                    $hs->execute([$serviceNo]);
                    $posting_history = $hs->fetchAll();
                    
                    if (empty($posting_history) && $current_posting_data && !empty($current_posting_data['posting_location'])) {
                        $pdo_idcard->prepare("INSERT INTO posting_history (serviceNo, posting_location, posting_date, posting_type) VALUES (?, ?, CURDATE(), 'initial')")->execute([$serviceNo, $current_posting_data['posting_location']]);
                        $hs->execute([$serviceNo]);
                        $posting_history = $hs->fetchAll();
                    }
                } catch (PDOException $e) {
                    if (!empty($personnel['presentPosting'])) {
                        $current_posting_data = ['posting_location' => $personnel['presentPosting'], 'updated_at' => null];
                    }
                }
            } else {
                if (!empty($personnel['presentPosting'])) {
                    $current_posting_data = ['posting_location' => $personnel['presentPosting'], 'updated_at' => null];
                }
            }
        } else {
            if (!in_array(strtolower($userRole), ['admin', 'service hq', 'shq admin', 'super admin', 'user'])) {
                $checkStmt = $pdo->prepare("SELECT e.serviceNo FROM tbl_employment e WHERE e.serviceNo = ?");
                $checkStmt->execute([$serviceNo]);
                if ($checkStmt->fetch()) {
                    $error = "You don't have permission to view officer: <strong>" . htmlspecialchars($serviceNo) . "</strong>. This officer is not in your assigned formation(s).";
                }
            }
        }
    } catch (PDOException $e) {
        $error = "Error searching: " . $e->getMessage();
    }
}
// DISPLAY ALL RECORDS
elseif (!isset($_GET['serviceNo']) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $whereSQL = "";
        $whereParams = [];
        $conditions = [];
        
        if (!empty($userFilterSQL)) {
            $cleanFilter = preg_replace('/^\s*AND\s*/', '', $userFilterSQL);
            $conditions[] = "(" . $cleanFilter . ")";
            $whereParams = array_merge($whereParams, $userFilterParams);
        }
        if (!empty($locationFilter)) {
            $conditions[] = "(e.presentPosting LIKE ? OR e.presentPosting LIKE ?)";
            $whereParams[] = "%" . $locationFilter . "%";
            $whereParams[] = "%" . str_replace(['+', '%20'], ' ', $locationFilter) . "%";
        }
        if (!empty($conditions)) { $whereSQL = "WHERE " . implode(" AND ", $conditions); }
        
        $countStmt = $pdo->prepare("SELECT COUNT(*) as total FROM tbl_employment e INNER JOIN tbl_emppersonal p ON e.serviceNo = p.serviceNo $whereSQL");
        $countStmt->execute($whereParams);
        $totalRecords = $countStmt->fetch()['total'] ?? 0;
        $totalPages = max(1, ceil($totalRecords / $limit));
        
        $stmt = $pdo->prepare("SELECT e.serviceNo, e.dofa, e.dopa, e.presentPosting, e.currentRank, e.empStatus, p.surname, p.firstName, p.middleName FROM tbl_employment e INNER JOIN tbl_emppersonal p ON e.serviceNo = p.serviceNo $whereSQL ORDER BY e.serviceNo ASC LIMIT $limit OFFSET $offset");
        $stmt->execute($whereParams);
        $all_personnel = $stmt->fetchAll();
        
        $serviceNumbers = [];
        foreach ($all_personnel as $person) { if (isset($person['serviceNo'])) $serviceNumbers[] = $person['serviceNo']; }
        
        $current_locations = [];
        if ($pdo_idcard && !empty($serviceNumbers)) {
            try {
                $placeholders = str_repeat('?,', count($serviceNumbers) - 1) . '?';
                $cs = $pdo_idcard->prepare("SELECT serviceNo, posting_location FROM current_posting WHERE serviceNo IN ($placeholders)");
                $cs->execute($serviceNumbers);
                foreach ($cs->fetchAll() as $cp) { $current_locations[$cp['serviceNo']] = $cp['posting_location']; }
            } catch (PDOException $e) {}
        }
        foreach ($all_personnel as &$person) {
            $person['current_location'] = $current_locations[$person['serviceNo']] ?? $person['presentPosting'] ?? 'N/A';
        }
        unset($person);
    } catch (PDOException $e) { $error = "Error loading records: " . $e->getMessage(); }
}
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
        flex-wrap: wrap;
        gap: 0.75rem;
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

    .search-bar-wrapper {
        display: flex;
        align-items: center;
        gap: 0.4rem;
    }
    .search-input-field {
        padding: 0.45rem 0.75rem;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        font-size: 0.825rem;
        outline: none;
        width: 220px;
    }
    .search-input-field:focus { border-color: #1a5632; }
    
    .btn-search {
        padding: 0.45rem 0.85rem;
        background: #1a5632;
        color: #ffffff;
        border: none;
        border-radius: 6px;
        font-size: 0.8rem;
        font-weight: 600;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
    }
    .btn-search:hover { background: #154628; }

    .card {
        background: #ffffff;
        border-radius: 12px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        margin-bottom: 1.25rem;
        border: 1px solid #e2e8f0;
    }
    .card-header {
        padding: 0.75rem 1.25rem;
        font-weight: 500;
        font-size: 0.85rem;
        border-bottom: 1px solid #e2e8f0;
        color: #1a5632;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .card-body { padding: 1.25rem; }

    /* Officer Profile Card Layout */
    .profile-hero {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 1rem 1.25rem;
        margin-bottom: 1.25rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 1rem;
    }
    .officer-title-block h2 {
        font-size: 1.1rem;
        font-weight: 600;
        color: #1e293b;
        margin: 0 0 0.2rem 0;
    }
    .officer-subtitle {
        font-size: 0.8rem;
        color: #64748b;
    }

    .info-section {
        margin-bottom: 1.25rem;
    }
    .info-section-title {
        font-size: 0.85rem;
        font-weight: 600;
        color: #1a5632;
        margin-bottom: 0.75rem;
        display: flex;
        align-items: center;
        gap: 0.4rem;
        border-bottom: 1px solid #f1f5f9;
        padding-bottom: 0.35rem;
    }

    .info-grid-2 {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 0.85rem;
        font-size: 0.825rem;
    }
    .grid-cell { display: flex; flex-direction: column; }
    .cell-label { font-size: 0.7rem; color: #94a3b8; text-transform: uppercase; font-weight: 500; }
    .cell-value { font-weight: 500; color: #1e293b; margin-top: 0.1rem; }

    /* Data Table */
    .table-container { overflow-x: auto; -webkit-overflow-scrolling: touch; width: 100%; }
    .data-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.825rem;
    }
    .data-table th {
        background: #f8fafc;
        color: #475569;
        font-weight: 500;
        padding: 0.65rem 0.85rem;
        text-align: left;
        border-bottom: 1px solid #e2e8f0;
    }
    .data-table td {
        padding: 0.65rem 0.85rem;
        border-bottom: 1px solid #f1f5f9;
        vertical-align: middle;
    }
    .data-table tbody tr:hover { background: #f8fafc; }

    .status-pill {
        display: inline-block;
        padding: 0.15rem 0.5rem;
        border-radius: 12px;
        font-size: 0.7rem;
        font-weight: 700;
    }
    .status-pill.active { background: #dcfce7; color: #166534; }
    .status-pill.inactive { background: #fee2e2; color: #991b1b; }

    .badge-tag {
        display: inline-block;
        padding: 0.1rem 0.4rem;
        border-radius: 4px;
        font-size: 0.7rem;
        font-weight: 600;
    }
    .badge-initial { background: #dbeafe; color: #1e40af; }
    .badge-current { background: #dcfce7; color: #166534; }
    .badge-transfer { background: #fef3c7; color: #92400e; }
    .badge-redeployment { background: #f3e8ff; color: #6b21a8; border: 1px solid #d8b4fe; }

    .btn-action-sm {
        padding: 0.35rem 0.65rem;
        border-radius: 6px;
        font-size: 0.75rem;
        font-weight: 600;
        text-decoration: none;
        cursor: pointer;
        border: none;
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
    }
    .btn-view { background: #1a5632; color: #ffffff; }
    .btn-view:hover { background: #154628; }
    .btn-edit { background: #f59e0b; color: #ffffff; }
    .btn-edit:hover { background: #d97706; }
    .btn-back { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }
    .btn-back:hover { background: #e2e8f0; }

    .pagination-bar {
        display: flex;
        justify-content: center;
        gap: 0.35rem;
        margin-top: 1rem;
        padding-top: 1rem;
        border-top: 1px solid #e2e8f0;
    }
    .page-item {
        padding: 0.3rem 0.65rem;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        color: #475569;
        text-decoration: none;
        font-size: 0.775rem;
        font-weight: 600;
        background: #ffffff;
    }
    .page-item:hover, .page-item.active {
        background: #1a5632;
        color: #ffffff;
        border-color: #1a5632;
    }

    .alert {
        padding: 0.65rem 0.85rem;
        border-radius: 6px;
        margin-bottom: 1rem;
        font-size: 0.8rem;
        display: flex;
        align-items: center;
        gap: 0.4rem;
    }
    .alert-error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
    .alert-warn { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }
    .alert-info { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; }
</style>

<div class="page-title">
    <h1><i class="fas fa-search"></i> Personnel Search & Records</h1>
    
    <form class="search-bar-wrapper" action="search" method="GET">
        <input type="hidden" name="search_type" value="form">
        <?php if (!empty($locationFilter)): ?>
            <input type="hidden" name="location" value="<?php echo htmlspecialchars($locationFilter); ?>">
        <?php endif; ?>
        <input type="text" name="serviceNo" class="search-input-field" placeholder="Enter Service Number..." value="<?php echo isset($_GET['serviceNo']) ? htmlspecialchars($_GET['serviceNo']) : ''; ?>" required>
        <button type="submit" class="btn-search"><i class="fas fa-search"></i> Search</button>
    </form>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
<?php endif; ?>

<?php if (!empty($locationFilter)): ?>
    <div class="alert alert-info">
        <i class="fas fa-filter"></i> Filtering officers posted to: <strong><?php echo htmlspecialchars($locationFilter); ?></strong> (<?php echo $totalRecords; ?> record<?php echo $totalRecords != 1 ? 's' : ''; ?>)
        <a href="search" style="margin-left:auto; text-decoration:none; color:#1e40af; font-weight:600;"><i class="fas fa-times"></i> Clear Filter</a>
    </div>
<?php endif; ?>

<?php if ($search_performed && empty($search_results) && empty($error)): ?>
    <div class="alert alert-warn"><i class="fas fa-exclamation-triangle"></i> No officer records match Service Number: <strong><?php echo htmlspecialchars($_GET['serviceNo'] ?? ''); ?></strong></div>
<?php endif; ?>

<?php if (!empty($search_results)): 
    $p = $search_results['personnel'];
    $pEligibility = checkOfficerPostingEligibility($pdo, $p['serviceNo']);
    $isOfficerRetired = !empty($pEligibility['is_retired']);
?>
    <!-- Single Officer Profile Card -->
    <div class="card">
        <div class="card-header">
            <span><i class="fas fa-id-card"></i> Officer Service No - <?php echo htmlspecialchars($p['serviceNo']); ?></span>
            <?php if ($isOfficerRetired): ?>
                <span class="status-pill inactive" style="background:#fee2e2; color:#991b1b; border:1px solid #fca5a5;" title="<?php echo htmlspecialchars($pEligibility['retirement_reason']); ?>"><i class="fas fa-ban"></i> Inactive (Retired)</span>
            <?php else: ?>
                <span class="status-pill <?php echo strtolower($p['empStatus'] ?? '') === 'active' ? 'active' : 'inactive'; ?>">
                    <?php echo htmlspecialchars($p['empStatus'] ?? 'Active'); ?>
                </span>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <?php if ($isOfficerRetired): ?>
            <div class="alert alert-error" style="margin-bottom:1.25rem; background:#fef2f2; border:1px solid #fecaca; color:#991b1b; padding:0.85rem 1rem; border-radius:8px; display:flex; align-items:center; gap:0.65rem;">
                <i class="fas fa-ban" style="font-size:1.4rem;"></i>
                <div>
                    <strong style="font-size:0.875rem;">POSTING BLOCKED — STATUTORY RETIREMENT:</strong><br>
                    This officer has reached statutory retirement threshold (<strong><?php echo htmlspecialchars($pEligibility['retirement_reason']); ?></strong>) and is designated as <strong>Inactive</strong>. Under Nigeria Immigration Service Regulations, retired officers cannot be posted.
                </div>
            </div>
            <?php endif; ?>

            <div class="profile-hero">
                <div class="officer-title-block">
                    <h2><?php echo htmlspecialchars(($p['surname']??'').' '.($p['firstName']??'').' '.($p['middleName']??'')); ?></h2>
                    <span class="officer-subtitle">NIS Service No: <strong><?php echo htmlspecialchars($p['serviceNo']); ?></strong> &bull; Rank: <strong><?php echo htmlspecialchars($p['currentRank'] ?? 'N/A'); ?></strong></span>
                </div>
                <div>
                    <span style="font-size:0.75rem; color:#64748b; font-weight:600;">Current Posting:</span><br>
                    <strong style="color:#1a5632; font-size:0.95rem;"><?php echo htmlspecialchars($current_posting_data['posting_location'] ?? $p['presentPosting'] ?? 'Not Assigned'); ?></strong>
                </div>
            </div>

            <div class="info-section">
                <div class="info-section-title"><i class="fas fa-user"></i> Personal & Employment Details</div>
                <div class="info-grid-2">
                    <div class="grid-cell"><span class="cell-label">Service Number</span><span class="cell-value"><?php echo htmlspecialchars($p['serviceNo']); ?></span></div>
                    <div class="grid-cell"><span class="cell-label">Current Rank</span><span class="cell-value"><?php echo htmlspecialchars($p['currentRank'] ?? 'N/A'); ?></span></div>
                    <div class="grid-cell"><span class="cell-label">DOFA (Date of 1st Appt)</span><span class="cell-value"><?php echo htmlspecialchars($p['dofa'] ?? 'N/A'); ?></span></div>
                    <div class="grid-cell"><span class="cell-label">DOPA (Date of Present Appt)</span><span class="cell-value"><?php echo htmlspecialchars($p['dopa'] ?? 'N/A'); ?></span></div>
                    <div class="grid-cell"><span class="cell-label">Disciplinary Status</span><span class="cell-value"><?php echo getDisciplinaryBadgeHTML($p['disciplinary_status'] ?? 'Clean', $p['disciplinary_remarks'] ?? ''); ?></span></div>
                    <div class="grid-cell"><span class="cell-label">Promotion Eligibility</span><span class="cell-value"><span class="status-pill active" style="background:#e0f2fe; color:#0369a1; border:1px solid #bae6fd;"><?php echo htmlspecialchars($p['promotion_eligibility'] ?? 'Eligible'); ?></span></span></div>
                </div>
            </div>

            <?php if (!empty($posting_history)): ?>
            <div class="info-section" style="margin-bottom:0;">
                <div class="info-section-title"><i class="fas fa-history"></i> Deployment & Posting History</div>
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Posting Location</th>
                                <th>Posting Date</th>
                                <th>Type</th>
                                <th>Recorded Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($posting_history as $index => $history): 
                                $isCurrent = ($index === 0) && $current_posting_data && ($history['posting_location'] ?? '') === ($current_posting_data['posting_location'] ?? '');
                                $type = ($index === 0) ? strtolower($history['posting_type'] ?? 'transfer') : ($history['posting_type'] === 'current' ? 'transfer' : strtolower($history['posting_type'] ?? 'transfer'));
                                $badgeClass = $type === 'initial' ? 'badge-initial' : ($type === 'redeployment' ? 'badge-redeployment' : ($isCurrent ? 'badge-current' : 'badge-transfer'));
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($history['posting_location'] ?? ''); ?></strong>
                                    <?php if ($isCurrent): ?>
                                        <span class="badge-tag badge-current" style="margin-left:0.3rem;">Active Posting</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('d M Y', strtotime($history['posting_date'])); ?></td>
                                <td><span class="badge-tag <?php echo $badgeClass; ?>"><?php echo ucfirst($history['posting_type'] ?? 'Transfer'); ?></span></td>
                                <td><?php echo date('d M Y, H:i', strtotime($history['created_at'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <div class="btn-action-group" style="margin-top:1.25rem;">
                <a href="search<?php echo !empty($locationFilter) ? '?location='.urlencode($locationFilter) : ''; ?>" class="btn-action-sm btn-back"><i class="fas fa-arrow-left"></i> Back to Search</a>
                <?php if ($pEligibility['can_post']): ?>
                    <a href="editp?serviceNo=<?php echo urlencode($p['serviceNo']); ?>&token=<?php echo generateRecordToken($p['serviceNo']); ?>" class="btn-action-sm btn-edit"><i class="fas fa-edit"></i> Update Posting</a>
                <?php else: ?>
                    <button disabled class="btn-action-sm" style="background:#f1f5f9; color:#94a3b8; border:1px solid #cbd5e1; cursor:not-allowed;" title="<?php echo htmlspecialchars($isOfficerRetired ? 'Posting Blocked: Officer is Retired' : 'Posting Blocked: Disciplinary Action'); ?>">
                        <i class="fas fa-ban"></i> Posting Blocked (<?php echo $isOfficerRetired ? 'Retired' : 'Disciplinary'; ?>)
                    </button>
                <?php endif; ?>
                <button onclick="printRecord()" class="btn-action-sm btn-view"><i class="fas fa-print"></i> Print Record</button>
            </div>
        </div>
    </div>

    <!-- Hidden Print Container -->
    <div id="printableArea" style="display:none;">
        <style>
            .print-wrapper { position: relative; padding: 15px; font-family: 'Segoe UI', Arial, sans-serif; color: #0f172a; }
            .watermark-classified {
                position: fixed;
                top: 38%;
                left: 5%;
                width: 90%;
                text-align: center;
                font-size: 80px;
                font-weight: 900;
                color: rgba(220, 38, 38, 0.12);
                transform: rotate(-32deg);
                letter-spacing: 12px;
                text-transform: uppercase;
                pointer-events: none;
                z-index: 0;
                font-family: Arial, sans-serif;
            }
            .print-header-block { text-align: center; border-bottom: 2px solid #1a5632; padding-bottom: 12px; margin-bottom: 20px; position: relative; z-index: 1; }
            .print-logo-img { height: 75px; width: auto; margin-bottom: 8px; }
            .print-main-title { color: #1a5632; font-size: 20px; font-weight: 800; margin: 0; letter-spacing: 1px; }
            .print-sub-title { margin: 4px 0 8px 0; color: #334155; font-size: 14px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
            .print-meta-line { font-size: 11px; color: #64748b; margin: 0; }
            .print-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 13px; position: relative; z-index: 1; }
            .print-table td, .print-table th { padding: 8px 12px; border: 1px solid #cbd5e1; }
            .print-table th { background-color: #f8fafc; color: #1e293b; font-weight: 700; text-align: left; }
            .print-section-heading { color: #1a5632; font-size: 14px; font-weight: 700; margin-bottom: 8px; border-bottom: 1px solid #cbd5e1; padding-bottom: 4px; position: relative; z-index: 1; }
        </style>

        <div class="print-wrapper">
            <div class="watermark-classified">CLASSIFIED</div>

            <div class="print-header-block">
                <img src="assets/images/logo.png" alt="NIS Coat of Arms Crest" class="print-logo-img">
                <h2 class="print-main-title">NIGERIA IMMIGRATION SERVICE</h2>
                <h4 class="print-sub-title">Personnel Posting History/Report</h4>
                <p class="print-meta-line">
                    <strong>Generated on:</strong> <?php echo date('d M Y, h:i A'); ?> &nbsp;|&nbsp; 
                    <strong>Generated by:</strong> <?php echo htmlspecialchars($generatedByName); ?>
                </p>
            </div>

            <table class="print-table">
                <tr><td style="font-weight:bold; width:35%; background:#f8fafc;">Service Number</td><td><code><?php echo htmlspecialchars($p['serviceNo']); ?></code></td></tr>
                <tr><td style="font-weight:bold; background:#f8fafc;">Officer Name</td><td><strong><?php echo htmlspecialchars(($p['surname']??'').' '.($p['firstName']??'').' '.($p['middleName']??'')); ?></strong></td></tr>
                <tr><td style="font-weight:bold; background:#f8fafc;">Current Rank</td><td><?php echo htmlspecialchars($p['currentRank'] ?? 'N/A'); ?></td></tr>
                <tr><td style="font-weight:bold; background:#f8fafc;">Current Posting Location</td><td><strong style="color:#1a5632;"><?php echo htmlspecialchars($current_posting_data['posting_location'] ?? $p['presentPosting'] ?? 'N/A'); ?></strong></td></tr>
            </table>

            <?php if (!empty($posting_history)): ?>
                <h4 class="print-section-heading">Posting History</h4>
                <table class="print-table">
                    <thead>
                        <tr>
                            <th style="width:50%;">Posting Location</th>
                            <th style="width:25%;">Posting Date</th>
                            <th style="width:25%;">Posting Type</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($posting_history as $h): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($h['posting_location']); ?></td>
                                <td><?php echo date('d/m/Y', strtotime($h['posting_date'])); ?></td>
                                <td><?php echo ucfirst($h['posting_type']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

<?php elseif (!$search_performed && !empty($all_personnel)): ?>
    <!-- All Personnel Listing Table -->
    <div class="card">
        <div class="card-header">
            <span><i class="fas fa-users"></i> All Personnel Records</span>
            <span style="font-size:0.75rem; color:#64748b; font-weight:500;">Page <?php echo $page; ?> of <?php echo $totalPages; ?> (<?php echo $totalRecords; ?> Officers)</span>
        </div>
        <div class="card-body" style="padding:0;">
            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Service No</th>
                            <th>Surname</th>
                            <th>First Name</th>
                            <th>Rank</th>
                            <th>Current Posting Location</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($all_personnel as $person): 
                            $personEligibility = checkOfficerPostingEligibility($pdo, $person['serviceNo']);
                            $isPersonRetired = !empty($personEligibility['is_retired']);
                        ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($person['serviceNo'] ?? ''); ?></strong></td>
                            <td><?php echo htmlspecialchars($person['surname'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($person['firstName'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($person['currentRank'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($person['current_location'] ?? $person['presentPosting'] ?? 'N/A'); ?></td>
                            <td>
                                <?php if ($isPersonRetired): ?>
                                    <span class="status-pill inactive" style="background:#fee2e2; color:#991b1b; border:1px solid #fca5a5;" title="<?php echo htmlspecialchars($personEligibility['retirement_reason']); ?>">Inactive (Retired)</span>
                                <?php else: ?>
                                    <span class="status-pill <?php echo strtolower($person['empStatus']??'') === 'active' ? 'active' : 'inactive'; ?>">
                                        <?php echo htmlspecialchars($person['empStatus'] ?? 'Active'); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="display:flex; gap:0.35rem;">
                                    <a href="search?serviceNo=<?php echo urlencode($person['serviceNo'] ?? ''); ?><?php echo !empty($locationFilter) ? '&location='.urlencode($locationFilter) : ''; ?>" class="btn-action-sm btn-view"><i class="fas fa-eye"></i> View</a>
                                    <?php if ($personEligibility['can_post']): ?>
                                        <a href="editp?serviceNo=<?php echo urlencode($person['serviceNo'] ?? ''); ?>&token=<?php echo generateRecordToken($person['serviceNo'] ?? ''); ?>" class="btn-action-sm btn-edit"><i class="fas fa-edit"></i> Post</a>
                                    <?php else: ?>
                                        <button disabled class="btn-action-sm" style="background:#f1f5f9; color:#94a3b8; border:1px solid #cbd5e1; cursor:not-allowed;" title="<?php echo htmlspecialchars($isPersonRetired ? 'Posting Blocked: Officer is Retired' : 'Posting Blocked: Disciplinary Action'); ?>"><i class="fas fa-ban"></i> Blocked</button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPages > 1): ?>
                <div class="pagination-bar">
                    <?php if ($page > 1): ?>
                        <a href="search?page=1<?php echo !empty($locationFilter) ? '&location='.urlencode($locationFilter) : ''; ?>" class="page-item"><i class="fas fa-angle-double-left"></i></a>
                        <a href="search?page=<?php echo $page-1; ?><?php echo !empty($locationFilter) ? '&location='.urlencode($locationFilter) : ''; ?>" class="page-item"><i class="fas fa-angle-left"></i></a>
                    <?php endif; ?>
                    
                    <?php for ($i = max(1, $page-2); $i <= min($totalPages, $page+2); $i++): ?>
                        <a href="search?page=<?php echo $i; ?><?php echo !empty($locationFilter) ? '&location='.urlencode($locationFilter) : ''; ?>" class="page-item <?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                    <?php endfor; ?>
                    
                    <?php if ($page < $totalPages): ?>
                        <a href="search?page=<?php echo $page+1; ?><?php echo !empty($locationFilter) ? '&location='.urlencode($locationFilter) : ''; ?>" class="page-item"><i class="fas fa-angle-right"></i></a>
                        <a href="search?page=<?php echo $totalPages; ?><?php echo !empty($locationFilter) ? '&location='.urlencode($locationFilter) : ''; ?>" class="page-item"><i class="fas fa-angle-double-right"></i></a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <div class="card-body" style="text-align:center; padding:3rem 1rem; color:#64748b;">
            <i class="fas fa-users" style="font-size:1.75rem; color:#cbd5e1; margin-bottom:0.75rem;"></i>
            <h3 style="font-size:1rem; color:#1e293b;">No Personnel Records Found</h3>
        </div>
    </div>
<?php endif; ?>

<script>
function printRecord() {
    const area = document.getElementById('printableArea').innerHTML;
    const win = window.open('', '_blank', 'width=900,height=750');
    win.document.write('<!DOCTYPE html><html><head><title>Personnel Posting History/Report - ' + <?php echo json_encode($p['serviceNo'] ?? ''); ?> + '</title><style>@page{size:auto;margin:12mm;} body{margin:0;padding:0;-webkit-print-color-adjust:exact;print-color-adjust:exact;}</style></head><body>' + area + '<script>window.onload=function(){setTimeout(function(){window.print();window.close();},400);};<\/script></body></html>');
    win.document.close();
}
</script>

</div><!-- close main-content -->
</div><!-- close content-wrapper -->
</div><!-- close dashboard-container -->

<?php include 'includes/footer.php'; ?>

</body>
</html>