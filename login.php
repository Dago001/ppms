<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/GoogleAuthenticator.php';
require_once __DIR__ . '/includes/settings_helper.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Redirect if already logged in
if (isLoggedIn()) {
    header('Location: dashboard');
    exit();
}

$error = '';
$errorType = ''; // 'rate_limit', 'csrf', 'credentials', 'inactive', 'not_found', 'system', '2fa'
$step = 1; // 1 = Password step, 2 = 2FA step

// Reset 2FA session step
if (isset($_GET['cancel_2fa'])) {
    unset($_SESSION['2fa_pending_user']);
    unset($_SESSION['2fa_staged_secret']);
    header('Location: login');
    exit();
}

// Check active 2FA step
if (isset($_SESSION['2fa_pending_user'])) {
    $step = 2;
}

// --------------------------------------------
// POST FORM HANDLER
// --------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($csrf_token, $_POST['csrf_token'])) {
        $error = "Invalid security token. Please refresh and try again.";
        $errorType = 'csrf';
    } else {
        // STEP 1: USERNAME & PASSWORD
        if (isset($_POST['action']) && $_POST['action'] === 'login_step1') {
            $username = sanitizeInput($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            
            $rateLimitKey = 'login_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
            $maxLoginAttempts = (int)getSystemSetting('login_max_attempts', 5);
            if (function_exists('checkRateLimit')) {
                if (!checkRateLimit($rateLimitKey, $maxLoginAttempts, 300)) {
                    $error = "Too many attempts. Please wait 5 minutes.";
                    $errorType = 'rate_limit';
                }
            }

            if (empty($error) && !preg_match('/^[A-Za-z0-9]+$/', $username)) {
                $error = "Username can only contain letters and numbers - no special characters.";
                $errorType = 'invalid_format';
            }

            if (empty($error)) {
                try {
                    $checkStmt = $pdo->prepare("SELECT id, username, status FROM users WHERE username = ?");
                    $checkStmt->execute([$username]);
                    $userExists = $checkStmt->fetch();
                    
                    if (!$userExists) {
                        $error = "No account found with username: <strong>" . htmlspecialchars($username) . "</strong>.";
                        $errorType = 'not_found';
                    } elseif ($userExists['status'] !== 'active') {
                        $error = "Your account is currently <strong>" . htmlspecialchars($userExists['status']) . "</strong>.";
                        $errorType = 'inactive';
                    } else {
                        $user = verifyUserCredentials($username, $password);
                        if ($user) {
                            // Maintenance Mode Enforcement: block login here (not after
                            // 2FA) for anyone but Admin, so a non-Admin never even reaches
                            // the 2FA step while maintenance is active.
                            $userRoleName = '';
                            if (!empty($user['role_id'])) {
                                try {
                                    $roleStmt = $pdo->prepare("SELECT name FROM roles WHERE id = ?");
                                    $roleStmt->execute([$user['role_id']]);
                                    $userRoleName = $roleStmt->fetchColumn() ?: '';
                                } catch (Exception $exRole) {}
                            }
                            if (isMaintenanceModeActive($userRoleName)) {
                                $maintMsg = getSystemSetting('maintenance_message', 'The NIS Posting System is currently undergoing scheduled system maintenance.');
                                renderMaintenancePage($maintMsg);
                            }

                            // Run Geofence verification
                            require_once __DIR__ . '/includes/GeofenceService.php';
                            $userLat = !empty($_POST['user_lat']) ? floatval($_POST['user_lat']) : null;
                            $userLng = !empty($_POST['user_lng']) ? floatval($_POST['user_lng']) : null;

                            if ($userLat && $userLng) {
                                $geoRes = GeofenceService::verifyUserLocation($pdo, $user['id'], $userLat, $userLng, 'login');
                                if (!$geoRes['success']) {
                                    $error = $geoRes['message'];
                                    $errorType = 'geofence_blocked';
                                }
                            }

                            if (empty($error)) {
                                $_SESSION['2fa_pending_user'] = $user;
                                if (empty($user['google_2fa_secret'])) {
                                    $_SESSION['2fa_staged_secret'] = GoogleAuthenticator::createSecret();
                                }
                                $step = 2;
                            }
                        } else {
                            $error = "Incorrect password for: <strong>" . htmlspecialchars($username) . "</strong>.";
                            $errorType = 'credentials';
                        }
                    }
                } catch (Exception $e) {
                    $error = "System error occurred. Please try again.";
                    $errorType = 'system';
                }
            }
        }
        
        // STEP 2: 2FA VERIFICATION
        elseif (isset($_POST['action']) && $_POST['action'] === 'verify_2fa') {
            if (!isset($_SESSION['2fa_pending_user'])) {
                header('Location: login');
                exit();
            }
            
            $pendingUser = $_SESSION['2fa_pending_user'];
            $totpCode = str_replace(' ', '', trim($_POST['totp_code'] ?? ''));
            $isNewSetup = empty($pendingUser['google_2fa_secret']);
            $secretToVerify = $isNewSetup ? ($_SESSION['2fa_staged_secret'] ?? '') : $pendingUser['google_2fa_secret'];
            
            if (empty($secretToVerify)) {
                $error = "Session expired. Please sign in again.";
                unset($_SESSION['2fa_pending_user']);
                unset($_SESSION['2fa_staged_secret']);
                $step = 1;
            } elseif (GoogleAuthenticator::verifyCode($secretToVerify, $totpCode)) {
                try {
                    if ($isNewSetup) {
                        try {
                            $pdo->prepare("UPDATE users SET google_2fa_secret = ? WHERE id = ?")->execute([$secretToVerify, $pendingUser['id']]);
                        } catch (Exception $ex1) {
                            try { $pdo->exec("ALTER TABLE users ADD COLUMN google_2fa_secret VARCHAR(255) NULL"); } catch (Exception $ex2) {}
                            $pdo->prepare("UPDATE users SET google_2fa_secret = ? WHERE id = ?")->execute([$secretToVerify, $pendingUser['id']]);
                        }
                    }
                    
                    $roleName = 'User';
                    $roleId = $pendingUser['role_id'] ?? 0;
                    if (!empty($roleId)) {
                        try {
                            $roleStmt = $pdo->prepare("SELECT name FROM roles WHERE id = ?");
                            $roleStmt->execute([$roleId]);
                            $r = $roleStmt->fetch();
                            if ($r) $roleName = $r['name'];
                        } catch (Exception $exRole) {}
                    }
                    
                    $_SESSION['user_id'] = $pendingUser['id'];
                    $_SESSION['username'] = $pendingUser['username'];
                    $_SESSION['full_name'] = $pendingUser['full_name'] ?? $pendingUser['username'];
                    $_SESSION['role_name'] = $roleName;
                    $_SESSION['role_id'] = $roleId;
                    $_SESSION['must_change_password'] = !empty($pendingUser['must_change_password']) ? 1 : 0;

                    unset($_SESSION['2fa_pending_user']);
                    unset($_SESSION['2fa_staged_secret']);
                    
                    try {
                        $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$pendingUser['id']]);
                    } catch (Exception $exLast) {
                        try { $pdo->exec("ALTER TABLE users ADD COLUMN last_login DATETIME NULL"); } catch (Exception $exLast2) {}
                    }

                    session_regenerate_id(true);
                    if (!empty($_SESSION['must_change_password'])) {
                        header('Location: changep');
                    } else {
                        header('Location: dashboard');
                    }
                    exit();
                } catch (Exception $e) {
                    error_log("2FA login completion error: " . $e->getMessage());
                    $error = "Error completing sign in: " . htmlspecialchars($e->getMessage());
                    $step = 2;
                }
            } else {
                $error = "Invalid 6-digit Google Authenticator code.";
                $errorType = '2fa';
                $step = 2;
            }
        }
    }
}

