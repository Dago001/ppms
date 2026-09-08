<?php
session_start();
require_once 'includes/config.php';
require_once 'includes/auth.php';
require_once 'includes/permissions.php';
require_once 'includes/GoogleAuthenticator.php';

if (!isLoggedIn()) {
    header('Location: login');
    exit();
}

$userId = $_SESSION['user_id'];
$successMsg = '';
$errorMsg = '';
$csrfToken = generateCSRFToken();

// Fetch fresh user data
try {
    $stmt = $pdo->prepare("
        SELECT u.*, r.name as role_name 
        FROM users u 
        LEFT JOIN roles r ON u.role_id = r.id 
        WHERE u.id = ?
    ");
    $stmt->execute([$userId]);
    $userData = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $errorMsg = "Database Error: " . $e->getMessage();
}

if (!$userData) {
    header('Location: login');
    exit();
}

// -------------------------------------------------------------
// POST HANDLERS: UPDATE INFO, UPLOAD PHOTO, 2FA MANAGEMENT
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !validateCSRFToken($_POST['csrf_token'] ?? '')) {
    $errorMsg = "Security validation failed. Please refresh the page and try again.";
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 1. UPDATE PERSONAL INFORMATION (Full Name & Phone Number)
    if (isset($_POST['action']) && $_POST['action'] === 'update_info') {
        $fullName = sanitizeInput($_POST['full_name'] ?? '');
        $phone    = sanitizeInput($_POST['phone'] ?? '');

        if (empty($fullName) || empty($phone)) {
            $errorMsg = "Full Name and Phone Number are required fields.";
        } else {
            try {
                $updateStmt = $pdo->prepare("UPDATE users SET full_name = ?, phone = ? WHERE id = ?");
                $updateStmt->execute([$fullName, $phone, $userId]);

                $_SESSION['full_name'] = $fullName;
                $userData['full_name'] = $fullName;
                $userData['phone'] = $phone;

                if (function_exists('logActivity')) {
                    logActivity($pdo, $userId, 'Profile Update', 'Updated personal information & phone number');
                }

                $successMsg = "Profile information updated successfully!";
            } catch (Exception $e) {
                $errorMsg = "Error updating profile: " . $e->getMessage();
            }
        }
    }

    // 2. UPLOAD PROFILE PHOTO
    elseif (isset($_POST['action']) && $_POST['action'] === 'upload_photo') {
        if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
            $fileTmp  = $_FILES['profile_photo']['tmp_name'];
            $fileName = $_FILES['profile_photo']['name'];
            $fileSize = $_FILES['profile_photo']['size'];

            $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];

            if (!in_array($fileExt, $allowedExts)) {
                $errorMsg = "Invalid file format. Allowed formats: JPG, PNG, WEBP.";
            } elseif ($fileSize > 5 * 1024 * 1024) { // 5MB limit
                $errorMsg = "File size too large. Maximum allowed size is 5MB.";
            } else {
                $targetDir = __DIR__ . '/uploads/profile_photos/';
                if (!file_exists($targetDir)) {
                    mkdir($targetDir, 0755, true);
                }

                // Delete old profile photo safely
                if (!empty($userData['profile_photo'])) {
                    $oldPath = $targetDir . $userData['profile_photo'];
                    if (is_file($oldPath)) {
                        @unlink($oldPath);
                    }
                }

                $newFileName = 'profile_' . $userId . '_' . uniqid() . '.' . $fileExt;
                $targetPath = $targetDir . $newFileName;

                if (move_uploaded_file($fileTmp, $targetPath)) {
                    $pdo->prepare("UPDATE users SET profile_photo = ? WHERE id = ?")->execute([$newFileName, $userId]);
                    $userData['profile_photo'] = $newFileName;

                    if (function_exists('logActivity')) {
                        logActivity($pdo, $userId, 'Photo Upload', 'Uploaded new profile photo');
                    }

                    $successMsg = "Profile photo updated successfully!";
                } else {
                    $errorMsg = "Failed to save uploaded photo to server storage.";
                }
            }
        } else {
            $errorMsg = "Please select a valid image file to upload.";
        }
    }

    // 3. REMOVE PROFILE PHOTO
    elseif (isset($_POST['action']) && $_POST['action'] === 'remove_photo') {
        if (!empty($userData['profile_photo'])) {
            $oldPath = __DIR__ . '/uploads/profile_photos/' . $userData['profile_photo'];
            if (is_file($oldPath)) {
                @unlink($oldPath);
            }
            $pdo->prepare("UPDATE users SET profile_photo = NULL WHERE id = ?")->execute([$userId]);
            $userData['profile_photo'] = null;
            $successMsg = "Profile photo removed successfully.";
        }
    }

    // 4. VERIFY & ACTIVATE GOOGLE 2FA
    elseif (isset($_POST['action']) && $_POST['action'] === 'enable_2fa') {
        $totpCode = str_replace(' ', '', trim($_POST['totp_code'] ?? ''));
        $secretToEnable = $_SESSION['staged_2fa_secret'] ?? '';

        if (empty($secretToEnable)) {
            $errorMsg = "2FA setup session expired. Please click 'Setup 2FA' again.";
        } elseif (GoogleAuthenticator::verifyCode($secretToEnable, $totpCode)) {
            $pdo->prepare("UPDATE users SET google_2fa_secret = ? WHERE id = ?")->execute([$secretToEnable, $userId]);
            $userData['google_2fa_secret'] = $secretToEnable;
            unset($_SESSION['staged_2fa_secret']);

            if (function_exists('logActivity')) {
                logActivity($pdo, $userId, '2FA Enabled', 'Activated Google Authenticator Two-Factor Authentication');
            }

            $successMsg = "Google Authenticator 2FA has been successfully activated for your account!";
        } else {
            $errorMsg = "Invalid 6-digit verification code. Please check your Google Authenticator app and try again.";
        }
    }

    // 5. DISABLE GOOGLE 2FA
    elseif (isset($_POST['action']) && $_POST['action'] === 'disable_2fa') {
        $confirmPassword = $_POST['confirm_password'] ?? '';
        if (password_verify($confirmPassword, $userData['password'])) {
            $pdo->prepare("UPDATE users SET google_2fa_secret = NULL WHERE id = ?")->execute([$userId]);
            $userData['google_2fa_secret'] = null;

            if (function_exists('logActivity')) {
                logActivity($pdo, $userId, '2FA Disabled', 'Deactivated Google Authenticator 2FA');
            }

            $successMsg = "Google Authenticator 2FA has been disabled for your account.";
        } else {
            $errorMsg = "Incorrect account password. 2FA deactivation cancelled.";
        }
    }
}

