<?php
// database/backfill_audit_logs.php
// Production migration script to fix activity_logs schema & backfill historical audit logs
require_once __DIR__ . '/../includes/config.php';

try {
    echo "=== FIXING ACTIVITY_LOGS TABLE SCHEMA ===\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS activity_logs_temp (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT(11) NULL,
            action VARCHAR(100) NULL,
            description TEXT NULL,
            ip_address VARCHAR(45) NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_created_at (created_at),
            INDEX idx_action (action)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        INSERT INTO activity_logs_temp (user_id, action, description, ip_address, created_at)
        SELECT user_id, action, description, ip_address, created_at 
        FROM activity_logs 
        GROUP BY user_id, action, description, created_at
        ORDER BY created_at ASC;
    ");

    $pdo->exec("DROP TABLE activity_logs;");
    $pdo->exec("RENAME TABLE activity_logs_temp TO activity_logs;");

    echo "Fixed activity_logs table schema.\n";

    echo "\n=== BACKFILLING HISTORICAL AUDIT LOGS ===\n";

    $stmt1 = $pdo->exec("
        INSERT INTO activity_logs (user_id, action, description, ip_address, created_at)
        SELECT 
            COALESCE(pn.created_by, 1) as user_id,
            'officer_posted' as action,
            CONCAT(
                'Officer #', pn.serviceNo, ' (', COALESCE(pn.officer_name, 'N/A'), 
                ', Rank: ', COALESCE(pn.officer_rank, 'N/A'), 
                ') posted to ''', pn.posting_location, ''' by ', COALESCE(pn.posted_by, 'System Administrator')
            ) as description,
            '127.0.0.1' as ip_address,
            pn.created_at
        FROM posting_notifications pn
    ");
    echo "Backfilled {$stmt1} officer posting audit log entries.\n";

    $stmt2 = $pdo->exec("
        INSERT INTO activity_logs (user_id, action, description, ip_address, created_at)
        SELECT 
            COALESCE(bpb.created_by, 1) as user_id,
            'bulk_posting_executed' as action,
            CONCAT(
                'Bulk posting batch ''', bpb.batch_name, ''' (File: ', COALESCE(bpb.file_name, 'N/A'), 
                ') executed: ', bpb.success_count, ' officers posted successfully (', bpb.failed_count, ' failed)'
            ) as description,
            '127.0.0.1' as ip_address,
            COALESCE(bpb.completed_at, bpb.created_at)
        FROM bulk_posting_batches bpb
    ");
    echo "Backfilled {$stmt2} bulk posting batch audit log entries.\n";

    $stmt3 = $pdo->exec("
        INSERT INTO activity_logs (user_id, action, description, ip_address, created_at)
        SELECT 
            u.id as user_id,
            'user_account_created' as action,
            CONCAT(
                'User account ''@', u.username, ''' (', COALESCE(u.full_name, 'N/A'), 
                ', Role: ', r.name, ') initialized in system'
            ) as description,
            '127.0.0.1' as ip_address,
            COALESCE(u.created_at, NOW())
        FROM users u
        JOIN roles r ON u.role_id = r.id
    ");
    echo "Backfilled {$stmt3} user account audit log entries.\n";

    $totalCount = $pdo->query("SELECT COUNT(*) FROM activity_logs")->fetchColumn();
    echo "\n=== MIGRATION COMPLETED: Total Audit Logs = {$totalCount} ===\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
