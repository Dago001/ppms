<?php
require_once 'includes/auth.php';
require_once 'includes/permissions.php';

if (!isLoggedIn()) {
    header('Location: login');
    exit();
}

$success = $error = '';
$csrfToken = generateCSRFToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !validateCSRFToken($_POST['csrf_token'] ?? '')) {
    $error = "Security validation failed. Please refresh the page and try again.";
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = sanitizeInput($_POST['full_name']);
    $email = sanitizeInput($_POST['email']);

    try {
        $pdo->beginTransaction();

        // First check if email column exists
        $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'email'");
        $emailColumnExists = $stmt->rowCount() > 0;

        // Update user profile
        if ($emailColumnExists) {
            $sql = "UPDATE users SET full_name = ?, email = ?";
            $params = [$full_name, $email];
        } else {
            $sql = "UPDATE users SET full_name = ?";
            $params = [$full_name];
        }

        if (isset($photo_path)) {
            $sql .= ", photo_path = ?";
            $params[] = $photo_path;
        }

        $sql .= " WHERE id = ?";
        $params[] = $_SESSION['user_id'];

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $pdo->commit();
        $success = "Profile updated successfully";
        
        // Log activity
        $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, description) VALUES (?, ?, ?)");
        $stmt->execute([
            $_SESSION['user_id'],
            'Profile Update',
            'Updated profile information'
        ]);

    } catch (Exception $e) {
        $pdo->rollBack();
        $error = $e->getMessage();
    }
}

// Get current user data
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
} catch (PDOException $e) {
    $error = "Error fetching user data: " . $e->getMessage();
}
?>

<?php include 'includes/header.php'; ?>
<link rel="stylesheet" href="assets/css/profile-style.css">

<div class="container mt-4">
    <div class="row justify-content-center">
        <div class="col-lg-8 col-xl-6">
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle me-2"></i>
                    <?php echo $success; ?>
                </div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <div class="card profile-card">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-user-edit me-2"></i>
                        Edit Profile
                    </h5>
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <div class="text-center mb-4">
                            <div class="profile-photo-container">
                                <?php if (isset($user['photo_path']) && !empty($user['photo_path'])): ?>
                                    <img src="<?php echo htmlspecialchars($user['photo_path']); ?>" 
                                         alt="Profile" class="rounded-circle profile-photo">
                                <?php else: ?>
                                    <div class="rounded-circle bg-secondary text-white d-flex align-items-center justify-content-center profile-photo">
                                        <?php echo strtoupper(substr($user['username'], 0, 1)); ?>
                                    </div>
                                <?php endif; ?>
                                <label for="photo" class="photo-upload-label">
                                    <i class="fas fa-camera me-1"></i>
                                    Change Photo
                                </label>
                                <input type="file" class="d-none" id="photo" name="photo" accept="image/*">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="username" class="form-label">Username</label>
                            <input type="text" class="form-control form-control-sm" id="username" 
                                   value="<?php echo htmlspecialchars($user['username']); ?>" readonly>
                        </div>

                        <div class="mb-2">
                            <label for="full_name" class="form-label">Full Name</label>
                            <input type="text" class="form-control form-control-sm" id="full_name" name="full_name" 
                                   value="<?php echo isset($user['full_name']) ? htmlspecialchars($user['full_name']) : ''; ?>" required>
                        </div>

                        <div class="mb-2">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" class="form-control form-control-sm" id="email" name="email" 
                                   value="<?php echo isset($user['email']) ? htmlspecialchars($user['email']) : ''; ?>"
                                   <?php if (!filter_var($user['email'], FILTER_VALIDATE_EMAIL)): ?>style="border-color: red;"<?php endif; ?>>
                        </div>

                        <div class="password-section">
                            <h6 class="mb-3">
                                <i class="fas fa-lock me-2"></i>
                                Change Password
                            </h6>
                            <div class="mb-3">
                                <label for="current_password" class="form-label">Current Password</label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="current_password" name="current_password">
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('current_password')">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="new_password" class="form-label">New Password</label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="new_password" name="new_password">
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('new_password')">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                <div class="form-text text-muted">
                                    <i class="fas fa-info-circle me-1"></i>
                                    Leave blank if you don't want to change password
                                </div>
                            </div>
                        </div>

                        <div class="btn-action-group mt-4">
                            <a href="dashboard" class="btn btn-secondary">
                                <i class="fas fa-times me-2"></i>Cancel
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>Save Changes
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function togglePassword(fieldId) {
    const field = document.getElementById(fieldId);
    const button = field.nextElementSibling.querySelector('i');
    
    if (field.type === 'password') {
        field.type = 'text';
        button.classList.remove('fa-eye');
        button.classList.add('fa-eye-slash');
    } else {
        field.type = 'password';
        button.classList.remove('fa-eye-slash');
        button.classList.add('fa-eye');
    }
}

// Preview image before upload
document.getElementById('photo').addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
            const container = document.querySelector('.profile-photo-container');
            const oldImage = container.querySelector('img, .rounded-circle.bg-secondary');
            if (oldImage) {
                oldImage.remove();
            }
            const newImage = document.createElement('img');
            newImage.src = e.target.result;
            newImage.classList.add('rounded-circle', 'profile-photo');
            newImage.alt = 'Profile';
            container.insertBefore(newImage, container.firstChild);
        }
        reader.readAsDataURL(file);
    }
});
</script>

<?php include 'includes/footer.php'; ?>
