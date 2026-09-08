<?php
require __DIR__ . '/../includes/config.php';
$colsPH = $pdo->query('SHOW COLUMNS FROM posting_history')->fetchAll(PDO::FETCH_COLUMN);
echo "POSTING_HISTORY: " . implode(', ', $colsPH) . "\n";
