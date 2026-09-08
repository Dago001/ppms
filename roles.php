<?php
// roles.php
// Master Role & Granular Permission Management Portal (Admin & Super Admin Exclusive)

session_start();
require_once 'includes/config.php';
require_once 'includes/security.php';
require_once 'includes/permissions.php';
require_once 'includes/settings_helper.php';

// Access Control: RESTRICTED STRICTLY BY HASPERMISSION('ROLES_PERMISSIONS')
if (!hasPermission('roles_permissions')) {
    header("HTTP/1.1 403 Forbidden");
    echo "<div style='font-family:Segoe UI, sans-serif; text-align:center; padding:3rem; background:#0f172a; color:#fff; min-height:100vh; display:flex; align-items:center; justify-content:center;'>
            <div style='background:#1e293b; border-radius:16px; padding:3rem 2rem; max-width:550px; border:1px solid rgba(255,255,255,0.1); box-shadow:0 25px 50px -12px rgba(0,0,0,0.5);'>
                <div style='font-size:3rem; margin-bottom:1rem;'>🔒</div>
                <h1 style='color:#ef4444; margin-bottom:0.75rem;'>403 Forbidden - Access Denied</h1>
                <p style='color:#94a3b8; line-height:1.6;'>You do not have permission ('roles_permissions') to access the Role & Permission Management Portal.</p>
                <a href='dashboard' style='display:inline-block; margin-top:1.5rem; padding:0.6rem 1.25rem; background:#1a5632; color:#fff; text-decoration:none; border-radius:8px; font-weight:600;'>Return to Dashboard</a>
            </div>
          </div>";
    exit();
}

$page_title = "Role & Permission Management";
$message = "";
$messageType = "";

// Ensure CSRF Token is initialized early
$csrfToken = generateCSRFToken();

