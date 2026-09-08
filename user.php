<?php
ini_set('display_errors', 0);
session_start();

require_once 'includes/config.php';
require_once 'includes/auth.php';
require_once 'includes/permissions.php';

if (!isLoggedIn()) { header('Location: login'); exit(); }

// Only Admin and SHQ Admin can access User Management
if (!isAdmin() && !isServiceHQ()) {
    header('Location: dashboard');
    exit();
}

// Get current user info
try {
    $stmt = $pdo->prepare("SELECT u.*, r.name as role_name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user) {
        $user = ['username' => $_SESSION['username'] ?? 'User', 'full_name' => $_SESSION['username'] ?? 'User', 'role_name' => 'User'];
    }
} catch (Exception $e) {
    $user = ['username' => $_SESSION['username'] ?? 'User', 'full_name' => $_SESSION['username'] ?? 'User', 'role_name' => 'User'];
}

$error = ''; $success = ''; $users = []; $roles = []; $zones = []; $user_zones = [];
$csrfToken = generateCSRFToken();

// Check for session success message
if (isset($_SESSION['success_msg'])) {
    $success = $_SESSION['success_msg'];
    unset($_SESSION['success_msg']);
}

// NIS Formations
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
        'Abia State Command' => 'Abia State Command',
        'Imo State Command' => 'Imo State Command',
        'Rivers State Command' => 'Rivers State Command',
        'Cross River State Command' => 'Cross River State Command',
        'Ebonyi State Command' => 'Ebonyi State Command',
        'Akwa Ibom State Command' => 'Akwa Ibom State Command',
        'Nigeria Immigration Training School Orlu' => 'Nigeria Immigration Training School Orlu',
        'Rivers Marine Command Onne' => 'Rivers Marine Command Onne',
        'Mfum Border Command' => 'Mfum Border Command',
        'Nigeria Immigration Training School Ahoada' => 'Nigeria Immigration Training School Ahoada'
    ],
    'ZONE F' => [
        'Zone F HQ Ibadan' => 'Zone F Ibadan',
        'Oyo State Command' => 'Oyo State Command',
        'Ekiti State Command' => 'Ekiti State Command',
        'Ondo State Command' => 'Ondo State Command',
        'Osun State Command' => 'Osun State Command'
    ],
    'ZONE G' => [
        'Zone G HQ Benin' => 'Zone G Benin City',
        'Edo State Command' => 'Edo State Command',
        'Anambra State Command' => 'Anambra State Command',
        'Delta State Command' => 'Delta State Command',
        'Enugu State Command' => 'Enugu State Command',
        'Bayelsa State Command' => 'Bayelsa State Command'
    ],
    'ZONE H' => [
        'Zone H HQ Makurdi' => 'Zone H Makurdi',
        'Nasarawa State Command' => 'Nasarawa State Command',
        'Benue State Command' => 'Benue State Command',
        'Kogi State Command' => 'Kogi State Command',
        'Taraba State Command' => 'Taraba State Command'
    ]
];

try {
    $users = $pdo->query("SELECT u.id, u.username, u.full_name, u.email, u.phone, u.status, u.created_at, u.last_login, u.geofence_enabled, u.geofence_mode, u.custom_lat, u.custom_lng, u.custom_radius_meters, r.name as role_name, r.id as role_id, (SELECT uz.assigned_command FROM user_zones uz WHERE uz.user_id = u.id LIMIT 1) as assigned_command FROM users u JOIN roles r ON u.role_id = r.id GROUP BY u.id ORDER BY u.created_at DESC")->fetchAll();
    
    // SHQ Admin can create Command Admin and SHQ Admin accounts. Admin can manage all.
    if (isServiceHQ() && !isAdmin()) {
        $roles = $pdo->query("SELECT id, name FROM roles WHERE LOWER(name) IN ('shq admin', 'command admin', 'user') GROUP BY id, name ORDER BY id ASC")->fetchAll();
    } else {
        $roles = $pdo->query("SELECT id, name FROM roles GROUP BY id, name ORDER BY id ASC")->fetchAll();
    }
    
    $zones = $pdo->query("SELECT id, zone_name, zone_code FROM zones ORDER BY zone_name")->fetchAll();
    
    if (isAdmin() || isServiceHQ()) {
        $user_zones = $zones;
    } else {
        $user_zones = getUserZones($_SESSION['user_id']);
    }
} catch (PDOException $e) {
    $error = "Database Error: " . $e->getMessage();
}

// HANDLE CREATE USER
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_user']) && !validateCSRFToken($_POST['csrf_token'] ?? '')) {
    $error = "Security validation failed. Please refresh the page and try again.";
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_user'])) {
    $username = trim($_POST['username']);
    $full_name = trim($_POST['full_name']);
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $role_id = intval($_POST['role_id']);
    $assigned_zone_id = intval($_POST['assigned_zone_id'] ?? 0);
    $assigned_command = trim($_POST['assigned_command'] ?? '');
    
    if (empty($username) || empty($full_name) || empty($phone) || empty($password)) {
        $error = "All required fields must be filled.";
    } elseif (!preg_match('/^[0-9]{4,5}$/', $username)) {
        $error = "Username must be numbers only and contain 4 to 5 digits (e.g. 12345).";
    } elseif (!preg_match('/^[0-9]+$/', $phone)) {
        $error = "Phone number must contain numbers only.";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } elseif (!($pwdCheck = validatePasswordStrength($password))['valid']) {
        $error = $pwdCheck['message'];
    } elseif (empty($role_id)) {
        $error = "Please select a user role.";
    } else {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR phone = ? OR email = ?");
        $stmt->execute([$username, $phone, $phone]);
        if ($stmt->rowCount() > 0) {
            $error = "Username or Phone Number already exists in the system.";
        } else {
            try {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                try {
                    $stmt = $pdo->prepare("INSERT INTO users (username, full_name, email, phone, password, role_id, status, created_by, must_change_password) VALUES (?, ?, ?, ?, ?, ?, 'active', ?, 1)");
                    $stmt->execute([$username, $full_name, $phone, $phone, $hashed, $role_id, $_SESSION['user_id']]);
                } catch (Exception $exCol) {
                    $pdo->exec("ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0");
                    $stmt = $pdo->prepare("INSERT INTO users (username, full_name, email, phone, password, role_id, status, created_by, must_change_password) VALUES (?, ?, ?, ?, ?, ?, 'active', ?, 1)");
                    $stmt->execute([$username, $full_name, $phone, $phone, $hashed, $role_id, $_SESSION['user_id']]);
                }
                $user_id = $pdo->lastInsertId();
                
                if ($assigned_zone_id > 0 && $user_id > 0) {
                    $stmt = $pdo->prepare("INSERT INTO user_zones (user_id, zone_id, assigned_command) VALUES (?, ?, ?)");
                    $stmt->execute([$user_id, $assigned_zone_id, !empty($assigned_command) ? $assigned_command : null]);
                }

                $rName = $pdo->query("SELECT name FROM roles WHERE id = " . intval($role_id))->fetchColumn() ?: 'Role '.$role_id;
                logSystemActivity('user_created', "Created user account '@$username' ($full_name, Role: $rName) by " . ($_SESSION['full_name'] ?? 'Admin'), $_SESSION['user_id']);
                
                $_SESSION['success_msg'] = "User account for <strong>" . htmlspecialchars($full_name) . "</strong> (@$username) created successfully!";
                header("Location: user");
                exit();
            } catch (Exception $e) {
                $error = "Error creating user account: " . $e->getMessage();
            }
        }
    }
}

