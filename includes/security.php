<?php
// includes/security.php
// Comprehensive Security Layer for NIS-PPMS

// ============================================
// 1. SECURITY HEADERS
// ============================================
function setSecurityHeaders() {
    // Stop leaking the PHP version to attackers via the response headers
    header_remove('X-Powered-By');

    // Prevent clickjacking
    header('X-Frame-Options: SAMEORIGIN');
    
    // Enable XSS protection in older browsers
    header('X-XSS-Protection: 1; mode=block');
    
    // Prevent MIME-type sniffing
    header('X-Content-Type-Options: nosniff');
    
    // Referrer policy
    header('Referrer-Policy: strict-origin-when-cross-origin');

    // Every page here is dynamically rendered PHP carrying session-scoped/PII
    // data - never let a browser or shared-computer cache serve a stale copy
    // (e.g. an old sidebar after a route rename, or a personnel record after
    // logout via the back button).
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    // Content Security Policy
    // Note: unpkg.com serves Leaflet's JS/CSS/marker images for the geofencing maps,
    // tile.openstreetmap.org serves the street map tiles, server.arcgisonline.com
    // serves the satellite imagery layer, and nominatim.openstreetmap.org powers
    // the location search box in the geofence config modal.
    $csp = "default-src 'self'; " .
           "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://unpkg.com; " .
           "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://unpkg.com; " .
           "font-src 'self' https://cdnjs.cloudflare.com data:; " .
           "img-src 'self' data: blob: https://unpkg.com https://*.tile.openstreetmap.org https://server.arcgisonline.com; " .
           "connect-src 'self' https://nominatim.openstreetmap.org; " .
           "frame-src 'none'; " .
           "object-src 'none'; " .
           "base-uri 'self'; " .
           "form-action 'self';";
    header("Content-Security-Policy: $csp");

    // Permissions Policy (formerly Feature-Policy)
    // geolocation must stay enabled for 'self' - login.php captures GPS coordinates
    // via navigator.geolocation to enforce the officer geofencing security feature.
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(self), interest-cohort=()');
    
    // HSTS (only if using HTTPS)
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
    }
}

// ============================================
// 2. CSRF PROTECTION
// ============================================
function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCSRFToken($token) {
    if (!isset($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

function csrfField() {
    return '<input type="hidden" name="csrf_token" value="' . generateCSRFToken() . '">';
}

// ============================================
// 3. VALIDATION FUNCTIONS
// ============================================
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function validateInteger($value, $min = null, $max = null) {
    $value = filter_var($value, FILTER_VALIDATE_INT);
    if ($value === false) return false;
    if ($min !== null && $value < $min) return false;
    if ($max !== null && $value > $max) return false;
    return true;
}

// ============================================
// 4. RATE LIMITING
// ============================================
function checkRateLimit($key, $maxAttempts = 5, $windowSeconds = 300) {
    $storageDir = __DIR__ . '/../storage/ratelimit/';
    if (!is_dir($storageDir)) {
        @mkdir($storageDir, 0755, true);
    }
    
    $file = $storageDir . md5($key) . '.ratelimit';
    $current = [];
    
    if (file_exists($file)) {
        $current = json_decode(file_get_contents($file), true) ?: [];
    }
    
    // Clean old entries
    $current = array_filter($current, function($timestamp) use ($windowSeconds) {
        return $timestamp > (time() - $windowSeconds);
    });
    
    if (count($current) >= $maxAttempts) {
        return false;
    }
    
    $current[] = time();
    @file_put_contents($file, json_encode($current), LOCK_EX);
    return true;
}

// ============================================
// 5. SESSION SECURITY
// ============================================
function secureSessionStart() {
    if (session_status() === PHP_SESSION_NONE) {
        $cookieParams = [
            'lifetime' => 28800,
            'path' => '/',
            'domain' => '',
            'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
            'httponly' => true,
            'samesite' => 'Lax'
        ];
        
        session_set_cookie_params($cookieParams);
        session_start();
        
        if (!isset($_SESSION['last_regeneration'])) {
            $_SESSION['last_regeneration'] = time();
        } elseif (time() - $_SESSION['last_regeneration'] > 1800) {
            session_regenerate_id(true);
            $_SESSION['last_regeneration'] = time();
        }
    }
}

// ============================================
// 6. PASSWORD POLICY
// ============================================
function validatePasswordStrength($password) {
    if (strlen($password) < 8) {
        return ['valid' => false, 'message' => 'Password must be at least 8 characters long.', 'errors' => ['Password must be at least 8 characters']];
    }
    if (!preg_match('/[A-Z]/', $password)) {
        return ['valid' => false, 'message' => 'Password must contain at least one uppercase letter (A-Z).', 'errors' => ['Password must contain at least one uppercase letter']];
    }
    if (!preg_match('/[a-z]/', $password)) {
        return ['valid' => false, 'message' => 'Password must contain at least one lowercase letter (a-z).', 'errors' => ['Password must contain at least one lowercase letter']];
    }
    if (!preg_match('/[0-9]/', $password)) {
        return ['valid' => false, 'message' => 'Password must contain at least one number (0-9).', 'errors' => ['Password must contain at least one number']];
    }
    if (!preg_match('/[\W_]/', $password)) {
        return ['valid' => false, 'message' => 'Password must contain at least one special character (e.g. @, #, $, %, !, &).', 'errors' => ['Password must contain at least one special character']];
    }
    return ['valid' => true, 'message' => 'Password meets all security requirements.', 'errors' => []];
}

// ============================================
// 7. FILE UPLOAD SECURITY
// ============================================
function secureFileUpload($file, $allowedTypes = ['csv', 'txt'], $maxSize = 5242880) {
    if (!isset($file['error']) || is_array($file['error'])) {
        return ['success' => false, 'message' => 'Invalid file upload parameters'];
    }
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'File upload stopped by extension'
        ];
        return ['success' => false, 'message' => $errors[$file['error']] ?? 'Unknown upload error'];
    }
    
    if ($file['size'] > $maxSize) {
        return ['success' => false, 'message' => 'File size exceeds maximum allowed'];
    }
    
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedTypes)) {
        return ['success' => false, 'message' => 'File type not allowed'];
    }
    
    // Check MIME type
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        $allowedMimes = [
            'csv' => ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'],
            'txt' => ['text/plain']
        ];
        
        $validMime = false;
        foreach ($allowedTypes as $type) {
            if (isset($allowedMimes[$type]) && in_array($mimeType, $allowedMimes[$type])) {
                $validMime = true;
                break;
            }
        }
        
        if (!$validMime) {
            return ['success' => false, 'message' => 'Invalid file type detected'];
        }
    }
    
    return ['success' => true];
}

