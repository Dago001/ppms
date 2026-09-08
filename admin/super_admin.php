<?php
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_once '../includes/security.php';

// Only super admin can access this page
if (!isLoggedIn() || !isSuperAdmin()) {
    header('Location: ../dashboard');
    exit();
}

$action = isset($_GET['action']) ? $_GET['action'] : 'list';
$error = $success = '';
$csrfToken = generateCSRFToken();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = "Security validation failed. Please refresh the page and try again.";
    } elseif (isset($_POST['update_role'])) {
        $user_id = (int)$_POST['user_id'];
        $new_role = (int)$_POST['role_id'];

        try {
            $stmt = $pdo->prepare("UPDATE users SET role_id = ? WHERE id = ?");
            $stmt->execute([$new_role, $user_id]);
            $success = "User role updated successfully";
        } catch (PDOException $e) {
            $error = "Error updating user role: " . $e->getMessage();
        }
    } elseif (isset($_POST['delete_user'])) {
        $user_id = (int)$_POST['user_id'];

        // Never allow deleting the last admin account, regardless of role naming.
        $adminCount = (int)$pdo->query("SELECT COUNT(*) FROM users u JOIN roles r ON u.role_id = r.id WHERE LOWER(r.name) = 'admin'")->fetchColumn();
        $targetRole = $pdo->prepare("SELECT LOWER(r.name) FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
        $targetRole->execute([$user_id]);
        $targetRoleName = $targetRole->fetchColumn();

        if ($targetRoleName === 'admin' && $adminCount <= 1) {
            $error = "Cannot delete the last remaining Admin account.";
        } else {
            try {
                $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$user_id]);
                $success = "User deleted successfully";
            } catch (PDOException $e) {
                $error = "Error deleting user: " . $e->getMessage();
            }
        }
    }
}

// Get all users with their roles
$users = [];
try {
    $stmt = $pdo->query("
        SELECT u.*, r.name as role_name 
        FROM users u 
        JOIN roles r ON u.role_id = r.id 
        ORDER BY u.username
    ");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error fetching users: " . $e->getMessage();
}

// Get all roles
$roles = [];
try {
    $stmt = $pdo->query("SELECT * FROM roles ORDER BY name");
    $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error fetching roles: " . $e->getMessage();
}

include '../includes/header.php';
?>

<div class="container mt-4">
    <h2>Super Administrator Dashboard</h2>
    
    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo $error; ?></div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo $success; ?></div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-header">
            <h3 class="card-title">User Management</h3>
        </div>
        <div class="card-body">
            <table class="table">
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Full Name</th>
                        <th>Email</th>
                        <th>Current Role</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($user['username']); ?></td>
                        <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                        <td><?php echo htmlspecialchars($user['email']); ?></td>
                        <td><?php echo htmlspecialchars($user['role_name']); ?></td>
                        <td>
                            <form method="post" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                <select name="role_id" class="form-control form-control-sm d-inline-block w-auto">
                                    <?php foreach ($roles as $role): ?>
                                        <option value="<?php echo $role['id']; ?>" <?php echo $user['role_id'] == $role['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($role['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" name="update_role" class="btn btn-primary btn-sm">Update Role</button>
                            </form>

                            <?php if (strtolower($user['role_name']) !== 'admin'): ?>
                            <form method="post" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this user?');">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                <button type="submit" name="delete_user" class="btn btn-danger btn-sm">Delete</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
