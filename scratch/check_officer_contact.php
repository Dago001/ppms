<?php
require __DIR__ . '/../includes/config.php';

$svc = '35562';
$stmt = $pdo->prepare("SELECT * FROM tbl_emppersonal WHERE serviceNo = ?");
$stmt->execute([$svc]);
$empRow = $stmt->fetch(PDO::FETCH_ASSOC);

echo "TBL_EMPPERSONAL DATA FOR #$svc:\n";
print_r($empRow);

$stmt2 = $pdo->prepare("SELECT * FROM tbl_employment WHERE serviceNo = ?");
$stmt2->execute([$svc]);
$empData = $stmt2->fetch(PDO::FETCH_ASSOC);

echo "\nTBL_EMPLOYMENT DATA FOR #$svc:\n";
print_r($empData);
