<?php
// ssettings.php
// System Settings & Global API Management Portal (Super Admin Exclusive)

session_start();
require_once 'includes/config.php';
require_once 'includes/security.php';
require_once 'includes/settings_helper.php';
require_once 'includes/permissions.php';

// Access Control: RESTRICTED STRICTLY BY HASPERMISSION('SYSTEM_SETTINGS')
if (!hasPermission('system_settings')) {
    header("HTTP/1.1 403 Forbidden");
    echo "<div style='font-family:sans-serif; text-align:center; padding:3rem;'>
            <h1 style='color:#dc2626;'>403 Forbidden - Access Denied</h1>
            <p>You do not have permission ('system_settings') to access System Settings.</p>
            <a href='dashboard' style='display:inline-block; margin-top:1rem; padding:0.5rem 1rem; background:#1a5632; color:#fff; text-decoration:none; border-radius:6px;'>Return to Dashboard</a>
          </div>";
    exit();
}

$page_title = "System Settings & Global API Gateway";
$message = "";
$messageType = "";

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Save System Configuration Settings
    if (isset($_POST['action']) && $_POST['action'] === 'save_settings') {
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
            $message = "Security Token Validation Failed. Please try again.";
            $messageType = "danger";
        } else {
            $settingsToSave = [
                'site_name' => [trim($_POST['site_name'] ?? ''), 'general'],
                'agency_title' => [trim($_POST['agency_title'] ?? ''), 'general'],
                'contact_email' => [trim($_POST['contact_email'] ?? ''), 'general'],
                'contact_phone' => [trim($_POST['contact_phone'] ?? ''), 'general'],
                'maintenance_mode' => [isset($_POST['maintenance_mode']) ? '1' : '0', 'general'],
                'maintenance_message' => [trim($_POST['maintenance_message'] ?? ''), 'general'],

                'overstay_years_threshold' => [min(max(intval($_POST['overstay_years_threshold'] ?? 5), 1), 20), 'posting'],
                'assumption_window_days' => [min(max(intval($_POST['assumption_window_days'] ?? 30), 1), 90), 'posting'],
                'disciplinary_guard_enabled' => [isset($_POST['disciplinary_guard_enabled']) ? '1' : '0', 'posting'],

                'auto_sms_enabled' => [isset($_POST['auto_sms_enabled']) ? '1' : '0', 'notifications'],
                'auto_email_enabled' => [isset($_POST['auto_email_enabled']) ? '1' : '0', 'notifications'],
                'smtp_host' => [trim($_POST['smtp_host'] ?? ''), 'notifications'],
                'smtp_port' => [min(max(intval($_POST['smtp_port'] ?? 587), 1), 65535), 'notifications'],
                'smtp_username' => [trim($_POST['smtp_username'] ?? ''), 'notifications'],
                'smtp_password' => [trim($_POST['smtp_password'] ?? ''), 'notifications'],
                'smtp_encryption' => [in_array($_POST['smtp_encryption'] ?? 'tls', ['tls', 'ssl', 'none'], true) ? $_POST['smtp_encryption'] : 'tls', 'notifications'],
                'inapp_bell_alerts_enabled' => [isset($_POST['inapp_bell_alerts_enabled']) ? '1' : '0', 'notifications'],
                'whatsapp_enabled' => [isset($_POST['whatsapp_enabled']) ? '1' : '0', 'notifications'],
                'whatsapp_phone_number_id' => [trim($_POST['whatsapp_phone_number_id'] ?? ''), 'notifications'],
                'whatsapp_access_token' => [trim($_POST['whatsapp_access_token'] ?? ''), 'notifications'],
                'whatsapp_api_version' => [trim($_POST['whatsapp_api_version'] ?? 'v18.0'), 'notifications'],

                'login_max_attempts' => [min(max(intval($_POST['login_max_attempts'] ?? 5), 3), 20), 'security'],
                'session_timeout_minutes' => [min(max(intval($_POST['session_timeout_minutes'] ?? 30), 5), 240), 'security'],

                'api_master_enabled' => [isset($_POST['api_master_enabled']) ? '1' : '0', 'api'],
                'api_global_cors_enabled' => [isset($_POST['api_global_cors_enabled']) ? '1' : '0', 'api'],
                'api_rate_limit_per_minute' => [min(max(intval($_POST['api_rate_limit_per_minute'] ?? 100), 10), 5000), 'api']
            ];

            foreach ($settingsToSave as $key => $info) {
                updateSystemSetting($key, $info[0], $info[1]);
            }

            $message = "System settings updated successfully!";
            $messageType = "success";
        }
    }

    // 2. Generate New API Key
    elseif (isset($_POST['action']) && $_POST['action'] === 'generate_api_key') {
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
            $message = "Security Token Validation Failed.";
            $messageType = "danger";
        } else {
            $clientName = trim($_POST['client_name'] ?? '');
            $perms = isset($_POST['permissions']) && is_array($_POST['permissions']) ? implode(',', $_POST['permissions']) : 'read_personnel,read_postings';
            $allowedIps = trim($_POST['allowed_ips'] ?? '');
            $rateLimit = max(intval($_POST['rate_limit'] ?? 100), 10);

            if (empty($clientName)) {
                $message = "Client / Partner Name is required to generate an API key.";
                $messageType = "danger";
            } else {
                $newKey = 'nis_live_' . bin2hex(random_bytes(20));
                $stmt = $pdo->prepare("INSERT INTO api_keys (client_name, api_key, permissions, allowed_ips, rate_limit, is_active) VALUES (?, ?, ?, ?, ?, 1)");
                $stmt->execute([$clientName, $newKey, $perms, $allowedIps, $rateLimit]);

                $message = "New Global API Key created for '" . htmlspecialchars($clientName) . "'! Key: <code>" . htmlspecialchars($newKey) . "</code>";
                $messageType = "success";
            }
        }
    }

    // 3. Toggle / Revoke / Delete API Key
    elseif (isset($_POST['action']) && $_POST['action'] === 'toggle_api_key') {
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
            $message = "Security Token Validation Failed.";
            $messageType = "danger";
        } else {
            $keyId = intval($_POST['key_id'] ?? 0);
            $status = intval($_POST['status'] ?? 0);
            $pdo->prepare("UPDATE api_keys SET is_active = ? WHERE id = ?")->execute([$status, $keyId]);
            $message = "API Key status updated.";
            $messageType = "success";
        }
    }
    elseif (isset($_POST['action']) && $_POST['action'] === 'delete_api_key') {
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
            $message = "Security Token Validation Failed.";
            $messageType = "danger";
        } else {
            $keyId = intval($_POST['key_id'] ?? 0);
            $pdo->prepare("DELETE FROM api_keys WHERE id = ?")->execute([$keyId]);
            $message = "API Key permanently revoked and deleted.";
            $messageType = "success";
        }
    }

    // 4. Send Test Email - verifies SMTP credentials (as typed, not necessarily
    // saved yet) by actually connecting and sending a real message.
    elseif (isset($_POST['action']) && $_POST['action'] === 'send_test_email') {
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
            $message = "Security Token Validation Failed.";
            $messageType = "danger";
        } else {
            require_once __DIR__ . '/includes/NotificationService.php';
            $testRecipient = trim($_POST['test_email_recipient'] ?? '');
            $notificationService = new NotificationService($pdo);
            $testResult = $notificationService->testSMTPConnection(
                trim($_POST['smtp_host'] ?? ''),
                intval($_POST['smtp_port'] ?? 587),
                trim($_POST['smtp_username'] ?? ''),
                trim($_POST['smtp_password'] ?? ''),
                $_POST['smtp_encryption'] ?? 'tls',
                $testRecipient
            );
            $message = $testResult['success']
                ? "Test email sent successfully to {$testRecipient}. Check that inbox to confirm delivery."
                : "SMTP test failed: " . $testResult['response'];
            $messageType = $testResult['success'] ? "success" : "danger";
        }
    }
}

