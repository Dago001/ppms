<?php
// includes/header.php
// This file contains the common header used across all pages
// It requires $user, $totalPersonnel, $totalUsers to be set before inclusion

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Auto-detect page title from filename
$pageTitle = 'NIS-PPMS';
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
switch ($currentPage) {
    case 'dashboard': $pageTitle = 'NIS-PPMS Dashboard'; break;
    case 'search': $pageTitle = 'NIS-PPMS - Search Personnel'; break;
    case 'editp': $pageTitle = 'NIS-PPMS - Update Posting'; break;
    case 'user': $pageTitle = 'NIS-PPMS - User Management'; break;
    case 'notifications': $pageTitle = 'NIS-PPMS - Notifications'; break;
    case 'reports': $pageTitle = 'NIS-PPMS - Posting Reports'; break;
    case 'changep': $pageTitle = 'NIS-PPMS - Change Password'; break;
    default: $pageTitle = 'NIS-PPMS - ' . ucfirst(str_replace('_', ' ', $currentPage));
}

// Ensure user data is available
if (!isset($user) || empty($user)) {
    $user = [
        'username' => $_SESSION['username'] ?? 'User',
        'full_name' => $_SESSION['username'] ?? 'User',
        'role_name' => $_SESSION['role_name'] ?? 'User'
    ];
}

// Ensure stats are available
if (!isset($totalPersonnel)) $totalPersonnel = 0;
if (!isset($totalUsers)) $totalUsers = 0;