// ============================================
// 8. IP BLOCKING / BLACKLIST
// ============================================
function isIPBlocked($ip) {
    $blockedFile = __DIR__ . '/../storage/blocked_ips.json';
    if (!file_exists($blockedFile)) return false;
    
    $blocked = @json_decode(file_get_contents($blockedFile), true);
    return is_array($blocked) && in_array($ip, $blocked);
}

function blockIP($ip, $reason = 'Suspicious activity') {
    $blockedFile = __DIR__ . '/../storage/blocked_ips.json';
    $blocked = [];
    
    if (file_exists($blockedFile)) {
        $blocked = @json_decode(file_get_contents($blockedFile), true) ?: [];
    }
    
    if (!in_array($ip, $blocked)) {
        $blocked[] = $ip;
        @file_put_contents($blockedFile, json_encode($blocked, JSON_PRETTY_PRINT), LOCK_EX);
        error_log("IP Blocked: $ip - Reason: $reason");
    }
}

// ============================================
// 9. IDOR & RECORD TOKEN SECURITY
// ============================================
if (!function_exists('generateRecordToken')) {
    function generateRecordToken($serviceNo) {
        if (!defined('RECORD_TOKEN_SECRET')) {
            $localSecrets = __DIR__ . '/local_secrets.php';
            if (file_exists($localSecrets)) {
                require_once $localSecrets;
            }
        }
        // Fallback keeps older deployments without local_secrets.php working;
        // set up includes/local_secrets.php (see local_secrets.sample.php) everywhere.
        $secret = defined('RECORD_TOKEN_SECRET') ? RECORD_TOKEN_SECRET : 'NIS_PPMS_SECRET_KEY_2026';
        $sessionId = session_id() ?: 'NIS_STATIC_SESSION';
        return substr(hash_hmac('sha256', (string)$serviceNo . '|' . $sessionId, $secret), 0, 16);
    }
}

if (!function_exists('verifyRecordToken')) {
    function verifyRecordToken($serviceNo, $token) {
        if (empty($token)) return false;
        $expected = generateRecordToken($serviceNo);
        return hash_equals($expected, (string)$token);
    }
}

// ============================================
// 9.5 SESSION TIMEOUT SECURITY
// ============================================
if (!function_exists('checkSessionTimeout')) {
    function checkSessionTimeout() {
        if (isset($_SESSION['user_id'])) {
            require_once __DIR__ . '/settings_helper.php';
            $timeoutMinutes = max(intval(getSystemSetting('session_timeout_minutes', '30')), 5);
            $timeoutSeconds = $timeoutMinutes * 60;

            if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeoutSeconds)) {
                // Inactive session - destroy session
                session_unset();
                session_destroy();
                header("Location: login?timeout=1");
                exit();
            }
            $_SESSION['last_activity'] = time();
        }
    }
}

