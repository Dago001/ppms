<?php
// disciplinary.php
// Dedicated Disciplinary Board & Sensitive Posting Control Module for NIS-PPMS

session_start();
require_once 'includes/config.php';
require_once 'includes/auth.php';
require_once 'includes/security.php';
require_once 'includes/permissions.php';

if (!isLoggedIn()) {
    header('Location: login');
    exit();
}

$canManageDisciplinary = hasPermission('manage_disciplinary');

if (!$canManageDisciplinary && ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['action']))) {
    header("HTTP/1.1 403 Forbidden");
    echo "<div style='font-family:sans-serif; text-align:center; padding:3rem;'>
            <h1 style='color:#dc2626;'>403 Forbidden - Access Denied</h1>
            <p>You do not have permission ('manage_disciplinary') to manage disciplinary board records.</p>
            <a href='dashboard' style='display:inline-block; margin-top:1rem; padding:0.5rem 1rem; background:#1a5632; color:#fff; text-decoration:none; border-radius:6px;'>Return to Dashboard</a>
          </div>";
    exit();
}

// Auto-migration check for disciplinary_history audit table
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `disciplinary_history` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `serviceNo` VARCHAR(50) NOT NULL,
            `officer_name` VARCHAR(255) DEFAULT NULL,
            `action_type` ENUM('Blacklisted / Updated', 'Cleared / Removed') NOT NULL,
            `previous_status` VARCHAR(100) DEFAULT NULL,
            `new_status` VARCHAR(100) NOT NULL,
            `reason_remarks` TEXT DEFAULT NULL,
            `action_by_user_id` INT(11) NOT NULL,
            `action_by_username` VARCHAR(100) NOT NULL,
            `action_by_full_name` VARCHAR(150) NOT NULL,
            `action_by_role` VARCHAR(100) NOT NULL,
            `ip_address` VARCHAR(50) DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_serviceNo` (`serviceNo`),
            INDEX `idx_action_type` (`action_type`),
            INDEX `idx_created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
} catch (Exception $e) {}

