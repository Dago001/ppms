<?php
// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once 'includes/config.php';
require_once 'includes/auth.php';

if (!isLoggedIn()) {
    header('Location: login');
    exit();
}

require_once 'includes/security.php';

if (!isset($_GET['id'])) {
    header('Location: personnel');
    exit();
}

$id = intval($_GET['id']);
$token = $_GET['token'] ?? '';

if (!verifyRecordToken($id, $token)) {
    if (function_exists('logActivity')) {
        logActivity($pdo, $_SESSION['user_id'] ?? 0, 'Security Violation', "URL Tampering Blocked (IDOR): Invalid token for view_personnel.php?id=" . $id);
    }
    die("<div style='max-width:650px;margin:5rem auto;padding:2.5rem;background:#fff5f5;border:1px solid #fecaca;border-radius:12px;text-align:center;font-family:sans-serif;box-shadow:0 10px 25px rgba(220,38,38,0.1);'><div style='width:46px;height:46px;border-radius:50%;background:#fee2e2;color:#dc2626;display:flex;align-items:center;justify-content:center;font-size:1.25rem;margin:0 auto 1rem;'>&times;</div><h2 style='color:#991b1b;margin-bottom:0.5rem;'>403 Forbidden: Security Violation</h2><p style='color:#7f1d1d;line-height:1.5;'>Direct modification of URL parameters is strictly prohibited. Please access personnel records through the official Personnel management list.</p><a href='personnel' style='display:inline-block;margin-top:1.25rem;padding:0.6rem 1.25rem;background:#1a5632;color:#fff;border-radius:8px;text-decoration:none;font-weight:600;'>Return to Personnel Roster</a></div>");
}
$record = null;

try {
    $stmt = $pdo->prepare("SELECT p.*, e.disciplinary_status, e.disciplinary_remarks, e.promotion_eligibility, e.promotion_remarks, e.currentRank FROM tbl_emppersonal p LEFT JOIN tbl_employment e ON p.serviceNo = e.serviceNo WHERE p.id = ?");
    $stmt->execute([$id]);
    $record = $stmt->fetch();
    
    if (!$record) {
        die("Record not found.");
    }
} catch (PDOException $e) {
    die("Error fetching record: " . $e->getMessage());
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
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Personnel Details</h5>
                        <a href="personnel" class="btn btn-light btn-sm">
                            <i class="fas fa-arrow-left"></i> Back to List
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <table class="table table-bordered">
                                <tr>
                                    <th width="40%">Service Number:</th>
                                    <td><?php echo htmlspecialchars($record['service_number']); ?></td>
                                </tr>
                                <tr>
                                    <th>Rank:</th>
                                    <td><?php echo htmlspecialchars($record['rank']); ?></td>
                                </tr>
                                <tr>
                                    <th>Full Name:</th>
                                    <td><?php echo htmlspecialchars($record['first_name'] . ' ' . $record['last_name']); ?></td>
                                </tr>
                                <tr>
                                    <th>Gender:</th>
                                    <td><?php echo htmlspecialchars($record['gender']); ?></td>
                                </tr>
                                <tr>
                                    <th>Date of Birth:</th>
                                    <td><?php echo htmlspecialchars($record['date_of_birth']); ?></td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <table class="table table-bordered">
                                <tr>
                                    <th width="40%">State of Origin:</th>
                                    <td><?php echo htmlspecialchars($record['state_of_origin']); ?></td>
                                </tr>
                                <tr>
                                    <th>LGA:</th>
                                    <td><?php echo htmlspecialchars($record['lga']); ?></td>
                                </tr>
                                <tr>
                                    <th>Phone Number:</th>
                                    <td><?php echo htmlspecialchars($record['phone_number']); ?></td>
                                </tr>
                                <tr>
                                    <th>Email:</th>
                                    <td><?php echo htmlspecialchars($record['email']); ?></td>
                                </tr>
                                <tr>
                                    <th>Date Created:</th>
                                    <td><?php echo htmlspecialchars($record['created_at']); ?></td>
                                </tr>
                                <tr>
                                    <th>Disciplinary Status:</th>
                                    <td><?php echo getDisciplinaryBadgeHTML($record['disciplinary_status'] ?? 'Clean', $record['disciplinary_remarks'] ?? ''); ?></td>
                                </tr>
                                <tr>
                                    <th>Promotion Eligibility:</th>
                                    <td><span class="status-pill active" style="background:#e0f2fe; color:#0369a1; border:1px solid #bae6fd;"><?php echo htmlspecialchars($record['promotion_eligibility'] ?? 'Eligible'); ?></span></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    
                    <div class="btn-action-group mt-3">
                        <a href="personnel?edit_id=<?php echo $record['id']; ?>" class="btn btn-primary">
                            <i class="fas fa-edit"></i> Edit Record
                        </a>
                        <a href="personnel" class="btn btn-secondary">Back to List</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>