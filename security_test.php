<?php
// security_test.php - Run this to verify security features
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>NIS-PPMS Security Verification</h2>";
echo "<style>body{font-family:monospace;padding:20px;background:#f8fafc} .pass{color:#27ae60} .fail{color:#e74c3c} .info{color:#3b82f6} .section{margin:15px 0;padding:15px;background:#fff;border-radius:8px;border:1px solid #e2e8f0}</style>";

// ============================================
// 1. FILE EXISTENCE CHECK
// ============================================
echo "<div class='section'><h3>1. Required Files</h3>";
$requiredFiles = [
    'includes/config.php',
    'includes/security.php',
    'includes/auth.php',
    '.htaccess'
];
foreach ($requiredFiles as $file) {
    if (file_exists($file)) {
        echo "<span class='pass'>✅ $file exists</span><br>";
    } else {
        echo "<span class='fail'>❌ $file MISSING</span><br>";
    }
}
echo "</div>";

// ============================================
// 2. FUNCTION AVAILABILITY
// ============================================
echo "<div class='section'><h3>2. Security Functions</h3>";
require_once 'includes/config.php';

$securityFunctions = [
    'setSecurityHeaders',
    'generateCSRFToken',
    'validateCSRFToken',
    'csrfField',
    'validateEmail',
    'validateInteger',
    'checkRateLimit',
    'validatePasswordStrength',
    'sanitizeInput'
];

foreach ($securityFunctions as $func) {
    if (function_exists($func)) {
        echo "<span class='pass'>✅ $func() - Available</span><br>";
    } else {
        echo "<span class='fail'>❌ $func() - NOT FOUND</span><br>";
    }
}
echo "</div>";

// ============================================
// 3. SQL INJECTION PROTECTION
// ============================================
echo "<div class='section'><h3>3. SQL Injection Protection</h3>";

// Test prepared statements
try {
    $testStmt = $pdo->prepare("SELECT 1");
    $testStmt->execute();
    echo "<span class='pass'>✅ PDO Prepared Statements - Working</span><br>";
} catch (Exception $e) {
    echo "<span class='fail'>❌ PDO Error: " . $e->getMessage() . "</span><br>";
}

// Check emulated prepares is OFF
if ($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES) === false) {
    echo "<span class='pass'>✅ Emulated Prepares - OFF (safer)</span><br>";
} else {
    echo "<span class='fail'>❌ Emulated Prepares - ON (use real prepared statements)</span><br>";
}
echo "</div>";

// ============================================
// 4. SESSION SECURITY
// ============================================
echo "<div class='section'><h3>4. Session Security</h3>";

$cookieParams = session_get_cookie_params();
echo "<span class='info'>🔹 Session Cookie Path: " . $cookieParams['path'] . "</span><br>";
echo "<span class='info'>🔹 Session Cookie Domain: " . ($cookieParams['domain'] ?: 'Not set') . "</span><br>";

if ($cookieParams['httponly']) {
    echo "<span class='pass'>✅ HttpOnly - Enabled</span><br>";
} else {
    echo "<span class='fail'>❌ HttpOnly - Disabled (vulnerable to XSS cookie theft)</span><br>";
}

if ($cookieParams['secure']) {
    echo "<span class='pass'>✅ Secure Flag - Enabled (HTTPS only)</span><br>";
} else {
    echo "<span class='info'>⚠️ Secure Flag - Disabled (enable if using HTTPS)</span><br>";
}

if (ini_get('session.use_strict_mode')) {
    echo "<span class='pass'>✅ Strict Mode - Enabled</span><br>";
} else {
    echo "<span class='fail'>❌ Strict Mode - Disabled</span><br>";
}

if (ini_get('session.use_only_cookies')) {
    echo "<span class='pass'>✅ Cookies Only - Enabled</span><br>";
} else {
    echo "<span class='fail'>❌ Cookies Only - Disabled (session ID in URL)</span><br>";
}
echo "</div>";

// ============================================
// 5. PHP SECURITY SETTINGS
// ============================================
echo "<div class='section'><h3>5. PHP Security Settings</h3>";

$checks = [
    'expose_php' => ['Off', '0'],
    'display_errors' => ['Off', '0'],
    'allow_url_fopen' => ['Off', '0'],
    'allow_url_include' => ['Off', '0'],
];

foreach ($checks as $setting => $safeValues) {
    $current = ini_get($setting);
    if (in_array($current, $safeValues)) {
        echo "<span class='pass'>✅ $setting = $current</span><br>";
    } else {
        echo "<span class='info'>⚠️ $setting = $current (recommended: " . implode(' or ', $safeValues) . ")</span><br>";
    }
}
echo "</div>";

// ============================================
// 6. CSRF TOKEN TEST
// ============================================
echo "<div class='section'><h3>6. CSRF Protection Test</h3>";

