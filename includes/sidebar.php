<?php
// includes/sidebar.php
// Official NIS-PPMS Global Standard Navigation Sidebar Structure
// Dynamic Real-time Role & Permission Enforcement Engine

global $pdo;
if (!isset($pdo) && file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

require_once __DIR__ . '/permissions.php';

// Fetch fresh user profile & active role directly from DB for instant real-time updates
if (isset($_SESSION['user_id']) && isset($pdo)) {
    try {
        $stmtS = $pdo->prepare("SELECT u.username, u.full_name, r.name as role_name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
        $stmtS->execute([$_SESSION['user_id']]);
        $freshU = $stmtS->fetch(PDO::FETCH_ASSOC);
        if ($freshU) {
            $user['role_name'] = $freshU['role_name'];
            $_SESSION['role_name'] = $freshU['role_name'];
            if (!empty($freshU['full_name'])) $user['full_name'] = $freshU['full_name'];
        }
    } catch (Exception $e) {}
}

if (!isset($user) || empty($user)) {
    $user = [
        'username' => $_SESSION['username'] ?? 'Officer',
        'full_name' => $_SESSION['username'] ?? 'Officer',
        'role_name' => $_SESSION['role_name'] ?? 'User'
    ];
}

$currentPage = basename($_SERVER['PHP_SELF'], '.php');
?>

<!-- Sidebar HTML Structure -->
<aside class="sidebar" id="mainSidebar" aria-label="Main navigation">
    <!-- User Profile & Officer Badge Section -->
    <div class="sidebar-profile">
        <div class="profile-avatar">
            <div class="avatar-circle">
                <img src="assets/images/logo.png" alt="NIS Logo" class="avatar-logo" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                <i class="fas fa-shield-halved" style="display:none; color:#2ecc71; font-size:16px;"></i>
            </div>
            <div class="profile-info-wrapper">
                <div class="profile-info">
                    <h4 class="profile-name"><?php echo htmlspecialchars($user['full_name'] ?? $user['username']); ?></h4>
                    <span class="profile-role"><?php echo htmlspecialchars($user['role_name']); ?></span>
                </div>
                <div class="profile-status" title="Officer Active Online"></div>
            </div>
        </div>
        <div class="last-login">
            <i class="fas fa-clock"></i>
            <span>Active: <?php echo date('M j, Y g:i A'); ?></span>
        </div>
    </div>

    <!-- Navigation Menu -->
    <nav class="sidebar-nav" aria-label="Sidebar navigation">
        <!-- Main Dashboard Section -->
        <div class="nav-section">
            <h5 class="nav-section-title"><i class="fas fa-compass"></i><span>Main Menu</span></h5>
            <ul class="nav-menu">
                <?php if (hasPermission('view_dashboard')): ?>
                <li class="nav-item <?php echo $currentPage == 'dashboard' ? 'active' : ''; ?>">
                    <a href="dashboard" class="nav-link">
                        <i class="fas fa-chart-pie"></i><span>Dashboard</span>
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </div>

        <!-- Operations & Posting Management Section -->
        <div class="nav-section">
            <h5 class="nav-section-title"><i class="fas fa-paper-plane"></i><span>Operations</span></h5>
            <ul class="nav-menu">

                <?php if (hasPermission('search_personnel')): ?>
                <li class="nav-item <?php echo $currentPage == 'search' || $currentPage == 'editp' ? 'active' : ''; ?>">
                    <a href="search" class="nav-link">
                        <i class="fas fa-search"></i><span>Posting</span>
                    </a>
                </li>
                <?php endif; ?>

                <?php if (canInitiatePosting() && hasPermission('bulk_posting')): ?>
                <li class="nav-item <?php echo $currentPage == 'bulk' ? 'active' : ''; ?>">
                    <a href="bulk" class="nav-link">
                        <i class="fas fa-file-csv"></i><span>Bulk Posting</span>
                    </a>
                </li>

                <li class="nav-item <?php echo $currentPage == 'notifications' ? 'active' : ''; ?>">
                    <a href="notifications" class="nav-link">
                        <i class="fas fa-bell"></i><span>Notifications</span>
                        <?php 
                        $currentUserId = $_SESSION['user_id'] ?? 0;
                        $unreadBadge = 0;
                        try {
                            if (isset($pdo)) {
                                if (in_array($_SESSION['role_name'] ?? '', ['admin', 'Service HQ', 'Super Admin'])) {
                                    $stmt = $pdo->query("SELECT COUNT(*) as count FROM posting_notifications WHERE is_read = 0");
                                    $unreadBadge = $stmt->fetch()['count'] ?? 0;
                                }
                            }
                        } catch (Exception $e) { $unreadBadge = 0; }
                        if ($unreadBadge > 0): 
                        ?>
                            <span class="unread-badge"><?php echo $unreadBadge > 99 ? '99+' : $unreadBadge; ?></span>
                        <?php endif; ?>
                    </a>
                </li>

<li class="nav-item <?php echo $currentPage == 'recent' ? 'active' : ''; ?>">
                    <a href="recent" class="nav-link">
                        <i class="fas fa-history"></i><span>Recent Postings</span>
                    </a>
                </li>

                <?php endif; ?>
            </ul>
        </div>

        <!-- Reports & Intelligence Section -->
        <div class="nav-section">
            <h5 class="nav-section-title"><i class="fas fa-chart-bar"></i><span>Reports & Intelligence</span></h5>
            <ul class="nav-menu">
                <?php if (hasPermission('view_reports') || hasPermission('view_analytics')): ?>
                <li class="nav-item <?php echo $currentPage == 'reports' || $currentPage == 'report_dashboard' ? 'active' : ''; ?>">
                    <a href="reports" class="nav-link">
                        <i class="fas fa-file-invoice"></i><span>Report</span>
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </div>

        <!-- Administration Section -->
        <?php if (hasPermission('manage_disciplinary') || hasPermission('manage_users') || isAdmin() || isServiceHQ()): ?>
        <div class="nav-section">
            <h5 class="nav-section-title"><i class="fas fa-user-shield"></i><span>Administration</span></h5>
            <ul class="nav-menu">
                <?php if (hasPermission('manage_disciplinary')): ?>
                <li class="nav-item <?php echo $currentPage == 'disciplinary' ? 'active' : ''; ?>">
                    <a href="disciplinary" class="nav-link">
                        <i class="fas fa-gavel"></i><span>Disciplinary Board</span>
                    </a>
                </li>
                <?php endif; ?>

                <?php if (isAdmin() || isServiceHQ() || hasPermission('manage_users')): ?>
                <li class="nav-item <?php echo $currentPage == 'user' ? 'active' : ''; ?>">
                    <a href="user" class="nav-link">
                        <i class="fas fa-users-cog"></i><span>User Accounts</span>
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </div>
        <?php endif; ?>

        <!-- Account & Security Section -->
        <div class="nav-section">
            <h5 class="nav-section-title"><i class="fas fa-lock"></i><span>Account & System Help</span></h5>
            <ul class="nav-menu">
                <li class="nav-item <?php echo $currentPage == 'profile' ? 'active' : ''; ?>">
                    <a href="profile" class="nav-link">
                        <i class="fas fa-user-cog"></i><span>My Profile</span>
                    </a>
                </li>

                <?php if (hasPermission('roles_permissions')): ?>
                <li class="nav-item <?php echo $currentPage == 'roles' ? 'active' : ''; ?>">
                    <a href="roles" class="nav-link" style="color:#0284c7;">
                        <i class="fas fa-user-shield"></i><span>Role & Permission</span>
                    </a>
                </li>
                <?php endif; ?>

                <?php if (hasPermission('system_settings')): ?>
                <li class="nav-item <?php echo $currentPage == 'ssettings' ? 'active' : ''; ?>">
                    <a href="ssettings" class="nav-link" style="color:#d97706;">
                        <i class="fas fa-sliders-h"></i><span>System Setting</span>
                    </a>
                </li>
                <?php endif; ?>
                
                <li class="nav-item">
                    <a href="logout" class="nav-link logout-link">
                        <i class="fas fa-sign-out-alt"></i><span>Logout</span>
                    </a>
                </li>
            </ul>
        </div>
    </nav>
</aside>