<?php
require __DIR__ . '/../includes/config.php';
$roles = $pdo->query('SELECT * FROM roles')->fetchAll(PDO::FETCH_ASSOC);
print_r($roles);

$users = $pdo->query('SELECT u.id, u.username, u.role_id, r.name as role_name FROM users u LEFT JOIN roles r ON u.role_id = r.id')->fetchAll(PDO::FETCH_ASSOC);
print_r($users);
