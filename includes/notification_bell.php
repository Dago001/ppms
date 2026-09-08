<?php
$currentRole = $_SESSION['role_name'] ?? $user['role_name'] ?? '';
$currentUserId = $_SESSION['user_id'] ?? 0;
$bellZoneNames = [];

if (!in_array($currentRole, ['admin', 'Service HQ'])) {
    try {
        $stmt = $pdo->prepare("SELECT z.zone_name FROM user_zones uz JOIN zones z ON uz.zone_id = z.id WHERE uz.user_id = ?");
        $stmt->execute([$currentUserId]);
        $bellZoneNames = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) { $bellZoneNames = []; }
}

$unreadCount = getUnreadNotificationCount($bellZoneNames);
?>

<?php if ($unreadCount > 0): ?>
<style>
.notification-bell {
    position: fixed;
    bottom: 20px;
    right: 20px;
    z-index: 9999;
    animation: bellRing 2s infinite;
}
.notification-bell a {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 50px;
    height: 50px;
    background: #27ae60;
    color: #fff;
    border-radius: 50%;
    text-decoration: none;
    font-size: 1.2rem;
    box-shadow: 0 4px 12px rgba(39,174,96,0.4);
    position: relative;
}
.notification-bell .badge {
    position: absolute;
    top: -5px;
    right: -5px;
    background: #e74c3c;
    color: #fff;
    width: 22px;
    height: 22px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-weight: 700;
}
@keyframes bellRing {
    0%, 100% { transform: rotate(0); }
    10% { transform: rotate(15deg); }
    20% { transform: rotate(-15deg); }
    30% { transform: rotate(10deg); }
    40% { transform: rotate(-10deg); }
    50% { transform: rotate(0); }
}
</style>
<div class="notification-bell">
    <a href="notifications.php" title="<?php echo $unreadCount; ?> new posting notification(s) for your formation">
        <i class="fas fa-bell"></i>
        <span class="badge"><?php echo $unreadCount; ?></span>
    </a>
</div>
<?php endif; ?>