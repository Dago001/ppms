<?php
require_once 'includes/config.php';

$nos = ['35562', '25403', '25581', '16201', '25169', '25174', '25542', '27318'];

foreach ($nos as $svc) {
    $stmt = $pdo->prepare("SELECT p.surname, p.firstName, p.middleName, e.currentRank, e.presentPosting, e.empStatus, p.serviceNo 
                          FROM tbl_emppersonal p 
                          LEFT JOIN tbl_employment e ON p.serviceNo = e.serviceNo 
                          WHERE p.serviceNo = ? OR e.serviceNo = ? OR TRIM(p.serviceNo) = ? OR TRIM(e.serviceNo) = ?
                          LIMIT 1");
    $stmt->execute([$svc, $svc, $svc, $svc]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Query for $svc => ServiceNo in DB: " . ($row['serviceNo'] ?? 'NULL') . ", Name: " . ($row['surname'] ?? '') . " " . ($row['firstName'] ?? '') . "\n";
}
