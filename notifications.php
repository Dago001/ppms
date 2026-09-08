<?php
session_start();
require_once 'includes/config.php';
require_once 'includes/auth.php';
require_once 'includes/notification_helpers.php';
require_once 'includes/NotificationService.php';

if (!isLoggedIn()) { header('Location: login'); exit(); }

// Get user info
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

// Initialize NotificationService
$notificationService = new NotificationService($pdo);

// Get user's zone names for filtering
$userRole = $user['role_name'] ?? '';
$userId = $_SESSION['user_id'] ?? 0;
$userZoneNames = [];

if (!in_array(strtolower($userRole), ['admin', 'service hq', 'shq admin', 'super admin', 'user'])) {
    try {
        $stmt = $pdo->prepare("SELECT z.zone_name FROM user_zones uz JOIN zones z ON uz.zone_id = z.id WHERE uz.user_id = ?");
        $stmt->execute([$userId]);
        $userZoneNames = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        $userZoneNames = [];
    }
}

$csrfToken = generateCSRFToken();

// Handle Admit Officer (Officer reported to formation)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admit_officer']) && !validateCSRFToken($_POST['csrf_token'] ?? '')) {
    $error = "Security validation failed. Please refresh the page and try again.";
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admit_officer'])) {
    $notification_id = intval($_POST['notification_id']);
    $admitted_by = $user['full_name'] ?? $user['username'];
    $admitted_zone = implode(', ', $userZoneNames);
    
    try {
        $stmt = $pdo->prepare("UPDATE posting_notifications SET status = 'admitted', admitted_at = NOW(), admitted_by = ? WHERE id = ?");
        $stmt->execute([$admitted_by, $notification_id]);
        
        $stmt = $pdo->prepare("SELECT * FROM posting_notifications WHERE id = ?");
        $stmt->execute([$notification_id]);
        $notif_details = $stmt->fetch();
        
        if ($notif_details) {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE full_name = ? OR username = ?");
            $stmt->execute([$notif_details['posted_by'], $notif_details['posted_by']]);
            $posted_by_user = $stmt->fetch();
            
            $message = "Officer " . $notif_details['officer_name'] . " (NIS No: " . $notif_details['serviceNo'] . ") has reported to " . $notif_details['posting_location'] . " and has been admitted.";
            
            if ($posted_by_user) {
                $stmt = $pdo->prepare("INSERT INTO posting_notifications (serviceNo, officer_name, officer_rank, posting_location, posting_zone, posting_date, posted_by, created_by, message, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'admitted')");
                $stmt->execute([
                    $notif_details['serviceNo'],
                    $notif_details['officer_name'],
                    $notif_details['officer_rank'],
                    $notif_details['posting_location'],
                    $notif_details['posting_zone'],
                    $notif_details['posting_date'],
                    $admitted_by . ' (' . $admitted_zone . ')',
                    $posted_by_user['id'],
                    $message
                ]);
            }
            
            $notificationService->sendAdmitConfirmationSMS(
                $notif_details['serviceNo'],
                $notif_details['officer_name'],
                $notif_details['posting_location']
            );
            
            $notificationService->sendPostingEmail(
                $notif_details['serviceNo'],
                $notif_details['officer_name'],
                $notif_details['officer_rank'],
                $notif_details['posting_location'],
                $notif_details['posting_date']
            );
            
            if (class_exists('CacheManager')) { CacheManager::delete('dashboard_analytics'); }
            header('Location: notifications?msg=admitted');
            exit();
        }
    } catch (PDOException $e) {
        header('Location: notifications?msg=error');
        exit();
    }
}