// ============================================
// 10. DISCIPLINARY, RETIREMENT & POSTING ELIGIBILITY GUARDS
// ============================================
if (!function_exists('checkOfficerPostingEligibility')) {
    function checkOfficerPostingEligibility($pdo, $serviceNo) {
        if (empty($serviceNo) || !$pdo) {
            return ['can_post' => true, 'disciplinary_status' => 'Clean', 'is_retired' => false, 'retirement_reason' => ''];
        }
        
        try {
            $stmt = $pdo->prepare("SELECT e.disciplinary_status, e.disciplinary_remarks, e.promotion_eligibility, e.promotion_remarks, e.empStatus, e.dofa, p.dob,
                                   TIMESTAMPDIFF(YEAR, e.dofa, CURDATE()) as service_years,
                                   TIMESTAMPDIFF(YEAR, p.dob, CURDATE()) as age_years
                                   FROM tbl_employment e 
                                   LEFT JOIN tbl_emppersonal p ON e.serviceNo = p.serviceNo 
                                   WHERE e.serviceNo = ? LIMIT 1");
            $stmt->execute([$serviceNo]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$row) {
                return ['can_post' => true, 'disciplinary_status' => 'Clean', 'is_retired' => false, 'retirement_reason' => ''];
            }
            
            $status = trim($row['disciplinary_status'] ?? 'Clean');
            $remarks = trim($row['disciplinary_remarks'] ?? '');
            $empStatus = trim($row['empStatus'] ?? 'Active');
            
            $serviceYears = (!empty($row['dofa']) && $row['dofa'] !== '0000-00-00') ? intval($row['service_years'] ?? 0) : 0;
            $ageYears = (!empty($row['dob']) && $row['dob'] !== '0000-00-00') ? intval($row['age_years'] ?? 0) : 0;
            
            $hasDiscBlock = (!empty($status) && strcasecmp($status, 'Clean') !== 0);
            
            // Statutory Retirement Checks (35 Years Service OR 60 Years Age OR Retired status)
            $isRetired = false;
            $retirementReasons = [];
            
            if ($serviceYears >= 35) {
                $isRetired = true;
                $retirementReasons[] = "35+ Years of Service ($serviceYears yrs elapsed from DOFA)";
            }
            if ($ageYears >= 60) {
                $isRetired = true;
                $retirementReasons[] = "60+ Years of Age ($ageYears yrs old from DOB)";
            }
            if (!empty($empStatus) && in_array(strtolower($empStatus), ['retired', 'dismissed', 'deceased', 'inactive'])) {
                $isRetired = true;
                $retirementReasons[] = "Status: " . ucfirst($empStatus);
            }
            
            $canPost = !$hasDiscBlock && !$isRetired;
            $retirementReasonStr = implode(' | ', $retirementReasons);
            
            return [
                'can_post' => $canPost,
                'is_retired' => $isRetired,
                'retirement_reason' => $retirementReasonStr,
                'service_years' => $serviceYears,
                'age_years' => $ageYears,
                'disciplinary_status' => $status,
                'disciplinary_remarks' => $remarks,
                'empStatus' => $isRetired ? 'Inactive' : $empStatus,
                'promotion_eligibility' => $row['promotion_eligibility'] ?? 'Eligible',
                'promotion_remarks' => $row['promotion_remarks'] ?? ''
            ];
        } catch (Exception $e) {
            return ['can_post' => true, 'disciplinary_status' => 'Clean', 'is_retired' => false, 'retirement_reason' => ''];
        }
    }
}

if (!function_exists('getDisciplinaryBadgeHTML')) {
    function getDisciplinaryBadgeHTML($status, $remarks = '') {
        $status = trim($status ?? 'Clean');
        if (empty($status) || strcasecmp($status, 'Clean') === 0) {
            return '<span class="status-pill active" title="Clear Disciplinary Record &bull; Eligible for Posting"><i class="fas fa-check-circle"></i> Clean</span>';
        }
        
        $title = htmlspecialchars($status . ($remarks ? ': ' . $remarks : ' &bull; POSTING BLOCKED'));
        
        if (strcasecmp($status, 'Under Interdiction') === 0 || strcasecmp($status, 'Under Suspension') === 0 || strcasecmp($status, 'Dismissed') === 0) {
            return '<span class="status-pill inactive" style="background:#fee2e2; color:#991b1b; border:1px solid #fca5a5;" title="' . $title . '"><i class="fas fa-ban"></i> ' . htmlspecialchars($status) . ' (Blocked)</span>';
        }
        
        return '<span class="status-pill inactive" style="background:#fef3c7; color:#92400e; border:1px solid #fde68a;" title="' . $title . '"><i class="fas fa-exclamation-triangle"></i> ' . htmlspecialchars($status) . ' (Blocked)</span>';
    }
}