$token = generateCSRFToken();
echo "<span class='info'>🔹 Generated Token: " . substr($token, 0, 20) . "...</span><br>";

if (validateCSRFToken($token)) {
    echo "<span class='pass'>✅ CSRF Token Validation - Working</span><br>";
} else {
    echo "<span class='fail'>❌ CSRF Token Validation - Failed</span><br>";
}

// Test invalid token
if (!validateCSRFToken('invalid_token')) {
    echo "<span class='pass'>✅ Invalid Token Rejected - Working</span><br>";
} else {
    echo "<span class='fail'>❌ Invalid Token Accepted - SECURITY ISSUE</span><br>";
}
echo "</div>";

// ============================================
// 7. RATE LIMITING TEST
// ============================================
echo "<div class='section'><h3>7. Rate Limiting Test</h3>";

$testKey = 'test_rate_' . time();
echo "<span class='info'>🔹 Testing rate limit (5 attempts)...</span><br>";

$passedAll = true;
for ($i = 1; $i <= 6; $i++) {
    $result = checkRateLimit($testKey, 5, 300);
    if ($i <= 5 && !$result) {
        echo "<span class='fail'>❌ Attempt $i should be allowed but was blocked</span><br>";
        $passedAll = false;
    }
    if ($i === 6 && $result) {
        echo "<span class='fail'>❌ Attempt 6 should be blocked but was allowed</span><br>";
        $passedAll = false;
    }
}

if ($passedAll) {
    echo "<span class='pass'>✅ Rate Limiting - Working Correctly</span><br>";
}

// Clean up test entries
unset($_SESSION['rate_limits'][$testKey]);
echo "</div>";

// ============================================
// 8. PASSWORD SECURITY
// ============================================
echo "<div class='section'><h3>8. Password Security Test</h3>";

$testPasswords = [
    'short' => ['Weak', false],
    'NoNumbers!' => ['Weak', false],
    'nonumbersorchars' => ['Weak', false],
    'StrongP@ss1' => ['Weak', true], // 10 chars
    'VeryStr0ngP@ss!' => ['Strong', true], // 14 chars
];

foreach ($testPasswords as $pass => $info) {
    $errors = validatePasswordStrength($pass);
    $passed = empty($errors);
    $expectedStrong = $info[1];
    
    if ($passed === $expectedStrong) {
        echo "<span class='pass'>✅ Password '$pass' - " . ($passed ? 'Accepted' : 'Rejected') . " (Correct)</span><br>";
    } else {
        echo "<span class='fail'>❌ Password '$pass' - " . ($passed ? 'Accepted' : 'Rejected') . " (Wrong!)</span><br>";
    }
}
echo "</div>";

// ============================================
// 9. DATABASE CONNECTION TEST
// ============================================
echo "<div class='section'><h3>9. Database Security</h3>";

try {
    $testStmt = $pdo->query("SELECT VERSION() as version");
    $dbInfo = $testStmt->fetch();
    echo "<span class='pass'>✅ Database Connected: MySQL " . $dbInfo['version'] . "</span><br>";
    
    // Test that PDO is using real prepared statements
    $testStmt = $pdo->prepare("SELECT 1 as test WHERE 1 = ?");
    $testStmt->execute([1]);
    $result = $testStmt->fetch();
    if ($result && $result['test'] == 1) {
        echo "<span class='pass'>✅ Prepared Statements - Working</span><br>";
    }
} catch (Exception $e) {
    echo "<span class='fail'>❌ Database Error: " . $e->getMessage() . "</span><br>";
}
echo "</div>";

// ============================================
// 10. HTACCESS CHECK
// ============================================
echo "<div class='section'><h3>10. .htaccess Configuration</h3>";

if (file_exists('.htaccess')) {
    $htaccess = file_get_contents('.htaccess');
    
    $checks = [
        'X-Frame-Options' => 'Clickjacking protection',
        'X-Content-Type-Options' => 'MIME sniffing protection',
        'mod_deflate' => 'GZIP compression',
        'mod_expires' => 'Browser caching',
        'mod_headers' => 'Security headers module'
    ];
    
    foreach ($checks as $keyword => $description) {
        if (stripos($htaccess, $keyword) !== false) {
            echo "<span class='pass'>✅ $description - Present</span><br>";
        } else {
            echo "<span class='info'>⚠️ $description - Not in .htaccess</span><br>";
        }
    }
} else {
    echo "<span class='fail'>❌ .htaccess file not found</span><br>";
}
echo "</div>";

// ============================================
// FINAL SUMMARY
// ============================================
echo "<div class='section' style='background:#1a5632;color:#fff;'>";
echo "<h3>🔒 Security Status Summary</h3>";
echo "<p>Run this test on your production server for accurate results.</p>";
echo "<p><strong>Important:</strong> Some settings require server configuration changes.</p>";
echo "<p>If any items show ❌, review the corresponding security setting.</p>";
echo "</div>";