// Handle Report Officer (Officer did not report)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['report_officer']) && !validateCSRFToken($_POST['csrf_token'] ?? '')) {
    $error = "Security validation failed. Please refresh the page and try again.";
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['report_officer'])) {
    $notification_id = intval($_POST['notification_id']);
    $reported_by = $user['full_name'] ?? $user['username'];
    $reported_zone = implode(', ', $userZoneNames);
    
    try {
        $stmt = $pdo->prepare("UPDATE posting_notifications SET status = 'reported', reported_at = NOW(), reported_by = ? WHERE id = ?");
        $stmt->execute([$reported_by, $notification_id]);
        
        $stmt = $pdo->prepare("SELECT * FROM posting_notifications WHERE id = ?");
        $stmt->execute([$notification_id]);
        $notif_details = $stmt->fetch();
        
        if ($notif_details) {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE full_name = ? OR username = ?");
            $stmt->execute([$notif_details['posted_by'], $notif_details['posted_by']]);
            $posted_by_user = $stmt->fetch();
            
            $message = "OFFICER NOT REPORTED: Officer " . $notif_details['officer_name'] . " (NIS No: " . $notif_details['serviceNo'] . ") has NOT reported to " . $notif_details['posting_location'] . " as of " . date('d/m/Y') . ". Action required.";
            
            if ($posted_by_user) {
                $stmt = $pdo->prepare("INSERT INTO posting_notifications (serviceNo, officer_name, officer_rank, posting_location, posting_zone, posting_date, posted_by, created_by, message, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'reported')");
                $stmt->execute([
                    $notif_details['serviceNo'],
                    $notif_details['officer_name'],
                    $notif_details['officer_rank'],
                    $notif_details['posting_location'],
                    $notif_details['posting_zone'],
                    $notif_details['posting_date'],
                    $reported_by . ' (' . $reported_zone . ')',
                    $posted_by_user['id'],
                    $message
                ]);
            }
            
            $daysSincePosting = floor((time() - strtotime($notif_details['posting_date'])) / 86400);
            $notificationService->sendReminderSMS(
                $notif_details['serviceNo'],
                $notif_details['officer_name'],
                $notif_details['posting_location'],
                $notif_details['posting_date'],
                $daysSincePosting
            );
            
            $notificationService->sendReminderEmail(
                $notif_details['serviceNo'],
                $notif_details['officer_name'],
                $notif_details['posting_location'],
                $notif_details['posting_date'],
                $daysSincePosting
            );
            
            if (class_exists('CacheManager')) { CacheManager::delete('dashboard_analytics'); }
            header('Location: notifications?msg=reported');
            exit();
        }
    } catch (PDOException $e) {
        header('Location: notifications?msg=error');
        exit();
    }
}

// Handle mark as read
if (isset($_GET['mark_read']) && is_numeric($_GET['mark_read'])) {
    markNotificationAsRead($_GET['mark_read']);
    header('Location: notifications');
    exit();
}

// Handle mark all as read
if (isset($_GET['mark_all_read'])) {
    markAllNotificationsAsRead($userZoneNames);
    header('Location: notifications');
    exit();
}

// Auto-check for officers not admitted after 14 days
try {
    $stmt = $pdo->prepare("
        SELECT * FROM posting_notifications 
        WHERE status = 'pending' 
        AND created_at <= DATE_SUB(NOW(), INTERVAL 14 DAY)
        AND auto_reported = 0
    ");
    $stmt->execute();
    $overdue_postings = $stmt->fetchAll();
    
    foreach ($overdue_postings as $posting) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE full_name = ? OR username = ?");
        $stmt->execute([$posting['posted_by'], $posting['posted_by']]);
        $posted_by_user = $stmt->fetch();
        
        if ($posted_by_user) {
            $message = "AUTO ALERT: Officer " . $posting['officer_name'] . " (NIS No: " . $posting['serviceNo'] . ") has NOT reported to " . $posting['posting_location'] . " within 14 days. Status: Not Admitted.";
            
            $stmt = $pdo->prepare("INSERT INTO posting_notifications (serviceNo, officer_name, officer_rank, posting_location, posting_zone, posting_date, posted_by, created_by, message, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'auto_reported')");
            $stmt->execute([
                $posting['serviceNo'],
                $posting['officer_name'],
                $posting['officer_rank'],
                $posting['posting_location'],
                $posting['posting_zone'],
                $posting['posting_date'],
                'System Auto-Report',
                $posted_by_user['id'],
                $message
            ]);
            
            $stmt = $pdo->prepare("UPDATE posting_notifications SET auto_reported = 1 WHERE id = ?");
            $stmt->execute([$posting['id']]);
            
            $daysSincePosting = floor((time() - strtotime($posting['posting_date'])) / 86400);
            $notificationService->sendReminderSMS(
                $posting['serviceNo'],
                $posting['officer_name'],
                $posting['posting_location'],
                $posting['posting_date'],
                $daysSincePosting
            );
            $notificationService->sendReminderEmail(
                $posting['serviceNo'],
                $posting['officer_name'],
                $posting['posting_location'],
                $posting['posting_date'],
                $daysSincePosting
            );
        }
    }
} catch (PDOException $e) {}

