<?php
require_once __DIR__ . '/../includes/config.php';

function sql_escape_value($v) {
    if ($v === null) return 'NULL';
    $v = str_replace(
        ['\\', "\0", "\n", "\r", "'"],
        ['\\\\', '\\0', '\\n', '\\r', "\\'"],
        $v
    );
    return "'" . $v . "'";
}

$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

$dump = "-- ========================================================\n";
$dump .= "-- NIS-PPMS Lightweight Database Dump for InfinityFree Testing\n";
$dump .= "-- Target Site: https://ppms.infinityfree.io\n";
$dump .= "-- Export Date: " . date('Y-m-d H:i:s') . "\n";
$dump .= "-- ========================================================\n\n";
$dump .= "SET FOREIGN_KEY_CHECKS = 0;\n";
$dump .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n\n";

foreach ($tables as $table) {
    $create = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC)['Create Table'];
    $dump .= "DROP TABLE IF EXISTS `$table`;\n";
    $dump .= $create . ";\n\n";
    
    // For large tables (tbl_employment, tbl_emppersonal, current_posting, posting_history), import first 50 rows for testing
    $limit = in_array($table, ['tbl_employment', 'tbl_emppersonal', 'current_posting', 'posting_history']) ? "LIMIT 50" : "";
    $rows = $pdo->query("SELECT * FROM `$table` $limit")->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($rows)) {
        $cols = array_map(function($c) { return "`$c`"; }, array_keys($rows[0]));
        $colList = implode(', ', $cols);
        
        $chunks = array_chunk($rows, 50);
        foreach ($chunks as $chunk) {
            $valueTuples = [];
            foreach ($chunk as $row) {
                $vals = array_map('sql_escape_value', array_values($row));
                $valueTuples[] = "(" . implode(', ', $vals) . ")";
            }
            $dump .= "INSERT INTO `$table` ($colList) VALUES\n" . implode(",\n", $valueTuples) . ";\n\n";
        }
    }
}

$dump .= "SET FOREIGN_KEY_CHECKS = 1;\n";

$targetPath = __DIR__ . '/../nisposting_infinityfree_light_database.sql';
file_put_contents($targetPath, $dump);

echo "SUCCESS: Lightweight database dump generated at " . realpath($targetPath) . " (" . number_format(filesize($targetPath)) . " bytes)\n";
