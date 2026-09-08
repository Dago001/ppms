<?php
// Function to check if user has a specific permission (supports per-user overrides and role permissions)
function hasPermission($permissionKey, $userId = null) {
    $uid = $userId ?? ($_SESSION['user_id'] ?? null);
    if (!$uid) return false;
    
    global $pdo;
    if (!isset($pdo)) return false;

    try {
        // 1. Check for explicit per-user permission override FIRST (Explicit Give = 1, Explicit Deny = 0)
        $stmtUser = $pdo->prepare("SELECT is_granted FROM user_permissions WHERE user_id = ? AND permission_key = ?");
        $stmtUser->execute([$uid, $permissionKey]);
        $userOverride = $stmtUser->fetch(PDO::FETCH_ASSOC);

        if ($userOverride !== false) {
            return (int)$userOverride['is_granted'] === 1;
        }

        // 2. Fetch User Role if not in session or for specific userId
        $roleName = strtolower(trim($_SESSION['role_name'] ?? ''));
        $roleId = $_SESSION['role_id'] ?? null;

        if ($userId !== null || empty($roleName) || !$roleId) {
            $stmtRole = $pdo->prepare("SELECT u.role_id, r.name as role_name FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
            $stmtRole->execute([$uid]);
            $rRow = $stmtRole->fetch(PDO::FETCH_ASSOC);
            if ($rRow) {
                $roleId = $rRow['role_id'];
                $roleName = strtolower(trim($rRow['role_name'] ?? ''));
            }
        }

        // 3. Super Admin & Admin default bypass (if no explicit per-user override exists)
        if (in_array($roleName, ['admin', 'super admin', 'superadmin', 'system administrator'])) {
            return true;
        }

        // 4. Check baseline role permissions fallback
        if ($roleId) {
            $stmtRolePerm = $pdo->prepare("SELECT is_granted FROM role_permissions WHERE role_id = ? AND permission_key = ?");
            $stmtRolePerm->execute([$roleId, $permissionKey]);
            $rolePerm = $stmtRolePerm->fetch(PDO::FETCH_ASSOC);

            if ($rolePerm !== false) {
                return (int)$rolePerm['is_granted'] === 1;
            }
        }

        return false;
    } catch (Exception $e) {
        error_log("Permission check error: " . $e->getMessage());
        return false;
    }
}

// Alternative: Check multiple permissions at once
function hasAnyPermission($permissions = []) {
    foreach ($permissions as $permission) {
        if (hasPermission($permission)) {
            return true;
        }
    }
    return false;
}

// Check if user has all specified permissions
function hasAllPermissions($permissions = []) {
    foreach ($permissions as $permission) {
        if (!hasPermission($permission)) {
            return false;
        }
    }
    return true;
}

// NEW ZONE-BASED PERMISSION FUNCTIONS

// Check if user is Admin (Full System Access)
function isAdmin() {
    if (!isset($_SESSION['user_id'])) return false;
    
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT r.name 
            FROM users u 
            JOIN roles r ON u.role_id = r.id 
            WHERE u.id = ? AND LOWER(r.name) IN ('admin', 'super admin', 'superadmin')
        ");
        $stmt->execute([$_SESSION['user_id']]);
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        error_log("isAdmin check error: " . $e->getMessage());
        return false;
    }
}

// Legacy alias for backward compatibility
function isSuperAdmin() {
    return isAdmin();
}

// Check if user is SHQ Admin (Service HQ Admin)
function isServiceHQ() {
    if (!isset($_SESSION['user_id'])) return false;
    
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT r.name 
            FROM users u 
            JOIN roles r ON u.role_id = r.id 
            WHERE u.id = ? AND LOWER(r.name) IN ('shq admin', 'service hq', 'service headquarters')
        ");
        $stmt->execute([$_SESSION['user_id']]);
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        error_log("isServiceHQ check error: " . $e->getMessage());
        return false;
    }
}

// Check if user is Command Admin
function isCommandAdmin() {
    if (!isset($_SESSION['user_id'])) return false;
    
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT r.name 
            FROM users u 
            JOIN roles r ON u.role_id = r.id 
            WHERE u.id = ? AND LOWER(r.name) LIKE '%command%admin%'
        ");
        $stmt->execute([$_SESSION['user_id']]);
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        error_log("isCommandAdmin check error: " . $e->getMessage());
        return false;
    }
}