// Ensure permissions tables exist
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS permissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        permission_key VARCHAR(60) NOT NULL UNIQUE,
        permission_name VARCHAR(100) NOT NULL,
        category VARCHAR(50) DEFAULT 'General',
        description VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS role_permissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        role_id INT NOT NULL,
        permission_key VARCHAR(60) NOT NULL,
        is_granted TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_role_perm (role_id, permission_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS user_permissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        permission_key VARCHAR(60) NOT NULL,
        is_granted TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_user_perm (user_id, permission_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Exception $e) {}

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = "Security validation failed (invalid or expired session token). Please refresh the page and try again.";
        $messageType = "danger";
        $action = ''; // Block every action below from executing on a failed CSRF check
    }

    // 1. SAVE PER-USER ROLE AND GRANULAR PERMISSIONS (GIVE OR DENY)
    if ($action === 'save_user_permissions') {
        $targetUserId = intval($_POST['target_user_id'] ?? 0);
        $newRoleId = intval($_POST['role_id'] ?? 0);
        $newStatus = trim($_POST['status'] ?? 'active');
        $grantedKeys = $_POST['granted_permissions'] ?? [];

        if ($targetUserId <= 0) {
            $message = "Invalid Target User selected.";
            $messageType = "danger";
        } elseif ($targetUserId === (int)($_SESSION['user_id'] ?? 0) && $newStatus !== 'active') {
            $message = "You cannot deactivate or suspend your own account while logged in.";
            $messageType = "danger";
        } else {
            try {
                $pdo->beginTransaction();

                // Update User Role & Account Status
                if ($newRoleId > 0) {
                    $stmtUpdateUser = $pdo->prepare("UPDATE users SET role_id = ?, status = ? WHERE id = ?");
                    $stmtUpdateUser->execute([$newRoleId, $newStatus, $targetUserId]);
                }

                // Fetch all registered permissions
                $allPerms = $pdo->query("SELECT permission_key FROM permissions")->fetchAll(PDO::FETCH_COLUMN);

                // Process each permission explicitly as Granted (1) or Denied (0)
                $stmtUserPerm = $pdo->prepare("INSERT INTO user_permissions (user_id, permission_key, is_granted) 
                    VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted)");

                foreach ($allPerms as $pk) {
                    $isGranted = in_array($pk, $grantedKeys) ? 1 : 0;
                    $stmtUserPerm->execute([$targetUserId, $pk, $isGranted]);
                }

                $pdo->commit();

                // Refresh current session role if updating self
                if ($targetUserId == ($_SESSION['user_id'] ?? 0)) {
                    $_SESSION['role_id'] = $newRoleId;
                    $rStmt = $pdo->prepare("SELECT name FROM roles WHERE id = ?");
                    $rStmt->execute([$newRoleId]);
                    $rName = $rStmt->fetchColumn();
                    if ($rName) $_SESSION['role_name'] = $rName;
                }

                $message = "Role and Granular Permissions updated successfully for User ID #{$targetUserId}!";
                $messageType = "success";
            } catch (Exception $e) {
                $pdo->rollBack();
                $message = "Error updating permissions: " . $e->getMessage();
                $messageType = "danger";
            }
        }
    }

    // 2. RESET USER PERMISSIONS TO DEFAULT ROLE MATRIX
    elseif ($action === 'reset_user_permissions') {
        $targetUserId = intval($_POST['target_user_id'] ?? 0);
        if ($targetUserId > 0) {
            $pdo->prepare("DELETE FROM user_permissions WHERE user_id = ?")->execute([$targetUserId]);
            $message = "Explicit permission overrides reset. User ID #{$targetUserId} now inherits default role permissions.";
            $messageType = "success";
        }
    }

    // 3. SAVE BASELINE ROLE PERMISSIONS MATRIX
    elseif ($action === 'save_role_permissions') {
        $roleId = intval($_POST['role_id'] ?? 0);
        $grantedKeys = $_POST['role_permissions'] ?? [];

        if ($roleId > 0) {
            try {
                $pdo->beginTransaction();
                $allPerms = $pdo->query("SELECT permission_key FROM permissions")->fetchAll(PDO::FETCH_COLUMN);
                
                $stmtRolePerm = $pdo->prepare("INSERT INTO role_permissions (role_id, permission_key, is_granted) 
                    VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted)");

                foreach ($allPerms as $pk) {
                    $isGranted = in_array($pk, $grantedKeys) ? 1 : 0;
                    $stmtRolePerm->execute([$roleId, $pk, $isGranted]);
                }

                $pdo->commit();
                $message = "Baseline permissions matrix updated successfully for Role ID #{$roleId}!";
                $messageType = "success";
            } catch (Exception $e) {
                $pdo->rollBack();
                $message = "Error updating role matrix: " . $e->getMessage();
                $messageType = "danger";
            }
        }
    }

    // 4. REGISTER NEW SYSTEM PERMISSION
    elseif ($action === 'create_permission') {
        $permKey = strtolower(trim(preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['permission_key'] ?? '')));
        $permName = trim($_POST['permission_name'] ?? '');
        $category = trim($_POST['category'] ?? 'General');
        $desc = trim($_POST['description'] ?? '');

        if (empty($permKey) || empty($permName)) {
            $message = "Permission key and display name are required.";
            $messageType = "danger";
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO permissions (permission_key, permission_name, category, description) VALUES (?, ?, ?, ?)");
                $stmt->execute([$permKey, $permName, $category, $desc]);
                $message = "New system permission '$permName' ($permKey) registered successfully!";
                $messageType = "success";
            } catch (Exception $e) {
                $message = "Error registering permission: " . $e->getMessage();
                $messageType = "danger";
            }
        }
    }
}

