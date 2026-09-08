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
$dump .= "-- NIS-PPMS Complete Database Dump for cPanel / Production Deployment\n";
$dump .= "-- Target Site: https://posting.niims.com.ng\n";
$dump .= "-- Export Date: " . date('Y-m-d H:i:s') . "\n";
$dump .= "-- ========================================================\n\n";
$dump .= "SET FOREIGN_KEY_CHECKS = 0;\n";
$dump .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n\n";

foreach ($tables as $table) {
    $create = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC)['Create Table'];
    $dump .= "DROP TABLE IF EXISTS `$table`;\n";
    $dump .= $create . ";\n\n";
    
    $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($rows)) {
        $cols = array_map(function($c) { return "`$c`"; }, array_keys($rows[0]));
        $colList = implode(', ', $cols);
        
        $chunks = array_chunk($rows, 100);
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

// Save to database.sql and nisposting_complete_cpanel_database.sql
file_put_contents(__DIR__ . '/../database.sql', $dump);
file_put_contents(__DIR__ . '/../nisposting_complete_cpanel_database.sql', $dump);
file_put_contents(__DIR__ . '/../nisposting_infinityfree_database.sql', $dump);

echo "SUCCESS: Complete database export saved to database.sql and nisposting_complete_cpanel_database.sql (" . number_format(strlen($dump)) . " bytes)\n";
