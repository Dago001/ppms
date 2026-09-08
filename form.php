<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';
require_once 'includes/permissions.php';

if (!isLoggedIn()) {
    header('Location: login');
    exit();
}

if (!canInitiatePosting()) {
    header('Location: dashboard');
    exit();
}

// Set PDO to throw exceptions on errors
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$csrfToken = generateCSRFToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !validateCSRFToken($_POST['csrf_token'] ?? '')) {
    $error = "Security validation failed. Please refresh the page and try again.";
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validate and sanitize inputs
        $service_number = filter_var($_POST['service_number'], FILTER_SANITIZE_STRING);
        if (!preg_match('/^\d{1,5}$/', $service_number)) {
            throw new Exception("Service Number must be between 1 and 5 digits only.");
        }

        $personnel_data = [
            'service_number' => $service_number,
            'surname' => strtoupper(filter_var($_POST['surname'], FILTER_SANITIZE_STRING)),
            'firstname' => strtoupper(filter_var($_POST['firstname'], FILTER_SANITIZE_STRING)),
            'middlename' => strtoupper(filter_var($_POST['middlename'], FILTER_SANITIZE_STRING)),
            'date_of_birth' => $_POST['date_of_birth'],
            'gender' => strtoupper(filter_var($_POST['gender'], FILTER_SANITIZE_STRING)),
            'rank' => filter_var($_POST['rank'], FILTER_SANITIZE_STRING)
        ];

        $pdo->beginTransaction();

        // Insert personnel data
        $sql = "INSERT INTO personnel (service_number, surname, firstname, middlename, date_of_birth, gender, rank) 
                VALUES (:service_number, :surname, :firstname, :middlename, :date_of_birth, :gender, :rank)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($personnel_data);

        // Get the personnel_id of the newly inserted record
        $personnel_id = $pdo->lastInsertId();

        // Process postings
        if (isset($_POST['posting_type']) && is_array($_POST['posting_type'])) {
            // First, get the formation_id for each posting
            $formation_stmt = $pdo->prepare("SELECT id FROM formations WHERE name = ?");
            
            $posting_stmt = $pdo->prepare("
                INSERT INTO postings (personnel_id, formation_id, date_of_posting, posting_status) 
                VALUES (:personnel_id, :formation_id, :date_of_posting, :posting_status)
            ");

            foreach ($_POST['posting_type'] as $index => $posting_type) {
                // Get formation_id
                $formation_stmt->execute([strtoupper($_POST['formation'][$index])]);
                $formation = $formation_stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$formation) {
                    // If formation doesn't exist, create it
                    $new_formation_stmt = $pdo->prepare("
                        INSERT INTO formations (name, location_type) 
                        VALUES (?, 'COMMAND')
                    ");
                    $new_formation_stmt->execute([strtoupper($_POST['formation'][$index])]);
                    $formation_id = $pdo->lastInsertId();
                } else {
                    $formation_id = $formation['id'];
                }

                $posting_data = [
                    'personnel_id' => $personnel_id,
                    'formation_id' => $formation_id,
                    'date_of_posting' => $_POST['date_of_posting'][$index],
                    'posting_status' => $posting_type === 'Current' ? 'Active' : 'Completed'
                ];
                
                $posting_stmt->execute($posting_data);
            }
        }

        $pdo->commit();
        $success = "Record added successfully!";
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error: " . $e->getMessage());
        $error = $e->getMessage();
    }
}

// Add after the POST processing try-catch block
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Add this debugging code
    error_log('Form submitted. POST data: ' . print_r($_POST, true));
    
    // Check if the form was submitted but no processing occurred
    if (!isset($success) && !isset($error)) {
        $error = "Form submission failed. Please try again.";
        error_log('Form submission failed - no success/error set');
    }
}
?>

<?php include 'includes/header.php'; ?>
<link rel="stylesheet" href="assets/css/form-style.css">
<link rel="stylesheet" href="assets/css/additional-styles.css">