// Fetch User Info
$user = [];
try {
    if (isset($_SESSION['user_id'])) {
        $stmt = $pdo->prepare("SELECT u.*, r.name as role_name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
    }
} catch (Exception $e) {}

if (empty($user)) {
    $user = ['username' => $_SESSION['username'] ?? 'User', 'full_name' => $_SESSION['username'] ?? 'User', 'role_name' => $currentUserRole];
}

$error = '';
$success = '';
$csrfToken = generateCSRFToken();

// Handle Disciplinary Add / Update / Clear Actions
if ($canManageDisciplinary && $_SERVER['REQUEST_METHOD'] === 'POST' && !validateCSRFToken($_POST['csrf_token'] ?? '')) {
    $error = "Security validation failed. Please refresh the page and try again.";
} elseif ($canManageDisciplinary && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = sanitizeInput($_POST['action'] ?? '');
    $serviceNo = sanitizeInput($_POST['serviceNo'] ?? '');
    
    if (empty($serviceNo)) {
        $error = "Officer Service Number is required.";
    } else {
        try {
            // Verify Officer Exists
            $checkStmt = $pdo->prepare("SELECT ep.surname, ep.firstName, te.disciplinary_status FROM tbl_emppersonal ep LEFT JOIN tbl_employment te ON ep.serviceNo = te.serviceNo WHERE ep.serviceNo = ?");
            $checkStmt->execute([$serviceNo]);
            $officer = $checkStmt->fetch();
            
            if (!$officer) {
                $error = "Officer record with Service Number '$serviceNo' was not found.";
            } else {
                $officerName = trim(($officer['surname'] ?? '') . ' ' . ($officer['firstName'] ?? ''));
                $prevStatus = !empty($officer['disciplinary_status']) ? $officer['disciplinary_status'] : 'Clean';
                
                if ($action === 'add_disciplinary' || $action === 'update_disciplinary') {
                    $status = sanitizeInput($_POST['disciplinary_status'] ?? 'Under Query');
                    $remarks = sanitizeInput($_POST['disciplinary_remarks'] ?? '');
                    
                    if ($status === 'Clean') {
                        $status = 'Under Query'; // Force active disciplinary status when added
                    }
                    
                    $updateStmt = $pdo->prepare("UPDATE tbl_employment SET disciplinary_status = ?, disciplinary_remarks = ? WHERE serviceNo = ?");
                    $updateStmt->execute([$status, $remarks, $serviceNo]);
                    
                    // Insert audit record into disciplinary_history
                    $auditStmt = $pdo->prepare("
                        INSERT INTO disciplinary_history 
                        (serviceNo, officer_name, action_type, previous_status, new_status, reason_remarks, action_by_user_id, action_by_username, action_by_full_name, action_by_role, ip_address)
                        VALUES (?, ?, 'Blacklisted / Updated', ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $auditStmt->execute([
                        $serviceNo,
                        $officerName,
                        $prevStatus,
                        $status,
                        $remarks,
                        $_SESSION['user_id'] ?? 0,
                        $user['username'] ?? ($_SESSION['username'] ?? 'Admin'),
                        $user['full_name'] ?? ($user['username'] ?? 'Admin'),
                        $user['role_name'] ?? ($currentUserRole ?? 'Admin'),
                        $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
                    ]);

                    if (function_exists('logActivity')) {
                        logActivity($pdo, $_SESSION['user_id'] ?? 0, 'Disciplinary Board', "Blacklisted/Updated Disciplinary Status for Officer $officerName ($serviceNo) from '$prevStatus' to '$status'. Remarks: $remarks");
                    }
                    
                    $success = "Officer <strong>$officerName ($serviceNo)</strong> has been placed under <strong>$status</strong>. All posting operations for this officer are now strictly BLOCKED and recorded in Disciplinary Audit History.";
                } elseif ($action === 'clear_disciplinary') {
                    $clearanceReason = sanitizeInput($_POST['clearance_reason'] ?? '');
                    if (empty($clearanceReason)) {
                        $clearanceReason = "Disciplinary clearance approved by Board authority.";
                    }

                    $updateStmt = $pdo->prepare("UPDATE tbl_employment SET disciplinary_status = 'Clean', disciplinary_remarks = NULL WHERE serviceNo = ?");
                    $updateStmt->execute([$serviceNo]);
                    
                    // Insert audit record into disciplinary_history
                    $auditStmt = $pdo->prepare("
                        INSERT INTO disciplinary_history 
                        (serviceNo, officer_name, action_type, previous_status, new_status, reason_remarks, action_by_user_id, action_by_username, action_by_full_name, action_by_role, ip_address)
                        VALUES (?, ?, 'Cleared / Removed', ?, 'Clean', ?, ?, ?, ?, ?, ?)
                    ");
                    $auditStmt->execute([
                        $serviceNo,
                        $officerName,
                        $prevStatus,
                        $clearanceReason,
                        $_SESSION['user_id'] ?? 0,
                        $user['username'] ?? ($_SESSION['username'] ?? 'Admin'),
                        $user['full_name'] ?? ($user['username'] ?? 'Admin'),
                        $user['role_name'] ?? ($currentUserRole ?? 'Admin'),
                        $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
                    ]);

                    if (function_exists('logActivity')) {
                        logActivity($pdo, $_SESSION['user_id'] ?? 0, 'Disciplinary Board', "Cleared Disciplinary Status for Officer $officerName ($serviceNo) (Previous Status: '$prevStatus'). Reason: $clearanceReason");
                    }
                    
                    $success = "Disciplinary record cleared for Officer <strong>$officerName ($serviceNo)</strong>. Clearance reason and auditor details successfully logged into Disciplinary Audit History.";
                }
            }
        } catch (Exception $e) {
            $error = "Database Error: " . $e->getMessage();
        }
    }
}

// Fetch Active Disciplinary Roster
$disciplinaryList = [];
$stats = ['total' => 0, 'query' => 0, 'interdiction' => 0, 'suspension' => 0, 'pending' => 0];

try {
    $stmt = $pdo->query("
        SELECT p.serviceNo, p.surname, p.firstName, p.middleName, p.state_of_origin,
               e.currentRank, e.presentPosting, e.disciplinary_status, e.disciplinary_remarks, e.empStatus
        FROM tbl_emppersonal p
        JOIN tbl_employment e ON p.serviceNo = e.serviceNo
        WHERE e.disciplinary_status IS NOT NULL AND e.disciplinary_status != 'Clean' AND e.disciplinary_status != ''
        ORDER BY e.disciplinary_status ASC, p.surname ASC
    ");
    $disciplinaryList = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($disciplinaryList as $row) {
        $stats['total']++;
        $st = strtolower($row['disciplinary_status']);
        if (strpos($st, 'query') !== false) $stats['query']++;
        elseif (strpos($st, 'interdiction') !== false) $stats['interdiction']++;
        elseif (strpos($st, 'suspension') !== false) $stats['suspension']++;
        else $stats['pending']++;
    }
} catch (Exception $e) {}

// Fetch Disciplinary Audit History
$disciplinaryHistory = [];
try {
    $histStmt = $pdo->query("
        SELECT * FROM disciplinary_history 
        ORDER BY created_at DESC 
        LIMIT 200
    ");
    $disciplinaryHistory = $histStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $disciplinaryHistory = [];
}

// Include Global Header
require_once 'includes/header.php';
?>

<style>
    .page-title {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1.25rem;
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
    .toolbar-wrapper {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        flex-wrap: wrap;
    }
    .search-filter-input {
        padding: 0.4rem 0.75rem;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        font-size: 0.8rem;
        width: 250px;
        outline: none;
    }
    .search-filter-input:focus { border-color: #1a5632; }

    .card {
        background: #ffffff;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        margin-bottom: 1.25rem;
        overflow: hidden;
    }
    .card-header {
        padding: 0.75rem 1rem;
        background: #f8fafc;
        border-bottom: 1px solid #cbd5e1;
        font-weight: 700;
        font-size: 0.85rem;
        color: #1e293b;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .card-body { padding: 1rem; }

    .data-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.8rem;
    }
    .data-table th, .data-table td {
        padding: 0.6rem 0.75rem;
        border-bottom: 1px solid #e2e8f0;
        text-align: left;
    }
    .data-table th {
        background: #f1f5f9;
        color: #334155;
        font-weight: 700;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.02em;
    }
    .data-table tbody tr:hover { background: #f8fafc; }

    .status-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        padding: 0.15rem 0.55rem;
        border-radius: 12px;
        font-size: 0.7rem;
        font-weight: 600;
        white-space: nowrap;
    }
    .status-pill.blacklisted { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
    .status-pill.cleared { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }

    .btn-action-sm {
        padding: 0.3rem 0.55rem;
        border-radius: 4px;
        font-size: 0.75rem;
        font-weight: 500;
        cursor: pointer;
        border: 1px solid #cbd5e1;
        background: #ffffff;
        color: #475569;
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        text-decoration: none;
        transition: all 0.15s ease;
    }
    .btn-action-sm:hover { background: #f8fafc; color: #1e293b; }
    .btn-action-sm.btn-danger { color: #dc3545; border-color: #fecaca; }
    .btn-action-sm.btn-danger:hover { background: #fef2f2; }
    .btn-action-sm.btn-success { color: #166534; border-color: #bbf7d0; }
    .btn-action-sm.btn-success:hover { background: #f0fdf4; }
    .btn-action-sm.btn-edit { color: #1d4ed8; border-color: #bfdbfe; }
    .btn-action-sm.btn-edit:hover { background: #eff6ff; }
    .btn-action-sm.btn-view { color: #0284c7; border-color: #bae6fd; }
    .btn-action-sm.btn-view:hover { background: #f0f9ff; }

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
    }
    .btn-danger-primary { background: #dc2626; color: #ffffff; }
    .btn-danger-primary:hover { background: #b91c1c; }
    .btn-success-primary { background: #166534; color: #ffffff; }
    .btn-success-primary:hover { background: #14532d; }
    .btn-secondary { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }
    .btn-secondary:hover { background: #e2e8f0; }

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
    .alert-danger { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

    .modal { display: none; position: fixed; z-index: 9999; left:0; top:0; width:100%; height:100%; background: rgba(0,0,0,0.6); overflow-y:auto; }
    .modal-content { position: relative; background: #ffffff; margin:5% auto; width:90%; max-width:550px; border-radius:10px; box-shadow:0 10px 25px rgba(0,0,0,0.2); }
    .modal-header { padding:0.75rem 1.25rem; background:#1a5632; color:#ffffff; border-radius:10px 10px 0 0; display:flex; justify-content:space-between; align-items:center; }
    .modal-header.danger-header { background: #dc2626; }
    .modal-header h3 { color: #ffffff !important; font-size: 0.95rem; font-weight: 600; margin: 0; display: flex; align-items: center; gap: 0.5rem; }
    .close-modal { background:none; border:none; color:#ffffff; font-size:1.4rem; cursor:pointer; }
    .modal-body { padding:1.25rem; }
    .modal-footer { padding:0.75rem 1.25rem; border-top:1px solid #e2e8f0; display:flex; justify-content:flex-end; gap:0.5rem; }

    .form-group { margin-bottom: 0.85rem; }
    .form-label {
        font-weight: 600;
        color: #334155;
        margin-bottom: 0.3rem;
        display: block;
        font-size: 0.8rem;
    }
    .form-control {
        width: 100%;
        padding: 0.45rem 0.75rem;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        font-size: 0.85rem;
        outline: none;
        background: #ffffff;
    }
    .form-control:focus { border-color: #1a5632; }

    .nav-tabs {
        display: flex;
        gap: 0.5rem;
        border-bottom: 2px solid #e2e8f0;
        margin-bottom: 1.25rem;
    }
    .nav-tab {
        padding: 0.6rem 1rem;
        font-size: 0.85rem;
        font-weight: 600;
        color: #64748b;
        cursor: pointer;
        border-bottom: 3px solid transparent;
        transition: all 0.2s ease;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    .nav-tab.active {
        color: #1a5632;
        border-bottom-color: #1a5632;
    }
    .nav-tab:hover { color: #1a5632; }
</style>

<div class="page-title">
    <h1><i class="fas fa-gavel"></i> Disciplinary Board & Sensitive Posting Control</h1>
    <div class="toolbar-wrapper">
        <input type="text" id="disciplinarySearchInput" class="search-filter-input" placeholder="Search active or audit history..." onkeyup="filterDisciplinaryTables()">
        <?php if ($canManageDisciplinary): ?>
            <button onclick="openAddModal()" class="btn btn-danger-primary"><i class="fas fa-plus"></i> Blacklist / Add Officer</button>
        <?php endif; ?>
    </div>
</div>

<?php if (!$canManageDisciplinary): ?>
    <div class="alert alert-danger">
        <i class="fas fa-ban"></i> 403 Forbidden Access: The Disciplinary Board module is restricted exclusively to Admin, Super Admin, and Service HQ roles.
    </div>
<?php else: ?>

    <?php if (!empty($success)): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div><?php endif; ?>
    <?php if (!empty($error)): ?><div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div><?php endif; ?>

    <!-- Summary Stats Bar -->
    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:1rem; margin-bottom:1.25rem;">
        <div class="card" style="margin:0; padding:1rem;">
            <div style="display:flex; align-items:center; justify-content:space-between;">
                <div>
                    <span style="font-size:0.7rem; color:#64748b; font-weight:700; text-transform:uppercase;">Total Blocked Personnel</span>
                    <h3 style="font-size:1.5rem; font-weight:800; color:#dc2626; margin:0.2rem 0 0 0;"><?php echo $stats['total']; ?></h3>
                </div>
                <div class="stat-icon" style="background:#fef2f2; color:#dc2626; padding:12px; border-radius:50%;"><i class="fas fa-ban fa-lg"></i></div>
            </div>
        </div>

        <div class="card" style="margin:0; padding:1rem;">
            <div style="display:flex; align-items:center; justify-content:space-between;">
                <div>
                    <span style="font-size:0.7rem; color:#64748b; font-weight:700; text-transform:uppercase;">Officers Under Query</span>
                    <h3 style="font-size:1.5rem; font-weight:800; color:#d97706; margin:0.2rem 0 0 0;"><?php echo $stats['query']; ?></h3>
                </div>
                <div class="stat-icon" style="background:#fffbe6; color:#d97706; padding:12px; border-radius:50%;"><i class="fas fa-question-circle fa-lg"></i></div>
            </div>
        </div>

        <div class="card" style="margin:0; padding:1rem;">
            <div style="display:flex; align-items:center; justify-content:space-between;">
                <div>
                    <span style="font-size:0.7rem; color:#64748b; font-weight:700; text-transform:uppercase;">Interdiction & Suspension</span>
                    <h3 style="font-size:1.5rem; font-weight:800; color:#475569; margin:0.2rem 0 0 0;"><?php echo $stats['suspension'] + $stats['interdiction']; ?></h3>
                </div>
                <div class="stat-icon" style="background:#f1f5f9; color:#475569; padding:12px; border-radius:50%;"><i class="fas fa-user-slash fa-lg"></i></div>
            </div>
        </div>

        <div class="card" style="margin:0; padding:1rem;">
            <div style="display:flex; align-items:center; justify-content:space-between;">
                <div>
                    <span style="font-size:0.7rem; color:#64748b; font-weight:700; text-transform:uppercase;">Audit History Logged</span>
                    <h3 style="font-size:1.5rem; font-weight:800; color:#166534; margin:0.2rem 0 0 0;"><?php echo count($disciplinaryHistory); ?></h3>
                </div>
                <div class="stat-icon" style="background:#f0fdf4; color:#166534; padding:12px; border-radius:50%;"><i class="fas fa-history fa-lg"></i></div>
            </div>
        </div>
    </div>

    <!-- Tab Navigation -->
    <div class="nav-tabs">
        <div class="nav-tab active" id="tabActiveBtn" onclick="switchTab('active')">
            <i class="fas fa-user-shield"></i> Active Disciplinary Blocklist (<?php echo count($disciplinaryList); ?>)
        </div>
        <div class="nav-tab" id="tabAuditBtn" onclick="switchTab('audit')">
            <i class="fas fa-file-shield"></i> Disciplinary Audit & History Ledger (<?php echo count($disciplinaryHistory); ?> Records)
        </div>
    </div>

    <!-- TAB 1: ACTIVE DISCIPLINARY BLOCKLIST -->
    <div id="tabActiveSection" class="card">
        <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.5rem;">
            <span><i class="fas fa-list-check"></i> Active Disciplinary Records (Posting Blocked)</span>
            <div style="display:flex; align-items:center; gap:0.75rem;">
                <div style="position:relative;">
                    <i class="fas fa-search" style="position:absolute; left:10px; top:50%; transform:translateY(-50%); color:#94a3b8; font-size:0.75rem;"></i>
                    <input type="text" id="activeSearchInput" class="search-filter-input" placeholder="Search Active by Service No..." onkeyup="filterActiveTable()" style="padding-left:28px; width:260px;">
                </div>
                <span style="font-size:0.75rem; color:#64748b; font-weight:500;"><?php echo count($disciplinaryList); ?> Personnel Currently Blocked</span>
            </div>
        </div>
        <div class="card-body" style="padding:0;">
            <div class="table-container">
                <?php if (empty($disciplinaryList)): ?>
                    <div style="text-align:center; padding:3rem 1rem; color:#64748b;">
                        <i class="fas fa-check-circle" style="font-size:1.75rem; color:#16a34a; margin-bottom:0.75rem; display:block;"></i>
                        <h3 style="font-size:0.95rem; color:#1e293b; margin-bottom:0.25rem;">No Personnel Currently Under Disciplinary Action</h3>
                        <p style="font-size:0.775rem;">All active personnel records are currently clean and eligible for posting.</p>
                    </div>
                <?php else: ?>
                    <table class="data-table" id="disciplinaryTable">
                        <thead>
                            <tr>
                                <th>Service No</th>
                                <th>Officer Name</th>
                                <th>Rank</th>
                                <th>Present Location</th>
                                <th>Disciplinary Status</th>
                                <th>Query / Case Reference Details</th>
                                <th style="text-align:center;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($disciplinaryList as $item): 
                                $name = trim(($item['surname']??'') . ' ' . ($item['firstName']??'') . ' ' . ($item['middleName']??''));
                            ?>
                            <tr>
                                <td><strong><code><?php echo htmlspecialchars($item['serviceNo']); ?></code></strong></td>
                                <td><strong><?php echo htmlspecialchars($name); ?></strong></td>
                                <td><?php echo htmlspecialchars($item['currentRank'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($item['presentPosting'] ?? 'N/A'); ?></td>
                                <td><?php echo getDisciplinaryBadgeHTML($item['disciplinary_status'], $item['disciplinary_remarks']); ?></td>
                                <td style="font-size:0.775rem; color:#475569; max-width:280px;"><?php echo htmlspecialchars($item['disciplinary_remarks'] ?? 'No case remarks recorded.'); ?></td>
                                <td style="text-align:center;">
                                    <div style="display:flex; justify-content:center; gap:0.35rem;">
                                        <!-- Clear Trigger Modal -->
                                        <button onclick="openClearModal('<?php echo htmlspecialchars($item['serviceNo'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($name, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($item['disciplinary_status'], ENT_QUOTES); ?>')" class="btn-action-sm btn-success" title="Clear Disciplinary Record"><i class="fas fa-check"></i> Clear</button>

                                        <!-- Edit Modal Trigger -->
                                        <button onclick="openEditModal('<?php echo htmlspecialchars($item['serviceNo'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($item['disciplinary_status'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($item['disciplinary_remarks'] ?? '', ENT_QUOTES); ?>')" class="btn-action-sm btn-edit" title="Edit Case Remarks"><i class="fas fa-edit"></i> Edit</button>

                                        <!-- View Search File -->
                                        <a href="search?serviceNo=<?php echo urlencode($item['serviceNo']); ?>" class="btn-action-sm btn-view" title="View Service File"><i class="fas fa-eye"></i> View</a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- TAB 2: DISCIPLINARY AUDIT & HISTORY LEDGER -->
    <div id="tabAuditSection" class="card" style="display:none;">
        <div class="card-header" style="background:#f0fdf4; border-bottom-color:#bbf7d0; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.5rem;">
            <span style="color:#166534;"><i class="fas fa-shield-halved"></i> Permanent Disciplinary Audit & Investigation Ledger</span>
            <div style="display:flex; align-items:center; gap:0.75rem;">
                <div style="position:relative;">
                    <i class="fas fa-search" style="position:absolute; left:10px; top:50%; transform:translateY(-50%); color:#166534; font-size:0.75rem;"></i>
                    <input type="text" id="auditSearchInput" class="search-filter-input" placeholder="Search Audit Logs by Service No..." onkeyup="filterAuditTable()" style="padding-left:28px; width:280px; border-color:#bbf7d0;">
                </div>
                <span style="font-size:0.75rem; color:#15803d; font-weight:600;"><i class="fas fa-lock"></i> Immutable Audit Logs</span>
            </div>
        </div>
        <div class="card-body" style="padding:0;">
            <div class="table-container">
                <?php if (empty($disciplinaryHistory)): ?>
                    <div style="text-align:center; padding:3rem 1rem; color:#64748b;">
                        <i class="fas fa-history" style="font-size:1.75rem; color:#94a3b8; margin-bottom:0.75rem; display:block;"></i>
                        <h3 style="font-size:0.95rem; color:#1e293b; margin-bottom:0.25rem;">No Disciplinary History Logs Recorded Yet</h3>
                        <p style="font-size:0.775rem;">All future blacklisting, modifications, and clearance operations will be logged here automatically.</p>
                    </div>
                <?php else: ?>
                    <table class="data-table" id="auditHistoryTable">
                        <thead>
                            <tr>
                                <th>Date & Time</th>
                                <th>Service No</th>
                                <th>Officer Name</th>
                                <th>Action Taken</th>
                                <th>Status Transition</th>
                                <th>Case Remarks / Clearance Reason</th>
                                <th>Action Performed By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($disciplinaryHistory as $h): ?>
                            <tr>
                                <td style="white-space:nowrap; font-size:0.75rem; color:#64748b;">
                                    <i class="far fa-clock"></i> <?php echo date('d M Y, H:i', strtotime($h['created_at'])); ?>
                                </td>
                                <td><strong><code><?php echo htmlspecialchars($h['serviceNo']); ?></code></strong></td>
                                <td><strong><?php echo htmlspecialchars($h['officer_name'] ?? 'N/A'); ?></strong></td>
                                <td>
                                    <?php if ($h['action_type'] === 'Blacklisted / Updated'): ?>
                                        <span class="status-pill blacklisted"><i class="fas fa-user-slash"></i> Blacklisted / Updated</span>
                                    <?php else: ?>
                                        <span class="status-pill cleared"><i class="fas fa-user-check"></i> Cleared / Restored</span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size:0.775rem;">
                                    <span style="color:#64748b;"><?php echo htmlspecialchars($h['previous_status'] ?? 'Clean'); ?></span>
                                    <i class="fas fa-arrow-right" style="font-size:0.65rem; color:#94a3b8; margin:0 4px;"></i>
                                    <strong style="color:<?php echo $h['new_status'] === 'Clean' ? '#166534' : '#dc2626'; ?>;"><?php echo htmlspecialchars($h['new_status']); ?></strong>
                                </td>
                                <td style="font-size:0.775rem; color:#334155; max-width:250px;">
                                    <?php echo htmlspecialchars($h['reason_remarks'] ?? 'N/A'); ?>
                                </td>
                                <td style="font-size:0.75rem; color:#475569;">
                                    <strong><i class="fas fa-user-shield"></i> <?php echo htmlspecialchars($h['action_by_full_name']); ?></strong> 
                                    <span style="color:#64748b;">(<?php echo htmlspecialchars($h['action_by_username']); ?> - <?php echo htmlspecialchars($h['action_by_role']); ?>)</span>
                                    <div style="font-size:0.70rem; color:#94a3b8;"><i class="fas fa-laptop"></i> IP: <?php echo htmlspecialchars($h['ip_address'] ?? 'N/A'); ?></div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

<?php endif; ?>

<!-- Modal 1: Add / Update Disciplinary Action -->
<div id="disciplinaryModal" class="modal">
    <div class="modal-content">
        <div class="modal-header danger-header">
            <h3 id="modalTitle"><i class="fas fa-gavel"></i> Place Officer Under Disciplinary Action</h3>
            <button onclick="closeModal('disciplinaryModal')" class="close-modal">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <div class="modal-body">
                <input type="hidden" name="action" id="modalAction" value="add_disciplinary">
                
                <div class="form-group">
                    <label class="form-label">Officer Service Number</label>
                    <input type="text" name="serviceNo" id="modalServiceNo" class="form-control" placeholder="Enter Service Number (e.g. 10482)" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Disciplinary Status</label>
                    <select name="disciplinary_status" id="modalStatus" class="form-control" required style="font-weight:600;">
                        <option value="Under Query">⚠️ Under Query (POSTING BLOCKED)</option>
                        <option value="Under Interdiction">⛔ Under Interdiction (POSTING BLOCKED)</option>
                        <option value="Under Suspension">🚫 Under Suspension (POSTING BLOCKED)</option>
                        <option value="Pending Disciplinary Action">⚖️ Pending Disciplinary Action (POSTING BLOCKED)</option>
                        <option value="Dismissed">❌ Dismissed (POSTING BLOCKED)</option>
                    </select>
                    <small style="color:#dc2626; font-size:0.725rem; display:block; margin-top:4px;"><i class="fas fa-shield-alt"></i> Any officer placed on this list is strictly blocked from receiving new postings.</small>
                </div>

                <div class="form-group">
                    <label class="form-label">Query Reference & Case Remarks</label>
                    <textarea name="disciplinary_remarks" id="modalRemarks" rows="3" class="form-control" placeholder="e.g. Query Ref: NIS/HQ/DISC/2026/019 - Unauthorized Absence from Seme Border Post"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeModal('disciplinaryModal')" class="btn btn-secondary">Cancel</button>
                <button type="submit" class="btn btn-danger-primary"><i class="fas fa-shield-alt"></i> Save Disciplinary Action</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal 2: Clear Officer Disciplinary Status with Audit Notes -->
<div id="clearModal" class="modal">
    <div class="modal-content">
        <div class="modal-header" style="background:#166534;">
            <h3><i class="fas fa-user-check"></i> Clear Disciplinary Record & Restore Eligibility</h3>
            <button onclick="closeModal('clearModal')" class="close-modal">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <div class="modal-body">
                <input type="hidden" name="action" value="clear_disciplinary">
                <input type="hidden" name="serviceNo" id="clearServiceNo" value="">
                
                <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:6px; padding:10px 14px; margin-bottom:15px; font-size:0.825rem; color:#166534;">
                    <i class="fas fa-info-circle"></i> You are clearing the disciplinary status for Officer: <strong id="clearOfficerName"></strong> (<code id="clearServiceNoDisplay"></code>).
                </div>

                <div class="form-group">
                    <label class="form-label">Current Status Being Cleared</label>
                    <input type="text" id="clearCurrentStatus" class="form-control" readonly style="background:#f8fafc; font-weight:bold; color:#dc2626;">
                </div>

                <div class="form-group">
                    <label class="form-label">Clearance Reason / Audit Notes <span style="color:#dc2626;">*</span></label>
                    <textarea name="clearance_reason" rows="3" class="form-control" placeholder="e.g. Exonerated by Disciplinary Committee Order Ref: NIS/HQ/DISC/CLR/2026/04" required></textarea>
                    <small style="color:#64748b; font-size:0.725rem; display:block; margin-top:4px;"><i class="fas fa-lock"></i> Your user identity, IP address, and clearance note will be permanently archived in the Disciplinary Audit History Ledger.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeModal('clearModal')" class="btn btn-secondary">Cancel</button>
                <button type="submit" class="btn btn-success-primary"><i class="fas fa-check-circle"></i> Confirm Clearance & Restore Officer</button>
            </div>
        </form>
    </div>
</div>

<script>
function switchTab(tab) {
    const tabActiveBtn = document.getElementById('tabActiveBtn');
    const tabAuditBtn = document.getElementById('tabAuditBtn');
    const tabActiveSection = document.getElementById('tabActiveSection');
    const tabAuditSection = document.getElementById('tabAuditSection');

    if (tab === 'active') {
        tabActiveBtn.classList.add('active');
        tabAuditBtn.classList.remove('active');
        tabActiveSection.style.display = 'block';
        tabAuditSection.style.display = 'none';
    } else {
        tabActiveBtn.classList.remove('active');
        tabAuditBtn.classList.add('active');
        tabActiveSection.style.display = 'none';
        tabAuditSection.style.display = 'block';
    }
}

function openAddModal() {
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-gavel"></i> Blacklist / Add Officer to Disciplinary List';
    document.getElementById('modalAction').value = 'add_disciplinary';
    document.getElementById('modalServiceNo').value = '';
    document.getElementById('modalServiceNo').readOnly = false;
    document.getElementById('modalStatus').value = 'Under Query';
    document.getElementById('modalRemarks').value = '';
    document.getElementById('disciplinaryModal').style.display = 'block';
}

function openEditModal(svcNo, status, remarks) {
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Update Disciplinary Record';
    document.getElementById('modalAction').value = 'update_disciplinary';
    document.getElementById('modalServiceNo').value = svcNo;
    document.getElementById('modalServiceNo').readOnly = true;
    document.getElementById('modalStatus').value = status;
    document.getElementById('modalRemarks').value = remarks;
    document.getElementById('disciplinaryModal').style.display = 'block';
}

function openClearModal(svcNo, name, status) {
    document.getElementById('clearServiceNo').value = svcNo;
    document.getElementById('clearServiceNoDisplay').textContent = svcNo;
    document.getElementById('clearOfficerName').textContent = name;
    document.getElementById('clearCurrentStatus').value = status;
    document.getElementById('clearModal').style.display = 'block';
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

function filterActiveTable() {
    const input = document.getElementById('activeSearchInput');
    if (!input) return;
    const filter = input.value.toLowerCase();
    const table = document.getElementById('disciplinaryTable');
    if (!table) return;
    const tr = table.getElementsByTagName('tr');

    for (let i = 1; i < tr.length; i++) {
        let textMatch = false;
        const td = tr[i].getElementsByTagName('td');
        for (let j = 0; j < td.length - 1; j++) {
            if (td[j]) {
                const txtValue = td[j].textContent || td[j].innerText;
                if (txtValue.toLowerCase().indexOf(filter) > -1) {
                    textMatch = true;
                    break;
                }
            }
        }
        tr[i].style.display = textMatch ? '' : 'none';
    }
}

function filterAuditTable() {
    const input = document.getElementById('auditSearchInput');
    if (!input) return;
    const filter = input.value.toLowerCase();
    const table = document.getElementById('auditHistoryTable');
    if (!table) return;
    const tr = table.getElementsByTagName('tr');

    for (let i = 1; i < tr.length; i++) {
        let textMatch = false;
        const td = tr[i].getElementsByTagName('td');
        for (let j = 0; j < td.length; j++) {
            if (td[j]) {
                const txtValue = td[j].textContent || td[j].innerText;
                if (txtValue.toLowerCase().indexOf(filter) > -1) {
                    textMatch = true;
                    break;
                }
            }
        }
        tr[i].style.display = textMatch ? '' : 'none';
    }
}

function filterDisciplinaryTables() {
    const input = document.getElementById('disciplinarySearchInput');
    if (!input) return;
    const filter = input.value.toLowerCase();
    
    // Sync with individual inputs
    const activeInput = document.getElementById('activeSearchInput');
    if (activeInput) { activeInput.value = filter; filterActiveTable(); }

    const auditInput = document.getElementById('auditSearchInput');
    if (auditInput) { auditInput.value = filter; filterAuditTable(); }
}
</script>

<?php require_once 'includes/footer.php'; ?>