// Check if user can initiate officer postings (Admin, SHQ Admin, Command Admin can; User CANNOT)
function canInitiatePosting($userId = null) {
    $uid = $userId ?? ($_SESSION['user_id'] ?? null);
    if (!$uid) return false;

    global $pdo;
    if (!isset($pdo)) return false;

    try {
        $stmt = $pdo->prepare("SELECT LOWER(r.name) as role_name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
        $stmt->execute([$uid]);
        $roleName = $stmt->fetchColumn();

        if (!$roleName) return false;

        // User and Command User roles CANNOT initiate posting (View & Download Reports ONLY)
        if (in_array($roleName, ['user', 'command user'])) {
            return false;
        }

        // Admin, SHQ Admin, Command Admin CAN initiate postings
        return in_array($roleName, ['admin', 'shq admin', 'service hq', 'service headquarters', 'command admin']);
    } catch (Exception $e) {
        error_log("canInitiatePosting error: " . $e->getMessage());
        return false;
    }
}

// Deprecated Zone helper aliases
function isZoneAdmin() { return isCommandAdmin(); }
function isZoneUser() { return false; }

// Get zones assigned to a user
function getUserZones($user_id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT z.id, z.zone_name, z.zone_code 
            FROM user_zones uz 
            JOIN zones z ON uz.zone_id = z.id 
            WHERE uz.user_id = ?
            ORDER BY z.zone_name
        ");
        $stmt->execute([$user_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("getUserZones error: " . $e->getMessage());
        return [];
    }
}

// Check if user can modify another user
function canModifyUser($current_user_id, $target_user_id) {
    if ($current_user_id == $target_user_id) return false; // Can't modify self
    
    global $pdo;
    
    try {
        // Get current user's role
        $stmt = $pdo->prepare("
            SELECT LOWER(r.name) as role_name 
            FROM users u 
            JOIN roles r ON u.role_id = r.id 
            WHERE u.id = ?
        ");
        $stmt->execute([$current_user_id]);
        $current_role = $stmt->fetchColumn();
        
        if (!$current_role) return false;
        
        // Get target user's role
        $stmt->execute([$target_user_id]);
        $target_role = $stmt->fetchColumn();
        
        if (!$target_role) return false;
        
        // Admin can modify anyone
        if ($current_role === 'admin') return true;
        
        // SHQ Admin can modify Command Admin and other SHQ Admin accounts, but not Admin
        if (in_array($current_role, ['shq admin', 'service hq', 'service headquarters'])) {
            if ($target_role === 'admin') return false;
            return in_array($target_role, ['command admin', 'shq admin', 'service hq', 'service headquarters', 'user', 'command user']);
        }
        
        return false;
    } catch (PDOException $e) {
        error_log("canModifyUser error: " . $e->getMessage());
        return false;
    }
}

// Check if current user can access a specific zone
function canAccessZone($zone_id) {
    if (!isset($_SESSION['user_id'])) return false;
    
    // Super Admin and Service HQ can access all zones
    if (isSuperAdmin() || isServiceHQ()) {
        return true;
    }
    
    global $pdo;
    try {
        // Check if user is assigned to this zone
        $stmt = $pdo->prepare("
            SELECT 1 
            FROM user_zones 
            WHERE user_id = ? AND zone_id = ?
        ");
        $stmt->execute([$_SESSION['user_id'], $zone_id]);
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        error_log("canAccessZone error: " . $e->getMessage());
        return false;
    }
}

// Get user's accessible zones (for dropdowns/filters)
function getUserAccessibleZones() {
    if (!isset($_SESSION['user_id'])) return [];
    
    global $pdo;
    
    try {
        if (isSuperAdmin() || isServiceHQ()) {
            // Return all zones
            $stmt = $pdo->prepare("SELECT id, zone_name, zone_code FROM zones ORDER BY zone_name");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            // Return only user's assigned zones
            return getUserZones($_SESSION['user_id']);
        }
    } catch (PDOException $e) {
        error_log("getUserAccessibleZones error: " . $e->getMessage());
        return [];
    }
}

// Log activity
function logActivity($action) {
    if (!isset($_SESSION['user_id'])) return false;
    
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            INSERT INTO activity_logs (user_id, action, ip_address, user_agent)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([
            $_SESSION['user_id'],
            $action,
            $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
        ]);
        return true;
    } catch (PDOException $e) {
        error_log("logActivity error: " . $e->getMessage());
        return false;
    }
}

// Check if user can manage personnel in a specific zone
function canManageZonePersonnel($zone_id = null) {
    if (!isset($_SESSION['user_id'])) return false;
    
    // Super Admin and Service HQ can manage all zones
    if (isSuperAdmin() || isServiceHQ()) {
        return true;
    }
    
    // Zone Admins can only manage their assigned zones
    if (isZoneAdmin() || isZoneUser()) {
        if ($zone_id === null) {
            // If no specific zone, check if they have any zones
            $userZones = getUserZones($_SESSION['user_id']);
            return !empty($userZones);
        } else {
            // Check specific zone
            return canAccessZone($zone_id);
        }
    }
    
    return false;
}

// Get user's role name
function getUserRoleName($user_id = null) {
    if ($user_id === null && !isset($_SESSION['user_id'])) {
        return 'Unknown';
    }
    
    $target_user_id = $user_id ?? $_SESSION['user_id'];
    
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT r.name 
            FROM users u 
            JOIN roles r ON u.role_id = r.id 
            WHERE u.id = ?
        ");
        $stmt->execute([$target_user_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result ? $result['name'] : 'Unknown';
    } catch (PDOException $e) {
        error_log("getUserRoleName error: " . $e->getMessage());
        return 'Unknown';
    }
}
?>