<style>
    :root {
        --primary-green: #2E7D32;
        --secondary-green: #43A047;
        --light-green: #81C784;
    }

    body {
        margin: 0;
        padding: 0;
        min-height: 100vh;
        background: linear-gradient(rgba(46, 125, 50, 0.85), rgba(67, 160, 71, 0.9)), url('assets/images/tech_building.jpg');
        background-size: cover;
        background-position: center;
        background-attachment: fixed;
        font-family: 'Segoe UI', Arial, sans-serif;
    }

    .container {
        padding-top: 2rem;
        padding-bottom: 2rem;
    }

    .card {
        background: rgba(255, 255, 255, 0.98);
        border-radius: 10px;
        box-shadow: 0 0 20px rgba(0, 0, 0, 0.3);
        margin-bottom: 2rem;
    }

    .uppercase-input {
        text-transform: uppercase;
    }

    .posting-entry {
        border-bottom: 1px solid #eee;
        padding: 15px;
        margin-bottom: 15px;
    }

    .posting-entry:last-child {
        border-bottom: none;
    }

    .btn-sm {
        padding: 0.25rem 0.5rem;
    }

    h2.display-4 {
        color: #2e7d32;
        text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.3);
    }

    .border-bottom.border-success {
        border-color: #ffffff !important;
        width: 100px;
        margin: 0 auto;
    }

    .btn-primary {
        background-color: var(--primary-green);
        border: none;
        transition: background-color 0.3s ease;
    }

    .btn-primary:hover {
        background-color: var(--secondary-green);
    }

    .form-control:focus {
        border-color: var(--primary-green);
        box-shadow: 0 0 0 0.2rem rgba(46, 125, 50, 0.25);
    }

    .is-invalid {
        border-color: #dc3545 !important;
    }

    .is-invalid:focus {
        border-color: #dc3545 !important;
        box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25) !important;
    }
</style>

<div class="container">
    <div class="text-center mb-5">
        <h2 class="display-4 mb-3">Posting Order</h2>
        <div class="border-bottom border-success mx-auto" style="width: 100px;"></div>
    </div>
    
    <?php if (isset($success)): ?>
        <div class="alert alert-success"><?php echo $success; ?></div>
    <?php endif; ?>
    
    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><?php echo $error; ?></div>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['error_details']) && defined('ENVIRONMENT') && ENVIRONMENT === 'development'): ?>
    <div class="alert alert-warning">
        <strong>Debug Information:</strong>
        <pre><?php print_r($_SESSION['error_details']); ?></pre>
    </div>
    <?php unset($_SESSION['error_details']); ?>
