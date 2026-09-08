<?php
require __DIR__ . '/../includes/config.php';

echo "=== SETTING UP ROLES & PERMISSIONS DATABASE STRUCTURE ===\n\n";

// 1. Create permissions table
$sql1 = "CREATE TABLE IF NOT EXISTS permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    permission_key VARCHAR(60) NOT NULL UNIQUE,
    permission_name VARCHAR(100) NOT NULL,
    category VARCHAR(50) DEFAULT 'General',
    description VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$pdo->exec($sql1);
echo "[SUCCESS] Created permissions table.\n";

// 2. Create role_permissions table
$sql2 = "CREATE TABLE IF NOT EXISTS role_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_id INT NOT NULL,
    permission_key VARCHAR(60) NOT NULL,
    is_granted TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_role_perm (role_id, permission_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$pdo->exec($sql2);
echo "[SUCCESS] Created role_permissions table.\n";

// 3. Create user_permissions table (For granular per-user overrides: give or deny)
$sql3 = "CREATE TABLE IF NOT EXISTS user_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    permission_key VARCHAR(60) NOT NULL,
    is_granted TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_perm (user_id, permission_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$pdo->exec($sql3);
echo "[SUCCESS] Created user_permissions table.\n";

// 4. Seed Standard System Permissions
$standardPermissions = [
    ['view_dashboard', 'View Executive Dashboard & KPI Metrics', 'General Overview', 'Access to main executive dashboard and counter stats'],
    ['search_personnel', 'Search & View Officer Profiles', 'Personnel Roster', 'Access to personnel roster, bio-data search, and employment records'],
    ['edit_posting', 'Execute Single Officer Posting Movement Order', 'Posting Operations', 'Ability to re-post an officer and generate posting letters'],
    ['bulk_posting', 'Execute Bulk CSV Batch Postings', 'Posting Operations', 'Ability to upload CSV batch postings with pre-validation'],
    ['manage_disciplinary', 'Manage Disciplinary Board & Sanctions', 'Disciplinary Guard', 'Ability to update disciplinary status, queries, and interdictions'],
    ['view_analytics', 'View Analytics & 5+ Year Overstay Engine', 'Intelligence', 'Access to formation heatmaps, demographic charts, and 5+ yr overstay recommendations'],
    ['view_reports', 'Generate Custom Reports & Classified Watermarked Prints', 'Reports & Printing', 'Ability to build custom report queries and print watermarked documents'],
    ['manage_users', 'User Account Management & Password Resets', 'System Management', 'Ability to create users, assign roles, and reset passwords'],
    ['system_settings', 'Master System Settings & Global Parameters', 'Super Admin Master', 'Access to global maintenance mode, 2FA, rules, and system settings'],
    ['roles_permissions', 'Role & Granular Permission Management Portal', 'Security & RBAC', 'Ability to assign roles and grant or deny specific permissions to users'],
    ['api_access', 'Global RESTful API Gateway Access', 'API Integration', 'Ability to generate API keys and consume external RESTful API endpoints']
];

$stmtSeed = $pdo->prepare("INSERT INTO permissions (permission_key, permission_name, category, description) 
    VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE permission_name = VALUES(permission_name), category = VALUES(category), description = VALUES(description)");

foreach ($standardPermissions as $p) {
    $stmtSeed->execute($p);
}
echo "[SUCCESS] Seeded " . count($standardPermissions) . " standard system permissions.\n";

// 5. Seed default Role Permissions
// Roles: 1=admin, 2=user, 3=Super Admin, 4=SHQ Admin, 5=Zone Admin, 6=Command Admin, 7=Zone User, 8=Command User
$roleDefaults = [
    // Super Admin (3) & Admin (1): All permissions granted
    3 => ['view_dashboard', 'search_personnel', 'edit_posting', 'bulk_posting', 'manage_disciplinary', 'view_analytics', 'view_reports', 'manage_users', 'system_settings', 'roles_permissions', 'api_access'],
    1 => ['view_dashboard', 'search_personnel', 'edit_posting', 'bulk_posting', 'manage_disciplinary', 'view_analytics', 'view_reports', 'manage_users', 'system_settings', 'roles_permissions', 'api_access'],
    
    // SHQ Admin (4): High operational access without System Settings / Roles
    4 => ['view_dashboard', 'search_personnel', 'edit_posting', 'bulk_posting', 'manage_disciplinary', 'view_analytics', 'view_reports', 'manage_users'],
    
    // Zone Admin (5) & Command Admin (6)
    5 => ['view_dashboard', 'search_personnel', 'view_analytics', 'view_reports'],
    6 => ['view_dashboard', 'search_personnel', 'view_analytics', 'view_reports'],
    
    // Regular Users (2, 7, 8)
    2 => ['view_dashboard', 'search_personnel'],
    7 => ['view_dashboard', 'search_personnel'],
    8 => ['view_dashboard', 'search_personnel']
];

$stmtRolePerm = $pdo->prepare("INSERT INTO role_permissions (role_id, permission_key, is_granted) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE is_granted = 1");

foreach ($roleDefaults as $roleId => $permKeys) {
    foreach ($permKeys as $pk) {
        $stmtRolePerm->execute([$roleId, $pk]);
    }
}
echo "[SUCCESS] Seeded default role permission mappings.\n";
echo "\n=== MIGRATION COMPLETE ===\n";