$pendingUser = $_SESSION['2fa_pending_user'] ?? null;
$isNew2faSetup = ($pendingUser && empty($pendingUser['google_2fa_secret']));
$stagedSecret = $_SESSION['2fa_staged_secret'] ?? ($pendingUser['google_2fa_secret'] ?? '');

$qrUrl = '';
$qrFallbackUrl = '';
$otpAuthUrl = '';
if ($step === 2 && $isNew2faSetup && !empty($stagedSecret)) {
    $qrUrl = GoogleAuthenticator::getQrUrl($pendingUser['username'], $stagedSecret);
    $qrFallbackUrl = "https://api.qrserver.com/v1/create-qr-code/?data=" . urlencode(GoogleAuthenticator::getOtpAuthUrl($pendingUser['username'], $stagedSecret)) . "&size=180x180";
    $otpAuthUrl = GoogleAuthenticator::getOtpAuthUrl($pendingUser['username'], $stagedSecret);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PPMS Login</title>
    
    <link rel="preconnect" href="https://cdnjs.cloudflare.com">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <!-- QRCode Client-Side Generator Library -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>

    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body { 
            font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, sans-serif;
            background: url('assets/images/tech_building.jpg') center/cover no-repeat fixed;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 12px;
            position: relative;
        }
        
        body::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: linear-gradient(135deg, rgba(26, 86, 50, 0.82) 0%, rgba(0, 0, 0, 0.8) 100%);
            z-index: 0;
        }
        
        .login-container {
            background: #ffffff;
            padding: 1.15rem 1.25rem;
            border-radius: 16px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.35);
            width: 100%;
            max-width: 360px;
            position: relative;
            z-index: 1;
            border-top: 4px solid #1a5632;
        }
        
        .logo { text-align: center; margin-bottom: 0.75rem; }
        
        .logo-image {
            width: <?php echo $step === 2 ? '55px' : '70px'; ?>;
            height: <?php echo $step === 2 ? '55px' : '70px'; ?>;
            margin: 0 auto 0.4rem;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #ffffff;
            border-radius: 50%;
            padding: 4px;
            box-shadow: 0 4px 12px rgba(26, 86, 50, 0.2);
            border: 2px solid #1a5632;
            transition: all 0.2s ease;
        }
        
        .logo-image img { max-width: 100%; max-height: 100%; object-fit: contain; }
        
        .logo-title { color: #1a5632; font-size: 1.05rem; font-weight: 600; margin-bottom: 0.1rem; }
        .logo-subtitle { color: #64748b; font-size: 0.65rem; font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px; }
        
        .form-group { margin-bottom: 0.75rem; }
        .input-wrapper { position: relative; }
        .input-wrapper i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 0.85rem; z-index: 1; }
        
        input[type="text"], input[type="password"] {
            width: 100%;
            padding: 0.65rem 0.85rem 0.65rem 2.2rem;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 0.825rem;
            font-weight: 500;
            background: #f8fafc;
            color: #1e293b;
        }
        
        input:focus { outline: none; border-color: #1a5632; background: #ffffff; }
        
        .totp-input {
            text-align: center;
            letter-spacing: 8px;
            font-size: 1.25rem !important;
            font-weight: 600 !important;
            padding: 0.6rem !important;
            color: #1a5632 !important;
            border-color: #cbd5e1 !important;
        }
        
        .password-container { position: relative; }
        .password-toggle { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); cursor: pointer; background: none; border: none; font-size: 0.9rem; padding: 6px; color: #94a3b8; }
        
        .btn-login {
            width: 100%;
            padding: 0.7rem;
            background: linear-gradient(135deg, #1a5632, #1f6b3e);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            text-transform: uppercase;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.35rem;
            text-decoration: none;
        }
        
        .btn-login:hover { background: linear-gradient(135deg, #1f6b3e, #145226); }
        .btn-secondary-link { display: block; text-align: center; margin-top: 0.5rem; color: #64748b; font-size: 0.7rem; font-weight: 500; text-decoration: none; }
        .btn-secondary-link:hover { color: #1a5632; text-decoration: underline; }
        
        .error {
            padding: 0.5rem 0.75rem;
            border-radius: 8px;
            margin-bottom: 0.75rem;
            border-left: 3px solid #dc2626;
            background: #fef2f2;
            color: #991b1b;
            font-size: 0.725rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }

        .qr-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 0.6rem;
            text-align: center;
            margin-bottom: 0.65rem;
        }

        #qrcode-canvas canvas, #qrcode-canvas img {
            margin: 0 auto;
            border-radius: 6px;
            display: block;
        }

        .secret-box {
            background: #ffffff;
            border: 1px dashed #cbd5e1;
            padding: 0.15rem 0.35rem;
            border-radius: 4px;
            font-family: monospace;
            font-size: 0.725rem;
            font-weight: 600;
            color: #1a5632;
            display: inline-block;
            margin-top: 0.2rem;
            user-select: all;
        }

        .footer-info {
            background: #f8fafc;
            color: #64748b;
            padding: 0.45rem;
            border-radius: 8px;
            margin-top: 0.75rem;
            font-size: 0.65rem;
            text-align: center;
            border: 1px solid #e2e8f0;
            font-weight: 500;
        }

        .no-background-image body { background: linear-gradient(135deg, #1a5632 0%, #0d2e1a 50%, #000000 100%) !important; }
    </style>
</head>
<body>

    <div class="login-container">
        <div class="logo">
            <div class="logo-image">
                <img src="assets/images/logo.png" alt="NIS Logo" onerror="this.style.display='none'">
            </div>
            <div class="logo-title">NIS-PPMS</div>
            <?php if ($step === 1): ?>
                <div class="logo-subtitle">Personnel Posting Management System</div>
            <?php endif; ?>
        </div>

        <?php if (!empty($error)): ?>
            <div class="error">
                <i class="fas fa-exclamation-circle" style="font-size:0.85rem; flex-shrink:0;"></i>
                <div><?php echo $error; ?></div>
            </div>
        <?php endif; ?>

        <!-- STEP 1: USERNAME & PASSWORD -->
        <?php if ($step === 1): ?>
            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="login_step1">
                <input type="hidden" name="user_lat" id="userLatInput">
                <input type="hidden" name="user_lng" id="userLngInput">
                
                <div class="form-group">
                    <div class="input-wrapper">
                        <i class="fas fa-user"></i>
                        <input type="text" id="username" name="username" placeholder="Username" required autocomplete="username" autofocus
                               pattern="[A-Za-z0-9]+" title="Only letters and numbers are allowed - no special characters."
                               oninput="this.value = this.value.replace(/[^A-Za-z0-9]/g, '')"
                               value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                    </div>
                </div>
                
                <div class="form-group password-container">
                    <div class="input-wrapper">
                        <i class="fas fa-lock"></i>
                        <input type="password" id="password" name="password" placeholder="Password" required autocomplete="current-password">
                    </div>
                    <button type="button" class="password-toggle" id="togglePassword" tabindex="-1"><i class="fas fa-eye"></i></button>
                </div>
                
                <button type="submit" class="btn-login">
                    <i class="fas fa-sign-in-alt"></i> Sign in
                </button>
            </form>

        <!-- STEP 2: GOOGLE AUTHENTICATOR -->
        <?php else: ?>
            <div style="text-align:center; margin-bottom:0.5rem;">
                <span style="font-size:0.75rem; color:#64748b; font-weight:500;">
                    User: <strong><?php echo htmlspecialchars($pendingUser['username'] ?? ''); ?></strong>
                </span>
            </div>

            <?php if ($isNew2faSetup): ?>
                <!-- COMPACT HIGH-RELIABILITY QR SETUP -->
                <div class="qr-card">
                    <!-- Client-Side & Image Dual Renderer -->
                    <div style="background:#fff; display:inline-block; padding:6px; border-radius:8px; box-shadow:0 1px 4px rgba(0,0,0,0.08); border:1px solid #cbd5e1;">
                        <div id="qrcode-canvas"></div>
                        <img id="qrcode-img" src="<?php echo $qrUrl; ?>" onerror="this.src='<?php echo $qrFallbackUrl; ?>';" alt="Google Authenticator QR Code" style="width:130px; height:130px; display:none;">
                    </div>

                    <div style="margin-top:0.4rem;">
                        <a href="<?php echo $otpAuthUrl; ?>" class="btn-login" style="background:#0284c7; padding:0.35rem 0.6rem; font-size:0.6875rem; text-transform:none;">
                            <i class="fas fa-mobile-alt"></i> Open Authenticator App
                        </a>
                    </div>

                    <div style="font-size:0.625rem; color:#64748b; margin-top:0.3rem;">
                        Manual Key: <span class="secret-box"><?php echo implode('-', str_split($stagedSecret, 4)); ?></span>
                    </div>
                </div>
            <?php else: ?>
                <!-- RETURNING USER 2FA VERIFICATION -->
                <div style="text-align:center; margin-bottom:0.65rem;">
                    <p style="font-size:0.725rem; color:#475569; font-weight:500;">
                        Enter 6-digit Google Authenticator code:
                    </p>
                </div>
            <?php endif; ?>

            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="verify_2fa">
                
                <div class="form-group" style="margin-bottom:0.6rem;">
                    <input type="text" name="totp_code" class="totp-input" placeholder="000000" maxlength="6" pattern="[0-9]*" inputmode="numeric" required autofocus autocomplete="one-time-code">
                </div>
                
                <button type="submit" class="btn-login">
                    <i class="fas fa-check-circle"></i> Verify & Sign In
                </button>
            </form>

            <a href="login?cancel_2fa=1" class="btn-secondary-link">
                <i class="fas fa-arrow-left"></i> Sign in as different user
            </a>
        <?php endif; ?>

        <div class="footer-info">
            Designed & Developed by NIS Web Team
        </div>
    </div>

    <script>
        // Password visibility toggle
        const togglePassword = document.getElementById('togglePassword');
        const passwordInput = document.getElementById('password');
        if (togglePassword && passwordInput) {
            togglePassword.addEventListener('click', function() {
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                const icon = this.querySelector('i');
                if (icon) icon.className = type === 'password' ? 'fas fa-eye' : 'fas fa-eye-slash';
            });
        }

        // High-reliability QR Code Generator
        <?php if ($step === 2 && $isNew2faSetup && !empty($otpAuthUrl)): ?>
        document.addEventListener('DOMContentLoaded', function() {
            var qrContainer = document.getElementById('qrcode-canvas');
            var qrImg = document.getElementById('qrcode-img');
            
            if (typeof QRCode !== 'undefined' && qrContainer) {
                try {
                    new QRCode(qrContainer, {
                        text: <?php echo json_encode($otpAuthUrl); ?>,
                        width: 130,
                        height: 130,
                        colorDark: "#000000",
                        colorLight: "#ffffff",
                        correctLevel: QRCode.CorrectLevel.M
                    });
                } catch(e) {
                    if (qrImg) qrImg.style.display = 'block';
                }
            } else {
                if (qrImg) qrImg.style.display = 'block';
            }
        });
        <?php endif; ?>

        // HTML5 Geolocation capture for Geofencing verification
        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(function(pos) {
                var latIn = document.getElementById('userLatInput');
                var lngIn = document.getElementById('userLngInput');
                if (latIn) latIn.value = pos.coords.latitude;
                if (lngIn) lngIn.value = pos.coords.longitude;
            }, function(err) {
                console.warn('Geolocation capture:', err.message);
            }, { enableHighAccuracy: true, timeout: 6000, maximumAge: 0 });
        }
    </script>
    <script src="assets/js/disable-right-click.js"></script>
</body>
</html>