<?php endif; ?>
    
    <form method="POST" id="personnelForm">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
        <div class="card mb-4">
            <div class="card-header">
                <h5>Personal Information</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="service_number" class="required-field">Service Number</label>
                            <input type="text" class="form-control" id="service_number" name="service_number" required 
                                   placeholder="Enter service number (max 5 digits)" 
                                   maxlength="5" 
                                   pattern="[0-9]{1,5}" 
                                   title="Please enter up to 5 digits only"
                                   oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 5)">
                        </div>
                        <div class="form-group">
                            <label for="surname" class="required-field">Surname</label>
                            <input type="text" class="form-control uppercase-input" id="surname" name="surname" required placeholder="Enter surname">
                        </div>
                        <div class="form-group">
                            <label for="firstname" class="required-field">Firstname</label>
                            <input type="text" class="form-control uppercase-input" id="firstname" name="firstname" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="middlename">Middlename</label>
                            <input type="text" class="form-control uppercase-input" id="middlename" name="middlename">
                        </div>
                        <div class="form-group">
                            <label for="date_of_birth" class="required-field">Date of Birth</label>
                            <input type="date" class="form-control" id="date_of_birth" name="date_of_birth" required>
                        </div>
                        <div class="form-group">
                            <label for="rank">Rank*</label>
                            <select class="form-control" id="rank" name="rank" required>
                                <option value="">Select Rank</option>
                                <option value="CG">CG</option>
                                <option value="DCG">DCG</option>
                                <option value="ACG">ACG</option>
                                <option value="CIS">CIS</option>
                                <option value="DCI">DCI</option>
                                <option value="ACI">ACI</option>
                                <option value="CSI">CSI</option>
                                <option value="SI">SI</option>
                                <option value="DSI">DSI</option>
                                <option value="ASI-1">ASI-1</option>
                                <option value="ASI-2">ASI-2</option>
                                <option value="II">II</option>
                                <option value="CIA">CIA</option>
                                <option value="AII">AII</option>
                                <option value="SIA">SIA</option>
                                <option value="IA-1">IA-1</option>
                                <option value="IA-2">IA-2</option>
                                <option value="IA-3">IA-3</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="gender" class="required-field">Gender</label>
                            <select class="form-control" id="gender" name="gender" required>
                                <option value="">Select Gender</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">Postings</h5>
            </div>
            <div class="card-body" id="postingsContainer">
                <!-- Postings will be added here dynamically -->
                <div class="posting-entry mb-3">
                    <div class="row">
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Posting Type*</label>
                                <select class="form-control posting-type" name="posting_type[]" required>
                                    <option value="">Select Type</option>
                                    <option value="Previous">Previous Posting</option>
                                    <option value="Current">Current Posting</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Formation*</label>
                                <select class="form-control" name="formation[]" required>
                                    <option value="">--Select Formation--</option>
                                    <option value="Service Headquarters">Service Headquarters (SHQ)</option>
                                    <option value="Zone A Lagos">Zone 'A' Lagos</option>
                                    <option value="Zone B Kaduna">Zone 'B' Kaduna</option>
                                    <option value="Zone C Bauchi">Zone 'C' Bauchi</option>
                                    <option value="Zone D Minna">Zone 'D' Minna</option>
                                    <option value="Zone E Owerri">Zone 'E' Owerri</option>
                                    <option value="Zone F Ibadan">Zone 'F' Ibadan</option>
                                    <option value="Zone G Benin City">Zone 'G' Benin City</option>
                                    <option value="Zone H Makurdi">Zone 'H' Makurdi</option>
                                    <!-- State Commands -->
                                    <option value="Abia">Abia State Command</option>
                                    <option value="Adamawa">Adamawa State Command</option>
                                    <option value="Akwa Ibom">Akwa Ibom State Command</option>
                                    <option value="Anambra">Anambra State Command</option>
                                    <option value="Bauchi">Bauchi State Command</option>
                                    <option value="Bayelsa">Bayelsa State Command</option>
                                    <option value="Benue">Benue State Command</option>
                                    <option value="Borno">Borno State Command</option>
                                    <option value="Cross River">Cross River State Command</option>
                                    <option value="Delta">Delta State Command</option>
                                    <option value="Ebonyi">Ebonyi State Command</option>
                                    <option value="Edo">Edo State Command</option>
                                    <option value="Ekiti">Ekiti State Command</option>
                                    <option value="Enugu">Enugu State Command</option>
                                    <option value="FCT">FCT Command</option>
                                    <option value="Gombe">Gombe State Command</option>
                                    <option value="Imo">Imo State Command</option>
                                    <option value="Jigawa">Jigawa State Command</option>
                                    <option value="Kaduna">Kaduna State Command</option>
                                    <option value="Kano">Kano State Command</option>
                                    <option value="Katsina">Katsina State Command</option>
                                    <option value="Kebbi">Kebbi State Command</option>
                                    <option value="Kogi">Kogi State Command</option>
                                    <option value="Kwara">Kwara State Command</option>
                                    <option value="Lagos">Lagos State Command</option>
                                    <option value="Nasarawa">Nasarawa State Command</option>
                                    <option value="Niger">Niger State Command</option>
                                    <option value="Ogun">Ogun State Command</option>
                                    <option value="Ondo">Ondo State Command</option>
                                    <option value="Osun">Osun State Command</option>
                                    <option value="Oyo">Oyo State Command</option>
                                    <option value="Plateau">Plateau State Command</option>
                                    <option value="Rivers">Rivers State Command</option>
                                    <option value="Sokoto">Sokoto State Command</option>
                                    <option value="Taraba">Taraba State Command</option>
                                    <option value="Yobe">Yobe State Command</option>
                                    <option value="Zamfara">Zamfara State Command</option>
                                    <option value="LABPC">Lagos Border Patrol Command</option>
                                    <option value="MMIA">MMIA</option>
                                    <option value="Lagos Passport Command">Lagos Passport Command</option>
                                    <option value="Seme Border Command">Seme Border Command</option>
                                    <option value="Idiroko Border Command">Idiroko Border Command</option>
                                    <option value="Illela Border Command">Illela Border Command</option>
                                    <option value="Jibiya Border Command">Jibiya Border Command</option>
                                    <option value="ITSK">Immigration Training School Kano</option>
                                    <option value="ICSC">Immigration Command and Staff College</option>
                                    <option value="NITSA">Nigeria Immigration Training School Ahoada</option>
                                    <option value="NITSOL">Nigeria Immigration Training School Orlu</option>
                                    <option value="Mfum Border Command">Mfum Border Command</option>
                                    <option value="River Marine Command">River Marine Command</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Date of Posting*</label>
                                <input type="date" class="form-control" name="date_of_posting[]" required>
                            </div>
                        </div>
                        <div class="col-md-2 d-flex align-items-end mb-3">
                            <button type="button" class="btn btn-primary btn-sm mr-2" onclick="addPosting(this)">ADD</button>
                            <button type="button" class="btn btn-danger btn-sm" onclick="removePosting(this)">×</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="text-center mt-5 mb-5">
    <button type="submit" class="btn btn-primary btn-lg px-5 py-3">Submit</button>
