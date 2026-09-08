<?php
require __DIR__ . '/../includes/config.php';
$k = $pdo->query('SELECT api_key FROM api_keys LIMIT 1')->fetchColumn();
echo "API_KEY:" . $k;
