<?php
require __DIR__ . '/../includes/config.php';
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
echo "TABLES:\n" . implode("\n", $tables) . "\n\n";

if (in_array('roles', $tables)) {
    echo "ROLES COLS:\n";
    print_r($pdo->query("SHOW COLUMNS FROM roles")->fetchAll(PDO::FETCH_ASSOC));
}

if (in_array('permissions', $tables)) {
    echo "PERMISSIONS COLS:\n";
    print_r($pdo->query("SHOW COLUMNS FROM permissions")->fetchAll(PDO::FETCH_ASSOC));
}

if (in_array('role_permissions', $tables)) {
    echo "ROLE_PERMISSIONS COLS:\n";
    print_r($pdo->query("SHOW COLUMNS FROM role_permissions")->fetchAll(PDO::FETCH_ASSOC));
}

if (in_array('user_permissions', $tables)) {
    echo "USER_PERMISSIONS COLS:\n";
    print_r($pdo->query("SHOW COLUMNS FROM user_permissions")->fetchAll(PDO::FETCH_ASSOC));
}
