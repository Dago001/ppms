<?php
// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once 'includes/config.php';
require_once 'includes/auth.php';
require_once 'includes/permissions.php';

if (!isLoggedIn()) {
    header('Location: login');
    exit();
}

// Creating new personnel records requires the same posting-initiation
// privilege as postings - a plain User/Command User account should not
// be able to add records at all.
if (!canInitiatePosting()) {
    header('Location: dashboard');
    exit();
}

$error = '';
$success = '';
$csrfToken = generateCSRFToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !validateCSRFToken($_POST['csrf_token'] ?? '')) {
    $error = "Security validation failed. Please refresh the page and try again.";
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $service_number = sanitizeInput($_POST['service_number']);
        $rank = sanitizeInput($_POST['rank']);
        $first_name = sanitizeInput($_POST['first_name']);
        $last_name = sanitizeInput($_POST['last_name']);
        $gender = sanitizeInput($_POST['gender']);
        $date_of_birth = sanitizeInput($_POST['date_of_birth']);
        $state_of_origin = sanitizeInput($_POST['state_of_origin']);
        $lga = sanitizeInput($_POST['lga']);
        $phone_number = sanitizeInput($_POST['phone_number']);
        $email = sanitizeInput($_POST['email']);

        // Check if service number already exists
        $checkStmt = $pdo->prepare("SELECT id FROM tbl_emppersonal WHERE service_number = ?");
        $checkStmt->execute([$service_number]);
        
        if ($checkStmt->fetch()) {
            $error = "Service number already exists!";
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO tbl_emppersonal 
                (service_number, rank, first_name, last_name, gender, date_of_birth, 
                 state_of_origin, lga, phone_number, email, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            
            $stmt->execute([
                $service_number, $rank, $first_name, $last_name, $gender,
                $date_of_birth, $state_of_origin, $lga, $phone_number, $email
            ]);

            $success = "Personnel record added successfully!";
            
            // Clear form
            $_POST = array();
        }
        
    } catch (PDOException $e) {
        $error = "Error adding record: " . $e->getMessage();
    }
}
?>

<?php include 'includes/header.php'; ?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-md-3">
            <?php include 'includes/sidebar.php'; ?>
        </div>
        
        <div class="col-md-9">
            <div class="card">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0">Add New Personnel</h5>
                </div>
                <div class="card-body">
                    <?php if ($success): ?>
                        <div class="alert alert-success"><?php echo $success; ?></div>
                    <?php endif; ?>
                    
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?php echo $error; ?></div>
                    <?php endif; ?>

                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Service Number *</label>
                                    <input type="text" class="form-control" name="service_number" 
                                           value="<?php echo $_POST['service_number'] ?? ''; ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Rank *</label>
                                    <input type="text" class="form-control" name="rank" 
                                           value="<?php echo $_POST['rank'] ?? ''; ?>" required>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">First Name *</label>
                                    <input type="text" class="form-control" name="first_name" 
                                           value="<?php echo $_POST['first_name'] ?? ''; ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Last Name *</label>
                                    <input type="text" class="form-control" name="last_name" 
                                           value="<?php echo $_POST['last_name'] ?? ''; ?>" required>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">Gender *</label>
                                    <select class="form-control" name="gender" required>
                                        <option value="">Select Gender</option>
                                        <option value="Male" <?php echo ($_POST['gender'] ?? '') == 'Male' ? 'selected' : ''; ?>>Male</option>
                                        <option value="Female" <?php echo ($_POST['gender'] ?? '') == 'Female' ? 'selected' : ''; ?>>Female</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">Date of Birth</label>
                                    <input type="date" class="form-control" name="date_of_birth" 
                                           value="<?php echo $_POST['date_of_birth'] ?? ''; ?>">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">Phone Number</label>
                                    <input type="tel" class="form-control" name="phone_number" 
                                           value="<?php echo $_POST['phone_number'] ?? ''; ?>">
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">State of Origin</label>
                                    <input type="text" class="form-control" name="state_of_origin" 
                                           value="<?php echo $_POST['state_of_origin'] ?? ''; ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">LGA</label>
                                    <input type="text" class="form-control" name="lga" 
                                           value="<?php echo $_POST['lga'] ?? ''; ?>">
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email" 
                                   value="<?php echo $_POST['email'] ?? ''; ?>">
                        </div>

                        <div class="btn-action-group gap-2">
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-save"></i> Add Personnel
                            </button>
                            <a href="personnel" class="btn btn-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>