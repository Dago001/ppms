<?php
require __DIR__ . '/../includes/config.php';

try {
    // 1. Create system_settings table
    $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value TEXT NULL,
        setting_group VARCHAR(50) DEFAULT 'general',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Seed default settings
    $defaultSettings = [
        ['site_name', 'Nigeria Immigration Service - Personnel Posting Management System', 'general'],
        ['agency_title', 'Nigeria Immigration Service (NIS)', 'general'],
        ['contact_email', 'support@immigration.gov.ng', 'general'],
        ['contact_phone', '+234 9 290 0000', 'general'],
        ['maintenance_mode', '0', 'general'],
        ['maintenance_message', 'The NIS-PPMS platform is currently undergoing scheduled maintenance. Authorized Super Administrators may log in.', 'general'],
        
        ['overstay_years_threshold', '5', 'posting'],
        ['assumption_window_days', '30', 'posting'],
        ['disciplinary_guard_enabled', '1', 'posting'],
        ['auto_sms_enabled', '1', 'notifications'],
        ['auto_email_enabled', '1', 'notifications'],
        ['inapp_bell_alerts_enabled', '1', 'notifications'],
        
        ['enforce_2fa_systemwide', '0', 'security'],
        ['login_max_attempts', '5', 'security'],
        ['session_timeout_minutes', '30', 'security'],
        
        ['api_master_enabled', '1', 'api'],
        ['api_global_cors_enabled', '1', 'api'],
        ['api_ip_whitelist', '', 'api'],
        ['api_rate_limit_per_minute', '120', 'api']
    ];

    $stmtInsert = $pdo->prepare("INSERT IGNORE INTO system_settings (setting_key, setting_value, setting_group) VALUES (?, ?, ?)");
    foreach ($defaultSettings as $setting) {
        $stmtInsert->execute($setting);
    }

    // 2. Create api_keys table
    $pdo->exec("CREATE TABLE IF NOT EXISTS api_keys (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_name VARCHAR(150) NOT NULL,
        api_key VARCHAR(64) UNIQUE NOT NULL,
        permissions TEXT NOT NULL,
        allowed_ips TEXT NULL,
        rate_limit INT DEFAULT 100,
        is_active TINYINT(1) DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        last_used_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Seed default initial master API key if empty
    $countKeys = $pdo->query("SELECT COUNT(*) FROM api_keys")->fetchColumn();
    if ($countKeys == 0) {
        $demoKey = 'nis_live_key_' . bin2hex(random_bytes(16));
        $pdo->prepare("INSERT INTO api_keys (client_name, api_key, permissions, rate_limit, is_active) VALUES (?, ?, ?, ?, 1)")
            ->execute([
                'Global Immigration Exchange (Default Gateway)',
                $demoKey,
                'read_personnel,write_personnel,read_postings,write_postings,read_disciplinary,write_disciplinary',
                120
            ]);
    }

    // 3. Create api_logs table
    $pdo->exec("CREATE TABLE IF NOT EXISTS api_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        api_key_id INT NULL,
        endpoint VARCHAR(255) NOT NULL,
        method VARCHAR(10) NOT NULL,
        ip_address VARCHAR(45) NOT NULL,
        status_code INT NOT NULL,
        response_time_ms FLOAT NULL,
        payload_summary TEXT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    echo "SUCCESS: system_settings, api_keys, and api_logs tables created & initialized successfully!\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
