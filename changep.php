<?php
session_start();
require_once 'includes/config.php';
require_once 'includes/auth.php';

if (!isLoggedIn()) {
    header('Location: login');
    exit();
}

$error = '';
$success = '';
$csrfToken = generateCSRFToken();
$isForcedChange = !empty($_SESSION['must_change_password']);

// Get user info
$user = [];
try {
    $stmt = $pdo->prepare("SELECT u.*, r.name as role_name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
} catch (Exception $e) {
    $user = ['username' => $_SESSION['username'] ?? 'User', 'full_name' => 'User', 'role_name' => 'User'];
}

if (empty($user)) {
    $user = ['username' => $_SESSION['username'] ?? 'User', 'full_name' => $_SESSION['username'] ?? 'User', 'role_name' => 'User'];
}

$totalPersonnel = 0;
$totalUsers = 0;
try {
    $totalPersonnel = $pdo->query("SELECT COUNT(*) as count FROM tbl_emppersonal")->fetch()['count'] ?? 0;
    $totalUsers = $pdo->query("SELECT COUNT(*) as count FROM users")->fetch()['count'] ?? 0;
} catch (Exception $e) {}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !validateCSRFToken($_POST['csrf_token'] ?? '')) {
    $error = "Security validation failed. Please refresh the page and try again.";
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $error = "All fields are required.";
    } elseif ($new_password !== $confirm_password) {
        $error = "New passwords do not match.";
    } elseif (!($pwdCheck = validatePasswordStrength($new_password))['valid']) {
        $error = $pwdCheck['message'];
    } else {
        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $userData = $stmt->fetch();
        
        if ($userData && password_verify($current_password, $userData['password'])) {
            $hashed = password_hash($new_password, PASSWORD_DEFAULT);
            try {
                $stmt = $pdo->prepare("UPDATE users SET password = ?, must_change_password = 0 WHERE id = ?");
                $stmt->execute([$hashed, $_SESSION['user_id']]);
            } catch (Exception $exCol) {
                $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->execute([$hashed, $_SESSION['user_id']]);
            }
            $_SESSION['must_change_password'] = 0;
            $success = "Password updated successfully.";
        } else {
            $error = "Current password is incorrect.";
        }
    }
}
?>

<?php include 'includes/header.php'; ?>

<style>
    .page-title {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 0.85rem 1.25rem;
        margin-bottom: 1.25rem;
    }
    .page-title h1 {
        font-size: 1.15rem;
        font-weight: 700;
        color: #1e293b;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    .page-title h1 i { color: #1a5632; }

    .card {
        background: #ffffff;
        border-radius: 12px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        border: 1px solid #e2e8f0;
        max-width: 480px;
    }
    .card-header {
        padding: 0.85rem 1.25rem;
        font-weight: 600;
        font-size: 0.85rem;
        border-bottom: 1px solid #e2e8f0;
        color: #1a5632;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    .card-body { padding: 1.25rem; }

    .form-group { margin-bottom: 1rem; }
    .form-group label {
        display: block;
        font-weight: 600;
        color: #475569;
        margin-bottom: 0.35rem;
        font-size: 0.8rem;
    }
    
    .input-wrapper {
        position: relative;
    }
    .form-control {
        width: 100%;
        padding: 0.5rem 2.2rem 0.5rem 0.75rem;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        font-size: 0.85rem;
        outline: none;
        transition: border-color 0.15s ease;
    }
    .form-control:focus {
        border-color: #1a5632;
        box-shadow: 0 0 0 2px rgba(26, 86, 50, 0.1);
    }
    .toggle-pwd {
        position: absolute;
        right: 0.75rem;
        top: 50%;
        transform: translateY(-50%);
        color: #94a3b8;
        cursor: pointer;
        font-size: 0.8rem;
    }

    .btn-submit {
        padding: 0.5rem 1.25rem;
        border-radius: 6px;
        font-size: 0.825rem;
        font-weight: 600;
        cursor: pointer;
        border: none;
        background: #1a5632;
        color: #ffffff;
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        margin-top: 0.5rem;
    }
    .btn-submit:hover { background: #154628; }

    .alert {
        padding: 0.65rem 0.85rem;
        border-radius: 6px;
        margin-bottom: 1rem;
        font-size: 0.8rem;
        display: flex;
        align-items: center;
        gap: 0.4rem;
    }
    .alert-success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
    .alert-error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
</style>

<div class="page-title">
    <h1><i class="fas fa-key"></i> Security Settings</h1>
</div>

<div class="card">
    <div class="card-header">
        <i class="fas fa-lock"></i> Change Account Password
    </div>
    <div class="card-body">
        <?php if ($isForcedChange && !$success): ?>
            <div class="alert alert-error">
                <i class="fas fa-shield-alt"></i> You are using a temporary or default password. You must set a new password before you can continue.
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <div class="form-group">
                <label>Current Password</label>
                <div class="input-wrapper">
                    <input type="password" name="current_password" id="currPwd" class="form-control" required autocomplete="current-password">
                    <i class="fas fa-eye toggle-pwd" onclick="toggleVisibility('currPwd', this)"></i>
                </div>
            </div>

            <div class="form-group">
                <label>New Password (min. 8 characters)</label>
                <div class="input-wrapper">
                    <input type="password" name="new_password" id="newPwd" class="form-control" required minlength="8" pattern="(?=.*\d)(?=.*[a-z])(?=.*[A-Z])(?=.*[\W_]).{8,}" title="Password must be at least 8 characters and contain 1 uppercase letter, 1 lowercase letter, 1 number, and 1 special character" autocomplete="new-password">
                    <i class="fas fa-eye toggle-pwd" onclick="toggleVisibility('newPwd', this)"></i>
                </div>
                <small style="color: #64748b; font-size: 0.7rem; display: block; margin-top: 4px;">Must contain at least 1 uppercase letter (A-Z), 1 lowercase letter (a-z), 1 number (0-9), and 1 special character (@, #, $, %, !).</small>
            </div>

            <div class="form-group">
                <label>Confirm New Password</label>
                <div class="input-wrapper">
                    <input type="password" name="confirm_password" id="confPwd" class="form-control" required minlength="8" pattern="(?=.*\d)(?=.*[a-z])(?=.*[A-Z])(?=.*[\W_]).{8,}" title="Password must be at least 8 characters and contain 1 uppercase letter, 1 lowercase letter, 1 number, and 1 special character" autocomplete="new-password">
                    <i class="fas fa-eye toggle-pwd" onclick="toggleVisibility('confPwd', this)"></i>
                </div>
            </div>

            <button type="submit" class="btn-submit">
                <i class="fas fa-save"></i> Save Password
            </button>
        </form>
    </div>
</div>

</div><!-- close main-content -->
</div><!-- close content-wrapper -->
</div><!-- close dashboard-container -->

<script>
function toggleVisibility(id, icon) {
    const field = document.getElementById(id);
    if (field.type === 'password') {
        field.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    } else {
        field.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    }
}
</script>

<?php include 'includes/footer.php'; ?>

</body>
</html>