</div>
    </div>
</div>
</div>
    </form>
</div>

<?php
// Add before the form HTML

if (defined('ENVIRONMENT') && ENVIRONMENT === 'development') {
    echo '<div class="container mt-3">';
    echo '<div class="alert alert-info">';
    echo '<h4>Debug Information:</h4>';
    echo '<pre>';
    if (isset($_POST)) {
        print_r($_POST);
    }
    echo '</pre>';
    echo '</div></div>';
}
?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('personnelForm');
    
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        
        // Basic validation
        const requiredFields = form.querySelectorAll('[required]');
        let isValid = true;
        
        requiredFields.forEach(field => {
            if (!field.value.trim()) {
                isValid = false;
                field.classList.add('is-invalid');
            } else {
                field.classList.remove('is-invalid');
            }
        });
        
        // Check for current posting
        const postingTypes = document.getElementsByName('posting_type[]');
        let hasCurrentPosting = false;
        
        postingTypes.forEach(select => {
            if (select.value === 'Current') {
                hasCurrentPosting = true;
            }
        });
        
        if (!hasCurrentPosting) {
            alert('Please add at least one Current Posting before submitting.');
            return false;
        }
        
        if (!isValid) {
            alert('Please fill in all required fields.');
            return false;
        }
        
        // If all validations pass, submit the form
        this.submit();
    });
});

function addPosting(button) {
    const postingEntry = button.closest('.posting-entry');
    const newPosting = postingEntry.cloneNode(true);
    
    // Clear the values in the cloned posting
    newPosting.querySelectorAll('select, input').forEach(input => {
        input.value = '';
        input.classList.remove('is-invalid');
    });
    
    postingEntry.parentNode.insertBefore(newPosting, postingEntry.nextSibling);
}

function removePosting(button) {
    const postingEntry = button.closest('.posting-entry');
    const allPostings = document.getElementsByClassName('posting-entry');
    
    if (allPostings.length > 1) {
        const postingType = postingEntry.querySelector('.posting-type').value;
        
        if (postingType === 'Current') {
            let currentPostingsCount = Array.from(allPostings).filter(entry => 
                entry.querySelector('.posting-type').value === 'Current'
            ).length;
            
            if (currentPostingsCount <= 1) {
                alert('At least one Current Posting is required.');
                return;
            }
        }
        
        postingEntry.remove();
    }
}
</script>

<?php include 'includes/footer.php'; ?>