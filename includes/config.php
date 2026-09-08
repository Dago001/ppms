<?php
// includes/config.php
// Live Production Database & Security Configuration for NIS-PPMS

// ============================================
// LOAD ENVIRONMENT VARIABLES
// ============================================
$env = false;
$envFileLocal = __DIR__ . '/../.env';
$envFileOutside = __DIR__ . '/../../.env';

if (@file_exists($envFileLocal)) {
    $env = @parse_ini_file($envFileLocal);
} elseif (@file_exists($envFileOutside)) {
    $env = @parse_ini_file($envFileOutside);
}

if (!$env) {
    // Dynamic Environment Detection: Localhost vs niscoreapps.com.ng vs niims.com.ng vs Custom
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';

    $isLocalhost = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1']) || (php_sapi_name() === 'cli');

    $customConfig = __DIR__ . '/custom_db_config.php';
    if (file_exists($customConfig)) {
        $env = require $customConfig;
    } elseif ($isLocalhost) {
        $env = [
            'DB_SERVER'   => 'localhost',
            'DB_PORT'     => '3306',
            'DB_USERNAME' => 'root',
            'DB_PASSWORD' => '',
            'DB_DATABASE' => 'niimscom_idcard',
            'APP_ENV'     => 'development',
            'APP_DEBUG'    => 'true',
            'APP_URL'     => 'http://localhost/nisposting'
        ];
    } else {
        // Dynamic Live Production (Supports https://ppms.immigration.gov.ng, https://niscoreapps.com.ng/ppms, etc.)
        $appPath = (strpos($host, 'ppms.immigration.gov.ng') !== false) ? '' : ((strpos($uri, '/ppms') !== false) ? '/ppms' : ((strpos($uri, '/nisposting') !== false) ? '/nisposting' : ''));
        if (!defined('DB_PASSWORD_OVERRIDE')) {
            $localSecrets = __DIR__ . '/local_secrets.php';
            if (file_exists($localSecrets)) {
                require_once $localSecrets;
            }
        }
        $env = [
            'DB_SERVER'   => 'localhost',
            'DB_PORT'     => '3306',
            'DB_USERNAME' => 'immigrat_ppms',
            'DB_PASSWORD' => defined('DB_PASSWORD_OVERRIDE') ? DB_PASSWORD_OVERRIDE : '',
            'DB_DATABASE' => 'immigrat_ppms',
            'APP_ENV'     => 'production',
            'APP_DEBUG'    => 'false',
            'APP_URL'     => $scheme . '://' . ($host ?: 'ppms.immigration.gov.ng') . $appPath
        ];
    }
}

// ============================================
// LOAD SECURITY
// ============================================
if (file_exists(__DIR__ . '/security.php')) {
    require_once __DIR__ . '/security.php';
    setSecurityHeaders();
}

// ============================================
// SECURE SESSION CONFIGURATION
// ============================================
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
}

// ============================================
// DATABASE CONFIGURATION (from .env / fallback)
// ============================================
define('DB_SERVER', $env['DB_SERVER']);
define('DB_PORT', $env['DB_PORT']);
define('DB_USERNAME', $env['DB_USERNAME']);
define('DB_PASSWORD', $env['DB_PASSWORD']);
define('DB_DATABASE', $env['DB_DATABASE']);
define('APP_URL', $env['APP_URL'] ?? 'https://ppms.immigration.gov.ng/');

// ============================================
// PDO CONNECTION
// ============================================
if (file_exists(__DIR__ . '/cache.php')) {
    require_once __DIR__ . '/cache.php';
}

try {
    $pdo = new PDO(
        "mysql:host=" . DB_SERVER . ";port=" . DB_PORT . ";dbname=" . DB_DATABASE . ";charset=utf8mb4",
        DB_USERNAME,
        DB_PASSWORD,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, SESSION sql_mode=''"
        ]
    );
} catch (PDOException $e) {
    error_log("Connection failed: " . $e->getMessage());
    die("A database error occurred. Please try again later.");
}

// ============================================
// MYSQLI CONNECTION
// ============================================
$conn = @mysqli_connect(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_DATABASE, DB_PORT);
if (!$conn) {
    error_log("MySQLi connection failed: " . mysqli_connect_error());
}

// ============================================
// SANITIZATION FUNCTION
// ============================================
if (!function_exists('sanitizeInput')) {
    function sanitizeInput($data) {
        if (is_array($data)) {
            return array_map('sanitizeInput', $data);
        }
        return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
    }
}

// ============================================
// CSRF TOKEN
// ============================================
if (function_exists('generateCSRFToken')) {
    $csrf_token = generateCSRFToken();
}

// ============================================
// ERROR HANDLING BASED ON ENVIRONMENT
// ============================================
$isProduction = ($env['APP_ENV'] ?? 'production') === 'production';
$isDebug = ($env['APP_DEBUG'] ?? 'false') === 'true';

if ($isProduction && !$isDebug) {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT);
} else {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}

// ============================================
// TIMEZONE
// ============================================
date_default_timezone_set('Africa/Lagos');