// Fetch all registered roles
$roles = $pdo->query("SELECT * FROM roles ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch all registered permissions grouped by category
$permissions = $pdo->query("SELECT * FROM permissions ORDER BY category ASC, permission_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$permissionsByCategory = [];
foreach ($permissions as $p) {
    $permissionsByCategory[$p['category']][] = $p;
}

// Fetch all users with role names
$usersList = $pdo->query("SELECT u.id, u.username, u.full_name, u.role_id, u.status, u.created_at, r.name as role_name 
    FROM users u 
    LEFT JOIN roles r ON u.role_id = r.id 
    ORDER BY u.id DESC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch per-user permission overrides lookup table
$rawUserPerms = $pdo->query("SELECT user_id, permission_key, is_granted FROM user_permissions")->fetchAll(PDO::FETCH_ASSOC);
$userPermissionsMap = [];
foreach ($rawUserPerms as $up) {
    $userPermissionsMap[$up['user_id']][$up['permission_key']] = (int)$up['is_granted'];
}

// Fetch role permissions lookup table
$rawRolePerms = $pdo->query("SELECT role_id, permission_key, is_granted FROM role_permissions")->fetchAll(PDO::FETCH_ASSOC);
$rolePermissionsMap = [];
foreach ($rawRolePerms as $rp) {
    if ((int)$rp['is_granted'] === 1) {
        $rolePermissionsMap[$rp['role_id']][] = $rp['permission_key'];
    }
}

$csrfToken = generateCSRFToken();
?>

<?php include 'includes/header.php'; ?>

<style>
    .roles-container { max-width: 1200px; margin: 0 auto; }
    .page-header-box { background: #fff; padding: 1.25rem 1.5rem; border-radius: 12px; border: 1px solid #e2e8f0; margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 1px 3px rgba(0,0,0,0.04); }
    .page-header-box h2 { font-size: 1.25rem; color: #1a5632; margin: 0; font-weight: 700; display: flex; align-items: center; gap: 0.5rem; }
    
    .settings-nav { display: flex; gap: 0.5rem; border-bottom: 2px solid #e2e8f0; margin-bottom: 1.5rem; background: #fff; padding: 0.5rem 0.5rem 0; border-radius: 10px 10px 0 0; }
    .nav-tab { padding: 0.65rem 1.2rem; font-size: 0.85rem; font-weight: 600; color: #64748b; background: none; border: none; border-bottom: 3px solid transparent; cursor: pointer; display: flex; align-items: center; gap: 0.4rem; transition: all 0.2s; }
    .nav-tab:hover { color: #1a5632; }
    .nav-tab.active { color: #1a5632; border-bottom-color: #1a5632; background: #f0fdf4; border-radius: 8px 8px 0 0; }
    
    .tab-content { display: none; }
    .tab-content.active { display: block; }
    
    .card { background: #fff; border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.04); margin-bottom: 1.5rem; overflow: hidden; }
    .card-header { background: #f8fafc; padding: 0.9rem 1.25rem; border-bottom: 1px solid #e2e8f0; font-weight: 600; font-size: 0.9rem; color: #1a5632; display: flex; justify-content: space-between; align-items: center; }
    .card-body { padding: 1.25rem; }
    
    .table-responsive { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .table { width: 100%; border-collapse: collapse; font-size: 0.8rem; min-width: 700px; }
    .table th { background: #f8fafc; padding: 0.65rem 0.85rem; text-align: left; font-weight: 600; color: #475569; border-bottom: 1px solid #e2e8f0; white-space: nowrap; }
    .table td { padding: 0.65rem 0.85rem; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    .table tbody tr:hover { background: #f8fafc; }
    
    .badge-status { padding: 0.2rem 0.6rem; border-radius: 12px; font-size: 0.7rem; font-weight: 600; display: inline-block; }
    .badge-active { background: #dcfce7; color: #166534; }
    .badge-inactive { background: #fee2e2; color: #991b1b; }
    .badge-override { background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }

    .btn-save { background: #1a5632; color: #fff; border: none; padding: 0.65rem 1.25rem; border-radius: 8px; font-size: 0.85rem; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 0.4rem; transition: background 0.2s; }
    .btn-save:hover { background: #145226; }
    .btn-action-sm { padding: 0.35rem 0.75rem; border-radius: 6px; font-size: 0.75rem; font-weight: 600; border: 1px solid #cbd5e1; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 0.25rem; background: #fff; color: #1e293b; }
    .btn-action-sm:hover { background: #f1f5f9; }
    .btn-primary-sm { background: #0284c7; color: #fff; border-color: #0284c7; }
    .btn-primary-sm:hover { background: #0369a1; }
    
    .alert { padding: 0.85rem 1.1rem; border-radius: 8px; font-size: 0.85rem; margin-bottom: 1.25rem; border-left: 4px solid; display: flex; align-items: center; gap: 0.5rem; }
    .alert-success { background: #f0fdf4; color: #166534; border-color: #22c55e; }
    .alert-danger { background: #fef2f2; color: #991b1b; border-color: #ef4444; }

    .permission-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1rem; }
    .perm-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 0.85rem; }
    .perm-card h6 { font-size: 0.8rem; font-weight: 700; color: #1a5632; margin: 0 0 0.5rem 0; border-bottom: 1px solid #cbd5e1; padding-bottom: 0.3rem; text-transform: uppercase; }
    .perm-item { display: flex; align-items: flex-start; gap: 0.5rem; margin-bottom: 0.6rem; }
    .perm-item input[type="checkbox"] { margin-top: 0.2rem; cursor: pointer; }
    .perm-label { font-size: 0.775rem; font-weight: 600; color: #1e293b; cursor: pointer; }
    .perm-desc { font-size: 0.7rem; color: #64748b; line-height: 1.3; }

    @media (max-width: 768px) {
        .roles-container { padding: 0 0.5rem; }
        .page-header-box { flex-direction: column; align-items: flex-start; gap: 0.75rem; padding: 1rem; }
        .page-header-box h2 { font-size: 1.05rem; }
        .settings-nav { display: flex; overflow-x: auto; white-space: nowrap; -webkit-overflow-scrolling: touch; padding: 0.4rem 0.4rem 0.2rem; margin-bottom: 1rem; border-radius: 8px 8px 0 0; }
        .nav-tab { padding: 0.5rem 0.85rem; font-size: 0.775rem; flex-shrink: 0; }
        .card-header { flex-direction: column; align-items: flex-start; gap: 0.5rem; padding: 0.75rem 1rem; }
        .card-body { padding: 1rem; }
        .btn-save { width: 100%; justify-content: center; }
    }
</style>

<div class="roles-container">
    <div class="page-header-box">
        <h2><i class="fas fa-user-shield"></i> Role & Granular Permission Management Portal</h2>
        
    </div>

    <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $messageType; ?>">
            <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
            <div><?php echo htmlspecialchars($message); ?></div>
        </div>
    <?php endif; ?>

    <!-- Navigation Tabs -->
    <div class="settings-nav">
        <button class="nav-tab active" onclick="switchTab('tab-users', this)"><i class="fas fa-users-cog"></i> User Roles & Permissions (Give/Deny)</button>
        <button class="nav-tab" onclick="switchTab('tab-roles', this)"><i class="fas fa-id-card"></i> Default Role Matrix</button>
        <button class="nav-tab" onclick="switchTab('tab-registry', this)"><i class="fas fa-list-check"></i> System Permission Registry</button>
    </div>

    <!-- TAB 1: USER ROLES & INDIVIDUAL PERMISSIONS (GIVE OR DENY) -->
    <div id="tab-users" class="tab-content active">
        <div class="card">
            <div class="card-header">
                <h5><i class="fas fa-users"></i> All User Accounts & Granular Permissions</h5>
                <div style="display:flex; gap:0.5rem; align-items:center;">
                    <input type="text" id="userSearchInput" onkeyup="filterUserTable()" placeholder="Search username / full name..." class="form-control" style="padding:0.35rem 0.75rem; font-size:0.775rem; width:220px;">
                </div>
            </div>
            <div class="card-body" style="padding:0;">
                <div class="table-responsive">
                    <table class="table" id="usersTable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Username</th>
                                <th>Full Name</th>
                                <th>Assigned System Role</th>
                                <th>Status</th>
                                <th>Permission Overrides</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($usersList as $u): 
                                $userOverrides = $userPermissionsMap[$u['id']] ?? [];
                                $overrideCount = count($userOverrides);
                                $grantedCount = count(array_filter($userOverrides, function($v) { return $v === 1; }));
                                $deniedCount = count(array_filter($userOverrides, function($v) { return $v === 0; }));
                            ?>
                                <tr>
                                    <td>#<?php echo $u['id']; ?></td>
                                    <td><strong><?php echo htmlspecialchars($u['username']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($u['full_name']); ?></td>
                                    <td>
                                        <span class="badge-status badge-active" style="background:#e0f2fe; color:#0369a1;">
                                            <i class="fas fa-user-tag"></i> <?php echo htmlspecialchars($u['role_name'] ?? 'Unassigned'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge-status <?php echo $u['status'] === 'active' ? 'badge-active' : 'badge-inactive'; ?>">
                                            <?php echo ucfirst($u['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($overrideCount > 0): ?>
                                            <span class="badge-status badge-override">
                                                <i class="fas fa-sliders-h"></i> <?php echo $grantedCount; ?> Granted &bull; <?php echo $deniedCount; ?> Denied
                                            </span>
                                        <?php else: ?>
                                            <span style="font-size:0.75rem; color:#94a3b8;">Standard Role Defaults</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button type="button" class="btn-action-sm btn-primary-sm" onclick="openUserPermModal(<?php echo htmlspecialchars(json_encode($u)); ?>, <?php echo htmlspecialchars(json_encode($userOverrides)); ?>)">
                                            <i class="fas fa-edit"></i> Manage Role & Permissions
                                        </button>
                                        <?php if ($overrideCount > 0): ?>
                                            <form method="POST" action="roles" style="display:inline;" onsubmit="return confirm('Reset all explicit permission overrides for this user to default role permissions?');">
                                                <input type="hidden" name="action" value="reset_user_permissions">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                                <input type="hidden" name="target_user_id" value="<?php echo $u['id']; ?>">
                                                <button type="submit" class="btn-action-sm" style="color:#dc2626;" title="Reset overrides"><i class="fas fa-undo"></i> Reset</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- TAB 2: DEFAULT ROLE PERMISSION MATRIX -->
    <div id="tab-roles" class="tab-content">
        <div class="card">
            <div class="card-header">
                <h5><i class="fas fa-id-card"></i> Baseline Default Permissions Per System Role</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="roles">
                    <input type="hidden" name="action" value="save_role_permissions">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">

                    <div style="margin-bottom:1.25rem;">
                        <label style="font-size:0.8rem; font-weight:700; color:#334155; display:block; margin-bottom:0.3rem;">Select Target System Role to Configure Defaults:</label>
                        <select id="roleMatrixSelect" name="role_id" class="form-control" style="max-width:350px;" onchange="loadRoleMatrix(this.value)">
                            <?php foreach ($roles as $r): ?>
                                <option value="<?php echo $r['id']; ?>"><?php echo htmlspecialchars($r['name']); ?> (ID #<?php echo $r['id']; ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="permission-grid">
                        <?php foreach ($permissionsByCategory as $cat => $perms): ?>
                            <div class="perm-card">
                                <h6><?php echo htmlspecialchars($cat); ?></h6>
                                <?php foreach ($perms as $p): ?>
                                    <div class="perm-item">
                                        <input type="checkbox" name="role_permissions[]" value="<?php echo htmlspecialchars($p['permission_key']); ?>" id="role_perm_<?php echo $p['id']; ?>">
                                        <div>
                                            <label for="role_perm_<?php echo $p['id']; ?>" class="perm-label"><?php echo htmlspecialchars($p['permission_name']); ?></label>
                                            <div class="perm-desc"><?php echo htmlspecialchars($p['description']); ?></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div style="margin-top:1.5rem; text-align:right;">
                        <button type="submit" class="btn-save"><i class="fas fa-save"></i> Save Baseline Role Matrix</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- TAB 3: SYSTEM PERMISSION REGISTRY -->
    <div id="tab-registry" class="tab-content">
        <div class="card">
            <div class="card-header">
                <h5><i class="fas fa-list-check"></i> System Permission Registry</h5>
                <button type="button" onclick="document.getElementById('newPermModal').style.display='block';" class="btn-save" style="padding:0.4rem 0.8rem; font-size:0.75rem;"><i class="fas fa-plus-circle"></i> Register New Permission</button>
            </div>
            <div class="card-body" style="padding:0;">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Permission Key</th>
                                <th>Permission Name</th>
                                <th>Category</th>
                                <th>Description</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($permissions as $p): ?>
                                <tr>
                                    <td><code><?php echo htmlspecialchars($p['permission_key']); ?></code></td>
                                    <td><strong><?php echo htmlspecialchars($p['permission_name']); ?></strong></td>
                                    <td><span class="badge-status badge-active" style="background:#f1f5f9; color:#334155;"><?php echo htmlspecialchars($p['category']); ?></span></td>
                                    <td><?php echo htmlspecialchars($p['description']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: MANAGE USER ROLE & GRANULAR PERMISSIONS -->
<div id="userPermModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:9999; overflow-y:auto;">
    <div style="background:#fff; width:95%; max-width:750px; margin:3% auto; padding:1.5rem; border-radius:14px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);">
        <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #e2e8f0; padding-bottom:0.75rem; margin-bottom:1rem;">
            <h4 style="margin:0; color:#1a5632; font-size:1.1rem;"><i class="fas fa-user-shield"></i> Manage User Role & Permissions</h4>
            <button type="button" onclick="closeUserPermModal()" style="background:none; border:none; font-size:1.4rem; cursor:pointer; color:#64748b;">&times;</button>
        </div>

        <form method="POST" action="roles">
            <input type="hidden" name="action" value="save_user_permissions">
            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
            <input type="hidden" name="target_user_id" id="modalTargetUserId">

            <div style="background:#f8fafc; padding:0.85rem; border-radius:8px; border:1px solid #e2e8f0; margin-bottom:1.25rem; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.5rem;">
                <div>
                    <div style="font-weight:700; color:#0f172a; font-size:0.9rem;" id="modalUserFullName">Username</div>
                    <div style="font-size:0.75rem; color:#64748b;" id="modalUsername">Username</div>
                </div>
                <div style="display:flex; gap:0.75rem; align-items:center;">
                    <div>
                        <label style="font-size:0.7rem; font-weight:700; display:block;">System Role:</label>
                        <select name="role_id" id="modalUserRoleId" class="form-control" style="padding:0.25rem 0.5rem; font-size:0.775rem;">
                            <?php foreach ($roles as $r): ?>
                                <option value="<?php echo $r['id']; ?>"><?php echo htmlspecialchars($r['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="font-size:0.7rem; font-weight:700; display:block;">Account Status:</label>
                        <select name="status" id="modalUserStatus" class="form-control" style="padding:0.25rem 0.5rem; font-size:0.775rem;">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="suspended">Suspended</option>
                        </select>
                    </div>
                </div>
            </div>

            <div style="margin-bottom:0.75rem; font-size:0.8rem; font-weight:700; color:#1a5632; display:flex; justify-content:space-between; align-items:center;">
                <span>Granular Permissions Matrix (Check = Grant, Uncheck = Deny):</span>
                <div>
                    <button type="button" onclick="selectAllUserPerms(true)" class="btn-action-sm" style="font-size:0.7rem;">Select All</button>
                    <button type="button" onclick="selectAllUserPerms(false)" class="btn-action-sm" style="font-size:0.7rem;">Deselect All</button>
                </div>
            </div>

            <div class="permission-grid" style="max-height:400px; overflow-y:auto; padding-right:0.5rem;">
                <?php foreach ($permissionsByCategory as $cat => $perms): ?>
                    <div class="perm-card">
                        <h6><?php echo htmlspecialchars($cat); ?></h6>
                        <?php foreach ($perms as $p): ?>
                            <div class="perm-item">
                                <input type="checkbox" name="granted_permissions[]" value="<?php echo htmlspecialchars($p['permission_key']); ?>" class="user-perm-checkbox" id="u_perm_<?php echo $p['id']; ?>">
                                <div>
                                    <label for="u_perm_<?php echo $p['id']; ?>" class="perm-label"><?php echo htmlspecialchars($p['permission_name']); ?></label>
                                    <div class="perm-desc"><?php echo htmlspecialchars($p['description']); ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <div style="margin-top:1.25rem; text-align:right; border-top:1px solid #e2e8f0; padding-top:0.85rem;">
                <button type="button" onclick="closeUserPermModal()" class="btn-action-sm">Cancel</button>
                <button type="submit" class="btn-save"><i class="fas fa-save"></i> Save User Permissions</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: REGISTER NEW PERMISSION -->
<div id="newPermModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#fff; width:90%; max-width:500px; margin:5% auto; padding:1.5rem; border-radius:12px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; border-bottom:1px solid #e2e8f0; padding-bottom:0.5rem;">
            <h4 style="margin:0; color:#1a5632;"><i class="fas fa-key"></i> Register New System Permission</h4>
            <button type="button" onclick="document.getElementById('newPermModal').style.display='none';" style="background:none; border:none; font-size:1.2rem; cursor:pointer;">&times;</button>
        </div>
        <form method="POST" action="roles">
            <input type="hidden" name="action" value="create_permission">
            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">

            <div class="form-group" style="margin-bottom:0.85rem;">
                <label style="font-size:0.8rem; font-weight:600;">Permission Key (Unique Identifier)</label>
                <input type="text" name="permission_key" class="form-control" placeholder="e.g. export_audit_logs" required>
            </div>

            <div class="form-group" style="margin-bottom:0.85rem;">
                <label style="font-size:0.8rem; font-weight:600;">Permission Display Title</label>
                <input type="text" name="permission_name" class="form-control" placeholder="e.g. Export Audit Activity Logs" required>
            </div>

            <div class="form-group" style="margin-bottom:0.85rem;">
                <label style="font-size:0.8rem; font-weight:600;">Module Category</label>
                <input type="text" name="category" class="form-control" placeholder="e.g. Security & Audit" value="General">
            </div>

            <div class="form-group" style="margin-bottom:0.85rem;">
                <label style="font-size:0.8rem; font-weight:600;">Description</label>
                <textarea name="description" class="form-control" rows="2" placeholder="Describe what actions this permission grants..."></textarea>
            </div>

            <div style="text-align:right; margin-top:1.25rem;">
                <button type="button" onclick="document.getElementById('newPermModal').style.display='none';" class="btn-action-sm">Cancel</button>
                <button type="submit" class="btn-save"><i class="fas fa-plus"></i> Register Permission</button>
            </div>
        </form>
    </div>
</div>

<script>
    var rolePermsData = <?php echo json_encode($rolePermissionsMap); ?>;

    function switchTab(tabId, btn) {
        document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
        document.querySelectorAll('.nav-tab').forEach(el => el.classList.remove('active'));
        
        document.getElementById(tabId).classList.add('active');
        if (btn) btn.classList.add('active');
    }

    function filterUserTable() {
        var input = document.getElementById('userSearchInput').value.toLowerCase();
        var rows = document.querySelectorAll('#usersTable tbody tr');
        rows.forEach(r => {
            var text = r.textContent.toLowerCase();
            r.style.display = text.includes(input) ? '' : 'none';
        });
    }

    function openUserPermModal(user, userOverrides) {
        document.getElementById('modalTargetUserId').value = user.id;
        document.getElementById('modalUserFullName').textContent = user.full_name || user.username;
        document.getElementById('modalUsername').textContent = '@' + user.username + ' (ID #' + user.id + ')';
        document.getElementById('modalUserRoleId').value = user.role_id;
        document.getElementById('modalUserStatus').value = user.status || 'active';

        // Get role default perms
        var roleDefaults = rolePermsData[user.role_id] || [];

        // Pre-check checkboxes based on explicit overrides or role defaults
        document.querySelectorAll('.user-perm-checkbox').forEach(cb => {
            var pKey = cb.value;
            if (userOverrides && userOverrides.hasOwnProperty(pKey)) {
                cb.checked = (parseInt(userOverrides[pKey]) === 1);
            } else {
                cb.checked = roleDefaults.includes(pKey);
            }
        });

        document.getElementById('userPermModal').style.display = 'block';
    }

    function closeUserPermModal() {
        document.getElementById('userPermModal').style.display = 'none';
    }

    function selectAllUserPerms(status) {
        document.querySelectorAll('.user-perm-checkbox').forEach(cb => {
            cb.checked = status;
        });
    }

    function loadRoleMatrix(roleId) {
        var grantedKeys = rolePermsData[roleId] || [];
        document.querySelectorAll('#tab-roles input[type="checkbox"]').forEach(cb => {
            cb.checked = grantedKeys.includes(cb.value);
        });
    }

    // Initialize first role selection in Tab 2
    document.addEventListener('DOMContentLoaded', function() {
        var sel = document.getElementById('roleMatrixSelect');
        if (sel) loadRoleMatrix(sel.value);
    });
</script>

<?php include 'includes/footer.php'; ?>