// Fetch all current settings
$allSettings = getAllSystemSettings();
$gen = $allSettings['general'] ?? [];
$post = $allSettings['posting'] ?? [];
$notif = $allSettings['notifications'] ?? [];
$sec = $allSettings['security'] ?? [];
$api = $allSettings['api'] ?? [];

// Fetch API Keys
$apiKeys = $pdo->query("SELECT * FROM api_keys ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch Recent API Logs
$apiLogs = $pdo->query("SELECT l.*, k.client_name FROM api_logs l LEFT JOIN api_keys k ON l.api_key_id = k.id ORDER BY l.id DESC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);

$csrfToken = generateCSRFToken();
?>

<?php include 'includes/header.php'; ?>

<style>
    .settings-container { max-width: 1200px; margin: 0 auto; }
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
    .card-body { padding: 1.5rem; }
    
    .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.25rem; }
    .form-group { margin-bottom: 1.25rem; }
    .form-group label { display: block; font-size: 0.8rem; font-weight: 600; color: #334155; margin-bottom: 0.4rem; }
    .form-control { width: 100%; padding: 0.6rem 0.8rem; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.85rem; transition: border-color 0.2s; }
    .form-control:focus { border-color: #1a5632; outline: none; box-shadow: 0 0 0 3px rgba(26, 86, 50, 0.1); }
    
    .toggle-wrapper { display: flex; align-items: center; justify-content: space-between; padding: 0.85rem 1rem; background: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0; margin-bottom: 0.85rem; }
    .toggle-title { font-size: 0.85rem; font-weight: 600; color: #1e293b; }
    .toggle-desc { font-size: 0.75rem; color: #64748b; margin-top: 0.15rem; }
    
    /* Switch */
    .switch { position: relative; display: inline-block; width: 44px; height: 24px; flex-shrink: 0; }
    .switch input { opacity: 0; width: 0; height: 0; }
    .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #cbd5e1; transition: .3s; border-radius: 24px; }
    .slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background-color: white; transition: .3s; border-radius: 50%; }
    input:checked + .slider { background-color: #1a5632; }
    input:checked + .slider:before { transform: translateX(20px); }
    
    .btn-save { background: #1a5632; color: #fff; border: none; padding: 0.65rem 1.5rem; border-radius: 8px; font-size: 0.85rem; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 0.4rem; transition: background 0.2s; }
    .btn-save:hover { background: #145226; }
    
    .btn-action-sm { padding: 0.3rem 0.6rem; border-radius: 6px; font-size: 0.75rem; font-weight: 600; border: 1px solid #cbd5e1; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 0.25rem; }
    .btn-danger-sm { background: #fef2f2; color: #991b1b; border-color: #fecaca; }
    .btn-danger-sm:hover { background: #fee2e2; }
    .btn-success-sm { background: #f0fdf4; color: #166534; border-color: #bbf7d0; }
    
    .badge-status { padding: 0.2rem 0.6rem; border-radius: 12px; font-size: 0.7rem; font-weight: 600; display: inline-block; }
    .badge-active { background: #dcfce7; color: #166534; }
    .badge-inactive { background: #fee2e2; color: #991b1b; }
    
    .alert { padding: 0.85rem 1.1rem; border-radius: 8px; font-size: 0.85rem; margin-bottom: 1.25rem; border-left: 4px solid; display: flex; align-items: center; gap: 0.5rem; }
    .alert-success { background: #f0fdf4; color: #166534; border-color: #22c55e; }
    .alert-danger { background: #fef2f2; color: #991b1b; border-color: #ef4444; }
    
    .code-box { background: #0f172a; color: #38bdf8; padding: 0.85rem; border-radius: 8px; font-family: monospace; font-size: 0.8rem; word-break: break-all; margin-top: 0.5rem; }
    
    .table-responsive { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .table { width: 100%; border-collapse: collapse; font-size: 0.8rem; min-width: 650px; }
    .table th { background: #f8fafc; padding: 0.65rem 0.85rem; text-align: left; font-weight: 600; color: #475569; border-bottom: 1px solid #e2e8f0; white-space: nowrap; }
    .table td { padding: 0.65rem 0.85rem; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    .table tbody tr:hover { background: #f8fafc; }

    /* Mobile Responsive Breakpoints */
    @media (max-width: 768px) {
        .settings-container { padding: 0 0.5rem; }
        .page-header-box { flex-direction: column; align-items: flex-start; gap: 0.75rem; padding: 1rem; }
        .page-header-box h2 { font-size: 1.05rem; }
        
        .settings-nav {
            display: flex;
            overflow-x: auto;
            white-space: nowrap;
            -webkit-overflow-scrolling: touch;
            padding: 0.4rem 0.4rem 0.2rem;
            margin-bottom: 1rem;
            border-radius: 8px 8px 0 0;
            scrollbar-width: none;
        }
        .settings-nav::-webkit-scrollbar { display: none; }
        .nav-tab { padding: 0.5rem 0.85rem; font-size: 0.775rem; flex-shrink: 0; }
        
        .card-header { flex-direction: column; align-items: flex-start; gap: 0.5rem; padding: 0.75rem 1rem; }
        .card-header h5 { font-size: 0.85rem; }
        .card-body { padding: 1rem; }
        
        .form-grid { grid-template-columns: 1fr; gap: 0.75rem; }
        
        .toggle-wrapper {
            flex-direction: row;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            padding: 0.75rem;
        }
        .toggle-title { font-size: 0.8rem; }
        .toggle-desc { font-size: 0.7rem; }
        
        .btn-save { width: 100%; justify-content: center; padding: 0.7rem 1rem; }
    }
</style>

<div class="settings-container">
    <div class="page-header-box">
        <h2><i class="fas fa-sliders-h"></i> System Settings & Global API Gateway</h2>
        
    </div>

    <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $messageType; ?>">
            <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
            <div><?php echo $message; ?></div>
        </div>
    <?php endif; ?>

    <!-- Navigation Tabs -->
    <div class="settings-nav">
        <button class="nav-tab active" onclick="switchTab('tab-general', this)"><i class="fas fa-cogs"></i> General System</button>
        <button class="nav-tab" onclick="switchTab('tab-posting', this)"><i class="fas fa-building"></i> Posting & Rules</button>
        <button class="nav-tab" onclick="switchTab('tab-notifications', this)"><i class="fas fa-bell"></i> Notifications</button>
        <button class="nav-tab" onclick="switchTab('tab-security', this)"><i class="fas fa-user-shield"></i> Security</button>
        <button class="nav-tab" onclick="switchTab('tab-api', this)"><i class="fas fa-network-wired"></i> Global RESTful API</button>
    </div>

    <form method="POST" action="ssettings">
        <input type="hidden" name="action" value="save_settings">
        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">

        <!-- TAB 1: GENERAL SYSTEM -->
        <div id="tab-general" class="tab-content active">
            <div class="card">
                <div class="card-header"><h5><i class="fas fa-sliders-h"></i> General Application Parameters</h5></div>
                <div class="card-body">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Application Title / System Name</label>
                            <input type="text" name="site_name" class="form-control" value="<?php echo htmlspecialchars($gen['site_name'] ?? ''); ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Paramilitary Agency Title</label>
                            <input type="text" name="agency_title" class="form-control" value="<?php echo htmlspecialchars($gen['agency_title'] ?? ''); ?>" required>
                        </div>
                        <div class="form-group">
                            <label>System Support Email</label>
                            <input type="email" name="contact_email" class="form-control" value="<?php echo htmlspecialchars($gen['contact_email'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label>System Support Helpline</label>
                            <input type="text" name="contact_phone" class="form-control" value="<?php echo htmlspecialchars($gen['contact_phone'] ?? ''); ?>">
                        </div>
                    </div>

                    <hr style="margin: 1.25rem 0; border:0; border-top:1px solid #e2e8f0;">

                    <div class="toggle-wrapper">
                        <div>
                            <div class="toggle-title"><i class="fas fa-tools"></i> Enable Maintenance Mode</div>
                            <div class="toggle-desc">Locks out non-Admin users from accessing system pages during scheduled maintenance.</div>
                        </div>
                        <label class="switch">
                            <input type="checkbox" name="maintenance_mode" value="1" <?php echo ($gen['maintenance_mode'] ?? '0') === '1' ? 'checked' : ''; ?>>
                            <span class="slider"></span>
                        </label>
                    </div>

                    <div class="form-group">
                        <label>Maintenance Mode Announcement Message</label>
                        <textarea name="maintenance_message" class="form-control" rows="2"><?php echo htmlspecialchars($gen['maintenance_message'] ?? ''); ?></textarea>
                    </div>

                    <div style="margin-top: 1.25rem; text-align: right;">
                        <button type="submit" class="btn-save"><i class="fas fa-save"></i> Save General Settings</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB 2: POSTING & RULES -->
        <div id="tab-posting" class="tab-content">
            <div class="card">
                <div class="card-header"><h5><i class="fas fa-gavel"></i> Posting Rules & Eligibility Thresholds</h5></div>
                <div class="card-body">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Over-Stay Recommendation Threshold (Years)</label>
                            <input type="number" name="overstay_years_threshold" class="form-control" min="1" max="20" value="<?php echo htmlspecialchars($post['overstay_years_threshold'] ?? '5'); ?>" required>
                            <small style="color:#64748b;">Officers deployed in a formation longer than this limit will appear on the Analytics Re-posting Engine.</small>
                        </div>
                        <div class="form-group">
                            <label>Duty Assumption Escalation Window (Days)</label>
                            <input type="number" name="assumption_window_days" class="form-control" min="1" max="90" value="<?php echo htmlspecialchars($post['assumption_window_days'] ?? '30'); ?>" required>
                            <small style="color:#64748b;">Posted officers failing to report within these days will trigger Non-Reporting alerts to Service HQ.</small>
                        </div>
                    </div>

                    <hr style="margin: 1.25rem 0; border:0; border-top:1px solid #e2e8f0;">

                    <div class="toggle-wrapper">
                        <div>
                            <div class="toggle-title"><i class="fas fa-shield-alt"></i> Enforce Disciplinary Posting Guard</div>
                            <div class="toggle-desc">Strictly block single & bulk postings for officers under active query, interdiction, or suspension.</div>
                        </div>
                        <label class="switch">
                            <input type="checkbox" name="disciplinary_guard_enabled" value="1" <?php echo ($post['disciplinary_guard_enabled'] ?? '1') === '1' ? 'checked' : ''; ?>>
                            <span class="slider"></span>
                        </label>
                    </div>

                    <div style="margin-top: 1.25rem; text-align: right;">
                        <button type="submit" class="btn-save"><i class="fas fa-save"></i> Save Posting Rules</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB 3: NOTIFICATIONS -->
        <div id="tab-notifications" class="tab-content">
            <div class="card">
                <div class="card-header"><h5><i class="fas fa-paper-plane"></i> Automated Multi-Channel Alerts</h5></div>
                <div class="card-body">
                    <div class="toggle-wrapper">
                        <div>
                            <div class="toggle-title"><i class="fas fa-sms"></i> Automatic SMS Alerts</div>
                            <div class="toggle-desc">Send automated SMS notifications to officer mobile phones upon posting order issuance.</div>
                        </div>
                        <label class="switch">
                            <input type="checkbox" name="auto_sms_enabled" value="1" <?php echo ($notif['auto_sms_enabled'] ?? '1') === '1' ? 'checked' : ''; ?>>
                            <span class="slider"></span>
                        </label>
                    </div>

                    <div class="toggle-wrapper">
                        <div>
                            <div class="toggle-title"><i class="fas fa-envelope"></i> Automatic Email Notifications</div>
                            <div class="toggle-desc">Dispatch official HTML posting notification letters via email.</div>
                        </div>
                        <label class="switch">
                            <input type="checkbox" name="auto_email_enabled" value="1" <?php echo ($notif['auto_email_enabled'] ?? '1') === '1' ? 'checked' : ''; ?>>
                            <span class="slider"></span>
                        </label>
                    </div>

                    <div class="form-grid" style="margin-top:1rem;">
                        <div class="form-group">
                            <label>SMTP Host</label>
                            <input type="text" name="smtp_host" class="form-control" placeholder="e.g. smtp.gmail.com or mail.yourdomain.com" value="<?php echo htmlspecialchars($notif['smtp_host'] ?? ''); ?>">
                            <small style="color:#64748b;">Leave blank to fall back to the server's local mail() - usually unreliable, often filtered as spam.</small>
                        </div>
                        <div class="form-group">
                            <label>SMTP Port</label>
                            <input type="number" name="smtp_port" class="form-control" placeholder="587" value="<?php echo htmlspecialchars($notif['smtp_port'] ?? '587'); ?>">
                            <small style="color:#64748b;">587 for TLS (most common), 465 for SSL.</small>
                        </div>
                        <div class="form-group">
                            <label>SMTP Username</label>
                            <input type="text" name="smtp_username" class="form-control" placeholder="e.g. noreply@yourdomain.com" value="<?php echo htmlspecialchars($notif['smtp_username'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label>SMTP Password</label>
                            <input type="password" name="smtp_password" class="form-control" placeholder="App password or mailbox password" value="<?php echo htmlspecialchars($notif['smtp_password'] ?? ''); ?>">
                            <small style="color:#64748b;">For Gmail, this must be a 16-character App Password, not your normal login password.</small>
                        </div>
                        <div class="form-group">
                            <label>Encryption</label>
                            <select name="smtp_encryption" class="form-control">
                                <?php $curEnc = $notif['smtp_encryption'] ?? 'tls'; ?>
                                <option value="tls" <?php echo $curEnc === 'tls' ? 'selected' : ''; ?>>TLS</option>
                                <option value="ssl" <?php echo $curEnc === 'ssl' ? 'selected' : ''; ?>>SSL</option>
                                <option value="none" <?php echo $curEnc === 'none' ? 'selected' : ''; ?>>None</option>
                            </select>
                        </div>
                    </div>

                    <div class="toggle-wrapper">
                        <div>
                            <div class="toggle-title"><i class="fas fa-bell"></i> Destination Command Bell Alerts</div>
                            <div class="toggle-desc">Trigger top navigation bell notifications for destination command administrators.</div>
                        </div>
                        <label class="switch">
                            <input type="checkbox" name="inapp_bell_alerts_enabled" value="1" <?php echo ($notif['inapp_bell_alerts_enabled'] ?? '1') === '1' ? 'checked' : ''; ?>>
                            <span class="slider"></span>
                        </label>
                    </div>

                    <div class="form-grid" style="margin-top:1rem; align-items:end;">
                        <div class="form-group">
                            <label>Send Test Email To</label>
                            <input type="email" name="test_email_recipient" class="form-control" placeholder="you@example.com">
                            <small style="color:#64748b;">Uses the SMTP fields above exactly as currently typed (they don't need to be saved first).</small>
                        </div>
                        <div class="form-group">
                            <button type="submit" name="action" value="send_test_email" class="btn-save" style="background:#2563eb;"><i class="fas fa-paper-plane"></i> Send Test Email</button>
                        </div>
                    </div>

                    <hr style="margin: 1.5rem 0; border:0; border-top:1px solid #e2e8f0;">

                    <div class="toggle-wrapper">
                        <div>
                            <div class="toggle-title"><i class="fab fa-whatsapp" style="color:#25D366; font-size:1.1rem;"></i> Meta WhatsApp Business API <span style="background:#25D366; color:#fff; font-size:0.7rem; padding:2px 6px; border-radius:10px; margin-left:6px;">1,000 FREE / Mo</span></div>
                            <div class="toggle-desc">Dispatch official posting orders directly to officer WhatsApp accounts for FREE using Meta Cloud API.</div>
                        </div>
                        <label class="switch">
                            <input type="checkbox" name="whatsapp_enabled" value="1" <?php echo ($notif['whatsapp_enabled'] ?? '0') === '1' ? 'checked' : ''; ?>>
                            <span class="slider"></span>
                        </label>
                    </div>

                    <div class="form-grid" style="margin-top:1rem;">
                        <div class="form-group">
                            <label>Meta WhatsApp Phone Number ID</label>
                            <input type="text" name="whatsapp_phone_number_id" class="form-control" placeholder="e.g. 109823475928374" value="<?php echo htmlspecialchars($notif['whatsapp_phone_number_id'] ?? ''); ?>">
                            <small style="color:#64748b;">Found in Meta Business Suite &rarr; WhatsApp &rarr; API Setup.</small>
                        </div>
                        <div class="form-group">
                            <label>Meta System User Access Token</label>
                            <input type="password" name="whatsapp_access_token" class="form-control" placeholder="EAAG..." value="<?php echo htmlspecialchars($notif['whatsapp_access_token'] ?? ''); ?>">
                            <small style="color:#64748b;">Permanent bearer token generated in Meta Business Manager.</small>
                        </div>
                        <div class="form-group">
                            <label>Meta Graph API Version</label>
                            <input type="text" name="whatsapp_api_version" class="form-control" placeholder="v18.0" value="<?php echo htmlspecialchars($notif['whatsapp_api_version'] ?? 'v18.0'); ?>">
                            <small style="color:#64748b;">Only change if Meta deprecates the current Graph API version.</small>
                        </div>
                    </div>

                    <div style="margin-top: 1.25rem; text-align: right;">
                        <button type="submit" class="btn-save"><i class="fas fa-save"></i> Save Notification Settings</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB 4: SECURITY -->
        <div id="tab-security" class="tab-content">
            <div class="card">
                <div class="card-header"><h5><i class="fas fa-lock"></i> Security & Authentication Hardening</h5></div>
                <div class="card-body">
                    <div class="toggle-wrapper" style="background:#f0fdf4; border-color:#bbf7d0;">
                        <div>
                            <div class="toggle-title"><i class="fas fa-key" style="color:#166534;"></i> Google Authenticator 2FA Enforcement</div>
                            <div class="toggle-desc">2FA is always required for every account on every login - there is no system setting that can disable it, so there's nothing to toggle here.</div>
                        </div>
                        <span class="badge-status badge-active"><i class="fas fa-check-circle"></i> Always On</span>
                    </div>

                    <div class="form-grid" style="margin-top:1rem;">
                        <div class="form-group">
                            <label>Brute-Force Max Login Failed Attempts</label>
                            <input type="number" name="login_max_attempts" class="form-control" min="3" max="20" value="<?php echo htmlspecialchars($sec['login_max_attempts'] ?? '5'); ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Session Inactivity Timeout (Minutes)</label>
                            <input type="number" name="session_timeout_minutes" class="form-control" min="5" max="240" value="<?php echo htmlspecialchars($sec['session_timeout_minutes'] ?? '30'); ?>" required>
                        </div>
                    </div>

                    <div style="margin-top: 1.25rem; text-align: right;">
                        <button type="submit" class="btn-save"><i class="fas fa-save"></i> Save Security Settings</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB 5: GLOBAL RESTFUL API -->
        <div id="tab-api" class="tab-content">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-network-wired"></i> Global RESTful API Gateway Configuration</h5>
                    <span class="badge-status badge-active">Live Endpoint: /api/v1/</span>
                </div>
                <div class="card-body">
                    <div class="toggle-wrapper">
                        <div>
                            <div class="toggle-title"><i class="fas fa-globe"></i> Master API Gateway Enable Switch</div>
                            <div class="toggle-desc">Allow external applications around the world to securely share and receive data.</div>
                        </div>
                        <label class="switch">
                            <input type="checkbox" name="api_master_enabled" value="1" <?php echo ($api['api_master_enabled'] ?? '1') === '1' ? 'checked' : ''; ?>>
                            <span class="slider"></span>
                        </label>
                    </div>

                    <div class="toggle-wrapper">
                        <div>
                            <div class="toggle-title"><i class="fas fa-code"></i> Enable Global CORS (Cross-Origin Resource Sharing)</div>
                            <div class="toggle-desc">Adds Access-Control-Allow-Origin headers so web applications worldwide can consume endpoints.</div>
                        </div>
                        <label class="switch">
                            <input type="checkbox" name="api_global_cors_enabled" value="1" <?php echo ($api['api_global_cors_enabled'] ?? '1') === '1' ? 'checked' : ''; ?>>
                            <span class="slider"></span>
                        </label>
                    </div>

                    <div class="form-group" style="margin-top: 1rem;">
                        <label>Global API Default Rate Limit (Requests per Minute)</label>
                        <input type="number" name="api_rate_limit_per_minute" class="form-control" min="10" max="5000" value="<?php echo htmlspecialchars($api['api_rate_limit_per_minute'] ?? '120'); ?>" required>
                    </div>

                    <div style="margin-top: 1.25rem; text-align: right;">
                        <button type="submit" class="btn-save"><i class="fas fa-save"></i> Save API Configuration</button>
                    </div>
                </div>
            </div>
    </form>

            <!-- API Keys Management (OUTSIDE MAIN FORM TO AVOID NESTED FORMS) -->
            <div class="card" style="margin-top: 1.5rem;">
                <div class="card-header">
                    <h5><i class="fas fa-key"></i> Registered Global Partner API Keys</h5>
                    <button type="button" onclick="document.getElementById('newKeyModal').style.display='block';" class="btn-save" style="padding:0.4rem 0.8rem; font-size:0.75rem;"><i class="fas fa-plus-circle"></i> Create New API Key</button>
                </div>
                <div class="card-body" style="padding:0;">
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Client / Partner Name</th>
                                    <th>API Key String</th>
                                    <th>Permissions</th>
                                    <th>Rate Limit</th>
                                    <th>Status</th>
                                    <th>Last Used</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($apiKeys)): ?>
                                    <tr><td colspan="7" style="text-align:center; padding:1.5rem; color:#64748b;">No API keys generated yet. Click "Create New API Key" above.</td></tr>
                                <?php else: foreach ($apiKeys as $k): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($k['client_name']); ?></strong></td>
                                        <td><code><?php echo htmlspecialchars($k['api_key']); ?></code></td>
                                        <td><span style="font-size:0.7rem; color:#334155;"><?php echo htmlspecialchars($k['permissions']); ?></span></td>
                                        <td><?php echo $k['rate_limit']; ?> req/min</td>
                                        <td>
                                            <span class="badge-status <?php echo $k['is_active'] ? 'badge-active' : 'badge-inactive'; ?>">
                                                <?php echo $k['is_active'] ? 'Active' : 'Revoked'; ?>
                                            </span>
                                        </td>
                                        <td><?php echo $k['last_used_at'] ? date('M d, H:i', strtotime($k['last_used_at'])) : 'Never'; ?></td>
                                        <td>
                                            <form method="POST" action="ssettings" style="display:inline;">
                                                <input type="hidden" name="action" value="toggle_api_key">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                                <input type="hidden" name="key_id" value="<?php echo $k['id']; ?>">
                                                <input type="hidden" name="status" value="<?php echo $k['is_active'] ? 0 : 1; ?>">
                                                <button type="submit" class="btn-action-sm <?php echo $k['is_active'] ? 'btn-danger-sm' : 'btn-success-sm'; ?>">
                                                    <?php echo $k['is_active'] ? 'Revoke' : 'Activate'; ?>
                                                </button>
                                            </form>
                                            <form method="POST" action="ssettings" style="display:inline;" onsubmit="return confirm('Permanently delete this API key for \'<?php echo htmlspecialchars(addslashes($k['client_name'])); ?>\'? This cannot be undone.');">
                                                <input type="hidden" name="action" value="delete_api_key">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                                <input type="hidden" name="key_id" value="<?php echo $k['id']; ?>">
                                                <button type="submit" class="btn-action-sm btn-danger-sm" title="Permanently delete this key"><i class="fas fa-trash"></i> Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- API Live Log Monitor -->
            <div class="card" style="margin-top: 1.5rem;">
                <div class="card-header"><h5><i class="fas fa-stream"></i> Global API Request Log Monitor (Recent 15 Hits)</h5></div>
                <div class="card-body" style="padding:0;">
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Timestamp</th>
                                    <th>Client Partner</th>
                                    <th>Method</th>
                                    <th>Endpoint</th>
                                    <th>Client IP</th>
                                    <th>HTTP Status</th>
                                    <th>Response Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($apiLogs)): ?>
                                    <tr><td colspan="7" style="text-align:center; padding:1.5rem; color:#64748b;">No API hits logged yet.</td></tr>
                                <?php else: foreach ($apiLogs as $log): ?>
                                    <tr>
                                        <td><?php echo date('Y-m-d H:i:s', strtotime($log['created_at'])); ?></td>
                                        <td><?php echo htmlspecialchars($log['client_name'] ?? 'Public / Unauthenticated'); ?></td>
                                        <td><strong><?php echo htmlspecialchars($log['method']); ?></strong></td>
                                        <td><code><?php echo htmlspecialchars($log['endpoint']); ?></code></td>
                                        <td><?php echo htmlspecialchars($log['ip_address']); ?></td>
                                        <td>
                                            <span class="badge-status <?php echo $log['status_code'] < 400 ? 'badge-active' : 'badge-inactive'; ?>">
                                                <?php echo $log['status_code']; ?>
                                            </span>
                                        </td>
                                        <td><?php echo $log['response_time_ms']; ?> ms</td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
</div>

<!-- Modal: Generate API Key -->
<div id="newKeyModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#fff; width:90%; max-width:500px; margin:5% auto; padding:1.5rem; border-radius:12px; border:1px solid #e2e8f0; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; border-bottom:1px solid #e2e8f0; padding-bottom:0.75rem;">
            <h4 style="margin:0; color:#1a5632;"><i class="fas fa-key"></i> Generate Global Partner API Key</h4>
            <button type="button" onclick="document.getElementById('newKeyModal').style.display='none';" style="background:none; border:none; font-size:1.2rem; cursor:pointer;">&times;</button>
        </div>
        <form method="POST" action="ssettings">
            <input type="hidden" name="action" value="generate_api_key">
            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">

            <div class="form-group">
                <label>Partner / Client Application Name</label>
                <input type="text" name="client_name" class="form-control" placeholder="e.g. Interpol NIS Gateway, Ministry HR App" required>
            </div>

            <div class="form-group">
                <label>API Endpoint Permissions</label>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.5rem; font-size:0.8rem;">
                    <label><input type="checkbox" name="permissions[]" value="read_personnel" checked> Share Personnel (Read)</label>
                    <label><input type="checkbox" name="permissions[]" value="write_personnel" checked> Receive Personnel (Write)</label>
                    <label><input type="checkbox" name="permissions[]" value="read_postings" checked> Share Postings (Read)</label>
                    <label><input type="checkbox" name="permissions[]" value="write_postings" checked> Receive Postings (Write)</label>
                    <label><input type="checkbox" name="permissions[]" value="read_disciplinary" checked> Share Disciplinary (Read)</label>
                    <label><input type="checkbox" name="permissions[]" value="write_disciplinary" checked> Receive Disciplinary (Write)</label>
                </div>
            </div>

            <div class="form-group">
                <label>Allowed Client IPs (Optional, comma-separated or blank for global)</label>
                <input type="text" name="allowed_ips" class="form-control" placeholder="e.g. 192.168.1.50, 102.164.20.10">
            </div>

            <div class="form-group">
                <label>Rate Limit (Requests per Minute)</label>
                <input type="number" name="rate_limit" class="form-control" value="120" min="10" max="5000">
            </div>

            <div style="text-align:right; margin-top:1.5rem;">
                <button type="button" onclick="document.getElementById('newKeyModal').style.display='none';" class="btn-action-sm">Cancel</button>
                <button type="submit" class="btn-save"><i class="fas fa-key"></i> Generate Key</button>
            </div>
        </form>
    </div>
</div>

<script>
    function switchTab(tabId, btn) {
        document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
        document.querySelectorAll('.nav-tab').forEach(el => el.classList.remove('active'));

        document.getElementById(tabId).classList.add('active');
        if (btn) btn.classList.add('active');
    }
</script>

<?php include 'includes/footer.php'; ?>
