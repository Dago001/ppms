<?php
// includes/settings_helper.php
// Global System Settings & API Helper Engine for NIS-PPMS

$GLOBALS['systemSettingsCache'] = null;

if (!function_exists('getSystemSetting')) {
    /**
     * Get a system setting value by key with optional default fallback
     */
    function getSystemSetting($key, $default = null) {
        global $pdo;

        if ($GLOBALS['systemSettingsCache'] === null && isset($pdo)) {
            try {
                $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
                $GLOBALS['systemSettingsCache'] = [];
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $GLOBALS['systemSettingsCache'][$row['setting_key']] = $row['setting_value'];
                }
            } catch (Exception $e) {
                $GLOBALS['systemSettingsCache'] = [];
            }
        }

        if (is_array($GLOBALS['systemSettingsCache']) && array_key_exists($key, $GLOBALS['systemSettingsCache'])) {
            return $GLOBALS['systemSettingsCache'][$key];
        }

        return $default;
    }
}

if (!function_exists('getAllSystemSettings')) {
    /**
     * Get all system settings grouped by category
     */
    function getAllSystemSettings() {
        global $pdo;
        $settings = [];
        try {
            $stmt = $pdo->query("SELECT * FROM system_settings ORDER BY setting_group ASC, setting_key ASC");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $settings[$row['setting_group']][$row['setting_key']] = $row['setting_value'];
            }
        } catch (Exception $e) {}
        return $settings;
    }
}

if (!function_exists('updateSystemSetting')) {
    /**
     * Update or insert a single system setting key
     */
    function updateSystemSetting($key, $value, $group = 'general') {
        global $pdo;
        try {
            $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), setting_group = VALUES(setting_group)");
            $res = $stmt->execute([$key, $value, $group]);
            // Invalidate cache immediately so new value takes effect
            $GLOBALS['systemSettingsCache'] = null;
            return $res;
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('isMaintenanceModeActive')) {
    /**
     * Check if Maintenance Mode is enabled and current user is not Admin.
     * Only the 'admin' role (and pure naming synonyms for that same role -
     * NOT distinct roles like SHQ Admin/Command Admin) bypasses maintenance.
     */
    function isMaintenanceModeActive($userRole = null) {
        $maintenance = getSystemSetting('maintenance_mode', '0');
        if ($maintenance === '1' || $maintenance === 1 || $maintenance === 'true') {
            $role = strtolower(trim($userRole ?? ''));
            if (in_array($role, ['admin', 'super admin', 'superadmin'])) {
                return false; // Only Admin bypasses maintenance mode
            }
            return true;
        }
        return false;
    }
}

if (!function_exists('renderMaintenancePage')) {
    /**
     * Render the styled "System Under Maintenance" page and terminate the request.
     * Shared by includes/header.php (already-logged-in users hitting any page)
     * and login.php (blocking non-Admin logins at the door during maintenance).
     */
    function renderMaintenancePage($maintMsg) {
        if (!headers_sent()) {
            header_remove('X-Powered-By');
            header('X-Frame-Options: SAMEORIGIN');
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: no-referrer');
            header("Content-Security-Policy: default-src 'self'; img-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self'; frame-ancestors 'self';");
        }

        echo "<!DOCTYPE html><html lang='en'><head><title>System Maintenance - NIS-PPMS</title>
              <style>
              *{margin:0;padding:0;box-sizing:border-box;}
              body{font-family:Segoe UI,sans-serif;color:#fff;background:#0f172a url('assets/images/tech_building.jpg') center/cover no-repeat fixed;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1.5rem;position:relative;user-select:none;-webkit-user-select:none;}
              body::before{content:'';position:absolute;top:0;left:0;right:0;bottom:0;background:linear-gradient(135deg, rgba(26, 86, 50, 0.82) 0%, rgba(0, 0, 0, 0.8) 100%);z-index:0;}
              .maint-card{position:relative;z-index:1;background:#f8f6f2;border-radius:16px;padding:3rem 2rem;max-width:550px;text-align:center;border:1px solid #e2e8f0;box-shadow:0 25px 50px -12px rgba(0,0,0,0.5);}
              .maint-badge{width:70px;height:70px;background:#fef3c7;color:#d97706;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:2rem;margin:0 auto 1.5rem;}
              h2{color:#b45309;margin-bottom:0.75rem;}p{color:#475569;line-height:1.6;font-size:0.9rem;}a{display:inline-block;margin-top:1.5rem;padding:0.6rem 1.25rem;background:#1a5632;color:#fff;text-decoration:none;border-radius:8px;font-weight:600;}
              </style></head><body><div class='maint-card'><div class='maint-badge'>🛠️</div><h2>System Under Maintenance</h2><p>" . htmlspecialchars($maintMsg) . "</p><a href='login'>Admin Login</a></div><script src='assets/js/disable-right-click.js'></script></body></html>";
        exit();
    }
}

if (!function_exists('validateAPIKey')) {
    /**
     * Validate an incoming API key string against api_keys table
     * @return array|false Returns API Key Record array if valid, or false if invalid/disabled
     */
    function validateAPIKey($apiKey, $clientIp = '') {
        global $pdo;
        if (empty($apiKey)) return false;

        try {
            $stmt = $pdo->prepare("SELECT * FROM api_keys WHERE api_key = ? AND is_active = 1");
            $stmt->execute([$apiKey]);
            $keyRecord = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$keyRecord) return false;

            // Check IP Whitelist if configured
            if (!empty($keyRecord['allowed_ips'])) {
                $allowed = array_map('trim', explode(',', $keyRecord['allowed_ips']));
                if (!empty($clientIp) && !in_array($clientIp, $allowed) && !in_array('*', $allowed)) {
                    return false; // IP not allowed
                }
            }

            // Update last_used_at timestamp asynchronously/quietly
            $pdo->prepare("UPDATE api_keys SET last_used_at = NOW() WHERE id = ?")->execute([$keyRecord['id']]);

            return $keyRecord;
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('logAPIRequest')) {
    /**
     * Record API request details into api_logs table
     */
    function logAPIRequest($keyId, $endpoint, $method, $ip, $statusCode, $responseTimeMs = 0, $summary = '') {
        global $pdo;
        try {
            $stmt = $pdo->prepare("INSERT INTO api_logs (api_key_id, endpoint, method, ip_address, status_code, response_time_ms, payload_summary) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$keyId, $endpoint, strtoupper($method), $ip, $statusCode, $responseTimeMs, substr($summary, 0, 500)]);
        } catch (Exception $e) {}
    }
}
