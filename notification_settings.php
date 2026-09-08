<?php
session_start();
require_once 'includes/config.php';
require_once 'includes/auth.php';
require_once 'includes/permissions.php';

if (!isLoggedIn()) { header('Location: login'); exit(); }

// Same privilege as the main System Settings page - this controls the live
// SMS/email provider configuration, not a per-user preference.
if (!hasPermission('system_settings')) {
    header('Location: dashboard');
    exit();
}

$userId = $_SESSION['user_id'];
$success = '';
$error = '';
$csrfToken = generateCSRFToken();

// Get current settings
$settings = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM notification_settings LIMIT 1");
    $stmt->execute();
    $settings = $stmt->fetch();
} catch (Exception $e) {
    $settings = [];
}

// Save settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !validateCSRFToken($_POST['csrf_token'] ?? '')) {
    $error = "Security validation failed. Please refresh the page and try again.";
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $enable_sms = isset($_POST['enable_sms']) ? 1 : 0;
    $enable_email = isset($_POST['enable_email']) ? 1 : 0;
    $sms_provider = $_POST['sms_provider'] ?? 'bulk_sms_nigeria';
    $sms_reminder_days = intval($_POST['sms_reminder_days'] ?? 14);
    $email_from = $_POST['email_from'] ?? 'noreply@nis.gov.ng';
    $email_from_name = $_POST['email_from_name'] ?? 'NIS Posting Management';
    
    try {
        if ($settings) {
            $stmt = $pdo->prepare("UPDATE notification_settings SET enable_sms=?, enable_email=?, sms_provider=?, sms_reminder_days=?, email_from=?, email_from_name=? WHERE id=?");
            $stmt->execute([$enable_sms, $enable_email, $sms_provider, $sms_reminder_days, $email_from, $email_from_name, $settings['id']]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO notification_settings (enable_sms, enable_email, sms_provider, sms_reminder_days, email_from, email_from_name) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$enable_sms, $enable_email, $sms_provider, $sms_reminder_days, $email_from, $email_from_name]);
        }
        $success = "Notification settings saved successfully!";
    } catch (Exception $e) {
        $error = "Error saving settings: " . $e->getMessage();
    }
}

// Get stats
$totalSMS = 0; $totalEmails = 0;
try {
    $totalSMS = $pdo->query("SELECT COUNT(*) FROM sms_logs")->fetchColumn();
    $totalEmails = $pdo->query("SELECT COUNT(*) FROM email_logs")->fetchColumn();
} catch (Exception $e) {}
?>

<?php include 'includes/header.php'; ?>

<style>
    .card{background:#fff;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.05);margin-bottom:1.25rem;border:1px solid #e2e8f0}
    .card-header{padding:.875rem 1.25rem;font-weight:600;font-size:.85rem;border-bottom:1px solid #e2e8f0;color:#1a5632}
    .card-body{padding:1.25rem}
    .form-group{margin-bottom:1rem}
    .form-group label{display:block;font-weight:600;color:#475569;margin-bottom:.3rem;font-size:.8rem}
    .form-control{width:100%;padding:.6rem .75rem;border:1px solid #e2e8f0;border-radius:8px;font-size:.85rem}
    .btn-success{background:#1a5632;color:#fff;padding:.5rem 1rem;border-radius:8px;border:none;cursor:pointer}
    .toggle-switch{position:relative;display:inline-block;width:50px;height:26px}
    .toggle-switch input{opacity:0;width:0;height:0}
    .toggle-slider{position:absolute;cursor:pointer;top:0;left:0;right:0;bottom:0;background-color:#ccc;transition:.4s;border-radius:34px}
    .toggle-slider:before{position:absolute;content:"";height:18px;width:18px;left:4px;bottom:4px;background-color:white;transition:.4s;border-radius:50%}
    input:checked+.toggle-slider{background-color:#1a5632}
    input:checked+.toggle-slider:before{transform:translateX(24px)}
    .alert{padding:.75rem 1rem;border-radius:8px;margin-bottom:1rem;border-left:4px solid;font-size:.85rem}
    .alert-success{background:rgba(26,86,50,.1);color:#155724;border-left-color:#1a5632}
    .alert-error{background:rgba(220,53,69,.1);color:#991b1b;border-left-color:#dc3545}
</style>

<div class="page-title"><h1><i class="fas fa-cog"></i> Notification Settings</h1></div>

<?php if ($success): ?>
<div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><h3><i class="fas fa-sms"></i> SMS & Email Configuration</h3></div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <div class="form-group">
                <label>
                    <label class="toggle-switch">
                        <input type="checkbox" name="enable_sms" <?php echo ($settings['enable_sms'] ?? 1) ? 'checked' : ''; ?>>
                        <span class="toggle-slider"></span>
                    </label>
                    <span style="margin-left:10px;">Enable SMS Notifications</span>
                </label>
            </div>
            
            <div class="form-group">
                <label>
                    <label class="toggle-switch">
                        <input type="checkbox" name="enable_email" <?php echo ($settings['enable_email'] ?? 1) ? 'checked' : ''; ?>>
                        <span class="toggle-slider"></span>
                    </label>
                    <span style="margin-left:10px;">Enable Email Notifications</span>
                </label>
            </div>
            
            <div class="form-group">
                <label>SMS Provider</label>
                <select name="sms_provider" class="form-control">
                    <option value="bulk_sms_nigeria" <?php echo ($settings['sms_provider'] ?? '') === 'bulk_sms_nigeria' ? 'selected' : ''; ?>>Bulk SMS Nigeria</option>
                    <option value="termii" <?php echo ($settings['sms_provider'] ?? '') === 'termii' ? 'selected' : ''; ?>>Termii</option>
                    <option value="africastalking" <?php echo ($settings['sms_provider'] ?? '') === 'africastalking' ? 'selected' : ''; ?>>Africa's Talking</option>
                </select>
            </div>
            
            <div class="form-group">
                <label>SMS Reminder (Days after posting)</label>
                <input type="number" name="sms_reminder_days" class="form-control" value="<?php echo $settings['sms_reminder_days'] ?? 14; ?>" min="1" max="60">
            </div>
            
            <div class="form-group">
                <label>Email From Address</label>
                <input type="email" name="email_from" class="form-control" value="<?php echo $settings['email_from'] ?? 'noreply@nis.gov.ng'; ?>">
            </div>
            
            <div class="form-group">
                <label>Email From Name</label>
                <input type="text" name="email_from_name" class="form-control" value="<?php echo $settings['email_from_name'] ?? 'NIS Posting Management'; ?>">
            </div>
            
            <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Save Settings</button>
        </form>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
    <div class="card">
        <div class="card-header"><h3><i class="fas fa-sms"></i> SMS Statistics</h3></div>
        <div class="card-body">
            <div style="font-size:2rem;font-weight:700;color:#1a5632;"><?php echo number_format($totalSMS); ?></div>
            <div style="color:#64748b;font-size:.8rem;">Total SMS Sent</div>
        </div>
    </div>
    <div class="card">
        <div class="card-header"><h3><i class="fas fa-envelope"></i> Email Statistics</h3></div>
        <div class="card-body">
            <div style="font-size:2rem;font-weight:700;color:#1a5632;"><?php echo number_format($totalEmails); ?></div>
            <div style="color:#64748b;font-size:.8rem;">Total Emails Sent</div>
        </div>
    </div>
</div>