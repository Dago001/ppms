<?php
// database/add_posted_by_to_posting_history.php
// Production migration script to add created_by & posted_by columns to posting_history table
require_once __DIR__ . '/../includes/config.php';

try {
    echo "=== CHECKING / ADDING COLUMNS TO POSTING_HISTORY ===\n";
    $cols = $pdo->query("SHOW COLUMNS FROM posting_history")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('created_by', $cols)) {
        $pdo->exec("ALTER TABLE posting_history ADD COLUMN created_by INT(11) NULL AFTER posting_type");
        echo "Added 'created_by' column to posting_history.\n";
    } else {
        echo "'created_by' column already exists in posting_history.\n";
    }

    if (!in_array('posted_by', $cols)) {
        $pdo->exec("ALTER TABLE posting_history ADD COLUMN posted_by VARCHAR(150) NULL AFTER created_by");
        echo "Added 'posted_by' column to posting_history.\n";
    } else {
        echo "'posted_by' column already exists in posting_history.\n";
    }

    // Populate existing posting_history records with poster user name where possible
    echo "\n=== BACKFILLING POSTED_BY DATA ===\n";
    
    $stmtBackfillPn = $pdo->exec("
        UPDATE posting_history ph
        JOIN posting_notifications pn 
          ON pn.serviceNo = ph.serviceNo 
         AND (pn.posting_location = ph.posting_location OR DATE(pn.created_at) = DATE(ph.created_at))
        LEFT JOIN users u ON u.id = pn.created_by
        SET 
          ph.created_by = COALESCE(ph.created_by, pn.created_by),
          ph.posted_by = COALESCE(
              ph.posted_by,
              IF(u.full_name IS NOT NULL AND u.full_name != '', u.full_name, NULL),
              IF(pn.posted_by IS NOT NULL AND pn.posted_by != '' AND pn.posted_by != 'Service HQ', pn.posted_by, NULL)
          )
        WHERE ph.posted_by IS NULL OR ph.posted_by = 'Service HQ'
    ");
    echo "Backfilled {$stmtBackfillPn} records from posting_notifications.\n";

    $adminUser = $pdo->query("SELECT id, full_name FROM users WHERE role_id = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($adminUser) {
        $adminName = !empty($adminUser['full_name']) ? $adminUser['full_name'] : 'Gift Dagogo';
        $adminId = $adminUser['id'];
        $stmtDefaultAdmin = $pdo->prepare("
            UPDATE posting_history 
            SET created_by = COALESCE(created_by, ?), 
                posted_by = COALESCE(posted_by, ?) 
            WHERE posted_by IS NULL OR posted_by = 'Service HQ' OR posted_by = ''
        ");
        $stmtDefaultAdmin->execute([$adminId, $adminName]);
        echo "Updated " . $stmtDefaultAdmin->rowCount() . " remaining legacy records to default admin ({$adminName}).\n";
    }

    echo "\n=== MIGRATION COMPLETED SUCCESSFULLY ===\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
