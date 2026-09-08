<?php
require_once 'includes/config.php';
require_once 'includes/NotificationService.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h3>Testing Email...</h3>";

$ns = new NotificationService($pdo);

// Get an officer with email
$stmt = $pdo->query("SELECT serviceNo, surname, firstName, email FROM tbl_emppersonal WHERE email IS NOT NULL AND email != '' LIMIT 1");
$officer = $stmt->fetch();

if ($officer) {
    $officerName = $officer['surname'] . ' ' . $officer['firstName'];
    echo "Testing email to: {$officerName} ({$officer['serviceNo']}) - Email: {$officer['email']}<br><br>";
    
    $result = $ns->sendPostingEmail(
        $officer['serviceNo'],
        $officerName,
        'Test Rank',
        'Test Location',
        date('Y-m-d')
    );
    
    echo "Email Result: ";
    print_r($result);
} else {
    echo "No officers with email addresses found.";
}