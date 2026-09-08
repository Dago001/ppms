<?php
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/permissions.php';

echo "=== REAL-TIME INSTANT PERMISSION ENFORCEMENT VERIFICATION ===\n\n";

$testUser = $pdo->query("SELECT id, username, role_id FROM users WHERE username != 'admin' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$testUser) {
    echo "[SKIP] No non-admin test user found.\n";
    exit();
}

$uId = $testUser['id'];
echo "Testing with User ID #{$uId} (Username: {$testUser['username']}, Role ID: {$testUser['role_id']})\n\n";

// 1. Initial Permission Check
$initBulk = hasPermission('bulk_posting', $uId);
echo "[STEP 1] Initial 'bulk_posting' permission: " . ($initBulk ? "TRUE" : "FALSE") . "\n";

// 2. Grant 'bulk_posting' explicitly for this user
$pdo->prepare("INSERT INTO user_permissions (user_id, permission_key, is_granted) VALUES (?, 'bulk_posting', 1) ON DUPLICATE KEY UPDATE is_granted = 1")->execute([$uId]);
$afterGrant = hasPermission('bulk_posting', $uId);
echo "[STEP 2] After explicit GRANT 'bulk_posting': " . ($afterGrant ? "PASSED (Instant TRUE)" : "FAILED (Still False)") . "\n";

// 3. Deny 'bulk_posting' explicitly for this user
$pdo->prepare("INSERT INTO user_permissions (user_id, permission_key, is_granted) VALUES (?, 'bulk_posting', 0) ON DUPLICATE KEY UPDATE is_granted = 0")->execute([$uId]);
$afterDeny = hasPermission('bulk_posting', $uId);
echo "[STEP 3] After explicit DENY 'bulk_posting': " . (!$afterDeny ? "PASSED (Instant FALSE)" : "FAILED (Still True)") . "\n";

// 4. Test Baseline Role Matrix update for User's Role
$pdo->prepare("DELETE FROM user_permissions WHERE user_id = ? AND permission_key = 'bulk_posting'")->execute([$uId]);
$pdo->prepare("INSERT INTO role_permissions (role_id, permission_key, is_granted) VALUES (?, 'bulk_posting', 1) ON DUPLICATE KEY UPDATE is_granted = 1")->execute([$testUser['role_id']]);
$afterRoleGrant = hasPermission('bulk_posting', $uId);
echo "[STEP 4] After Baseline Role Matrix GRANT 'bulk_posting' for Role #{$testUser['role_id']}: " . ($afterRoleGrant ? "PASSED (Instant TRUE)" : "FAILED (Still False)") . "\n";

// Cleanup
$pdo->prepare("DELETE FROM user_permissions WHERE user_id = ?")->execute([$uId]);
echo "\n[CLEANUP] Deleted test overrides.\n";
echo "=== VERIFICATION COMPLETE ===\n";