// Ensure $pdo is available
global $pdo;
if (!isset($pdo) && file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

require_once __DIR__ . '/settings_helper.php';
require_once __DIR__ . '/security.php';
if (function_exists('checkSessionTimeout')) {
    checkSessionTimeout();
}

// Maintenance Mode Enforcement
if ($currentPage !== 'login' && $currentPage !== 'ssettings' && isMaintenanceModeActive($_SESSION['role_name'] ?? '')) {
    $maintMsg = getSystemSetting('maintenance_message', 'The NIS Posting System is currently undergoing scheduled system maintenance.');
    renderMaintenancePage($maintMsg);
}

// Forced Password Change Enforcement - a user logged in with a default/reset
// password cannot reach any other page until they set their own. Checked here
// (not just at login) so it can't be bypassed by navigating straight to a URL.
if (!empty($_SESSION['must_change_password']) && !in_array($currentPage, ['login', 'changep', 'logout'])) {
    header('Location: changep');
    exit();
}

if (isset($_SESSION['user_id']) && isset($pdo)) {
    try {
        $userStmt = $pdo->prepare("SELECT u.*, r.name as role_name FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
        $userStmt->execute([$_SESSION['user_id']]);
        $fetchedUser = $userStmt->fetch();
        if ($fetchedUser) {
            $user = array_merge($user, $fetchedUser);
        }
    } catch (Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="assets/images/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/images/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/images/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="assets/images/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="192x192" href="assets/images/android-chrome-192x192.png">
    <link rel="icon" type="image/png" sizes="512x512" href="assets/images/android-chrome-512x512.png">
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#145226">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <!-- Leaflet.js Geofencing Maps -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    
    <!-- Low-Network & Weak Connection Resilience Engine -->
    <script src="assets/js/network-resilience.js" defer></script>
    
    <!-- Disable Right Click & Security Script -->
    <script src="assets/js/disable-right-click.js"></script>
    
    <!-- COMPLETE CSS - Loaded in head to prevent FOUC -->
    <style>
        /* ============================================
           SIDEBAR STYLES - GLOBAL STANDARD (NIS PPMS)
           ============================================ */
        :root {
            --sidebar-width: 215px;
            --sidebar-bg: #145226;
            --sidebar-bg-gradient: linear-gradient(180deg, #165629 0%, #0d3819 100%);
            --sidebar-hover: rgba(255, 255, 255, 0.09);
            --sidebar-active: rgba(255, 255, 255, 0.16);
            --sidebar-text: rgba(255, 255, 255, 0.88);
            --sidebar-text-muted: rgba(255, 255, 255, 0.52);
            --sidebar-border: rgba(255, 255, 255, 0.08);
            --sidebar-accent: #2ecc71;
            --sidebar-danger: #ef4444;
            --transition-speed: 0.2s;
        }

        /* ============================================
           CRITICAL FIX: Prevent white flash
           ============================================ */
        html {
            background: #0d3819;
        }

        aside.sidebar, aside.sidebar *, aside.sidebar *::before, aside.sidebar *::after {
            margin: 0; padding: 0; box-sizing: border-box;
        }

        aside.sidebar {
            width: var(--sidebar-width);
            min-width: var(--sidebar-width);
            max-width: var(--sidebar-width);
            height: 100vh;
            height: 100dvh;
            position: fixed;
            top: 0;
            left: 0;
            z-index: 1040;
            background: var(--sidebar-bg-gradient);
            color: var(--sidebar-text);
            display: flex;
            flex-direction: column;
            font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, system-ui, sans-serif;
            font-size: 11px;
            line-height: 1.35;
            box-shadow: 3px 0 25px rgba(0, 0, 0, 0.18);
            overflow-y: auto;
            overflow-x: hidden;
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            will-change: transform;
        }

        aside.sidebar::-webkit-scrollbar { width: 4px; }
        aside.sidebar::-webkit-scrollbar-track { background: transparent; }
        aside.sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.18); border-radius: 20px; }

        aside.sidebar .sidebar-profile {
            padding: 16px 14px 12px;
            border-bottom: 1px solid var(--sidebar-border);
            background: rgba(0, 0, 0, 0.18);
            flex-shrink: 0;
        }
        aside.sidebar .profile-avatar {
            display: flex; align-items: center; gap: 10px; margin-bottom: 8px;
        }
        aside.sidebar .avatar-circle {
            width: 38px; height: 38px; min-width: 38px; border-radius: 10px;
            background: rgba(255,255,255,0.12); border: 1px solid rgba(255,255,255,0.22);
            display: flex; align-items: center; justify-content: center; overflow: hidden;
            box-shadow: inset 0 1px 3px rgba(0,0,0,0.2);
        }
        aside.sidebar .avatar-logo { width: 100%; height: 100%; object-fit: contain; background: #ffffff; padding: 2px; }
        aside.sidebar .profile-info-wrapper { position: relative; flex: 1; min-width: 0; }
        aside.sidebar .profile-info { flex: 1; min-width: 0; overflow: hidden; }
        aside.sidebar .profile-name {
            font-size: 12px; font-weight: 600; color: #ffffff; margin-bottom: 3px;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis; letter-spacing: 0.1px;
        }
        aside.sidebar .profile-role {
            font-size: 9px; color: #2ecc71;
            background: rgba(46, 204, 113, 0.15); border: 1px solid rgba(46, 204, 113, 0.25);
            padding: 1px 7px; border-radius: 10px;
            display: inline-block; font-weight: 600; letter-spacing: 0.2px; text-transform: uppercase;
        }
        aside.sidebar .profile-status {
            position: absolute; top: 0; right: 0;
            width: 8px; height: 8px; background: #2ecc71;
            border-radius: 50%; box-shadow: 0 0 6px #2ecc71;
        }
        aside.sidebar .last-login {
            display: flex; align-items: center; gap: 6px; font-size: 9px;
            color: var(--sidebar-text-muted); padding-top: 8px;
            border-top: 1px solid var(--sidebar-border);
        }
        aside.sidebar .last-login i { font-size: 9px; color: #2ecc71; opacity: 0.85; }

        aside.sidebar .sidebar-nav { flex: 1; overflow-y: auto; overflow-x: hidden; padding: 8px 0; }
        aside.sidebar .nav-section { margin-bottom: 10px; }
        aside.sidebar .nav-section-title {
            font-size: 8.5px; text-transform: uppercase; letter-spacing: 0.9px;
            color: var(--sidebar-text-muted); padding: 6px 14px 4px;
            font-weight: 700; display: flex; align-items: center; gap: 6px;
        }
        aside.sidebar .nav-section-title i { font-size: 9px; width: 14px; text-align: center; color: rgba(255,255,255,0.4); }
        aside.sidebar .nav-menu { list-style: none; padding: 0; margin: 0; }
        aside.sidebar .nav-item { position: relative; }
        aside.sidebar .nav-link {
            display: flex; align-items: center; gap: 9px;
            padding: 7.5px 12px; margin: 1px 8px;
            color: var(--sidebar-text); text-decoration: none;
            border-radius: 6px; font-size: 11px; font-weight: 500;
            transition: all var(--transition-speed) ease;
            border-left: 3px solid transparent;
            white-space: nowrap;
        }
        aside.sidebar .nav-link:hover {
            background: var(--sidebar-hover); color: #ffffff;
            transform: translateX(2px);
        }
        aside.sidebar .nav-item.active .nav-link {
            background: var(--sidebar-active); color: #ffffff;
            border-left-color: var(--sidebar-accent); font-weight: 600;
            box-shadow: 0 2px 8px rgba(0,0,0,0.12);
        }
        aside.sidebar .nav-link i {
            width: 16px; font-size: 12px; text-align: center; flex-shrink: 0; opacity: 0.85;
        }
        aside.sidebar .nav-item.active .nav-link i {
            opacity: 1; color: var(--sidebar-accent);
        }
        aside.sidebar .nav-link span {
            flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }

        aside.sidebar .unread-badge {
            background: var(--sidebar-danger); color: #ffffff; font-size: 9px; font-weight: 700;
            min-width: 16px; height: 16px; padding: 0 5px; border-radius: 8px;
            display: flex; align-items: center; justify-content: center; margin-left: auto;
            flex-shrink: 0; box-shadow: 0 2px 5px rgba(239, 68, 68, 0.4);
        }

        aside.sidebar .logout-link { color: #fca5a5 !important; }
        aside.sidebar .logout-link:hover {
            background: rgba(239, 68, 68, 0.18) !important;
            border-left-color: #ef4444 !important;
            color: #ffffff !important;
        }
        aside.sidebar .logout-link i { color: #ef4444 !important; }

        aside.sidebar .sidebar-footer {
            padding: 10px 14px; border-top: 1px solid var(--sidebar-border);
            background: rgba(0, 0, 0, 0.15); flex-shrink: 0;
        }
        aside.sidebar .system-status { margin-bottom: 4px; }
        aside.sidebar .status-item {
            display: flex; justify-content: space-between; align-items: center; font-size: 9px;
        }
        aside.sidebar .status-label { color: var(--sidebar-text-muted); }
        aside.sidebar .status-badge { padding: 2px 7px; border-radius: 10px; font-size: 8px; font-weight: 600; }
        aside.sidebar .status-badge.success { background: rgba(46, 204, 113, 0.2); color: #2ecc71; border: 1px solid rgba(46, 204, 113, 0.3); }
        aside.sidebar .copyright {
            text-align: center; color: var(--sidebar-text-muted); font-size: 8px;
            padding-top: 6px; border-top: 1px solid var(--sidebar-border);
        }

        /* ============================================
           HEADER & LAYOUT STYLES
           ============================================ */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body { 
            font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, sans-serif; 
            background: #f8fafc; min-height: 100vh; color: #1e293b; 
            font-size: 14px; line-height: 1.5;
            margin-left: var(--sidebar-width) !important;
            padding-left: 0 !important;
            transition: margin-left 0.3s ease;
        }
        
        .header-container { 
            background: linear-gradient(135deg, #1a5632 0%, #1f6b3e 100%); 
            box-shadow: 0 2px 12px rgba(0,0,0,0.15); 
            padding: 0.7rem 1.5rem; position: fixed; top: 0; right: 0; 
            z-index: 999; height: 56px;
            display: flex; align-items: center; justify-content: space-between; color: #fff;
            left: var(--sidebar-width) !important;
            width: calc(100% - var(--sidebar-width)) !important;
            transition: all 0.3s ease;
        }
        .header-content { 
            display: flex; align-items: center; gap: 1.5rem; width: 100%; 
        }
        .logo-title-container { 
            display: flex; align-items: center; gap: 0.8rem; flex-shrink: 0; 
        }
        .header-logo { 
            height: 36px; object-fit: contain; background: #ffffff; padding: 2px; border-radius: 6px;
        }
        .system-title { 
            color: #fff; font-size: 1rem; font-weight: 700; 
            white-space: nowrap; letter-spacing: 0.3px; 
        }
        .user-info, .user-account-badge { 
            display: flex; align-items: center; gap: 0.6rem; margin-left: auto; 
            background: rgba(0, 0, 0, 0.2); padding: 0.35rem 0.75rem; 
            border-radius: 8px; border: 1px solid rgba(255, 255, 255, 0.18); 
            flex-shrink: 0; transition: background 0.2s ease;
        }
        .user-info:hover, .user-account-badge:hover {
            background: rgba(0, 0, 0, 0.28);
        }
        .user-profile, .officer-badge-icon { 
            width: 28px; height: 28px; background: rgba(255, 255, 255, 0.15); 
            border-radius: 6px; display: flex; align-items: center; justify-content: center; 
            color: #fff; font-weight: 600; font-size: 0.8rem; flex-shrink: 0;
        }
        .user-details, .officer-details {
            display: flex; flex-direction: column; line-height: 1.25;
        }
        .user-info strong, .officer-name { 
            font-size: 0.8rem; color: #fff; font-weight: 600; white-space: nowrap;
        }
        .user-info small, .officer-role { 
            font-size: 0.675rem; color: rgba(255, 255, 255, 0.8); font-weight: 500; text-transform: uppercase; letter-spacing: 0.02em;
        }
        .user-quick-actions {
            display: flex; align-items: center; gap: 0.35rem; padding-left: 0.5rem;
            border-left: 1px solid rgba(255, 255, 255, 0.2); margin-left: 0.2rem;
        }
        .user-quick-actions a {
            color: rgba(255, 255, 255, 0.8); font-size: 0.775rem; text-decoration: none;
            padding: 0.2rem 0.35rem; border-radius: 4px; transition: all 0.15s ease;
        }
        .user-quick-actions a:hover {
            color: #ffffff; background: rgba(255, 255, 255, 0.18);
        }
        .user-quick-actions a.logout-action:hover {
            color: #ff6b6b; background: rgba(239, 68, 68, 0.2);
        }
        
        .dashboard-container { padding: 1.25rem; padding-top: 4.5rem; width: 100%; }
        .content-wrapper { display: flex; width: 100%; }
        .main-content { flex: 1; min-width: 0; }

        /* Mobile Toggle Button */
        .sidebar-toggle-btn {
            display: none;
            position: fixed;
            top: 10px;
            left: 10px;
            z-index: 1050;
            width: 38px;
            height: 38px;
            background: var(--sidebar-bg);
            color: #ffffff;
            border: 2px solid rgba(255,255,255,0.25);
            border-radius: 10px;
            cursor: pointer;
            font-size: 18px;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 16px rgba(0,0,0,0.3);
            transition: all 0.2s ease;
        }
        .sidebar-toggle-btn:hover { 
            background: #219a52; 
            transform: scale(1.05);
        }
        .sidebar-toggle-btn:active {
            transform: scale(0.95);
        }

        /* Mobile Overlay */
        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.6);
            z-index: 1035;
            backdrop-filter: blur(2px);
            -webkit-backdrop-filter: blur(2px);
        }
        .sidebar-overlay.active { 
            display: block; 
        }

        /* ============================================
           GLOBAL TYPOGRAPHY REFINEMENT: Comfortable Sizing & Soft Weight
           ============================================ */
        body {
            font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, system-ui, sans-serif;
            font-size: 0.875rem;
            line-height: 1.5;
            color: #334155;
            -webkit-font-smoothing: antialiased;
        }

        h1, h2, h3, h4, h5, h6 {
            font-weight: 600 !important;
            color: #1e293b;
        }

        h1 { font-size: 1.15rem !important; }
        h2 { font-size: 1.05rem !important; }
        h3 { font-size: 0.9rem !important; }
        h4 { font-size: 0.85rem !important; }

        strong, b {
            font-weight: 600 !important;
            color: #1e293b;
        }

        .page-title h1, .page-header h1 {
            font-size: 1.15rem !important;
            font-weight: 600 !important;
        }

        .card-header, .card-header h3, .panel-title {
            font-size: 0.85rem !important;
            font-weight: 600 !important;
        }

        .modal-header h3, .modal-header h4, .modal-header h5 {
            color: #ffffff !important;
        }

        .table th, .data-table th, table th {
            font-weight: 600 !important;
            font-size: 0.8rem !important;
            color: #475569;
        }

        .table td, .data-table td, table td {
            font-size: 0.8rem !important;
        }

        .badge, .status-badge, .tag {
            font-weight: 500 !important;
            font-size: 0.7rem !important;
        }

        .btn, button, input, select, textarea {
            font-weight: 500 !important;
            font-size: 0.8rem !important;
        }

        /* ============================================
           GLOBAL MOBILE APP RESPONSIVENESS & TOUCH TARGETS
           ============================================ */
        button, a, input, select, textarea {
            touch-action: manipulation;
        }

        .table-container, .table-responsive {
            width: 100%;
            overflow-x: auto !important;
            -webkit-overflow-scrolling: touch;
            border-radius: 8px;
            margin-bottom: 0.75rem;
        }

        @media (max-width: 768px) {
            .dashboard-container {
                padding: 0.75rem !important;
                padding-top: 4rem !important;
            }
            .content-grid, .form-grid, .form-row, .info-grid, .info-grid-2, .summary-stats {
                grid-template-columns: 1fr !important;
                gap: 0.75rem !important;
            }
            .card {
                border-radius: 10px !important;
                margin-bottom: 1rem !important;
            }
            .card-header {
                padding: 0.65rem 0.85rem !important;
                font-size: 0.8rem !important;
            }
            .card-body {
                padding: 0.85rem !important;
            }
            .btn {
                padding: 0.4rem 0.75rem !important;
                font-size: 0.775rem !important;
            }
            .btn-action-group, .action-group, .btn-toolbar-group {
                display: flex !important;
                flex-direction: column !important;
                align-items: stretch !important;
                gap: 0.5rem !important;
                width: 100% !important;
            }
            .btn-action-group .btn, .action-group .btn, .btn-toolbar-group .btn,
            .btn-action-group a.btn, .action-group a.btn, .btn-toolbar-group a.btn {
                width: 100% !important;
                text-align: center !important;
                justify-content: center !important;
                display: inline-flex !important;
                align-items: center !important;
                box-sizing: border-box !important;
            }
            .table-container table, .table-responsive table, .data-table, .history-table, table.table {
                min-width: 680px !important;
            }
            .table-container::-webkit-scrollbar, .table-responsive::-webkit-scrollbar {
                height: 6px !important;
            }
            .table-container::-webkit-scrollbar-thumb, .table-responsive::-webkit-scrollbar-thumb {
                background: #cbd5e1 !important;
                border-radius: 6px !important;
            }
        }

        @media (max-width: 480px) {
            .dashboard-container {
                padding: 0.5rem !important;
                padding-top: 3.75rem !important;
            }
            .system-title {
                display: none;
            }
            .logo-title-container::after {
                content: "NIS-PPMS";
                color: #ffffff;
                font-weight: 700;
                font-size: 0.85rem;
                letter-spacing: 0.5px;
            }
        }

        /* ============================================
           RESPONSIVE SIDEBAR & HEADER
           ============================================ */
        @media (max-width: 991px) {
            aside.sidebar {
                transform: translateX(-100%);
                width: 260px !important;
                min-width: 260px !important;
                max-width: 260px !important;
                border-radius: 0 12px 12px 0;
                box-shadow: 4px 0 30px rgba(0,0,0,0.3);
            }
            aside.sidebar.active { 
                transform: translateX(0); 
            }
            body { 
                margin-left: 0 !important; 
            }
            .header-container { 
                left: 0 !important; 
                width: 100% !important; 
                padding-left: 60px;
            }
            .sidebar-toggle-btn { 
                display: flex; 
            }
            .sidebar-overlay.active {
                display: block;
            }
        }

        @media (max-width: 576px) {
            aside.sidebar {
                width: 80vw !important;
                min-width: 80vw !important;
                max-width: 80vw !important;
            }
        }

        @media (max-width: 768px) { 
            .header-container {
                padding: 0.6rem 1rem 0.6rem 55px;
                height: 52px;
            }
            .header-logo { height: 30px; }
            .user-info strong, .user-info small, .officer-details { display: none; }
            .user-info, .user-account-badge { padding: 0.25rem 0.4rem; }
            .user-profile, .officer-badge-icon { width: 28px; height: 28px; font-size: 0.775rem; }
            .user-quick-actions { border-left: none; margin-left: 0; padding-left: 0.2rem; }
        }
        @media (max-width: 480px) { 
            .header-container {
                padding: 0.5rem 0.75rem 0.5rem 50px;
                height: 48px;
            }
            .header-logo { height: 26px; } 
            .header-content { gap: 0.5rem; }
            .sidebar-toggle-btn {
                width: 34px !important;
                height: 34px !important;
                top: 7px !important;
                left: 7px !important;
                font-size: 15px !important;
            }
        }

        /* ============================================
           GLOBAL ICON & STAT-ICON 2-STEP SIZE REDUCTION
           ============================================ */
        .stat-icon, .summary-stat-icon, .kpi-icon, .card-icon, .profile-icon, .info-icon {
            width: 36px !important;
            height: 36px !important;
            font-size: 0.925rem !important;
            border-radius: 8px !important;
        }

        .stat-icon i, .summary-stat-icon i, .kpi-icon i, .card-icon i {
            font-size: 0.9rem !important;
        }

        /* Reduce all FontAwesome icons globally by 2 steps */
        .fas, .fa, .far, .fal, .fab, i[class*="fa-"] {
            font-size: 0.85em;
        }

        .btn i, .btn-action-sm i, .btn-sm i {
            font-size: 0.825em;
        }

        /* Network Status Badge */
        .network-status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.25rem 0.6rem;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            margin-right: 0.75rem;
            flex-shrink: 0;
            transition: all 0.3s ease;
        }
        .network-status-badge.online {
            background: rgba(46, 204, 113, 0.2);
            color: #2ecc71;
            border: 1px solid rgba(46, 204, 113, 0.35);
        }
        .network-status-badge.offline {
            background: rgba(245, 158, 11, 0.25);
            color: #f59e0b;
            border: 1px solid rgba(245, 158, 11, 0.45);
            animation: pulse-offline-badge 2s infinite;
        }
        @keyframes pulse-offline-badge {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.65; }
        }

        /* Real-Time Notification Bell & Shake Animation */
        .header-right-actions {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-left: auto;
            flex-shrink: 0;
        }

        .header-right-actions .user-account-badge {
            margin-left: 0;
        }

        .header-notif-wrapper {
            flex-shrink: 0;
            position: relative;
            display: inline-flex;
            align-items: center;
        }

        .header-notif-bell {
            background: rgba(255, 255, 255, 0.12);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: #ffffff;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            position: relative;
            transition: all 0.2s ease;
            font-size: 0.95rem;
            outline: none;
        }

        .header-notif-bell:hover {
            background: rgba(255, 255, 255, 0.25);
            transform: scale(1.05);
        }

        .header-notif-bell.shaking i {
            animation: bellShake 1.2s cubic-bezier(.36,.07,.19,.97) infinite;
            transform-origin: top center;
            color: #f59e0b !important;
        }

        @keyframes bellShake {
            0% { transform: rotate(0); }
            15% { transform: rotate(18deg); }
            30% { transform: rotate(-16deg); }
            45% { transform: rotate(12deg); }
            60% { transform: rotate(-10deg); }
            75% { transform: rotate(6deg); }
            85% { transform: rotate(-3deg); }
            100% { transform: rotate(0); }
        }

        .header-notif-badge {
            position: absolute;
            top: -3px;
            right: -3px;
            background: #ef4444;
            color: #ffffff;
            font-size: 0.65rem;
            font-weight: 700;
            padding: 1px 5px;
            border-radius: 10px;
            min-width: 17px;
            text-align: center;
            border: 2px solid #145226;
            box-shadow: 0 2px 5px rgba(0,0,0,0.3);
            line-height: 1.2;
        }

        .header-notif-dropdown {
            display: none;
            position: absolute;
            top: 48px;
            right: 0;
            width: 330px;
            background: #ffffff !important;
            border-radius: 12px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.3) !important;
            border: 1px solid #cbd5e1 !important;
            z-index: 999999 !important;
            overflow: hidden;
            box-sizing: border-box !important;
        }

        .header-notif-dropdown.active {
            display: block !important;
        }

        .header-notif-dropdown-header {
            padding: 0.75rem 1rem;
            background: #f8fafc !important;
            border-bottom: 1px solid #e2e8f0 !important;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: 700;
            font-size: 0.85rem;
            color: #1e293b !important;
        }

        .header-notif-dropdown-header .mark-all-link {
            font-size: 0.725rem;
            color: #1a5632 !important;
            text-decoration: none;
            font-weight: 600;
        }

        .header-notif-dropdown-header .mark-all-link:hover {
            text-decoration: underline;
        }

        .header-notif-dropdown-body {
            max-height: 320px;
            overflow-y: auto;
            background: #ffffff !important;
        }

        .notif-dropdown-item {
            padding: 0.75rem 0.9rem;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            gap: 0.65rem;
            align-items: flex-start;
            text-decoration: none !important;
            color: #334155 !important;
            background: #ffffff !important;
            transition: background 0.15s ease;
        }

        .notif-dropdown-item:hover {
            background: #f8fafc !important;
        }

        .notif-dropdown-item.unread {
            background: #f0fdf4 !important;
            border-left: 3px solid #1a5632 !important;
        }

        .notif-dropdown-icon {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: #dcfce7 !important;
            color: #166534 !important;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            flex-shrink: 0;
            margin-top: 2px;
        }

        .notif-dropdown-content {
            flex: 1;
            min-width: 0;
        }

        .notif-dropdown-content h5 {
            font-size: 0.775rem !important;
            margin: 0 0 0.2rem 0 !important;
            color: #1e293b !important;
            font-weight: 600 !important;
            line-height: 1.35;
        }

        .notif-dropdown-content p {
            font-size: 0.725rem !important;
            margin: 0 !important;
            color: #475569 !important;
            line-height: 1.35;
        }

        .notif-dropdown-content p strong {
            color: #1a5632 !important;
        }

        .notif-dropdown-time {
            font-size: 0.675rem !important;
            color: #64748b !important;
            margin-top: 0.25rem;
        }

        .header-notif-dropdown-footer {
            padding: 0.65rem;
            background: #f8fafc !important;
            border-top: 1px solid #e2e8f0 !important;
            text-align: center;
        }

        .header-notif-dropdown-footer a {
            font-size: 0.775rem;
            color: #1a5632 !important;
            font-weight: 600;
            text-decoration: none;
        }

        @media (max-width: 640px) {
            .header-notif-dropdown {
                position: fixed !important;
                top: 55px !important;
                left: 12px !important;
                right: 12px !important;
                width: auto !important;
                max-width: none !important;
                box-shadow: 0 20px 40px rgba(0,0,0,0.4) !important;
            }
        }
    </style>
</head>
<body>
    <!-- Mobile Sidebar Toggle Button -->
    <button type="button" class="sidebar-toggle-btn" id="sidebarToggleBtn" aria-label="Toggle Sidebar Menu">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Mobile Sidebar Backdrop Overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Header Bar -->
    <div class="header-container">
        <div class="header-content">
            <div class="logo-title-container" style="display:flex; align-items:center; gap:0.75rem;">
                <h1 class="system-title">NIS PERSONNEL POSTING MANAGEMENT SYSTEM</h1>
            </div>
            
            <div class="header-right-actions">
                <!-- Real-Time Notification Bell & Shake Effect -->
                <div class="header-notif-wrapper">
                    <button type="button" class="header-notif-bell" id="headerNotifBell" onclick="toggleHeaderNotifDropdown(event)" title="Real-Time Notifications">
                        <i class="fas fa-bell" id="headerBellIcon"></i>
                        <span class="header-notif-badge" id="headerNotifBadge" style="display:none;">0</span>
                    </button>

                    <!-- Notification Dropdown Popover -->
                    <div class="header-notif-dropdown" id="headerNotifDropdown" onclick="event.stopPropagation();">
                        <div class="header-notif-dropdown-header">
                            <span><i class="fas fa-bell"></i> Notifications</span>
                            <a href="notifications?mark_all_read=1" class="mark-all-link">Mark all read</a>
                        </div>
                        <div class="header-notif-dropdown-body" id="headerNotifList">
                            <div style="text-align:center; padding:1.5rem; color:#64748b; font-size:0.8rem;">
                                <i class="fas fa-spinner fa-spin" style="color:#1a5632; font-size:1.2rem; display:block; margin-bottom:0.4rem;"></i>
                                Loading notifications...
                            </div>
                        </div>
                        <div class="header-notif-dropdown-footer">
                            <a href="notifications"><i class="fas fa-list-ul"></i> View All Notifications</a>
                        </div>
                    </div>
                </div>

                <div class="user-account-badge" onclick="window.location.href='profile';" style="cursor:pointer;" title="Click to view My Profile & 2FA">
                    <div class="officer-badge-icon" style="display:flex; align-items:center; justify-content:center; overflow:hidden;">
                        <?php if (!empty($user['profile_photo']) && file_exists(__DIR__ . '/../uploads/profile_photos/' . $user['profile_photo'])): ?>
                            <img src="uploads/profile_photos/<?php echo htmlspecialchars($user['profile_photo']); ?>" alt="User Photo" style="width:34px; height:34px; border-radius:50%; object-fit:cover; border:2px solid #2ecc71;">
                        <?php else: ?>
                            <i class="fas fa-user-shield"></i>
                        <?php endif; ?>
                    </div>
                    <div class="officer-details">
                        <span class="officer-name"><?php echo htmlspecialchars($user['full_name'] ?? $user['username']); ?></span>
                        <span class="officer-role"><?php echo htmlspecialchars($user['role_name'] ?? 'User'); ?></span>
                    </div>
                    <div class="user-quick-actions" onclick="event.stopPropagation();">
                        <a href="profile" title="My Profile & 2FA Settings"><i class="fas fa-user-cog"></i></a>
                        <a href="changep" title="Change Password"><i class="fas fa-key"></i></a>
                        <a href="logout" title="Logout" class="logout-action"><i class="fas fa-sign-out-alt"></i></a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    // Mobile Sidebar Toggle JavaScript Logic
    document.addEventListener('DOMContentLoaded', function() {
        const toggleBtn = document.getElementById('sidebarToggleBtn');
        const overlay = document.getElementById('sidebarOverlay');

        function getSidebar() {
            return document.getElementById('mainSidebar') || document.querySelector('aside.sidebar');
        }

        function toggleSidebar(e) {
            if (e) e.stopPropagation();
            const sidebar = getSidebar();
            if (sidebar) sidebar.classList.toggle('active');
            if (overlay) overlay.classList.toggle('active');
        }

        function closeSidebar() {
            const sidebar = getSidebar();
            if (sidebar) sidebar.classList.remove('active');
            if (overlay) overlay.classList.remove('active');
        }

        if (toggleBtn) {
            toggleBtn.addEventListener('click', toggleSidebar);
        }

        if (overlay) {
            overlay.addEventListener('click', closeSidebar);
        }

        document.querySelectorAll('aside.sidebar .nav-link').forEach(function(link) {
            link.addEventListener('click', closeSidebar);
        });
    });

    // Offline PWA Service Worker Registration
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function() {
            navigator.serviceWorker.register('sw.js')
                .then(function(reg) { console.log('[PWA] Offline ServiceWorker Active:', reg.scope); })
                .catch(function(err) { console.warn('[PWA] ServiceWorker registration notice:', err); });
        });
    }

    // Real-time Network Connection Detector
    function updateNetworkStatusIndicator() {
        const badge = document.getElementById('networkStatusBadge');
        if (!badge) return;
        if (navigator.onLine) {
            badge.className = 'network-status-badge online';
            badge.innerHTML = '<i class="fas fa-wifi"></i> <span>Online</span>';
            badge.title = 'Application Online - Connected to Server';
        } else {
            badge.className = 'network-status-badge offline';
            badge.innerHTML = '<i class="fas fa-wifi-slash"></i> <span>Offline Mode</span>';
            badge.title = 'Application Offline - Local Cache Active';
        }
    }
    window.addEventListener('online', updateNetworkStatusIndicator);
    window.addEventListener('offline', updateNetworkStatusIndicator);
    document.addEventListener('DOMContentLoaded', updateNetworkStatusIndicator);

    // ============================================
    // REAL-TIME NOTIFICATION BELL, SHAKE & SOUND ENGINE
    // ============================================
    let previousUnreadCount = -1;
    let notifAudioCtx = null;

    function playNotificationChime() {
        try {
            if (!notifAudioCtx) {
                notifAudioCtx = new (window.AudioContext || window.webkitAudioContext)();
            }
            if (notifAudioCtx.state === 'suspended') {
                notifAudioCtx.resume();
            }
            
            const now = notifAudioCtx.currentTime;
            
            // Dual-tone crystal chime (Tone 1: E6 1318.51Hz, Tone 2: A6 1760Hz)
            const osc1 = notifAudioCtx.createOscillator();
            const gain1 = notifAudioCtx.createGain();
            osc1.type = 'sine';
            osc1.frequency.setValueAtTime(1318.51, now);
            gain1.gain.setValueAtTime(0.12, now);
            gain1.gain.exponentialRampToValueAtTime(0.001, now + 0.35);
            osc1.connect(gain1);
            gain1.connect(notifAudioCtx.destination);
            osc1.start(now);
            osc1.stop(now + 0.35);

            const osc2 = notifAudioCtx.createOscillator();
            const gain2 = notifAudioCtx.createGain();
            osc2.type = 'sine';
            osc2.frequency.setValueAtTime(1760, now + 0.1);
            gain2.gain.setValueAtTime(0.15, now + 0.1);
            gain2.gain.exponentialRampToValueAtTime(0.001, now + 0.5);
            osc2.connect(gain2);
            gain2.connect(notifAudioCtx.destination);
            osc2.start(now + 0.1);
            osc2.stop(now + 0.5);
        } catch (e) {
            console.warn('Audio chime fallback:', e);
        }
    }

    function toggleHeaderNotifDropdown(e) {
        if (e) e.stopPropagation();
        const dropdown = document.getElementById('headerNotifDropdown');
        if (dropdown) {
            dropdown.classList.toggle('active');
            if (dropdown.classList.contains('active')) {
                fetchRealtimeNotifications();
            }
        }
    }

    document.addEventListener('click', function(e) {
        const dropdown = document.getElementById('headerNotifDropdown');
        if (dropdown && dropdown.classList.contains('active')) {
            dropdown.classList.remove('active');
        }
    });

    function fetchRealtimeNotifications() {
        fetch('api/get_unread_notifications.php')
            .then(res => res.json())
            .then(data => {
                if (!data.success) return;
                
                const unreadCount = parseInt(data.unread_count || 0, 10);
                const bell = document.getElementById('headerNotifBell');
                const badge = document.getElementById('headerNotifBadge');
                const list = document.getElementById('headerNotifList');

                // Update unread count badge & bell shaking animation
                if (unreadCount > 0) {
                    if (badge) {
                        badge.textContent = unreadCount > 99 ? '99+' : unreadCount;
                        badge.style.display = 'inline-block';
                    }
                    if (bell) {
                        bell.classList.add('shaking');
                    }

                    // Play chime sound if new unread notifications arrived
                    if (previousUnreadCount !== -1 && unreadCount > previousUnreadCount) {
                        playNotificationChime();
                    }
                } else {
                    if (badge) {
                        badge.style.display = 'none';
                    }
                    if (bell) {
                        bell.classList.remove('shaking'); // Stop shaking when 0 unread
                    }
                }

                // First load sound check
                if (previousUnreadCount === -1 && unreadCount > 0) {
                    document.addEventListener('click', function prewarmAudio() {
                        if (unreadCount > 0) playNotificationChime();
                        document.removeEventListener('click', prewarmAudio);
                    }, { once: true });
                }

                previousUnreadCount = unreadCount;

                // Populate Dropdown Body
                if (list) {
                    if (!data.notifications || data.notifications.length === 0) {
                        list.innerHTML = '<div style="text-align:center; padding:1.5rem; color:#64748b; font-size:0.8rem;"><i class="fas fa-bell-slash" style="font-size:1.2rem; display:block; margin-bottom:0.4rem; color:#cbd5e1;"></i>No notifications</div>';
                    } else {
                        let html = '';
                        data.notifications.forEach(n => {
                            const isUnread = n.is_read == 0;
                            html += `
                                <a href="notifications" class="notif-dropdown-item ${isUnread ? 'unread' : ''}">
                                    <div class="notif-dropdown-icon"><i class="fas fa-exchange-alt"></i></div>
                                    <div class="notif-dropdown-content">
                                        <h5>${escapeNotifHtml(n.officer_name)} (${escapeNotifHtml(n.officer_rank)})</h5>
                                        <p>Posted to <strong>${escapeNotifHtml(n.posting_location)}</strong> by ${escapeNotifHtml(n.posted_by)}</p>
                                        <div class="notif-dropdown-time"><i class="fas fa-clock me-1"></i> ${escapeNotifHtml(n.time_ago)}</div>
                                    </div>
                                </a>
                            `;
                        });
                        list.innerHTML = html;
                    }
                }
            })
            .catch(err => console.warn('[NotifEngine] Poll notice:', err));
    }

    function escapeNotifHtml(str) {
        return String(str || '').replace(/[&<>"']/g, function(m) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
        });
    }

    // Poll every 10 seconds for real-time notifications
    document.addEventListener('DOMContentLoaded', function() {
        fetchRealtimeNotifications();
        setInterval(fetchRealtimeNotifications, 10000);
    });
    </script>

    <!-- Sidebar - Included immediately after header -->
    <?php include __DIR__ . '/sidebar.php'; ?>

    <!-- Dashboard Container -->
    <div class="dashboard-container">
        <div class="content-wrapper">
            <!-- Main Content -->
            <div class="main-content">