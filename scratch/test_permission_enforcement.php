<?php
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/permissions.php';

echo "=== PERMISSION ENFORCEMENT INTEGRATION TEST ===\n\n";

// Test User ID 2
$testUserId = 2;

// Step 1: Explicitly DENY 'bulk_posting' for User 2
$stmt = $pdo->prepare("INSERT INTO user_permissions (user_id, permission_key, is_granted) VALUES (?, 'bulk_posting', 0) ON DUPLICATE KEY UPDATE is_granted = 0");
$stmt->execute([$testUserId]);

$canBulkPost = hasPermission('bulk_posting', $testUserId);
echo "[TEST 1] Deny 'bulk_posting' for User #$testUserId: " . ($canBulkPost ? "FAILED (Returned True)" : "PASSED (Returned False)") . "\n";

// Step 2: Explicitly GRANT 'bulk_posting' for User 2
$stmt2 = $pdo->prepare("INSERT INTO user_permissions (user_id, permission_key, is_granted) VALUES (?, 'bulk_posting', 1) ON DUPLICATE KEY UPDATE is_granted = 1");
$stmt2->execute([$testUserId]);

$canBulkPostGranted = hasPermission('bulk_posting', $testUserId);
echo "[TEST 2] Grant 'bulk_posting' for User #$testUserId: " . ($canBulkPostGranted ? "PASSED (Returned True)" : "FAILED (Returned False)") . "\n";

// Step 3: Explicitly DENY 'manage_disciplinary' for User 2
$stmt3 = $pdo->prepare("INSERT INTO user_permissions (user_id, permission_key, is_granted) VALUES (?, 'manage_disciplinary', 0) ON DUPLICATE KEY UPDATE is_granted = 0");
$stmt3->execute([$testUserId]);

$canDisc = hasPermission('manage_disciplinary', $testUserId);
echo "[TEST 3] Deny 'manage_disciplinary' for User #$testUserId: " . ($canDisc ? "FAILED (Returned True)" : "PASSED (Returned False)") . "\n";

// Step 4: Clean up test overrides for User 2
$pdo->prepare("DELETE FROM user_permissions WHERE user_id = ? AND permission_key IN ('bulk_posting', 'manage_disciplinary')")->execute([$testUserId]);
echo "[CLEANUP] Deleted test overrides.\n";

echo "\n=== ALL PERMISSION TESTS COMPLETE ===\n";
