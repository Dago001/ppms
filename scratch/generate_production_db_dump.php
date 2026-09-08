<?php
require __DIR__ . '/../includes/config.php';

$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
$dumpFile = __DIR__ . '/../nisposting_production_dump.sql';

$sqlDump = "-- ========================================================\n";
"-- NIGERIA IMMIGRATION SERVICE - NIS-PPMS PRODUCTION DATABASE DUMP\n";
"-- Generated: " . date('Y-m-d H:i:s') . "\n";
"-- ========================================================\n\n";
$sqlDump .= "SET FOREIGN_KEY_CHECKS=0;\n";
$sqlDump .= "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n";
$sqlDump .= "SET time_zone = '+00:00';\n\n";

foreach ($tables as $table) {
    // Get Create Table SQL
    $createTableSql = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
    $sqlDump .= "-- Table structure for `$table` --\n";
    $sqlDump .= "DROP TABLE IF EXISTS `$table`;\n";
    $sqlDump .= $createTableSql['Create Table'] . ";\n\n";

    // Get Table Data
    $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($rows)) {
        $sqlDump .= "-- Dumping data for `$table` --\n";
        foreach ($rows as $row) {
            $cols = array_keys($row);
            $vals = array_values($row);
            
            $escapedVals = array_map(function($v) use ($pdo) {
                if ($v === null) return "NULL";
                return $pdo->quote($v);
            }, $vals);
            
            $sqlDump .= "INSERT INTO `$table` (`" . implode("`, `", $cols) . "`) VALUES (" . implode(", ", $escapedVals) . ");\n";
        }
        $sqlDump .= "\n";
    }
}

$sqlDump .= "SET FOREIGN_KEY_CHECKS=1;\n";

file_put_contents($dumpFile, $sqlDump);
echo "PRODUCTION SQL DUMP CREATED SUCCESSFULLY AT: $dumpFile (" . round(filesize($dumpFile)/1024, 2) . " KB)\n";