// Get zone-filtered notifications
$notifications = getNotifications($userZoneNames, 1000);
$unreadCount = getUnreadNotificationCount($userZoneNames);

$totalCount = count($notifications);
$pendingCount = 0;
$admittedCount = 0;
$reportedCount = 0;

foreach ($notifications as $n) {
    $st = $n['status'] ?? 'pending';
    if ($st === 'admitted') $admittedCount++;
    elseif ($st === 'reported' || $st === 'auto_reported') $reportedCount++;
    else $pendingCount++;
}

// Success/Error messages
$msg = $_GET['msg'] ?? '';
$msgText = '';
if ($msg === 'admitted') $msgText = 'Officer has been admitted successfully. SMS and email notifications have been sent.';
if ($msg === 'reported') $msgText = 'Officer has been reported. Reminder notifications have been sent.';
if ($msg === 'error') $msgText = 'An error occurred. Please try again.';
?>

<?php include 'includes/header.php'; ?>

<style>
    /* Executive Senior Software Developer UI Design for NIS-PPMS Notifications */
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

    /* Summary Metric Cards Bar */
    .metric-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
        margin-bottom: 1.25rem;
    }
    .metric-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 0.85rem 1.1rem;
        display: flex;
        align-items: center;
        gap: 0.85rem;
        box-shadow: 0 1px 3px rgba(0,0,0,0.03);
        transition: transform 0.15s ease, box-shadow 0.15s ease;
    }
    .metric-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.06);
    }
    .metric-icon {
        width: 42px;
        height: 42px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
        flex-shrink: 0;
    }
    .metric-icon.total { background: #e0e7ff; color: #3730a3; }
    .metric-icon.pending { background: #fef3c7; color: #d97706; }
    .metric-icon.admitted { background: #d1fae5; color: #059669; }
    .metric-icon.reported { background: #fee2e2; color: #dc2626; }
    .metric-info { display: flex; flex-direction: column; }
    .metric-val { font-size: 1.40rem; font-weight: 600; color: #1a5632; line-height: 1.1; margin-bottom: 0.25rem; }
    .metric-lbl { font-size: 0.75rem; font-weight: 600; color: #64748b; margin-top: 0.15rem; text-transform: uppercase; letter-spacing: 0.02em; }

    /* Compact Toolbar Filter Bar */
    .filter-bar {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 0.65rem 0.85rem;
        margin-bottom: 1.25rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 0.75rem;
        box-shadow: 0 1px 3px rgba(0,0,0,0.03);
    }
    
    .filter-group {
        display: flex;
        align-items: center;
        gap: 0.4rem;
        flex-wrap: wrap;
    }
    
    .filter-btn {
        padding: 0.38rem 0.8rem;
        font-size: 0.8rem;
        font-weight: 600;
        border-radius: 8px;
        background: #f8fafc;
        color: #475569;
        border: 1px solid #cbd5e1;
        cursor: pointer;
        transition: all 0.15s ease;
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
    }
    .filter-btn:hover { background: #f1f5f9; color: #1e293b; }
    .filter-btn.active {
        background: #1a5632;
        color: #ffffff;
        border-color: #1a5632;
        box-shadow: 0 2px 4px rgba(26, 86, 50, 0.2);
    }
    .filter-btn .count-chip {
        background: rgba(0,0,0,0.08);
        padding: 0.1rem 0.45rem;
        border-radius: 10px;
        font-size: 0.7rem;
        font-weight: 700;
    }
    .filter-btn.active .count-chip {
        background: rgba(255,255,255,0.25);
        color: #ffffff;
    }

    .search-wrapper {
        position: relative;
        display: flex;
        align-items: center;
    }
    .search-wrapper i {
        position: absolute;
        left: 0.65rem;
        color: #94a3b8;
        font-size: 0.8rem;
    }
    .search-input {
        padding: 0.4rem 0.75rem 0.4rem 2rem;
        font-size: 0.8rem;
        border-radius: 8px;
        border: 1px solid #cbd5e1;
        outline: none;
        width: 240px;
        transition: all 0.15s ease;
    }
    .search-input:focus { border-color: #1a5632; box-shadow: 0 0 0 3px rgba(26, 86, 50, 0.12); }

    /* Card Container Layout */
    .card {
        background: #ffffff;
        border-radius: 12px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        margin-bottom: 1.25rem;
        border: 1px solid #e2e8f0;
        overflow: hidden;
    }
    .card-header {
        padding: 0.85rem 1.25rem;
        font-weight: 700;
        font-size: 0.85rem;
        border-bottom: 1px solid #e2e8f0;
        color: #1a5632;
        background: #fafafa;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .card-body { padding: 0; }

    /* Modern Notification Items */
    .notification-item {
        display: flex;
        align-items: flex-start;
        padding: 1rem 1.25rem;
        border-bottom: 1px solid #f1f5f9;
        transition: all 0.15s ease;
        gap: 1rem;
        position: relative;
    }
    .notification-item:last-child { border-bottom: none; }
    .notification-item:hover { background: #f8fafc; }
    .notification-item.unread { background: #f0fdf4; border-left: 4px solid #1a5632; }
    .notification-item.read { opacity: 0.95; }
    .notification-item.admitted { border-left: 4px solid #27ae60; background: #f0fdf4; }
    .notification-item.reported { border-left: 4px solid #e74c3c; background: #fef2f2; }
    .notification-item.auto_reported { border-left: 4px solid #f39c12; background: #fffbeb; }

    .notification-icon {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        font-size: 1rem;
    }
    .notification-icon.posting { background: #e6f4ea; color: #1a5632; }
    .notification-icon.admitted { background: #d1fae5; color: #059669; }
    .notification-icon.reported { background: #fee2e2; color: #dc2626; }
    .notification-icon.auto_reported { background: #fef3c7; color: #d97706; }

    .notification-content { flex: 1; }
    .notification-title { font-weight: 700; font-size: 0.85rem; color: #0f172a; margin-bottom: 0.3rem; display: flex; align-items: center; flex-wrap: wrap; gap: 0.4rem; }
    .notification-details { font-size: 0.8rem; color: #475569; line-height: 1.5; background: rgba(248, 250, 252, 0.7); padding: 0.5rem 0.75rem; border-radius: 8px; border: 1px solid #f1f5f9; margin-top: 0.35rem; }
    .notification-details strong { color: #1e293b; font-weight: 600; }
    .notification-time { font-size: 0.725rem; color: #94a3b8; margin-top: 0.4rem; display: flex; align-items: center; gap: 0.3rem; }

    .notification-zone {
        display: inline-block;
        background: #eff6ff;
        color: #1d4ed8;
        border: 1px solid #bfdbfe;
        padding: 0.1rem 0.5rem;
        border-radius: 6px;
        font-size: 0.7rem;
        font-weight: 700;
    }

    .notification-actions {
        display: flex;
        gap: 0.4rem;
        flex-shrink: 0;
        align-items: center;
        margin-top: 0.2rem;
    }

    .btn-sm {
        padding: 0.4rem 0.75rem;
        font-size: 0.75rem;
        border-radius: 6px;
        cursor: pointer;
        border: none;
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        text-decoration: none;
        font-weight: 600;
        transition: all 0.15s ease;
    }
    .btn-success { background: #1a5632; color: #ffffff; }
    .btn-success:hover { background: #154628; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
    .btn-outline { background: #ffffff; color: #475569; border: 1px solid #cbd5e1; }
    .btn-outline:hover { background: #f8fafc; color: #0f172a; border-color: #94a3b8; }
    .btn-admit { background: #27ae60; color: #ffffff; }
    .btn-admit:hover { background: #219a52; box-shadow: 0 2px 4px rgba(39,174,96,0.25); }
    .btn-report { background: #e74c3c; color: #ffffff; }
    .btn-report:hover { background: #c0392b; box-shadow: 0 2px 4px rgba(231,76,60,0.25); }

    .empty-state { text-align: center; padding: 3rem 1.5rem; color: #64748b; }
    .empty-state i { font-size: 2.8rem; margin-bottom: 0.85rem; color: #cbd5e1; display: block; }
    .empty-state h3 { font-size: 1.05rem; margin-bottom: 0.35rem; color: #1e293b; font-weight: 700; }

    .badge { display: inline-block; padding: 0.25rem 0.6rem; border-radius: 12px; font-size: 0.725rem; font-weight: 700; }
    .badge-unread { background: #1a5632; color: #ffffff; }
    .zone-filter-badge { display: inline-block; background: #2563eb; color: #ffffff; padding: 0.25rem 0.65rem; border-radius: 12px; font-size: 0.725rem; font-weight: 700; margin-left: 0.4rem; }

    .status-badge {
        display: inline-block;
        padding: 0.15rem 0.5rem;
        border-radius: 6px;
        font-size: 0.7rem;
        font-weight: 800;
        letter-spacing: 0.02em;
    }
    .status-admitted { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
    .status-reported { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }

    .alert { padding: 0.75rem 1.1rem; border-radius: 10px; margin-bottom: 1.25rem; border-left: 4px solid; font-size: 0.825rem; font-weight: 600; display: flex; align-items: center; gap: 0.5rem; }
    .alert-success { background: #f0fdf4; color: #166534; border-left-color: #16a34a; box-shadow: 0 1px 2px rgba(0,0,0,0.03); }
    .alert-error { background: #fef2f2; color: #991b1b; border-left-color: #dc2626; box-shadow: 0 1px 2px rgba(0,0,0,0.03); }

    .admit-form { display: inline; }
    .action-group { display: flex; gap: 0.4rem; margin-top: 0.6rem; }

    .btn-notif-page {
        background: #ffffff;
        color: #334155;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        padding: 0.35rem 0.7rem;
        font-size: 0.775rem;
        font-weight: 600;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.35rem;
        transition: all 0.15s ease;
    }
    .btn-notif-page:hover:not(:disabled) {
        background: #f1f5f9;
        border-color: #1a5632;
        color: #1a5632;
    }
    .btn-notif-page.active {
        background: #1a5632;
        color: #ffffff;
        border-color: #1a5632;
    }
    .btn-notif-page:disabled {
        opacity: 0.4;
        cursor: not-allowed;
    }
</style>

<div class="page-title">
    <h1><i class="fas fa-bell"></i> Posting Notifications</h1>
    <div>
        <?php if (!empty($userZoneNames)): ?>
            <span class="zone-filter-badge"><i class="fas fa-map-marker-alt"></i> Zone: <?php echo htmlspecialchars(implode(', ', $userZoneNames)); ?></span>
        <?php endif; ?>
    </div>
</div>

<?php if ($msgText): ?>
<div class="alert <?php echo $msg === 'error' ? 'alert-error' : 'alert-success'; ?>">
    <i class="fas fa-<?php echo $msg === 'error' ? 'exclamation-circle' : 'check-circle'; ?>"></i> 
    <?php echo htmlspecialchars($msgText); ?>
</div>
<?php endif; ?>

<!-- Summary Metric Cards Bar -->
<div class="metric-grid">
    <div class="metric-card">
        <div class="metric-icon total"><i class="fas fa-layer-group"></i></div>
        <div class="metric-info">
            <span class="metric-val"><?php echo $totalCount; ?></span>
            <span class="metric-lbl">Total Alerts</span>
        </div>
    </div>
    <div class="metric-card">
        <div class="metric-icon pending"><i class="fas fa-user-clock"></i></div>
        <div class="metric-info">
            <span class="metric-val"><?php echo $pendingCount; ?></span>
            <span class="metric-lbl">Pending Duty</span>
        </div>
    </div>
    <div class="metric-card">
        <div class="metric-icon admitted"><i class="fas fa-user-check"></i></div>
        <div class="metric-info">
            <span class="metric-val"><?php echo $admittedCount; ?></span>
            <span class="metric-lbl">Admitted Officers</span>
        </div>
    </div>
    <div class="metric-card">
        <div class="metric-icon reported"><i class="fas fa-user-times"></i></div>
        <div class="metric-info">
            <span class="metric-val"><?php echo $reportedCount; ?></span>
            <span class="metric-lbl">Not Reported</span>
        </div>
    </div>
</div>

<!-- Quick Filter Toolbar -->
<div class="filter-bar">
    <div class="filter-group">
        <button class="filter-btn active" onclick="filterItems('all', this)">
            <i class="fas fa-list-ul"></i> All <span class="count-chip"><?php echo $totalCount; ?></span>
        </button>
        <button class="filter-btn" onclick="filterItems('unread', this)">
            <i class="fas fa-envelope"></i> Unread <span class="count-chip"><?php echo $unreadCount; ?></span>
        </button>
        <button class="filter-btn" onclick="filterItems('pending', this)">
            <i class="fas fa-hourglass-half"></i> Pending <span class="count-chip"><?php echo $pendingCount; ?></span>
        </button>
        <button class="filter-btn" onclick="filterItems('admitted', this)">
            <i class="fas fa-check-circle"></i> Admitted <span class="count-chip"><?php echo $admittedCount; ?></span>
        </button>
        <button class="filter-btn" onclick="filterItems('reported', this)">
            <i class="fas fa-exclamation-triangle"></i> Not Reported <span class="count-chip"><?php echo $reportedCount; ?></span>
        </button>
    </div>
    <div class="search-wrapper">
        <i class="fas fa-search"></i>
        <input type="text" id="notifSearchInput" class="search-input" placeholder="Search name, NIS no, location..." onkeyup="searchNotifs()">
    </div>
</div>

<div class="card">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.5rem;">
        <div>
            <span><i class="fas fa-stream"></i> Notification Stream</span>
            <?php if ($unreadCount > 0): ?>
                <span class="badge badge-unread" style="margin-left:0.5rem;"><?php echo $unreadCount; ?> Unread</span>
            <?php endif; ?>
        </div>
        <?php if ($unreadCount > 0): ?>
            <a href="?mark_all_read=1" class="btn-sm btn-success" style="font-size:0.775rem; padding:0.35rem 0.75rem; border-radius:6px; text-decoration:none; display:inline-flex; align-items:center; gap:0.35rem; color:#fff; background:#1a5632;"><i class="fas fa-check-double"></i> Mark All Read</a>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if (empty($notifications)): ?>
            <div class="empty-state">
                <i class="fas fa-bell-slash"></i>
                <h3>No Notifications Available</h3>
                <p>Posting notifications for your assigned formation(s) will appear here in real time.</p>
            </div>
        <?php else: ?>
            <div id="notifContainer">
                <?php foreach ($notifications as $notif): 
                    $statusClass = $notif['status'] ?? 'pending';
                    $iconClass = $statusClass === 'admitted' ? 'admitted' : ($statusClass === 'reported' ? 'reported' : ($statusClass === 'auto_reported' ? 'auto_reported' : 'posting'));
                    $icon = $statusClass === 'admitted' ? 'fa-check-circle' : ($statusClass === 'reported' ? 'fa-exclamation-triangle' : ($statusClass === 'auto_reported' ? 'fa-clock' : 'fa-exchange-alt'));
                    
                    $itemClass = $notif['is_read'] ? 'read' : 'unread';
                    if ($statusClass === 'admitted') $itemClass = 'admitted';
                    if ($statusClass === 'reported' || $statusClass === 'auto_reported') $itemClass = $statusClass;
                    
                    $searchText = strtolower(($notif['officer_name'] ?? '') . ' ' . ($notif['serviceNo'] ?? '') . ' ' . ($notif['posting_location'] ?? ''));
                ?>
                <div class="notification-item <?php echo $itemClass; ?>" 
                     data-status="<?php echo $statusClass; ?>" 
                     data-unread="<?php echo !$notif['is_read'] ? '1' : '0'; ?>"
                     data-search="<?php echo htmlspecialchars($searchText); ?>">
                     
                    <div class="notification-icon <?php echo $iconClass; ?>">
                        <i class="fas <?php echo $icon; ?>"></i>
                    </div>
                    
                    <div class="notification-content">
                        <div class="notification-title">
                            <?php if (!$notif['is_read'] && $statusClass === 'pending'): ?>
                                <span style="color:#1a5632;margin-right:.2rem;">●</span>
                            <?php endif; ?>
                            
                            <?php if ($statusClass === 'admitted'): ?>
                                <span class="status-badge status-admitted">ADMITTED</span>
                            <?php elseif ($statusClass === 'reported' || $statusClass === 'auto_reported'): ?>
                                <span class="status-badge status-reported">NOT REPORTED</span>
                            <?php endif; ?>
                            
                            <?php if ($statusClass === 'admitted' || $statusClass === 'reported' || $statusClass === 'auto_reported'): ?>
                                <?php echo htmlspecialchars($notif['message'] ?? ''); ?>
                            <?php else: ?>
                                Officer Posted to <strong><?php echo htmlspecialchars($notif['posting_location']); ?></strong>
                                <?php if ($notif['posting_zone']): ?>
                                    <span class="notification-zone"><?php echo htmlspecialchars($notif['posting_zone']); ?></span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        
                        <div class="notification-details">
                            <strong>NIS No:</strong> <?php echo htmlspecialchars($notif['serviceNo']); ?> &bull; 
                            <strong>Name:</strong> <?php echo htmlspecialchars($notif['officer_name']); ?> &bull; 
                            <strong>Rank:</strong> <?php echo htmlspecialchars($notif['officer_rank']); ?><br>
                            <strong>Posted by:</strong> <?php echo htmlspecialchars($notif['posted_by']); ?> &bull; 
                            <strong>Posting Date:</strong> <?php echo date('d/m/Y', strtotime($notif['posting_date'])); ?>
                            
                            <?php if ($statusClass === 'admitted' && !empty($notif['admitted_at'])): ?>
                                <br><span style="color:#059669;"><strong>Admitted:</strong> <?php echo date('d/m/Y H:i', strtotime($notif['admitted_at'])); ?> by <?php echo htmlspecialchars($notif['admitted_by']); ?></span>
                            <?php endif; ?>
                            
                            <?php if (($statusClass === 'reported' || $statusClass === 'auto_reported') && !empty($notif['reported_at'])): ?>
                                <br><span style="color:#dc2626;"><strong>Reported:</strong> <?php echo date('d/m/Y H:i', strtotime($notif['reported_at'])); ?> by <?php echo htmlspecialchars($notif['reported_by']); ?></span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="notification-time">
                            <i class="far fa-clock"></i> <?php echo date('d M Y, h:i A', strtotime($notif['created_at'])); ?>
                        </div>

                        <?php if ($statusClass === 'pending' && !in_array(strtolower($userRole), ['admin', 'service hq', 'shq admin', 'super admin', 'user']) && !empty($userZoneNames)): ?>
                        <div class="action-group">
                            <form method="POST" class="admit-form" onsubmit="return confirm('Admit this officer to your formation?');">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                <input type="hidden" name="notification_id" value="<?php echo $notif['id']; ?>">
                                <button type="submit" name="admit_officer" class="btn-sm btn-admit">
                                    <i class="fas fa-user-check"></i> Admit Officer
                                </button>
                            </form>
                            <form method="POST" class="admit-form" onsubmit="return confirm('Report this officer as not reported?');">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                <input type="hidden" name="notification_id" value="<?php echo $notif['id']; ?>">
                                <button type="submit" name="report_officer" class="btn-sm btn-report">
                                    <i class="fas fa-user-times"></i> Officer Did Not Report
                                </button>
                            </form>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="notification-actions">
                        <?php if (!$notif['is_read']): ?>
                            <a href="?mark_read=<?php echo $notif['id']; ?>" class="btn-sm btn-success" title="Mark as read"><i class="fas fa-check"></i></a>
                        <?php endif; ?>
                        <a href="search?serviceNo=<?php echo urlencode($notif['serviceNo']); ?>" class="btn-sm btn-outline" title="View Officer Profile"><i class="fas fa-eye"></i> View</a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <div class="card-footer" id="notifPaginationFooter" style="display:flex; justify-content:space-between; align-items:center; padding:0.75rem 1.25rem; background:#f8fafc; border-top:1px solid #e2e8f0; flex-wrap:wrap; gap:0.75rem;">
        <div style="font-size:0.8rem; color:#64748b; font-weight:500;" id="notifPaginationInfo">
            Showing 1 to <?php echo min(50, count($notifications)); ?> of <?php echo count($notifications); ?> notifications
        </div>
        <div style="display:flex; align-items:center; gap:0.35rem;" id="notifPaginationControls">
            <!-- Dynamic Page Buttons and Arrows rendered via JS -->
        </div>
    </div>
</div>

</div><!-- close main-content -->
</div><!-- close content-wrapper -->
</div><!-- close dashboard-container -->

<script>
let currentNotifPage = 1;
const itemsPerPage = 50;
let currentFilterType = 'all';

function updateNotifPagination() {
    const allItems = Array.from(document.querySelectorAll('.notification-item'));
    const searchVal = (document.getElementById('notifSearchInput')?.value || '').toLowerCase().trim();
    
    // Filter matching items
    const matchingItems = allItems.filter(item => {
        const status = item.getAttribute('data-status');
        const unread = item.getAttribute('data-unread') === '1';
        const text = item.getAttribute('data-search') || '';

        let matchesTab = true;
        if (currentFilterType === 'unread') matchesTab = unread;
        else if (currentFilterType === 'pending') matchesTab = (status === 'pending');
        else if (currentFilterType === 'admitted') matchesTab = (status === 'admitted');
        else if (currentFilterType === 'reported') matchesTab = (status === 'reported' || status === 'auto_reported');

        let matchesSearch = (searchVal === '' || text.includes(searchVal));

        return matchesTab && matchesSearch;
    });

    const totalMatching = matchingItems.length;
    const totalPages = Math.max(1, Math.ceil(totalMatching / itemsPerPage));

    if (currentNotifPage > totalPages) currentNotifPage = totalPages;
    if (currentNotifPage < 1) currentNotifPage = 1;

    const startIndex = (currentNotifPage - 1) * itemsPerPage;
    const endIndex = startIndex + itemsPerPage;

    // Show/hide items for pagination
    allItems.forEach(item => {
        item.style.display = 'none';
    });

    matchingItems.forEach((item, index) => {
        if (index >= startIndex && index < endIndex) {
            item.style.display = 'flex';
        }
    });

    // Render pagination footer info & controls
    const infoDiv = document.getElementById('notifPaginationInfo');
    const controlsDiv = document.getElementById('notifPaginationControls');

    if (infoDiv) {
        if (totalMatching === 0) {
            infoDiv.textContent = 'Showing 0 notifications';
        } else {
            const startRec = startIndex + 1;
            const endRec = Math.min(endIndex, totalMatching);
            infoDiv.innerHTML = `Showing <strong>${startRec}</strong> to <strong>${endRec}</strong> of <strong>${totalMatching}</strong> notifications (Page ${currentNotifPage} of ${totalPages})`;
        }
    }

    if (!controlsDiv) return;

    if (totalPages <= 1) {
        controlsDiv.innerHTML = '';
        return;
    }

    let html = '';

    // Previous Arrow Button (< Prev)
    const prevDisabled = currentNotifPage <= 1 ? 'disabled' : '';
    html += `<button class="btn-notif-page" ${prevDisabled} onclick="changeNotifPage(${currentNotifPage - 1})" title="Previous Page"><i class="fas fa-chevron-left"></i> Prev</button>`;

    // Page Number Buttons
    let startPage = Math.max(1, currentNotifPage - 2);
    let endPage = Math.min(totalPages, startPage + 4);
    if (endPage - startPage < 4) {
        startPage = Math.max(1, endPage - 4);
    }

    if (startPage > 1) {
        html += `<button class="btn-notif-page" onclick="changeNotifPage(1)">1</button>`;
        if (startPage > 2) {
            html += `<span style="font-size:0.8rem; color:#94a3b8; padding:0 3px;">...</span>`;
        }
    }

    for (let p = startPage; p <= endPage; p++) {
        const activeClass = p === currentNotifPage ? 'active' : '';
        html += `<button class="btn-notif-page ${activeClass}" onclick="changeNotifPage(${p})">${p}</button>`;
    }

    if (endPage < totalPages) {
        if (endPage < totalPages - 1) {
            html += `<span style="font-size:0.8rem; color:#94a3b8; padding:0 3px;">...</span>`;
        }
        html += `<button class="btn-notif-page" onclick="changeNotifPage(${totalPages})">${totalPages}</button>`;
    }

    // Next Arrow Button (Next >)
    const nextDisabled = currentNotifPage >= totalPages ? 'disabled' : '';
    html += `<button class="btn-notif-page" ${nextDisabled} onclick="changeNotifPage(${currentNotifPage + 1})" title="Next Page">Next <i class="fas fa-chevron-right"></i></button>`;

    controlsDiv.innerHTML = html;
}

function changeNotifPage(page) {
    currentNotifPage = page;
    updateNotifPagination();
    const cardHeader = document.querySelector('.card-header');
    if (cardHeader) cardHeader.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function filterItems(type, btn) {
    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
    if (btn) btn.classList.add('active');
    currentFilterType = type;
    currentNotifPage = 1;
    updateNotifPagination();
}

function searchNotifs() {
    currentNotifPage = 1;
    updateNotifPagination();
}

document.addEventListener('DOMContentLoaded', function() {
    updateNotifPagination();
});
</script>

<?php include 'includes/footer.php'; ?>

</body>
</html>