// Generate Staged 2FA Secret for Setup Modal if not already active
$is2FAActive = !empty($userData['google_2fa_secret']);
$stagedSecret = '';
$qrUrl = '';
$otpAuthUrl = '';

if (!$is2FAActive) {
    if (empty($_SESSION['staged_2fa_secret'])) {
        $_SESSION['staged_2fa_secret'] = GoogleAuthenticator::createSecret();
    }
    $stagedSecret = $_SESSION['staged_2fa_secret'];
    $qrUrl = GoogleAuthenticator::getQrUrl($userData['username'], $stagedSecret);
    $otpAuthUrl = GoogleAuthenticator::getOtpAuthUrl($userData['username'], $stagedSecret);
}

$pageTitle = 'My Profile & 2FA Security - NIS-PPMS';
include 'includes/header.php';
?>

<!-- QRCode JS Generator for 100% Offline/Client-side QR rendering -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>

<style>
    .profile-header-card {
        background: #ffffff;
        border-radius: 12px;
        padding: 1.25rem 1.5rem;
        color: #1e293b;
        margin-bottom: 1.25rem;
        border: 1px solid #e2e8f0;
        box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        display: flex;
        align-items: center;
        gap: 1.5rem;
        flex-wrap: wrap;
        position: relative;
    }
    
    .profile-avatar-container {
        position: relative;
        width: 100px;
        height: 100px;
        flex-shrink: 0;
    }

    .profile-avatar-img {
        width: 100px;
        height: 100px;
        border-radius: 50%;
        object-fit: cover;
        border: 3px solid #cbd5e1;
        box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        background: #ffffff;
    }

    .avatar-upload-btn {
        position: absolute;
        bottom: 0;
        right: 0;
        background: #0284c7;
        color: #ffffff;
        width: 32px;
        height: 32px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        border: 2px solid #ffffff;
        box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        transition: transform 0.15s ease;
    }
    .avatar-upload-btn:hover { transform: scale(1.1); background: #0369a1; }

    .profile-meta h2 { font-size: 1.35rem; font-weight: 700; margin: 0 0 0.25rem 0; color: #1a5632; }
    .profile-meta p { font-size: 0.8rem; color: #64748b; margin: 0 0 0.5rem 0; font-weight: 500; }
    
    .status-badge-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.2rem 0.65rem;
        border-radius: 20px;
        font-size: 0.725rem;
        font-weight: 600;
        background: #f8fafc;
        border: 1px solid #cbd5e1;
        color: #334155;
    }

    .grid-layout {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1.25rem;
        margin-bottom: 1.5rem;
    }

    @media (max-width: 868px) {
        .grid-layout { grid-template-columns: 1fr; }
        .profile-header-card { flex-direction: column; text-align: center; justify-content: center; }
        .profile-avatar-container { margin: 0 auto; }
    }

    .card-panel {
        background: #ffffff;
        border-radius: 12px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        margin-bottom: 1.25rem;
    }
    .card-panel-header {
        padding: 0.85rem 1.25rem;
        border-bottom: 1px solid #e2e8f0;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .card-panel-header h3 { font-size: 0.9rem; font-weight: 600; color: #1a5632; margin: 0; display: flex; align-items: center; gap: 0.5rem; }
    .card-panel-body { padding: 1.25rem; }

    .info-list-group { display: flex; flex-direction: column; gap: 0.75rem; }
    .info-item { display: flex; justify-content: space-between; align-items: center; padding-bottom: 0.6rem; border-bottom: 1px solid #f1f5f9; font-size: 0.825rem; }
    .info-item .label { color: #64748b; font-weight: 500; }
    .info-item .value { color: #1e293b; font-weight: 600; }

    .form-group { margin-bottom: 1rem; }
    .form-label { display: block; font-size: 0.8rem; font-weight: 500; color: #475569; margin-bottom: 0.35rem; }
    .form-control {
        width: 100%;
        padding: 0.55rem 0.85rem;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        font-size: 0.85rem;
        font-weight: 500;
        outline: none;
        background: #ffffff;
    }
    .form-control:focus { border-color: #1a5632; box-shadow: 0 0 0 3px rgba(26,86,50,0.1); }

    .btn-primary-action {
        background: #1a5632;
        color: #ffffff;
        border: none;
        padding: 0.6rem 1.15rem;
        border-radius: 8px;
        font-size: 0.8rem;
        font-weight: 600;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        text-decoration: none;
        transition: background 0.15s ease;
    }
    .btn-primary-action:hover { background: #154628; }

    .btn-sky-action {
        background: #0284c7;
        color: #ffffff;
        border: none;
        padding: 0.6rem 1.15rem;
        border-radius: 8px;
        font-size: 0.8rem;
        font-weight: 600;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        text-decoration: none;
    }
    .btn-sky-action:hover { background: #0369a1; }

    .btn-danger-action {
        background: #dc2626;
        color: #ffffff;
        border: none;
        padding: 0.5rem 0.95rem;
        border-radius: 8px;
        font-size: 0.775rem;
        font-weight: 600;
        cursor: pointer;
    }
    .btn-danger-action:hover { background: #b91c1c; }

    .alert-banner {
        padding: 0.75rem 1rem;
        border-radius: 8px;
        margin-bottom: 1.25rem;
        font-size: 0.8rem;
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    .alert-success-style { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
    .alert-danger-style { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

    .modal { display: none; position: fixed; z-index: 9999; left:0; top:0; width:100%; height:100%; background: rgba(0,0,0,0.6); overflow-y:auto; }
    .modal-content { position: relative; background: #ffffff; margin:4% auto; width:90%; max-width:440px; border-radius:14px; box-shadow:0 15px 35px rgba(0,0,0,0.25); overflow:hidden; }
    .modal-header { padding:0.85rem 1.25rem; background:#1a5632; color:#ffffff; display:flex; justify-content:space-between; align-items:center; }
    .modal-header h3 { color:#ffffff !important; font-size:0.95rem; font-weight:600; margin:0; }
    .close-modal { background:none; border:none; color:#ffffff; font-size:1.4rem; cursor:pointer; }
    .modal-body { padding:1.25rem; }
</style>

<!-- PAGE TITLE -->
<div class="page-title">
    <h1><i class="fas fa-user-circle"></i> My Account Profile & 2FA Security</h1>
    <div style="font-size:0.75rem; color:#64748b; font-weight:500;">
        <i class="fas fa-shield-alt" style="color:#1a5632;"></i> Verified Officer Identity
    </div>
</div>

<?php if (!empty($successMsg)): ?>
    <div class="alert-banner alert-success-style">
        <i class="fas fa-check-circle" style="font-size:1rem;"></i>
        <div><?php echo $successMsg; ?></div>
    </div>
<?php endif; ?>

<?php if (!empty($errorMsg)): ?>
    <div class="alert-banner alert-danger-style">
        <i class="fas fa-exclamation-circle" style="font-size:1rem;"></i>
        <div><?php echo $errorMsg; ?></div>
    </div>
<?php endif; ?>

<!-- PROFILE HEADER CARD -->
<div class="profile-header-card">
    <div class="profile-avatar-container">
        <?php if (!empty($userData['profile_photo']) && file_exists(__DIR__ . '/uploads/profile_photos/' . $userData['profile_photo'])): ?>
            <img src="uploads/profile_photos/<?php echo htmlspecialchars($userData['profile_photo']); ?>" alt="Profile Photo" class="profile-avatar-img">
        <?php else: ?>
            <div class="profile-avatar-img" style="display:flex; align-items:center; justify-content:center; background:#1a5632; color:#ffffff; font-size:2.5rem; font-weight:700;">
                <?php echo strtoupper(substr($userData['full_name'] ?? $userData['username'], 0, 1)); ?>
            </div>
        <?php endif; ?>

        <label for="photoUploadInput" class="avatar-upload-btn" title="Upload / Change Photo">
            <i class="fas fa-camera" style="font-size:0.85rem;"></i>
        </label>

        <!-- Hidden Photo Upload Form -->
        <form id="photoUploadForm" method="POST" enctype="multipart/form-data" style="display:none;">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <input type="hidden" name="action" value="upload_photo">
            <input type="file" id="photoUploadInput" name="profile_photo" accept="image/jpeg,image/png,image/webp" onchange="document.getElementById('photoUploadForm').submit();">
        </form>
    </div>

    <div class="profile-meta">
        <h2><?php echo htmlspecialchars($userData['full_name']); ?></h2>
        <p><i class="fas fa-id-badge"></i> Username / Service No: <strong>@<?php echo htmlspecialchars($userData['username']); ?></strong> &bull; Role: <strong><?php echo htmlspecialchars($userData['role_name']); ?></strong></p>
        
        <div style="display:flex; gap:0.5rem; flex-wrap:wrap;">
            <span class="status-badge-pill">
                <i class="fas fa-circle" style="color:#2ecc71; font-size:0.5rem;"></i> Status: <?php echo ucfirst($userData['status']); ?>
            </span>
            
            <span class="status-badge-pill">
                <i class="fas fa-shield-alt" style="color:<?php echo $is2FAActive ? '#2ecc71' : '#f59e0b'; ?>;"></i> 
                2FA: <?php echo $is2FAActive ? 'Google Authenticator Active' : 'Not Enrolled'; ?>
            </span>
        </div>
    </div>
</div>

<!-- TWO COLUMN GRID LAYOUT -->
<div class="grid-layout">
    
    <!-- LEFT COLUMN: ACCOUNT DETAILS & PHOTO MANAGEMENT -->
    <div>
        <div class="card-panel">
            <div class="card-panel-header">
                <h3><i class="fas fa-id-card"></i> Personal & System Details</h3>
            </div>
            <div class="card-panel-body">
                <div class="info-list-group">
                    <div class="info-item">
                        <span class="label">Full Officer Name</span>
                        <span class="value"><?php echo htmlspecialchars($userData['full_name']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="label">Username (Service No)</span>
                        <span class="value"><code>@<?php echo htmlspecialchars($userData['username']); ?></code></span>
                    </div>
                    <div class="info-item">
                        <span class="label">Phone Number</span>
                        <span class="value"><?php echo htmlspecialchars(!empty($userData['phone']) ? $userData['phone'] : 'Not Provided'); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="label">Assigned Role</span>
                        <span class="value"><?php echo htmlspecialchars($userData['role_name']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="label">Account Registered</span>
                        <span class="value"><?php echo date('d M Y, H:i', strtotime($userData['created_at'])); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="label">Last Active Session</span>
                        <span class="value"><?php echo !empty($userData['last_login']) ? date('d M Y, H:i', strtotime($userData['last_login'])) : 'Current Session'; ?></span>
                    </div>
                </div>

                <?php if (!empty($userData['profile_photo'])): ?>
                    <form method="POST" style="margin-top:1.15rem; text-align:right;">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="action" value="remove_photo">
                        <button type="submit" class="btn-danger-action" onclick="return confirm('Are you sure you want to remove your profile photo?');">
                            <i class="fas fa-trash-alt"></i> Remove Profile Photo
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- EDIT INFORMATION FORM -->
        <div class="card-panel">
            <div class="card-panel-header">
                <h3><i class="fas fa-user-edit"></i> Edit Officer Profile</h3>
            </div>
            <div class="card-panel-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" value="update_info">
                    
                    <div class="form-group">
                        <label class="form-label">Full Name *</label>
                        <input type="text" name="full_name" class="form-control" value="<?php echo htmlspecialchars($userData['full_name']); ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Phone Number *</label>
                        <input type="text" name="phone" class="form-control" value="<?php echo htmlspecialchars($userData['phone'] ?? ''); ?>" required placeholder="e.g. 08012345678">
                    </div>

                    <button type="submit" class="btn-primary-action">
                        <i class="fas fa-save"></i> Save Profile Changes
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- RIGHT COLUMN: 2FA GOOGLE AUTHENTICATOR & SECURITY -->
    <div>
        <div class="card-panel">
            <div class="card-panel-header">
                <h3><i class="fas fa-shield-alt"></i> Two-Factor Security (Google Authenticator)</h3>
            </div>
            <div class="card-panel-body">
                <div style="text-align:center; padding:0.5rem 0 1rem 0;">
                    <div style="width:60px; height:60px; border-radius:50%; background:<?php echo $is2FAActive ? '#f0fdf4' : '#fffbeb'; ?>; color:<?php echo $is2FAActive ? '#166534' : '#d97706'; ?>; display:flex; align-items:center; justify-content:center; font-size:1.8rem; margin:0 auto 0.75rem; border:1px solid <?php echo $is2FAActive ? '#bbf7d0' : '#fef3c7'; ?>;">
                        <i class="fas <?php echo $is2FAActive ? 'fa-user-shield' : 'fa-shield-halved'; ?>"></i>
                    </div>

                    <h4 style="font-size:1.05rem; font-weight:600; color:#1e293b; margin-bottom:0.25rem;">
                        Google 2FA Status: <span style="color:<?php echo $is2FAActive ? '#166534' : '#d97706'; ?>;"><?php echo $is2FAActive ? 'Active & Protected' : 'Not Configured'; ?></span>
                    </h4>

                    <p style="font-size:0.775rem; color:#64748b; line-height:1.4; max-width:340px; margin:0 auto 1.15rem auto;">
                        <?php if ($is2FAActive): ?>
                            Your account is secured with Google Authenticator time-based one-time passwords (TOTP).
                        <?php else: ?>
                            Protect your account against unauthorized access by pairing your smartphone with Google Authenticator.
                        <?php endif; ?>
                    </p>

                    <!-- 2FA BUTTON CONTROLS -->
                    <?php if ($is2FAActive): ?>
                        <button type="button" class="btn-danger-action" onclick="openModal('disable2FAModal')">
                            <i class="fas fa-lock-open"></i> Disable Google 2FA
                        </button>
                    <?php else: ?>
                        <button type="button" class="btn-sky-action" onclick="openModal('setup2FAModal')">
                            <i class="fas fa-qrcode"></i> Setup Google 2FA Now
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- QUICK SECURITY LINKS -->
        <div class="card-panel">
            <div class="card-panel-header">
                <h3><i class="fas fa-key"></i> Security Credentials</h3>
            </div>
            <div class="card-panel-body" style="display:flex; justify-content:space-between; align-items:center;">
                <div>
                    <strong style="font-size:0.85rem; color:#1e293b; display:block;">Account Password</strong>
                    <span style="font-size:0.75rem; color:#64748b;">Update your login password regularly for maximum safety.</span>
                </div>
                <a href="changep" class="btn-primary-action" style="font-size:0.75rem; padding:0.45rem 0.85rem;">
                    <i class="fas fa-key"></i> Change Password
                </a>
            </div>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- MODAL: GOOGLE 2FA SETUP -->
<!-- ============================================ -->
<?php if (!$is2FAActive): ?>
<div id="setup2FAModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-qrcode"></i> Pair Google Authenticator</h3>
            <button class="close-modal" onclick="closeModal('setup2FAModal')">&times;</button>
        </div>
        <div class="modal-body" style="text-align:center;">
            <div style="font-size:0.75rem; color:#334155; font-weight:600; margin-bottom:0.6rem;">
                Scan QR code with your Google Authenticator app:
            </div>

            <!-- Client-Side & Image Fallback QR Code -->
            <div style="background:#fff; display:inline-block; padding:6px; border-radius:10px; box-shadow:0 2px 8px rgba(0,0,0,0.06); border:1px solid #cbd5e1; margin-bottom:0.5rem;">
                <div id="modal-qrcode-canvas"></div>
                <img id="modal-qrcode-img" src="<?php echo $qrUrl; ?>" alt="QR Code" style="width:140px; height:140px; display:none; margin:0 auto;">
            </div>

            <div>
                <a href="<?php echo $otpAuthUrl; ?>" class="btn-sky-action" style="padding:0.4rem 0.75rem; font-size:0.7rem; margin-bottom:0.5rem;">
                    <i class="fas fa-mobile-alt"></i> Tap to Open Authenticator App
                </a>
            </div>

            <div style="font-size:0.675rem; color:#64748b; margin-bottom:1rem;">
                Manual Secret Key: <br>
                <code style="background:#f1f5f9; border:1px dashed #cbd5e1; padding:2px 6px; border-radius:4px; font-size:0.8rem; font-weight:600; color:#1a5632;"><?php echo implode('-', str_split($stagedSecret, 4)); ?></code>
            </div>

            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="action" value="enable_2fa">
                
                <div class="form-group" style="margin-bottom:0.85rem;">
                    <label class="form-label" style="font-weight:600;">Enter 6-Digit Code to Confirm:</label>
                    <input type="text" name="totp_code" class="form-control" placeholder="000000" maxlength="6" pattern="[0-9]*" inputmode="numeric" required style="text-align:center; letter-spacing:6px; font-size:1.2rem; font-weight:600; color:#1a5632;">
                </div>

                <button type="submit" class="btn-primary-action" style="width:100%; justify-content:center;">
                    <i class="fas fa-check-circle"></i> Verify & Activate 2FA
                </button>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var qrContainer = document.getElementById('modal-qrcode-canvas');
    var qrImg = document.getElementById('modal-qrcode-img');
    if (typeof QRCode !== 'undefined' && qrContainer) {
        try {
            new QRCode(qrContainer, {
                text: <?php echo json_encode($otpAuthUrl); ?>,
                width: 140,
                height: 140,
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
</script>
<?php endif; ?>

<!-- ============================================ -->
<!-- MODAL: DISABLE 2FA CONFIRMATION -->
<!-- ============================================ -->
<?php if ($is2FAActive): ?>
<div id="disable2FAModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Disable Google Authenticator</h3>
            <button class="close-modal" onclick="closeModal('disable2FAModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <input type="hidden" name="action" value="disable_2fa">
            <div class="modal-body">
                <p style="font-size:0.8rem; color:#dc2626; font-weight:500; margin-bottom:0.85rem;">
                    Warning: Deactivating Google 2FA will lower your account login security. Please enter your account password to confirm:
                </p>
                <div class="form-group">
                    <label class="form-label">Account Password *</label>
                    <input type="password" name="confirm_password" class="form-control" required placeholder="Enter password to confirm">
                </div>
                <div style="display:flex; justify-content:flex-end; gap:0.5rem;">
                    <button type="button" class="btn-reset" onclick="closeModal('disable2FAModal')">Cancel</button>
                    <button type="submit" class="btn-danger-action">Disable 2FA</button>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
function openModal(id) {
    document.getElementById(id).style.display = 'block';
}
function closeModal(id) {
    document.getElementById(id).style.display = 'none';
}
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
};
</script>

<?php include 'includes/footer.php'; ?>
