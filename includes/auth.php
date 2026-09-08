<?php
require_once __DIR__ . '/config.php';

if (!function_exists('logSystemActivity')) {
    function logSystemActivity($action, $description, $userId = null) {
        global $pdo;
        if (!isset($pdo) || !$pdo) return false;
        $uid = $userId ?? ($_SESSION['user_id'] ?? null);
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        try {
            $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, description, ip_address, created_at) VALUES (?, ?, ?, ?, NOW())");
            return $stmt->execute([$uid, $action, $description, $ip]);
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('isLoggedIn')) {
    function isLoggedIn() {
        if (!isset($_SESSION['user_id'])) {
            return false;
        }

        // Re-validate the session's user still exists and is active on every
        // request. Without this, a deleted/deactivated account - or a stale
        // session left over from a database reset/restore - stays "logged in"
        // forever: login.php waves it straight through to dashboard, which then
        // fails to find the user and renders with undefined data. Cached per
        // request via the static so this only costs one lookup per page load.
        static $validated = null;
        if ($validated !== null) {
            return $validated;
        }

        global $pdo;
        if (!isset($pdo) || !$pdo) {
            // No DB connection available to check against - fail open on the
            // session flag alone rather than locking everyone out.
            return $validated = true;
        }

        try {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND status = 'active'");
            $stmt->execute([$_SESSION['user_id']]);
            if (!$stmt->fetch()) {
                session_unset();
                session_destroy();
                return $validated = false;
            }
        } catch (Exception $e) {
            // Transient DB error - fail open rather than locking everyone out.
            return $validated = true;
        }

        return $validated = true;
    }
}

if (!function_exists('verifyUserCredentials')) {
    function verifyUserCredentials($username, $password) {
        global $pdo;
        try {
            $stmt = $pdo->prepare("SELECT u.* FROM users u WHERE u.username = ? AND u.status = 'active'");
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($password, $user['password'])) {
                return $user;
            }
            return false;
        } catch (PDOException $e) {
            error_log("Credential verification error: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('loginUser')) {
    function loginUser($username, $password) {
        global $pdo;
        
        try {
            // Simplified query - get user first
            $stmt = $pdo->prepare("SELECT u.* FROM users u WHERE u.username = ? AND u.status = 'active'");
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($password, $user['password'])) {
                // Now get role info separately
                $roleStmt = $pdo->prepare("SELECT name as role_name, id as role_id FROM roles WHERE id = ?");
                $roleStmt->execute([$user['role_id'] ?? 0]);
                $role = $roleStmt->fetch();
                
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['full_name'] = $user['full_name'] ?? $user['username'];
                $_SESSION['role_name'] = $role['role_name'] ?? 'User';
                $_SESSION['role_id'] = $role['role_id'] ?? 0;
                
                // Update last login
                try {
                    $updateStmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                    $updateStmt->execute([$user['id']]);
                } catch (Exception $e) {
                    // Ignore last_login errors
                }

                logSystemActivity('user_login', "User '@{$user['username']}' ({$_SESSION['full_name']}) logged into system", $user['id']);
                
                return true;
            }
            return false;
        } catch (PDOException $e) {
            error_log("Login error: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('logoutUser')) {
    function logoutUser() {
        if (isset($_SESSION['user_id'])) {
            logSystemActivity('user_logout', "User '@" . ($_SESSION['username'] ?? 'User') . "' logged out", $_SESSION['user_id']);
        }
        session_start();
        session_unset();
        session_destroy();
        session_write_close();
        setcookie(session_name(),'',0,'/');
    }
}

if (!function_exists('createUser')) {
    function createUser($username, $password, $fullName, $roleId) {
        global $pdo;
        
        try {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            
            $stmt = $pdo->prepare("
                INSERT INTO users (username, password, full_name, role_id)
                VALUES (?, ?, ?, ?)
            ");
            
            return $stmt->execute([$username, $hashedPassword, $fullName, $roleId]);
        } catch (PDOException $e) {
            error_log("Create user error: " . $e->getMessage());
            return false;
        }
    }
}

// NEW FUNCTIONS ADDED BELOW

if (!function_exists('getCurrentUserData')) {
    function getCurrentUserData() {
        global $pdo;
        
        if (!isset($_SESSION['user_id'])) {
            return [
                'username' => 'Guest',
                'full_name' => 'Guest User',
                'role_name' => 'Guest',
                'role_id' => null
            ];
        }
        
        try {
            $stmt = $pdo->prepare("
                SELECT u.*, r.name as role_name, r.id as role_id
                FROM users u 
                JOIN roles r ON u.role_id = r.id 
                WHERE u.id = ?
            ");
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch();
            
            if (!$user) {
                return [
                    'username' => $_SESSION['username'] ?? 'User',
                    'full_name' => $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User',
                    'role_name' => $_SESSION['role_name'] ?? 'User',
                    'role_id' => $_SESSION['role_id'] ?? null
                ];
            }
            
            return $user;
        } catch (PDOException $e) {
            error_log("Error getting user data: " . $e->getMessage());
            return [
                'username' => $_SESSION['username'] ?? 'User',
                'full_name' => $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User',
                'role_name' => $_SESSION['role_name'] ?? 'User',
                'role_id' => $_SESSION['role_id'] ?? null
            ];
        }
    }
}

if (!function_exists('getSystemStats')) {
    function getSystemStats() {
        global $pdo;
        
        try {
            // Get total personnel count
            $personnelQuery = $pdo->query("SELECT COUNT(*) as count FROM tbl_emppersonal");
            $personnelResult = $personnelQuery ? $personnelQuery->fetch() : ['count' => 0];
            $totalPersonnel = $personnelResult['count'] ?? 0;
            
            // Get total users count
            $usersQuery = $pdo->query("SELECT COUNT(*) as count FROM users");
            $usersResult = $usersQuery ? $usersQuery->fetch() : ['count' => 0];
            $totalUsers = $usersResult['count'] ?? 0;
            
            return [
                'totalPersonnel' => $totalPersonnel,
                'totalUsers' => $totalUsers
            ];
        } catch (PDOException $e) {
            error_log("Error getting system stats: " . $e->getMessage());
            return [
                'totalPersonnel' => 0,
                'totalUsers' => 0
            ];
        }
    }
}

if (!function_exists('getUserById')) {
    function getUserById($user_id) {
        global $pdo;
        
        try {
            $stmt = $pdo->prepare("
                SELECT u.*, r.name as role_name 
                FROM users u 
                JOIN roles r ON u.role_id = r.id 
                WHERE u.id = ?
            ");
            $stmt->execute([$user_id]);
            return $stmt->fetch();
        } catch (PDOException $e) {
            error_log("Error getting user by ID: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('updateUserLastLogin')) {
    function updateUserLastLogin($user_id) {
        global $pdo;
        
        try {
            $stmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
            return $stmt->execute([$user_id]);
        } catch (PDOException $e) {
            error_log("Error updating last login: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('changeUserPassword')) {
    function changeUserPassword($user_id, $new_password) {
        global $pdo;
        
        try {
            $hashedPassword = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            return $stmt->execute([$hashedPassword, $user_id]);
        } catch (PDOException $e) {
            error_log("Error changing password: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('checkUsernameExists')) {
    function checkUsernameExists($username, $exclude_user_id = null) {
        global $pdo;
        
        try {
            if ($exclude_user_id) {
                $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
                $stmt->execute([$username, $exclude_user_id]);
            } else {
                $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                $stmt->execute([$username]);
            }
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("Error checking username: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('checkEmailExists')) {
    function checkEmailExists($email, $exclude_user_id = null) {
        global $pdo;
        
        try {
            if ($exclude_user_id) {
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                $stmt->execute([$email, $exclude_user_id]);
            } else {
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                $stmt->execute([$email]);
            }
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("Error checking email: " . $e->getMessage());
            return false;
        }
    }
}

// Helper function to automatically set sidebar variables
if (!function_exists('prepareSidebarData')) {
    function prepareSidebarData() {
        global $user, $totalPersonnel, $totalUsers;
        
        // Get user data if not already set
        if (!isset($user) || !is_array($user)) {
            $user = getCurrentUserData();
        }
        
        // Get system stats if not already set
        if (!isset($totalPersonnel)) {
            $stats = getSystemStats();
            $totalPersonnel = $stats['totalPersonnel'];
        }
        
        if (!isset($totalUsers)) {
            $stats = getSystemStats();
            $totalUsers = $stats['totalUsers'];
        }
    }
}

// Helper to enforce strong password validation
if (!function_exists('validatePasswordStrength')) {
    function validatePasswordStrength($password) {
        if (strlen($password) < 8) {
            return ['valid' => false, 'message' => 'Password must be at least 8 characters long.', 'errors' => ['Password must be at least 8 characters']];
        }
        if (!preg_match('/[A-Z]/', $password)) {
            return ['valid' => false, 'message' => 'Password must contain at least one uppercase letter (A-Z).', 'errors' => ['Password must contain at least one uppercase letter']];
        }
        if (!preg_match('/[a-z]/', $password)) {
            return ['valid' => false, 'message' => 'Password must contain at least one lowercase letter (a-z).', 'errors' => ['Password must contain at least one lowercase letter']];
        }
        if (!preg_match('/[0-9]/', $password)) {
            return ['valid' => false, 'message' => 'Password must contain at least one number (0-9).', 'errors' => ['Password must contain at least one number']];
        }
        if (!preg_match('/[\W_]/', $password)) {
            return ['valid' => false, 'message' => 'Password must contain at least one special character (e.g. @, #, $, %, !, &).', 'errors' => ['Password must contain at least one special character']];
        }
        return ['valid' => true, 'message' => 'Password meets all security requirements.', 'errors' => []];
    }
}
?>