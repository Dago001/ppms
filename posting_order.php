<?php
require_once 'includes/auth.php';
require_once 'includes/config.php';
require_once 'includes/permissions.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

if (!isLoggedIn()) {
    header('Location: login');
    exit();
}

if (!canInitiatePosting()) {
    header('Location: dashboard');
    exit();
}

$personnel = null;
$postings = [];
$service_number = '';
$error = '';
$success = '';
$csrfToken = generateCSRFToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_posting']) && !validateCSRFToken($_POST['csrf_token'] ?? '')) {
    $error = "Security validation failed. Please refresh the page and try again.";
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_posting'])) {
    if (!isset($_POST['personnel_id']) || !isset($_POST['formation_id']) || !isset($_POST['date_of_posting'])) {
        $error = "Missing required fields";
    } else {
        $personnel_id = $_POST['personnel_id'];
        $new_formation_id = $_POST['formation_id'];
        $date_of_posting = $_POST['date_of_posting'];

        try {
            $pdo->beginTransaction();

            // Set the current 'Active' posting to 'Previous'
            $stmt = $pdo->prepare("
                UPDATE postings 
                SET posting_status = 'Previous' 
                WHERE personnel_id = ? AND posting_status = 'Active'
            ");
            $stmt->execute([$personnel_id]);

            // Add the new posting as 'Active'
            $stmt = $pdo->prepare("
                INSERT INTO postings 
                (personnel_id, formation_id, date_of_posting, posting_status) 
                VALUES (?, ?, ?, 'Active')
            ");
            $stmt->execute([$personnel_id, $new_formation_id, $date_of_posting]);

            $pdo->commit();
            $success = "Posting history updated successfully.";

        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Failed to update posting history: " . $e->getMessage();
        }
    }
}

if (isset($_GET['service_number'])) {
    $service_number = trim($_GET['service_number']);

    if (!empty($service_number)) {
        try {
            // Debug log
            error_log("Searching for service number: " . $service_number);

            // First get the personnel basic info
            $stmt = $pdo->prepare("SELECT * FROM personnel WHERE service_number = :service_number");
            $stmt->bindValue(':service_number', $service_number, PDO::PARAM_STR);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result) {
                // Store basic personnel info with id included
                $personnel = [
                    'id' => $result['id'] ?? null,
                    'service_number' => $result['service_number'],
                    'full_name' => ($result['surname'] ?? '') . ' ' . ($result['firstname'] ?? ''),
                    'rank' => $result['rank'] ?? 'N/A',
                    'photo_path' => $result['photo_path'] ?? null
                ];

                // Corrected query - using service_number instead of personnel_id
                $stmt = $pdo->prepare("
                    SELECT p.*, f.name as formation_name, f.location_type 
                    FROM postings p
                    INNER JOIN formations f ON p.formation_id = f.id
                    WHERE p.personnel_id = :personnel_id
                    ORDER BY p.date_of_posting DESC
                ");
                
                $stmt->bindValue(':personnel_id', $personnel['id'], PDO::PARAM_INT);
                $stmt->execute();
                $postings = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Debug log
                error_log("Found personnel with service number: " . $service_number);
                error_log("Number of postings found: " . count($postings));

            } else {
                $error = "No personnel found with Service Number: " . htmlspecialchars($service_number);
                error_log($error);
                $personnel = null;
                $postings = [];
            }
        } catch (PDOException $e) {
            error_log("Database Error Details: " . $e->getMessage());
            error_log("SQL State: " . $e->getCode());
            error_log("Stack Trace: " . $e->getTraceAsString());
            
            $error = "Database error occurred. Please try again. Error: " . $e->getMessage();
            $personnel = null;
            $postings = [];
        }
    } else {
        $error = "Please enter a valid Service Number";
    }
}

// Fetch formations grouped by type
try {
    $formationsByType = [];
    
    // Get all formations grouped by type
    $stmt = $pdo->query("
        SELECT id, name, location_type 
        FROM formations 
        ORDER BY location_type, name
    ");
    
    while ($formation = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $formationsByType[$formation['location_type']][] = $formation;
    }
} catch (PDOException $e) {
    $error = "Error fetching formations: " . $e->getMessage();
}

include 'includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row justify-content-center">
        <div class="col-lg-8 col-md-10">
            <div class="card shadow-sm">
                <div class="card-header bg-success text-white py-2">
                    <h4 class="mb-0">Manage Posting Order</h4>
                </div>
                <div class="card-body">
                    <form method="GET" action="posting_order" class="row g-2 align-items-end">
                        <div class="col-md-8">
                            <label for="service_number" class="form-label small mb-1">Enter Service Number</label>
                            <input type="text" class="form-control form-control-sm" id="service_number" 
                                name="service_number" value="<?php echo htmlspecialchars((string)($service_number ?? '')); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <button type="submit" class="btn btn-success btn-sm w-100">
                                <i class="fas fa-search me-1"></i> Search
                            </button>
                        </div>
                    </form>

                    <?php if ($error): ?>
                        <div class="alert alert-danger mt-3"><?php echo $error; ?></div>
                    <?php endif; ?>
                    <?php if ($success): ?>
                        <div class="alert alert-success mt-3"><?php echo $success; ?></div>
                    <?php endif; ?>

                    <?php if ($personnel): ?>
                        <hr>
                        <h3>Personnel Details</h3>
                        <div class="row mb-4">
                            <div class="col-md-3">
                                <?php
                                $defaultImage = 'assets/images/default.png';
                                $photoPath = isset($personnel['photo_path']) && !empty($personnel['photo_path']) 
                                    ? 'uploads/' . htmlspecialchars((string)$personnel['photo_path'])
                                    : $defaultImage;
                                ?>
                                <img src="<?php echo $photoPath; ?>" class="img-fluid rounded" alt="Photo">
                            </div>
                            <div class="col-md-9">
                                <p><strong>Name:</strong> <?php echo htmlspecialchars((string)($personnel['full_name'] ?? 'N/A')); ?></p>
<p><strong>Service Number:</strong> <?php echo htmlspecialchars((string)($personnel['service_number'] ?? 'N/A')); ?></p>
<p><strong>Rank:</strong> <?php echo htmlspecialchars((string)($personnel['rank'] ?? 'N/A')); ?></p>
                            </div>
                        </div>

                        <h3>Posting History</h3>
                        <?php if ($postings): ?>
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Formation</th>
                                        <th>Type</th>
                                        <th>Date of Posting</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($postings as $posting): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars((string)($posting['formation_name'] ?? '')); ?></td>
<td><?php echo htmlspecialchars((string)($posting['location_type'] ?? '')); ?></td>
<td><?php echo htmlspecialchars((string)(date('d M Y', strtotime($posting['date_of_posting'])))); ?></td>
<td>
    <span class="badge bg-<?php echo $posting['posting_status'] === 'Active' ? 'success' : 'secondary'; ?>">
        <?php echo htmlspecialchars((string)($posting['posting_status'] ?? '')); ?>
    </span>
</td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <p class="text-muted">No posting history found for this personnel.</p>
                        <?php endif; ?>

                        <hr>
                        <h3>Add New Posting</h3>
                        <form method="POST" action="posting_order?service_number=<?php echo htmlspecialchars((string)($service_number ?? '')); ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                            <input type="hidden" name="personnel_id" value="<?php echo htmlspecialchars((string)($personnel['id'] ?? '')); ?>">
                            <input type="hidden" name="service_number" value="<?php echo htmlspecialchars((string)($personnel['service_number'] ?? '')); ?>">
                            
                            <div class="form-group mb-3">
    <label for="formation_id">New Formation</label>
    <select class="form-control" id="formation_id" name="formation_id" required>
        <option value="">Select a Formation</option>
        
        <?php if (isset($formationsByType['Service HQ'])): ?>
            <optgroup label="Service HQ">
                <?php foreach ($formationsByType['Service HQ'] as $formation): ?>
                    <option value="<?php echo htmlspecialchars((string)$formation['id']); ?>">
                        <?php echo htmlspecialchars((string)$formation['name']); ?>
                    </option>
                <?php endforeach; ?>
            </optgroup>
        <?php endif; ?>

        <?php if (isset($formationsByType['Zonal Command'])): ?>
            <optgroup label="Zonal Commands">
                <?php foreach ($formationsByType['Zonal Command'] as $formation): ?>
                    <option value="<?php echo htmlspecialchars((string)$formation['id']); ?>">
                        <?php echo htmlspecialchars((string)$formation['name']); ?>
                    </option>
                <?php endforeach; ?>
            </optgroup>
        <?php endif; ?>

        <?php if (isset($formationsByType['State Command'])): ?>
            <optgroup label="State Commands">
                <?php foreach ($formationsByType['State Command'] as $formation): ?>
                    <option value="<?php echo htmlspecialchars((string)$formation['id']); ?>">
                        <?php echo htmlspecialchars((string)$formation['name']); ?>
                    </option>
                <?php endforeach; ?>
            </optgroup>
        <?php endif; ?>

        <?php if (isset($formationsByType['Special Command'])): ?>
            <optgroup label="Special Commands">
                <?php foreach ($formationsByType['Special Command'] as $formation): ?>
                    <option value="<?php echo htmlspecialchars((string)$formation['id']); ?>">
                        <?php echo htmlspecialchars((string)$formation['name']); ?>
                    </option>
                <?php endforeach; ?>
            </optgroup>
        <?php endif; ?>

        <?php if (isset($formationsByType['Training School'])): ?>
            <optgroup label="Training Schools">
                <?php foreach ($formationsByType['Training School'] as $formation): ?>
                    <option value="<?php echo htmlspecialchars((string)$formation['id']); ?>">
                        <?php echo htmlspecialchars((string)$formation['name']); ?>
                    </option>
                <?php endforeach; ?>
            </optgroup>
        <?php endif; ?>
    </select>
</div>
                            <div class="form-group mb-3">
                                <label for="date_of_posting">Date of New Posting</label>
                                <input type="date" class="form-control" id="date_of_posting" name="date_of_posting" required>
                            </div>
                            <button type="submit" name="update_posting" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>Update Posting
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>