// HANDLE OTHER ACTIONS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['create_user']) && !validateCSRFToken($_POST['csrf_token'] ?? '')) {
    $error = "Security validation failed. Please refresh the page and try again.";
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['activate_user'])) {
        $pdo->prepare("UPDATE users SET status='active' WHERE id=?")->execute([$_POST['user_id']]);
        $uName = $pdo->query("SELECT username FROM users WHERE id=" . intval($_POST['user_id']))->fetchColumn();
        logSystemActivity('user_activated', "Activated user account '@$uName'", $_SESSION['user_id']);
        $success = "User account activated!";
    }
    if (isset($_POST['deactivate_user'])) {
        $pdo->prepare("UPDATE users SET status='inactive' WHERE id=?")->execute([$_POST['user_id']]);
        $uName = $pdo->query("SELECT username FROM users WHERE id=" . intval($_POST['user_id']))->fetchColumn();
        logSystemActivity('user_deactivated', "Deactivated user account '@$uName'", $_SESSION['user_id']);
        $success = "User account deactivated!";
    }
    if (isset($_POST['delete_user']) && $_POST['user_id'] != $_SESSION['user_id']) {
        $uName = $pdo->query("SELECT username FROM users WHERE id=" . intval($_POST['user_id']))->fetchColumn();
        $pdo->prepare("DELETE FROM user_zones WHERE user_id=?")->execute([$_POST['user_id']]);
        $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$_POST['user_id']]);
        logSystemActivity('user_deleted', "Deleted user account '@$uName'", $_SESSION['user_id']);
        $success = "User account deleted permanently!";
    }
    if (isset($_POST['update_role'])) {
        $userId = $_POST['user_id'];
        $newRoleId = $_POST['role_id'];
        $pdo->prepare("UPDATE users SET role_id=? WHERE id=?")->execute([$newRoleId, $userId]);
        $uName = $pdo->query("SELECT username FROM users WHERE id=" . intval($userId))->fetchColumn();
        $rName = $pdo->query("SELECT name FROM roles WHERE id=" . intval($newRoleId))->fetchColumn();
        logSystemActivity('role_updated', "Updated role for user '@$uName' to '$rName'", $_SESSION['user_id']);
        $success = "User role updated successfully!";
    }
    if (isset($_POST['update_zones'])) {
        $uid = $_POST['user_id'];
        $pdo->prepare("DELETE FROM user_zones WHERE user_id=?")->execute([$uid]);
        if (!empty($_POST['zones'])) {
            foreach ($_POST['zones'] as $zid) {
                $commandKey = 'command_' . $zid;
                $specificCommand = $_POST[$commandKey] ?? null;
                $pdo->prepare("INSERT INTO user_zones (user_id, zone_id, assigned_command) VALUES (?, ?, ?)")
                    ->execute([$uid, $zid, !empty($specificCommand) ? $specificCommand : null]);
            }
        }
        $uName = $pdo->query("SELECT username FROM users WHERE id=" . intval($uid))->fetchColumn();
        logSystemActivity('zones_updated', "Updated command and zone assignments for user '@$uName'", $_SESSION['user_id']);
        $success = "User zone assignments updated successfully!";
    }
    if (isset($_POST['reset_password'])) {
        $defaultPassword = 'password123';
        try {
            $pdo->prepare("UPDATE users SET password=?, must_change_password=1 WHERE id=?")->execute([password_hash($defaultPassword, PASSWORD_DEFAULT), $_POST['user_id']]);
        } catch (Exception $exCol) {
            $pdo->exec("ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0");
            $pdo->prepare("UPDATE users SET password=?, must_change_password=1 WHERE id=?")->execute([password_hash($defaultPassword, PASSWORD_DEFAULT), $_POST['user_id']]);
        }
        $uName = $pdo->query("SELECT username FROM users WHERE id=" . intval($_POST['user_id']))->fetchColumn();
        logSystemActivity('password_reset', "Reset password for user '@$uName' to default", $_SESSION['user_id']);
        $success = "User password reset successfully to: <strong>password123</strong>. They will be required to set a new password on next login.";
    }
    if (isset($_POST['reset_2fa'])) {
        $pdo->prepare("UPDATE users SET google_2fa_secret = NULL WHERE id = ?")->execute([$_POST['user_id']]);
        $uName = $pdo->query("SELECT username FROM users WHERE id=" . intval($_POST['user_id']))->fetchColumn();
        logSystemActivity('2fa_reset', "Reset Google Authenticator (2FA) for user '@$uName' - will re-setup on next login", $_SESSION['user_id']);
        $success = "Google Authenticator reset successfully for <strong>@$uName</strong>. They will be prompted to scan a new QR code on their next login.";
    }
    if (isset($_POST['save_user_geofence'])) {
        $uid = intval($_POST['user_id']);
        $enabled = intval($_POST['geofence_enabled'] ?? 1);
        $mode = $_POST['geofence_mode'] ?? 'strict';
        if (!in_array($mode, ['strict', 'audit_only', 'disabled'])) $mode = 'strict';

        $lat = !empty($_POST['custom_lat']) ? floatval($_POST['custom_lat']) : null;
        $lng = !empty($_POST['custom_lng']) ? floatval($_POST['custom_lng']) : null;
        $radius = !empty($_POST['custom_radius_meters']) ? intval($_POST['custom_radius_meters']) : null;

        $pdo->prepare("UPDATE users SET geofence_enabled = ?, geofence_mode = ?, custom_lat = ?, custom_lng = ?, custom_radius_meters = ? WHERE id = ?")
            ->execute([$enabled, $mode, $lat, $lng, $radius, $uid]);

        $uName = $pdo->query("SELECT username FROM users WHERE id=" . intval($uid))->fetchColumn();
        logSystemActivity('geofence_updated', "Updated geofence settings for user '@$uName' (Mode: $mode)", $_SESSION['user_id']);

        $success = "Geofence configuration for user saved successfully!";
    }
    
    // Refresh user listing
    try {
        $users = $pdo->query("SELECT u.id, u.username, u.full_name, u.email, u.phone, u.status, u.created_at, u.last_login, u.geofence_enabled, u.geofence_mode, u.custom_lat, u.custom_lng, u.custom_radius_meters, r.name as role_name, r.id as role_id, (SELECT uz.assigned_command FROM user_zones uz WHERE uz.user_id = u.id LIMIT 1) as assigned_command FROM users u JOIN roles r ON u.role_id = r.id GROUP BY u.id ORDER BY u.created_at DESC")->fetchAll();
    } catch (Exception $e) {}
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

    .toolbar-wrapper {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        flex-wrap: wrap;
    }
    .search-filter-input {
        padding: 0.45rem 0.75rem;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        font-size: 0.825rem;
        outline: none;
        width: 220px;
    }
    .search-filter-input:focus { border-color: #1a5632; }

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
        flex-wrap: wrap;
        gap: 0.5rem;
    }
    .card-body { padding: 1.25rem; }

    .table-container { overflow-x: auto; -webkit-overflow-scrolling: touch; }
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
        white-space: nowrap;
    }
    .data-table td {
        padding: 0.65rem 0.85rem;
        border-bottom: 1px solid #f1f5f9;
        vertical-align: middle;
    }
    .data-table tbody tr:hover { background: #f8fafc; }

    .user-avatar-cell {
        display: flex;
        align-items: center;
        gap: 0.6rem;
    }
    .avatar-icon {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        background: #f1f5f9;
        color: #1a5632;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 0.8rem;
        flex-shrink: 0;
        border: 1px solid #cbd5e1;
    }
    .user-info-text { display: flex; flex-direction: column; }
    .user-name-title { font-weight: 600; color: #1e293b; line-height: 1.2; }
    .user-sub-text { font-size: 0.725rem; color: #64748b; margin-top: 0.1rem; }

    .role-badge {
        display: inline-block;
        padding: 0.15rem 0.5rem;
        border-radius: 6px;
        font-size: 0.7rem;
        font-weight: 600;
        white-space: nowrap;
    }
    .role-superadmin { background: #fee2e2; color: #991b1b; }
    .role-servicehq { background: #dbeafe; color: #1e40af; }
    .role-zoneadmin { background: #dcfce7; color: #166534; }
    .role-user { background: #f1f5f9; color: #475569; }

    .status-pill {
        display: inline-block;
        padding: 0.15rem 0.55rem;
        border-radius: 12px;
        font-size: 0.7rem;
        font-weight: 600;
        white-space: nowrap;
    }
    .status-pill.active { background: #dcfce7; color: #166534; }
    .status-pill.inactive { background: #fee2e2; color: #991b1b; }

    .cmd-tag {
        display: inline-block;
        background: #eff6ff;
        color: #1d4ed8;
        border: 1px solid #bfdbfe;
        padding: 0.05rem 0.35rem;
        border-radius: 4px;
        font-size: 0.65rem;
        font-weight: 500;
    }

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
    .btn-success { background: #1a5632; color: #ffffff; }
    .btn-success:hover { background: #154628; }
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

    .form-group { margin-bottom: 0.85rem; }
    .form-label {
        font-weight: 500;
        color: #475569;
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

    .modal { display: none; position: fixed; z-index: 9999; left:0; top:0; width:100%; height:100%; background: rgba(0,0,0,0.6); overflow-y:auto; }
    .modal-content { position: relative; background: #ffffff; margin:5% auto; width:90%; max-width:550px; border-radius:10px; box-shadow:0 10px 25px rgba(0,0,0,0.2); }
    .modal-header { padding:0.75rem 1.25rem; background:#1a5632; color:#ffffff; border-radius:10px 10px 0 0; display:flex; justify-content:space-between; align-items:center; }
    .modal-header h3 { color: #ffffff !important; font-size: 0.95rem; font-weight: 600; margin: 0; display: flex; align-items: center; gap: 0.5rem; }
    .close-modal { background:none; border:none; color:#ffffff; font-size:1.4rem; cursor:pointer; }
    .modal-body { padding:1.25rem; }
    .modal-footer { padding:0.75rem 1.25rem; border-top:1px solid #e2e8f0; display:flex; justify-content:flex-end; gap:0.5rem; }
</style>

<div class="page-title">
    <h1><i class="fas fa-users-cog"></i> User Accounts & RBAC Access</h1>
    <div class="toolbar-wrapper">
        <input type="text" id="userSearchInput" class="search-filter-input" placeholder="Search users by name, username..." onkeyup="filterUserTable()">
        <button onclick="openCreateUserModal()" class="btn btn-success"><i class="fas fa-user-plus"></i> Add New User</button>
    </div>
</div>

<?php if ($success): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div><?php endif; ?>

<!-- Users Data Table Card -->
<div class="card">
    <div class="card-header">
        <span><i class="fas fa-users"></i> System Accounts</span>
        <span style="font-size:0.75rem; color:#64748b; font-weight:500;"><?php echo count($users); ?> Registered Users</span>
    </div>
    <div class="card-body" style="padding:0;">
        <div class="table-container">
            <?php if (empty($users)): ?>
                <div style="text-align:center; padding:3rem 1rem; color:#94a3b8;">
                    <i class="fas fa-users-slash" style="font-size:1.75rem; margin-bottom:0.75rem; display:block;"></i>
                    No user accounts found in the database.
                </div>
            <?php else: ?>
                <table class="data-table" id="usersTable">
                    <thead>
                        <tr>
                            <th>User Account</th>
                            <th>Phone Number</th>
                            <th>System Role</th>
                            <th>Assigned Formation / Zone</th>
                            <th>Status</th>
                            <th>Last Activity</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($users as $ur): 
                            $rc = 'role-user';
                            if (stripos($ur['role_name'], 'Super Admin') !== false) $rc = 'role-superadmin';
                            elseif (stripos($ur['role_name'], 'Service HQ') !== false) $rc = 'role-servicehq';
                            elseif (stripos($ur['role_name'], 'Zone') !== false) $rc = 'role-zoneadmin';
                            
                            $userZonesData = [];
                            try {
                                $uzStmt = $pdo->prepare("SELECT uz.*, z.zone_name FROM user_zones uz JOIN zones z ON uz.zone_id = z.id WHERE uz.user_id = ?");
                                $uzStmt->execute([$ur['id']]);
                                $userZonesData = $uzStmt->fetchAll();
                            } catch (Exception $e) { $userZonesData = []; }
                            
                            $initials = strtoupper(substr($ur['full_name'], 0, 1));
                        ?>
                        <tr>
                            <td>
                                <div class="user-avatar-cell">
                                    <div class="avatar-icon"><?php echo $initials; ?></div>
                                    <div class="user-info-text">
                                        <span class="user-name-title"><?php echo htmlspecialchars($ur['full_name']); ?></span>
                                        <span class="user-sub-text">@<?php echo htmlspecialchars($ur['username']); ?></span>
                                    </div>
                                </div>
                            </td>
                            <td style="white-space:nowrap; font-size:0.775rem;">
                                <code style="background:#f1f5f9; padding:2px 6px; border-radius:4px; font-weight:500; color:#1a5632;"><?php echo htmlspecialchars(!empty($ur['phone']) ? $ur['phone'] : ($ur['email'] ?? 'N/A')); ?></code>
                            </td>
                            <td><span class="role-badge <?php echo $rc; ?>"><?php echo htmlspecialchars($ur['role_name']); ?></span></td>
                            <td>
                                <?php if (!empty($userZonesData)): ?>
                                    <?php foreach($userZonesData as $uz): ?>
                                        <div style="margin-bottom:0.15rem; font-size:0.75rem;">
                                            <strong style="color:#1a5632;"><?php echo htmlspecialchars($uz['zone_name']); ?></strong>
                                            <?php if (!empty($uz['assigned_command'])): ?>
                                                <span class="cmd-tag"><?php echo htmlspecialchars($uz['assigned_command']); ?></span>
                                            <?php else: ?>
                                                <span style="font-size:0.675rem; color:#94a3b8;">(All Commands)</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span style="color:#94a3b8; font-size:0.75rem;">All Formations (Unrestricted)</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-pill <?php echo $ur['status'] === 'active' ? 'active' : 'inactive'; ?>">
                                    <?php echo ucfirst($ur['status']); ?>
                                </span>
                            </td>
                            <td style="white-space:nowrap; font-size:0.75rem; color:#64748b;">
                                <?php echo $ur['last_login'] ? date('d M Y, H:i', strtotime($ur['last_login'])) : '<span style="color:#94a3b8;">Never Logged In</span>'; ?>
                            </td>
                            <td>
                                <div style="display:flex; gap:0.25rem; flex-wrap:wrap;">
                                    <?php if ($ur['status'] === 'active'): ?>
                                        <button class="btn-action-sm btn-danger" onclick="openModal('deactivate',<?php echo $ur['id']; ?>,'<?php echo htmlspecialchars($ur['username'],ENT_QUOTES); ?>')" title="Deactivate Account"><i class="fas fa-ban"></i></button>
                                    <?php else: ?>
                                        <button class="btn-action-sm btn-success" onclick="openModal('activate',<?php echo $ur['id']; ?>,'<?php echo htmlspecialchars($ur['username'],ENT_QUOTES); ?>')" title="Activate Account"><i class="fas fa-check"></i></button>
                                    <?php endif; ?>

                                    <button class="btn-action-sm" onclick="openRoleModal(<?php echo $ur['id']; ?>,'<?php echo htmlspecialchars($ur['username'],ENT_QUOTES); ?>',<?php echo $ur['role_id']; ?>)" title="Change Role"><i class="fas fa-user-tag"></i> Role</button>
                                    <button class="btn-action-sm" onclick="openZonesModal(<?php echo $ur['id']; ?>,'<?php echo htmlspecialchars($ur['username'],ENT_QUOTES); ?>')" title="Assign Formations"><i class="fas fa-map-marker-alt"></i> Zones</button>
                                    <button class="btn-action-sm" style="background:#1a5632; color:#ffffff;" onclick="openGeofenceModal(<?php echo $ur['id']; ?>,'<?php echo htmlspecialchars($ur['username'],ENT_QUOTES); ?>',<?php echo (int)($ur['geofence_enabled']??1); ?>,'<?php echo htmlspecialchars($ur['geofence_mode']??'strict',ENT_QUOTES); ?>','<?php echo htmlspecialchars($ur['custom_lat']??'',ENT_QUOTES); ?>','<?php echo htmlspecialchars($ur['custom_lng']??'',ENT_QUOTES); ?>','<?php echo htmlspecialchars($ur['custom_radius_meters']??'1000',ENT_QUOTES); ?>','<?php echo htmlspecialchars($ur['assigned_command']??'',ENT_QUOTES); ?>')" title="Configure Geofence"><i class="fas fa-street-view"></i> Fence</button>
                                    <button class="btn-action-sm" onclick="openModal('password',<?php echo $ur['id']; ?>,'<?php echo htmlspecialchars($ur['username'],ENT_QUOTES); ?>')" title="Reset Password"><i class="fas fa-key"></i></button>
                                    <button class="btn-action-sm" style="background:#7c3aed; color:#ffffff;" onclick="openModal('reset2fa',<?php echo $ur['id']; ?>,'<?php echo htmlspecialchars($ur['username'],ENT_QUOTES); ?>')" title="Reset Google Authenticator (2FA)"><i class="fas fa-shield-alt"></i></button>

                                    <?php if ($ur['id'] != $_SESSION['user_id']): ?>
                                        <button class="btn-action-sm btn-danger" onclick="openModal('delete',<?php echo $ur['id']; ?>,'<?php echo htmlspecialchars($ur['username'],ENT_QUOTES); ?>')" title="Delete Account"><i class="fas fa-trash"></i></button>
                                    <?php endif; ?>
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

<!-- AUDIT TRAIL LOG CARD -->
<?php 
$currentUserRole = $_SESSION['role_name'] ?? $user['role_name'] ?? '';
$isAuditAdmin = in_array(strtolower($currentUserRole), ['admin', 'super admin', 'shq admin', 'service hq', 'user']);
if ($isAuditAdmin): 
    $auditLogs = [];
    try {
        $auditLogs = $pdo->query("SELECT al.*, u.username, u.full_name as target_name FROM activity_logs al LEFT JOIN users u ON al.user_id = u.id ORDER BY al.created_at DESC LIMIT 200")->fetchAll();
    } catch (Exception $e) { $auditLogs = []; }
?>
<style>
@keyframes livePulse {
    0% { transform: scale(0.95); opacity: 0.8; }
    50% { transform: scale(1.15); opacity: 1; }
    100% { transform: scale(0.95); opacity: 0.8; }
}
.live-dot {
    width: 8px;
    height: 8px;
    background-color: #22c55e;
    border-radius: 50%;
    display: inline-block;
    animation: livePulse 1.5s infinite;
}
.btn-audit-page {
    background: #ffffff;
    color: #334155;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    padding: 0.3rem 0.65rem;
    font-size: 0.775rem;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.35rem;
    transition: all 0.15s ease;
}
.btn-audit-page:hover:not(:disabled) {
    background: #f1f5f9;
    border-color: #1a5632;
    color: #1a5632;
}
.btn-audit-page.active {
    background: #1a5632;
    color: #ffffff;
    border-color: #1a5632;
}
.btn-audit-page:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}
</style>
<div class="card" id="auditLogCard">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.75rem; padding: 0.85rem 1.25rem;">
        <div style="display:flex; align-items:center; gap:0.6rem; flex-wrap:wrap;">
            <span style="font-size:0.95rem; font-weight:700; color:#1e293b; display:flex; align-items:center; gap:0.4rem;">
                <i class="fas fa-history" style="color:#1a5632;"></i> Real-time System Activity Audit Log
            </span>
            <span id="auditLogCountBadge" style="background:#e0f2fe; color:#0369a1; font-size:0.75rem; font-weight:600; padding:0.2rem 0.65rem; border-radius:12px; border:1px solid #bae6fd;"><?php echo count($auditLogs); ?> Records</span>
                          
        </div>
        <div style="display:flex; align-items:center; gap:0.6rem; flex-wrap:wrap;">
            <div style="position:relative; width:260px;">
                <i class="fas fa-search" style="position:absolute; left:11px; top:50%; transform:translateY(-50%); color:#94a3b8; font-size:0.8rem;"></i>
                <input type="text" id="auditLogSearch" class="form-control" placeholder="Search Activity Audit Logs..." style="padding-left:32px; font-size:0.8rem; height:36px; border-radius:6px; border:1px solid #cbd5e1;" onkeyup="filterAuditLogs(this.value)">
            </div>
            <a href="api/fetch_audit_logs.php?export=csv" id="exportAuditCsvBtn" class="btn" style="background:#1a5632; color:#ffffff; font-size:0.75rem; font-weight:600; padding:0.45rem 0.85rem; display:inline-flex; align-items:center; gap:0.4rem; border-radius:6px; text-decoration:none; transition: all 0.2s;" title="Download Comprehensive Audit Logs CSV">
                <i class="fas fa-file-csv"></i> Download CSV
            </a>
        </div>
    </div>
    <div class="card-body" style="padding:0;">
        <div class="table-container" style="max-height: 520px; overflow-y: auto;">
            <table class="data-table" id="auditLogTable">
                <thead>
                    <tr style="position:sticky; top:0; background:#f8fafc; z-index:2; border-bottom:2px solid #cbd5e1;">
                        <th style="min-width:140px;">Timestamp</th>
                        <th style="min-width:130px;">Action</th>
                        <th style="min-width:160px;">User / Performed By</th>
                        <th>Activity Description</th>
                        <th style="min-width:110px;">IP Address</th>
                    </tr>
                </thead>
                <tbody id="auditLogTbody">
                    <?php if (empty($auditLogs)): ?>
                        <tr><td colspan="5" style="text-align:center; padding:2.5rem; color:#94a3b8;">No audit trail records logged yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($auditLogs as $log): ?>
                        <tr>
                            <td style="font-size:0.75rem; white-space:nowrap; font-weight:500;"><?php echo date('d M Y, H:i', strtotime($log['created_at'])); ?></td>
                            <td><span class="role-badge role-user" style="font-size:0.7rem; font-weight:600; text-transform:uppercase;"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $log['action']))); ?></span></td>
                            <td style="font-size:0.75rem;"><strong><?php echo htmlspecialchars(!empty($log['target_name']) ? ($log['target_name'] . ' (' . ($log['username'] ?? 'System') . ')') : ($log['username'] ?? 'System')); ?></strong></td>
                            <td style="font-size:0.75rem; color:#334155; line-height:1.4;"><?php echo htmlspecialchars($log['description']); ?></td>
                            <td style="font-size:0.7rem; color:#64748b; font-family:monospace;"><?php echo htmlspecialchars($log['ip_address'] ?? 'N/A'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer" id="auditLogPaginationFooter" style="display:flex; justify-content:space-between; align-items:center; padding:0.75rem 1.25rem; background:#f8fafc; border-top:1px solid #e2e8f0; flex-wrap:wrap; gap:0.75rem;">
        <div style="font-size:0.8rem; color:#64748b; font-weight:500;" id="auditPaginationInfo">
            Showing 1 to <?php echo min(50, count($auditLogs)); ?> of <?php echo count($auditLogs); ?> records
        </div>
        <div style="display:flex; align-items:center; gap:0.35rem;" id="auditPaginationControls">
            <!-- Dynamic Page Buttons and Arrows rendered via JavaScript -->
        </div>
    </div>
</div>
<?php endif; ?>

<!-- MODAL: CREATE USER -->
<div id="createUserModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-user-plus"></i> Create New User Account</h3>
            <button class="close-modal" onclick="closeM('createUserModal')">&times;</button>
        </div>
        <form method="POST" id="createUserForm">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <div class="modal-body">
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem;">
                    <div class="form-group">
                        <label class="form-label">Username (Service No) *</label>
                        <input type="text" name="username" class="form-control" required placeholder="e.g. 12345" pattern="[0-9]{4,5}" maxlength="5" minlength="4" title="Username must be numbers only (4 to 5 digits)" oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 5)">
                        <small style="color: #64748b; font-size: 0.7rem; display: block; margin-top: 3px;">Numeric only (4 to 5 digits, e.g. 12345)</small>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Full Name *</label>
                        <input type="text" name="full_name" class="form-control" required placeholder="e.g. Gift Dagogo">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Phone Number *</label>
                    <input type="text" name="phone" class="form-control" required placeholder="e.g. 08012345678" pattern="[0-9]{10,14}" maxlength="14" minlength="10" title="Phone number must be numbers only" oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                    <small style="color: #64748b; font-size: 0.7rem; display: block; margin-top: 3px;">Numeric only (e.g. 08012345678)</small>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem;">
                    <div class="form-group">
                        <label class="form-label">Password *</label>
                        <div style="position: relative;">
                            <input type="password" name="password" id="create_password" class="form-control" required minlength="8" pattern="(?=.*\d)(?=.*[a-z])(?=.*[A-Z])(?=.*[\W_]).{8,}" title="Password must be at least 8 characters and contain 1 uppercase letter, 1 lowercase letter, 1 number, and 1 special character" style="padding-right: 35px;">
                            <i class="fas fa-eye" onclick="togglePasswordVisibility('create_password', this)" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); cursor: pointer; color: #64748b; font-size: 0.9rem;" title="Toggle Password Visibility"></i>
                        </div>
                        <small style="color: #64748b; font-size: 0.68rem; display: block; margin-top: 3px;">Min. 8 chars (1 uppercase, 1 lowercase, 1 number, 1 special char)</small>
                        <button type="button" class="btn-action-sm" style="margin-top:0.4rem; font-size:0.7rem;" onclick="generateUserPassword()"><i class="fas fa-dice"></i> Generate Password</button>
                        <small style="color: #0369a1; font-size: 0.68rem; display: block; margin-top: 3px;"><i class="fas fa-info-circle"></i> The new user will be required to set their own password on first login.</small>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Confirm Password *</label>
                        <div style="position: relative;">
                            <input type="password" name="confirm_password" id="create_confirm_password" class="form-control" required minlength="8" pattern="(?=.*\d)(?=.*[a-z])(?=.*[A-Z])(?=.*[\W_]).{8,}" title="Password must be at least 8 characters and contain 1 uppercase letter, 1 lowercase letter, 1 number, and 1 special character" style="padding-right: 35px;">
                            <i class="fas fa-eye" onclick="togglePasswordVisibility('create_confirm_password', this)" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); cursor: pointer; color: #64748b; font-size: 0.9rem;" title="Toggle Password Visibility"></i>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Assign Role *</label>
                    <select name="role_id" class="form-control" required id="roleSelect" onchange="toggleZoneSection()">
                        <option value="">-- Select Role --</option>
                        <?php foreach($roles as $r): ?>
                            <option value="<?php echo $r['id']; ?>" data-role="<?php echo htmlspecialchars($r['name']); ?>"><?php echo htmlspecialchars($r['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div id="zoneSection" style="display:none; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:0.85rem; margin-top:0.5rem;">
                    <div class="form-group">
                        <label class="form-label">Assign Zone</label>
                        <select class="form-control" id="zoneSelect" onchange="updateCommands()">
                            <option value="">-- Select Zone --</option>
                            <?php foreach($zones as $z): ?>
                                <option value="<?php echo $z['id']; ?>" data-zone-name="<?php echo htmlspecialchars($z['zone_name']); ?>"><?php echo htmlspecialchars($z['zone_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="assigned_zone_id" id="assignedZoneId" value="">
                    </div>

                    <div class="form-group" style="margin-bottom:0;">
                        <label class="form-label">Specific Command (Optional)</label>
                        <select class="form-control" id="commandSelect" name="assigned_command" onchange="updateCommandSelection()">
                            <option value="">All Commands in Zone</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeM('createUserModal')">Cancel</button>
                <button type="submit" name="create_user" class="btn btn-success"><i class="fas fa-check"></i> Create Account</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: ACTIVATE -->
<div id="activateModal" class="modal">
    <div class="modal-content">
        <div class="modal-header"><h3>Activate User Account</h3><button class="close-modal" onclick="closeM('activateModal')">&times;</button></div>
        <form method="POST"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>"><div class="modal-body"><p style="font-size:0.85rem;">Are you sure you want to activate account: <strong id="activateName"></strong>?</p><input type="hidden" name="user_id" id="activateId"></div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeM('activateModal')">Cancel</button><button type="submit" name="activate_user" class="btn btn-success">Activate</button></div></form>
    </div>
</div>

<!-- MODAL: DEACTIVATE -->
<div id="deactivateModal" class="modal">
    <div class="modal-content">
        <div class="modal-header"><h3>Deactivate User Account</h3><button class="close-modal" onclick="closeM('deactivateModal')">&times;</button></div>
        <form method="POST"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>"><div class="modal-body"><p style="font-size:0.85rem;">Are you sure you want to deactivate account: <strong id="deactivateName"></strong>?</p><input type="hidden" name="user_id" id="deactivateId"></div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeM('deactivateModal')">Cancel</button><button type="submit" name="deactivate_user" class="btn btn-action-sm btn-danger">Deactivate</button></div></form>
    </div>
</div>

<!-- MODAL: DELETE -->
<div id="deleteModal" class="modal">
    <div class="modal-content">
        <div class="modal-header"><h3>Delete Account Permanently</h3><button class="close-modal" onclick="closeM('deleteModal')">&times;</button></div>
        <form method="POST"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>"><div class="modal-body"><p style="font-size:0.85rem; color:#dc3545;">Warning: Deleting account <strong id="deleteName"></strong> is permanent and cannot be undone.</p><input type="hidden" name="user_id" id="deleteId"></div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeM('deleteModal')">Cancel</button><button type="submit" name="delete_user" class="btn btn-action-sm btn-danger">Delete Account</button></div></form>
    </div>
</div>

<!-- MODAL: ROLE UPDATE -->
<div id="roleModal" class="modal">
    <div class="modal-content">
        <div class="modal-header"><h3>Change Account Role</h3><button class="close-modal" onclick="closeM('roleModal')">&times;</button></div>
        <form method="POST"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>"><div class="modal-body"><p style="font-size:0.85rem; margin-bottom:0.75rem;">Change role for: <strong id="roleName"></strong></p><input type="hidden" name="user_id" id="roleId"><select name="role_id" class="form-control" required><option value="">Select Role</option><?php foreach($roles as $r): ?><option value="<?php echo $r['id']; ?>"><?php echo htmlspecialchars($r['name']); ?></option><?php endforeach; ?></select></div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeM('roleModal')">Cancel</button><button type="submit" name="update_role" class="btn btn-success">Save Role</button></div></form>
    </div>
</div>

<!-- MODAL: ZONES UPDATE -->
<div id="zonesModal" class="modal">
    <div class="modal-content">
        <div class="modal-header"><h3>Update Zones & Command Access</h3><button class="close-modal" onclick="closeM('zonesModal')">&times;</button></div>
        <form method="POST"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <div class="modal-body">
                <p style="font-size:0.85rem; margin-bottom:0.75rem;">Assign Formations for: <strong id="zonesName"></strong></p>
                <input type="hidden" name="user_id" id="zonesId">
                <div style="display:grid; grid-template-columns:1fr; gap:0.5rem; max-height:350px; overflow-y:auto;">
                    <?php foreach($zones as $z): 
                        $zoneKey = '';
                        foreach($nis_formations as $zk => $cmds) { if(stripos($z['zone_name'], $zk) !== false) { $zoneKey = $zk; break; } }
                    ?>
                    <div style="display:flex; align-items:center; gap:0.5rem; padding:0.5rem; border:1px solid #e2e8f0; border-radius:6px; flex-wrap:wrap;">
                        <label style="display:flex; align-items:center; gap:0.4rem; cursor:pointer; font-size:0.75rem; min-width:130px;">
                            <input type="checkbox" name="zones[]" value="<?php echo $z['id']; ?>" class="zone-cb" onchange="toggleModalCommand(this)">
                            <span><?php echo htmlspecialchars($z['zone_name']); ?></span>
                        </label>
                        <?php if(!empty($zoneKey) && isset($nis_formations[$zoneKey])): ?>
                        <select name="command_<?php echo $z['id']; ?>" class="form-control modal-command-dropdown" style="flex:1; min-width:140px; font-size:0.725rem; padding:0.25rem 0.5rem;" disabled>
                            <option value="">All Commands in Zone</option>
                            <?php foreach($nis_formations[$zoneKey] as $code => $name): ?>
                            <option value="<?php echo htmlspecialchars($name); ?>"><?php echo htmlspecialchars($name); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeM('zonesModal')">Cancel</button>
                <button type="submit" name="update_zones" class="btn btn-success">Save Access</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: PASSWORD RESET -->
<div id="passwordModal" class="modal">
    <div class="modal-content">
        <div class="modal-header"><h3>Reset Account Password</h3><button class="close-modal" onclick="closeM('passwordModal')">&times;</button></div>
        <form method="POST"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>"><div class="modal-body"><p style="font-size:0.85rem;">Reset password for: <strong id="passwordName"></strong>?</p><p style="font-size:0.75rem; color:#dc3545; margin-top:0.25rem;">Password will be reset to default: <strong>password123</strong></p><input type="hidden" name="user_id" id="passwordId"></div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeM('passwordModal')">Cancel</button><button type="submit" name="reset_password" class="btn btn-action-sm btn-danger">Reset Password</button></div></form>
    </div>
</div>

<!-- MODAL: RESET GOOGLE AUTHENTICATOR (2FA) -->
<div id="reset2faModal" class="modal">
    <div class="modal-content">
        <div class="modal-header"><h3>Reset Google Authenticator (2FA)</h3><button class="close-modal" onclick="closeM('reset2faModal')">&times;</button></div>
        <form method="POST"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>"><div class="modal-body"><p style="font-size:0.85rem;">Reset Google Authenticator for: <strong id="reset2faName"></strong>?</p><p style="font-size:0.75rem; color:#dc3545; margin-top:0.25rem;">This clears their current 2FA setup. They will be required to scan a new QR code and re-register their authenticator app on their next login. Use this if the officer has lost their phone or can no longer generate codes.</p><input type="hidden" name="user_id" id="reset2faId"></div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeM('reset2faModal')">Cancel</button><button type="submit" name="reset_2fa" class="btn btn-action-sm btn-danger">Reset 2FA</button></div></form>
    </div>
</div>

<script>
var formations = <?php echo json_encode($nis_formations); ?>;
var rolesForZone = <?php echo json_encode(['Zonal Admin', 'Zonal User', 'Command Admin', 'Command User']); ?>;

function filterUserTable() {
    var input = document.getElementById('userSearchInput');
    var filter = input.value.toLowerCase();
    var rows = document.querySelectorAll('#usersTable tbody tr');
    
    rows.forEach(function(row) {
        var text = row.textContent.toLowerCase();
        row.style.display = text.indexOf(filter) > -1 ? '' : 'none';
    });
}

function togglePasswordVisibility(inputId, icon) {
    var input = document.getElementById(inputId);
    if (!input) return;
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    }
}

function generateUserPassword() {
    var lower = 'abcdefghijkmnpqrstuvwxyz';
    var upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    var digits = '23456789';
    var special = '!@#$%^&*-_';
    var all = lower + upper + digits + special;

    function randChar(chars) {
        var arr = new Uint32Array(1);
        window.crypto.getRandomValues(arr);
        return chars[arr[0] % chars.length];
    }

    // Guarantee at least one of each required character class, then fill the rest.
    var chars = [randChar(lower), randChar(upper), randChar(digits), randChar(special)];
    for (var i = chars.length; i < 12; i++) {
        chars.push(randChar(all));
    }
    // Shuffle so the guaranteed characters aren't always in the same position.
    for (var j = chars.length - 1; j > 0; j--) {
        var k = Math.floor(Math.random() * (j + 1));
        var tmp = chars[j]; chars[j] = chars[k]; chars[k] = tmp;
    }
    var password = chars.join('');

    var pwdField = document.getElementById('create_password');
    var confirmField = document.getElementById('create_confirm_password');
    pwdField.type = 'text';
    confirmField.type = 'text';
    pwdField.value = password;
    confirmField.value = password;
    pwdField.select();
}

function openCreateUserModal() {
    document.getElementById('createUserModal').style.display = 'block';
}

function toggleZoneSection(){
    var role = document.getElementById('roleSelect').selectedOptions[0].getAttribute('data-role') || '';
    document.getElementById('zoneSection').style.display = rolesForZone.indexOf(role) !== -1 ? 'block' : 'none';
}

function updateCommands(){
    var zoneName = document.getElementById('zoneSelect').selectedOptions[0].getAttribute('data-zone-name') || '';
    var zoneId = document.getElementById('zoneSelect').value;
    var cmd = document.getElementById('commandSelect');
    
    document.getElementById('assignedZoneId').value = zoneId;
    cmd.innerHTML = '<option value="">All Commands in Zone</option>';
    if(!zoneName) return;
    
    for(var zone in formations){
        if(zone.toUpperCase().indexOf(zoneName.toUpperCase()) !== -1){
            var commands = formations[zone];
            for(var code in commands){ 
                cmd.innerHTML += '<option value="'+commands[code]+'">'+commands[code]+'</option>'; 
            }
            break;
        }
    }
}

function toggleModalCommand(checkbox) {
    var container = checkbox.closest('div');
    var dropdown = container.querySelector('.modal-command-dropdown');
    if (dropdown) {
        dropdown.disabled = !checkbox.checked;
        if (!checkbox.checked) dropdown.value = '';
    }
}

function openModal(type, id, name){
    document.getElementById(type+'Id').value = id;
    document.getElementById(type+'Name').textContent = name;
    document.getElementById(type+'Modal').style.display = 'block';
}
function openRoleModal(id, name, rid){
    document.getElementById('roleId').value = id;
    document.getElementById('roleName').textContent = name;
    document.getElementById('roleModal').style.display = 'block';
}
function openZonesModal(id, name){
    document.getElementById('zonesId').value = id;
    document.getElementById('zonesName').textContent = name;
    
    document.querySelectorAll('.zone-cb').forEach(function(cb){cb.checked = false;});
    document.querySelectorAll('.modal-command-dropdown').forEach(function(dd){dd.disabled = true; dd.value = '';});
    
    fetch('get_user_zones.php?user_id='+id).then(function(r){return r.json();}).then(function(zones){
        zones.forEach(function(z){
            var cb = document.querySelector('.zone-cb[value="'+z.zone_id+'"]');
            if(cb) {
                cb.checked = true;
                var container = cb.closest('div');
                var dropdown = container.querySelector('.modal-command-dropdown');
                if (dropdown) {
                    dropdown.disabled = false;
                    if (z.assigned_command) dropdown.value = z.assigned_command;
                }
            }
        });
    });
    document.getElementById('zonesModal').style.display = 'block';
}

function closeM(id){ document.getElementById(id).style.display = 'none'; }
window.onclick = function(e){ if(e.target.classList.contains('modal')) e.target.style.display = 'none'; }

// ----------------------------------------------------
// REAL-TIME AUDIT LOG POLLING & PAGINATION (50 RECORDS / PAGE)
// ----------------------------------------------------
let currentAuditPage = 1;
let auditSearchDebounce = null;

function fetchLiveAuditLogs(page = currentAuditPage) {
    if (page) currentAuditPage = page;
    const searchInput = document.getElementById('auditLogSearch');
    const searchQuery = searchInput ? searchInput.value.trim() : '';
    const exportBtn = document.getElementById('exportAuditCsvBtn');
    
    if (exportBtn) {
        exportBtn.href = 'api/fetch_audit_logs.php?export=csv' + (searchQuery ? '&q=' + encodeURIComponent(searchQuery) : '');
    }

    const apiUrl = `api/fetch_audit_logs.php?page=${currentAuditPage}` + (searchQuery ? '&q=' + encodeURIComponent(searchQuery) : '');

    fetch(apiUrl)
        .then(response => response.json())
        .then(data => {
            if (data.success && Array.isArray(data.logs)) {
                renderAuditLogsTable(data.logs);
                renderAuditPagination(data);
            }
        })
        .catch(err => console.error('Audit Log real-time polling error:', err));
}

function filterAuditLogs(query) {
    clearTimeout(auditSearchDebounce);
    auditSearchDebounce = setTimeout(() => {
        currentAuditPage = 1;
        fetchLiveAuditLogs(1);
    }, 250);
}

function changeAuditPage(newPage) {
    if (newPage < 1) return;
    currentAuditPage = newPage;
    fetchLiveAuditLogs(newPage);
}

function renderAuditPagination(data) {
    const countBadge = document.getElementById('auditLogCountBadge');
    const infoDiv = document.getElementById('auditPaginationInfo');
    const controlsDiv = document.getElementById('auditPaginationControls');

    const totalRecords = data.total_records || 0;
    const totalPages = data.total_pages || 1;
    const currentPage = data.current_page || 1;
    const perPage = data.per_page || 50;

    if (countBadge) {
        countBadge.textContent = totalRecords + ' Records';
    }

    if (infoDiv) {
        if (totalRecords === 0) {
            infoDiv.textContent = 'Showing 0 records';
        } else {
            const startRec = ((currentPage - 1) * perPage) + 1;
            const endRec = Math.min(currentPage * perPage, totalRecords);
            infoDiv.innerHTML = `Showing <strong>${startRec}</strong> to <strong>${endRec}</strong> of <strong>${totalRecords}</strong> records (Page ${currentPage} of ${totalPages})`;
        }
    }

    if (!controlsDiv) return;

    if (totalPages <= 1) {
        controlsDiv.innerHTML = '';
        return;
    }

    let html = '';

    // Previous Arrow Button (<)
    const prevDisabled = currentPage <= 1 ? 'disabled' : '';
    html += `<button class="btn-audit-page" ${prevDisabled} onclick="changeAuditPage(${currentPage - 1})" title="Previous Page"><i class="fas fa-chevron-left"></i> Prev</button>`;

    // Page Number Buttons
    let startPage = Math.max(1, currentPage - 2);
    let endPage = Math.min(totalPages, startPage + 4);
    if (endPage - startPage < 4) {
        startPage = Math.max(1, endPage - 4);
    }

    if (startPage > 1) {
        html += `<button class="btn-audit-page" onclick="changeAuditPage(1)">1</button>`;
        if (startPage > 2) {
            html += `<span style="font-size:0.8rem; color:#94a3b8; padding:0 3px;">...</span>`;
        }
    }

    for (let p = startPage; p <= endPage; p++) {
        const activeClass = p === currentPage ? 'active' : '';
        html += `<button class="btn-audit-page ${activeClass}" onclick="changeAuditPage(${p})">${p}</button>`;
    }

    if (endPage < totalPages) {
        if (endPage < totalPages - 1) {
            html += `<span style="font-size:0.8rem; color:#94a3b8; padding:0 3px;">...</span>`;
        }
        html += `<button class="btn-audit-page" onclick="changeAuditPage(${totalPages})">${totalPages}</button>`;
    }

    // Next Arrow Button (>)
    const nextDisabled = currentPage >= totalPages ? 'disabled' : '';
    html += `<button class="btn-audit-page" ${nextDisabled} onclick="changeAuditPage(${currentPage + 1})" title="Next Page">Next <i class="fas fa-chevron-right"></i></button>`;

    controlsDiv.innerHTML = html;
}

function renderAuditLogsTable(logs) {
    const tbody = document.getElementById('auditLogTbody');
    if (!tbody) return;

    if (logs.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:2.5rem; color:#94a3b8;"><i class="fas fa-search" style="font-size:1.8rem; margin-bottom:0.5rem; display:block;"></i>No matching activity audit records found.</td></tr>';
        return;
    }

    let html = '';
    logs.forEach(log => {
        const actionFormatted = (log.action || '').replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
        const userDisplay = log.target_name ? (log.target_name + ' (' + (log.username || 'System') + ')') : (log.username || 'System');
        
        html += `<tr>
            <td style="font-size:0.75rem; white-space:nowrap; font-weight:500;">${escapeAuditHtml(log.formatted_date)}</td>
            <td><span class="role-badge role-user" style="font-size:0.7rem; font-weight:600; text-transform:uppercase;">${escapeAuditHtml(actionFormatted)}</span></td>
            <td style="font-size:0.75rem;"><strong>${escapeAuditHtml(userDisplay)}</strong></td>
            <td style="font-size:0.75rem; color:#334155; line-height:1.4;">${escapeAuditHtml(log.description || '')}</td>
            <td style="font-size:0.7rem; color:#64748b; font-family:monospace;">${escapeAuditHtml(log.ip_address || 'N/A')}</td>
        </tr>`;
    });

    tbody.innerHTML = html;
}

function escapeAuditHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

// Start real-time polling every 5 seconds
document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('auditLogCard')) {
        fetchLiveAuditLogs(1);
        setInterval(fetchLiveAuditLogs, 5000);
    }
});
</script>

<!-- MODAL: GEOFENCE CONFIGURATION -->
<div id="geofenceModal" class="modal">
    <div class="modal-content" style="max-width:620px;">
        <div class="modal-header" style="background:#1a5632; color:#fff;">
            <h3><i class="fas fa-map-marked-alt"></i> User Geofence Configuration</h3>
            <button class="close-modal" onclick="closeM('geofenceModal')">&times;</button>
        </div>
        <form method="POST"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <input type="hidden" name="save_user_geofence" value="1">
            <input type="hidden" name="user_id" id="geofenceUserId">
            <div class="modal-body" style="padding:1.25rem;">
                <div style="font-size:0.85rem; font-weight:700; color:#1e293b; margin-bottom:0.75rem; display:flex; justify-content:space-between; align-items:center;">
                    <span>Officer Account: <strong id="geofenceUserName" style="color:#1a5632;"></strong></span>
                    <span id="geofenceCommandBadge" style="font-size:0.7rem; background:#e0f2fe; color:#0369a1; padding:2px 8px; border-radius:10px; font-weight:600;"></span>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem; margin-bottom:0.85rem;">
                    <div class="form-group">
                        <label class="form-label" style="font-weight:600;">Enable Geofence Checks</label>
                        <select name="geofence_enabled" id="geofenceEnabledSelect" class="form-control">
                            <option value="1">Enabled (Active)</option>
                            <option value="0">Disabled (Bypass All Checks)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" style="font-weight:600;">Enforcement Mode</label>
                        <select name="geofence_mode" id="geofenceModeSelect" class="form-control">
                            <option value="strict">Strict (Block access when outside fence)</option>
                            <option value="audit_only">Audit Only (Allow access but log warning flag)</option>
                            <option value="disabled">Exempt / Disabled</option>
                        </select>
                    </div>
                </div>

                <!-- Leaflet Interactive Map Picker -->
                <div style="margin-bottom:0.85rem;">
                    <label class="form-label" style="font-weight:600; display:flex; justify-content:space-between;">
                        <span>Target Geofence Center (Click Map or Drag Marker to Set)</span>
                        <span style="font-weight:500; font-size:0.7rem; color:#64748b;">Leaflet OpenStreetMap</span>
                    </label>
                    <div style="display:flex; gap:0.4rem; margin-bottom:0.4rem;">
                        <input type="text" id="geofenceSearchInput" class="form-control" placeholder="Search a place or command name (e.g. Lagos Zonal Command)..." style="flex:1; font-size:0.75rem;" onkeydown="if(event.key==='Enter'){event.preventDefault();searchGeofenceLocation();}">
                        <button type="button" class="btn-action-sm" onclick="searchGeofenceLocation()" title="Search Location"><i class="fas fa-search"></i></button>
                        <button type="button" class="btn-action-sm" onclick="useMyCurrentLocation()" title="Use My Current Location"><i class="fas fa-crosshairs"></i></button>
                    </div>
                    <div id="geofenceSearchStatus" style="font-size:0.7rem; color:#64748b; margin-bottom:0.35rem; display:none;"></div>
                    <div id="geofenceMap" style="height:240px; width:100%; border-radius:8px; border:1px solid #cbd5e1; background:#f8fafc; z-index:1;"></div>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:0.6rem; margin-bottom:0.85rem;">
                    <div class="form-group">
                        <label class="form-label">Latitude</label>
                        <input type="number" step="any" name="custom_lat" id="geofenceLat" class="form-control" placeholder="e.g. 9.0765" onchange="updateMapFromInputs()">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Longitude</label>
                        <input type="number" step="any" name="custom_lng" id="geofenceLng" class="form-control" placeholder="e.g. 7.3986" onchange="updateMapFromInputs()">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Allowed Radius (Meters)</label>
                        <input type="number" name="custom_radius_meters" id="geofenceRadius" class="form-control" placeholder="1000" min="100" max="50000" value="1000" oninput="updateMapFromInputs()">
                    </div>
                </div>

                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:0.6rem; font-size:0.725rem; color:#64748b;">
                    <i class="fas fa-info-circle" style="color:#0284c7;"></i> Leave Latitude and Longitude empty to automatically inherit coordinates from the officer's assigned Command/Formation.
                </div>
            </div>
            <div class="modal-footer" style="padding:0.75rem 1.25rem; background:#f8fafc; border-top:1px solid #e2e8f0; display:flex; justify-content:flex-end; gap:0.5rem;">
                <button type="button" class="btn-reset" onclick="closeM('geofenceModal')">Cancel</button>
                <button type="submit" class="btn-primary-action" style="background:#1a5632; color:#fff; border:none; padding:0.5rem 1rem; border-radius:6px; font-weight:600; cursor:pointer;"><i class="fas fa-save"></i> Save Geofence</button>
            </div>
        </form>
    </div>
</div>

<script>
let gMap = null;
let gMarker = null;
let gCircle = null;

function openGeofenceModal(userId, username, enabled, mode, lat, lng, radius, command) {
    document.getElementById('geofenceUserId').value = userId;
    document.getElementById('geofenceUserName').textContent = '@' + username;
    document.getElementById('geofenceCommandBadge').textContent = command || 'Service HQ';
    document.getElementById('geofenceEnabledSelect').value = enabled;
    document.getElementById('geofenceModeSelect').value = mode;
    document.getElementById('geofenceLat').value = lat || '';
    document.getElementById('geofenceLng').value = lng || '';
    document.getElementById('geofenceRadius').value = radius || '1000';

    var searchInput = document.getElementById('geofenceSearchInput');
    if (searchInput) searchInput.value = '';
    setGeofenceStatus('');

    document.getElementById('geofenceModal').style.display = 'block';

    // Two staggered inits: Leaflet needs the container to have real, settled
    // dimensions before L.map() reads them, otherwise tiles render grey/blank
    // until the user manually drags or zooms. The modal has no CSS transition,
    // but layout can still take a frame or two to settle after display:block.
    setTimeout(() => {
        initGeofenceMap(lat || 9.0765, lng || 7.3986, radius || 1000);
    }, 150);
    setTimeout(() => {
        if (gMap) gMap.invalidateSize();
    }, 450);
}

function setGeofenceStatus(message, isError) {
    var el = document.getElementById('geofenceSearchStatus');
    if (!el) return;
    if (!message) {
        el.style.display = 'none';
        el.textContent = '';
        return;
    }
    el.style.display = 'block';
    el.style.color = isError ? '#dc2626' : '#64748b';
    el.textContent = message;
}

function initGeofenceMap(startLat, startLng, startRadius) {
    startLat = parseFloat(startLat) || 9.0765;
    startLng = parseFloat(startLng) || 7.3986;
    startRadius = parseInt(startRadius) || 1000;

    if (!gMap) {
        gMap = L.map('geofenceMap', { scrollWheelZoom: false }).setView([startLat, startLng], 13);

        var streetLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '© OpenStreetMap'
        }).addTo(gMap);

        var satelliteLayer = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
            maxZoom: 19,
            attribution: 'Tiles © Esri — Source: Esri, i-cubed, USDA, USGS, AEX, GeoEye, Getmapping, Aerogrid, IGN, IGP, UPR-EGP, GIS User Community'
        });

        var labelsOverlay = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile/{z}/{y}/{x}', {
            maxZoom: 19,
            attribution: 'Labels © Esri'
        });

        L.control.layers(
            { 'Street': streetLayer, 'Satellite': satelliteLayer },
            { 'Labels (Hybrid)': labelsOverlay },
            { position: 'topright', collapsed: true }
        ).addTo(gMap);

        gMap.on('click', function(e) {
            document.getElementById('geofenceLat').value = e.latlng.lat.toFixed(6);
            document.getElementById('geofenceLng').value = e.latlng.lng.toFixed(6);
            updateMapFromInputs();
        });
    } else {
        gMap.setView([startLat, startLng], 13);
    }

    // Always resync Leaflet's internal size cache with the actual container
    // size — required on first creation too, since the map container was
    // hidden (display:none) a moment before L.map() ran.
    gMap.invalidateSize();

    if (gMarker) gMap.removeLayer(gMarker);
    if (gCircle) gMap.removeLayer(gCircle);

    gMarker = L.marker([startLat, startLng], {draggable: true}).addTo(gMap);
    gCircle = L.circle([startLat, startLng], {
        radius: startRadius,
        color: '#1a5632',
        fillColor: '#22c55e',
        fillOpacity: 0.2
    }).addTo(gMap);

    gMarker.on('dragend', function(e) {
        var pos = gMarker.getLatLng();
        document.getElementById('geofenceLat').value = pos.lat.toFixed(6);
        document.getElementById('geofenceLng').value = pos.lng.toFixed(6);
        gCircle.setLatLng(pos);
    });
}

function updateMapFromInputs() {
    var lat = parseFloat(document.getElementById('geofenceLat').value) || 9.0765;
    var lng = parseFloat(document.getElementById('geofenceLng').value) || 7.3986;
    var rad = parseInt(document.getElementById('geofenceRadius').value) || 1000;

    if (gMap && gMarker && gCircle) {
        var newPos = [lat, lng];
        gMarker.setLatLng(newPos);
        gCircle.setLatLng(newPos);
        gCircle.setRadius(rad);
        gMap.panTo(newPos);
    }
}

function searchGeofenceLocation() {
    var query = (document.getElementById('geofenceSearchInput').value || '').trim();
    if (!query) {
        setGeofenceStatus('Type a place name to search.', true);
        return;
    }

    setGeofenceStatus('Searching...');

    var url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&countrycodes=ng&q=' + encodeURIComponent(query);
    fetch(url, { headers: { 'Accept': 'application/json' } })
        .then(function(res) { return res.json(); })
        .then(function(results) {
            if (!results || results.length === 0) {
                setGeofenceStatus('No location found for "' + query + '". Try a more specific name.', true);
                return;
            }
            var lat = parseFloat(results[0].lat);
            var lng = parseFloat(results[0].lon);
            document.getElementById('geofenceLat').value = lat.toFixed(6);
            document.getElementById('geofenceLng').value = lng.toFixed(6);
            if (gMap) gMap.setView([lat, lng], 14);
            updateMapFromInputs();
            setGeofenceStatus('Found: ' + (results[0].display_name || query));
        })
        .catch(function() {
            setGeofenceStatus('Location search failed. Check your internet connection and try again.', true);
        });
}

function useMyCurrentLocation() {
    if (!navigator.geolocation) {
        setGeofenceStatus('Geolocation is not supported by this browser.', true);
        return;
    }
    setGeofenceStatus('Getting your current location...');
    navigator.geolocation.getCurrentPosition(function(pos) {
        var lat = pos.coords.latitude;
        var lng = pos.coords.longitude;
        document.getElementById('geofenceLat').value = lat.toFixed(6);
        document.getElementById('geofenceLng').value = lng.toFixed(6);
        if (gMap) gMap.setView([lat, lng], 15);
        updateMapFromInputs();
        setGeofenceStatus('Map centered on your current location.');
    }, function(err) {
        setGeofenceStatus('Could not get your location: ' + (err.message || 'permission denied.'), true);
    }, { enableHighAccuracy: true, timeout: 10000 });
}
</script>

</div><!-- close main-content -->
</div><!-- close content-wrapper -->
</div><!-- close dashboard-container -->

</body>
</html>
<?php include 'includes/footer.php'; ?>