<?php
// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start session
session_start();

require_once 'includes/config.php';
require_once 'includes/auth.php';
require_once 'includes/permissions.php';
require_once 'includes/security.php';

if (!isLoggedIn()) {
    header('Location: login');
    exit();
}

// Initialize variables
$personnel = [];
$error = '';
$currentUserRole = $_SESSION['role_name'] ?? '';
$canDeletePersonnel = in_array($currentUserRole, ['admin', 'Service HQ', 'Super Admin']);

// Fetch all personnel records
try {
    $stmt = $pdo->query("
        SELECT p.*, e.disciplinary_status, e.disciplinary_remarks, e.promotion_eligibility, e.promotion_remarks, e.currentRank 
        FROM tbl_emppersonal p 
        LEFT JOIN tbl_employment e ON p.serviceNo = e.serviceNo 
        ORDER BY p.serviceNo ASC
    ");
    $personnel = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = "Error fetching personnel records: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NIS Personnel Posting Management System - Personnel Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* CSS Reset */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        /* Body styling */
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: 
                linear-gradient(135deg, rgba(44, 62, 80, 0.9) 0%, rgba(39, 174, 96, 0.9) 100%),
                url('assets/images/tech_building.jpg') center/cover no-repeat fixed;
            min-height: 100vh;
            color: #333;
            padding: 15px;
        }
        
        /* Header styling */
        .header-container {
            background: rgba(255, 255, 255, 0.98);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            padding: 0.8rem 2rem;
            position: sticky;
            top: 0;
            z-index: 1000;
            backdrop-filter: blur(5px);
            border-bottom: 2px solid #27ae60;
            margin: -15px -15px 20px -15px;
        }
        
        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .logo-title-container {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .header-logo {
            height: 50px;
            width: auto;
            object-fit: contain;
        }
        
        .system-title {
            color: #2c3e50;
            font-size: 1.4rem;
            font-weight: 800;
            margin: 0;
        }
        
        .user-info, .user-account-badge {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            background: #f8fafc;
            padding: 0.35rem 0.75rem;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }
        .user-profile, .officer-badge-icon {
            width: 28px;
            height: 28px;
            background: #1a5632;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 0.8rem;
        }
        .officer-details {
            display: flex;
            flex-direction: column;
            line-height: 1.25;
        }
        .officer-name {
            font-size: 0.8rem;
            color: #1e293b;
            font-weight: 600;
        }
        .officer-role {
            font-size: 0.675rem;
            color: #64748b;
            font-weight: 500;
            text-transform: uppercase;
        }
        .user-quick-actions {
            display: flex;
            align-items: center;
            gap: 0.35rem;
            padding-left: 0.5rem;
            border-left: 1px solid #cbd5e1;
            margin-left: 0.2rem;
        }
        .user-quick-actions a {
            color: #64748b;
            font-size: 0.775rem;
            text-decoration: none;
            padding: 0.2rem 0.35rem;
            border-radius: 4px;
        }
        .user-quick-actions a:hover {
            color: #1a5632;
            background: #e2e8f0;
        }
        
        /* Main content container */
        .content-container {
            background: rgba(255, 255, 255, 0.98);
            border-radius: 12px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
            padding: 2rem;
            max-width: 1400px;
            margin: 0 auto;
            backdrop-filter: blur(5px);
            border: 1px solid rgba(39, 174, 96, 0.1);
        }
        
        /* Page header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .page-title-section {
            display: flex;
            align-items: center;
            gap: 1rem;
            flex: 1;
        }
        
        .back-to-dashboard {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.6rem 1.2rem;
            background: #27ae60;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            white-space: nowrap;
        }
        
        .back-to-dashboard:hover {
            background: #219a52;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(39, 174, 96, 0.3);
        }
        
        .page-title {
            color: #2c3e50;
            font-size: 1.8rem;
            font-weight: 700;
            margin: 0;
        }
        
        /* Cards */
        .card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            margin-bottom: 1.5rem;
            overflow: hidden;
            border: 1px solid rgba(39, 174, 96, 0.1);
        }
        
        .card-header {
            background: linear-gradient(135deg, #2c3e50 0%, #27ae60 100%);
            color: white;
            padding: 1.2rem 1.5rem;
            font-weight: 600;
            font-size: 1.2rem;
            border: none;
        }
        
        .card-body {
            padding: 1.5rem;
        }
        
        /* Tables */
        .table-responsive {
            overflow-x: auto;
            margin: -1.5rem;
            padding: 1.5rem;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .table thead th {
            background: linear-gradient(135deg, #2c3e50 0%, #27ae60 100%);
            color: white;
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            border: none;
            position: sticky;
            top: 0;
            z-index: 1;
        }
        
        .table tbody tr {
            border-bottom: 1px solid #eee;
            transition: background-color 0.2s ease;
        }
        
        .table tbody tr:hover {
            background-color: rgba(39, 174, 96, 0.05);
        }
        
        .table tbody td {
            padding: 1rem;
            vertical-align: middle;
        }
        
        /* Buttons */
        .btn {
            padding: 0.5rem 1rem;
            border-radius: 6px;
            font-size: 0.9rem;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 3px 10px rgba(108, 117, 125, 0.3);
        }
        
        .btn-primary {
            background: #3498db;
            color: white;
        }
        
        .btn-primary:hover {
            background: #2980b9;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 3px 10px rgba(52, 152, 219, 0.3);
        }
        
        .btn-info {
            background: #17a2b8;
            color: white;
        }
        
        .btn-info:hover {
            background: #138496;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 3px 10px rgba(23, 162, 184, 0.3);
        }
        
        .btn-success {
            background: #27ae60;
            color: white;
        }
        
        .btn-success:hover {
            background: #219a52;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 3px 10px rgba(39, 174, 96, 0.3);
        }
        
        .btn-danger {
            background: #e74c3c;
            color: white;
        }
        
        .btn-danger:hover {
            background: #c0392b;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 3px 10px rgba(231, 76, 60, 0.3);
        }
        
        .btn-light {
            background: #f8f9fa;
            color: #333;
            border: 1px solid #ddd;
        }
        
        .btn-light:hover {
            background: #e9ecef;
            color: #333;
            transform: translateY(-2px);
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
        }
        
        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.85rem;
        }
        
        .btn-group {
            display: flex;
            gap: 0.5rem;
        }
        
        /* Alerts */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            border-left: 4px solid;
            display: flex;
            align-items: center;
            gap: 0.8rem;
        }
        
        .alert-danger {
            background: rgba(220, 53, 69, 0.1);
            color: #721c24;
            border-left-color: #dc3545;
        }
        
        .alert-success {
            background: rgba(39, 174, 96, 0.1);
            color: #155724;
            border-left-color: #27ae60;
        }
        
        .alert-warning {
            background: rgba(255, 193, 7, 0.1);
            color: #856404;
            border-left-color: #ffc107;
        }
        
        /* Search box */
        .search-box {
            max-width: 300px;
        }
        
        .search-input {
            padding: 0.75rem 1rem;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: white;
            width: 100%;
        }
        
        .search-input:focus {
            outline: none;
            border-color: #27ae60;
            box-shadow: 0 0 0 3px rgba(39, 174, 96, 0.2);
        }
        
        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
        }
        
        .empty-state i {
            font-size: 4rem;
            color: #ddd;
            margin-bottom: 1rem;
        }
        
        /* Responsive Design */
        @media (max-width: 992px) {
            .header-content {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
            }
            
            .logo-title-container {
                width: 100%;
                justify-content: space-between;
            }
            
            .user-info {
                width: 100;
                justify-content: flex-end;
            }
            
            .content-container {
                padding: 1.5rem;
            }
            
            .page-header {
                flex-direction: column;
                align-items: stretch;
            }
        }
        
        @media (max-width: 768px) {
            body {
                padding: 10px;
            }
            
            .header-container {
                padding: 0.8rem 1rem;
                margin: -10px -10px 15px -10px;
            }
            
            .header-logo {
                height: 40px;
            }
            
            .system-title {
                font-size: 1.1rem;
            }
            
            .content-container {
                padding: 1.2rem;
            }
            
            .page-title {
                font-size: 1.5rem;
            }
            
            .card-body {
                padding: 1.2rem;
            }
            
            .table-responsive {
                margin: -1.2rem;
                padding: 1.2rem;
            }
            
            .table thead th, .table tbody td {
                padding: 0.8rem;
            }
            
            .btn {
                padding: 0.4rem 0.8rem;
                font-size: 0.85rem;
            }
            
            .search-box {
                max-width: 100%;
            }
        }
        
        @media (max-width: 576px) {
            .header-content {
                flex-direction: column;
                align-items: stretch;
            }
            
            .logo-title-container {
                flex-direction: column;
                align-items: center;
                text-align: center;
                gap: 0.5rem;
            }
            
            .system-title {
                font-size: 1rem;
            }
            
            .user-info {
                justify-content: center;
                margin-top: 0.5rem;
            }
            
            .content-container {
                padding: 1rem;
            }
            
            .page-header {
                gap: 1rem;
            }
            
            .page-title-section {
                flex-direction: column;
                align-items: stretch;
                gap: 0.8rem;
            }
            
            .back-to-dashboard {
                align-self: flex-start;
            }
            
            .btn-group {
                flex-direction: column;
                gap: 0.3rem;
            }
            
            .btn-group .btn {
                width: 100%;
                justify-content: center;
            }
        }
        
        @media (max-width: 400px) {
            .page-title {
                font-size: 1.3rem;
            }
            
            .table {
                font-size: 0.85rem;
            }
            
            .btn {
                font-size: 0.8rem;
                padding: 0.4rem 0.6rem;
            }
            
            .back-to-dashboard {
                padding: 0.5rem 1rem;
                font-size: 0.9rem;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="header-container">
        <div class="header-content">
            <div class="logo-title-container">
                <h1 class="system-title">NIS Personnel Posting Management System</h1>
            </div>
            <div class="user-account-badge">
                <div class="officer-badge-icon">
                    <i class="fas fa-user-shield"></i>
                </div>
                <div class="officer-details">
                    <span class="officer-name"><?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User'); ?></span>
                    <span class="officer-role"><?php echo htmlspecialchars($_SESSION['role_name'] ?? 'User'); ?></span>
                </div>
                <div class="user-quick-actions">
                    <a href="dashboard" title="Dashboard"><i class="fas fa-home"></i></a>
                    <a href="logout" title="Logout" class="logout-action"><i class="fas fa-sign-out-alt"></i></a>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="content-container">
        <!-- Page Header -->
        <div class="page-header">
            <div class="page-title-section">
                <a href="dashboard" class="back-to-dashboard">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
                <h2 class="page-title">Personnel Management</h2>
            </div>
            <div class="search-box">
                <input type="text" class="search-input" placeholder="Search personnel..." id="searchInput">
            </div>
        </div>
        
        <!-- Error Message -->
        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <!-- Add New Personnel Button -->
        <div style="margin-bottom: 1.5rem; display: flex; justify-content: flex-end;">
            <a href="add_personnel" class="btn btn-success">
                <i class="fas fa-plus"></i> Add New Personnel
            </a>
        </div>

        <!-- Personnel List -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-users"></i> All Personnel Records (<?php echo count($personnel); ?>)
            </div>
            <div class="card-body">
                <?php if (empty($personnel)): ?>
                    <div class="empty-state">
                        <i class="fas fa-users"></i>
                        <h4>No Personnel Records Found</h4>
                        <p class="text-muted mb-4">There are no personnel records in the database yet.</p>
                        <a href="add_personnel" class="btn btn-success">
                            <i class="fas fa-user-plus"></i> Add First Personnel Record
                        </a>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table" id="personnelTable">
                             <thead>
                                 <tr>
                                     <th>Service No.</th>
                                     <th>Rank</th>
                                     <th>Full Name</th>
                                     <th>Gender</th>
                                     <th>State</th>
                                     <th>Disciplinary Status</th>
                                     <th>Actions</th>
                                 </tr>
                             </thead>
                             <tbody>
                                 <?php foreach ($personnel as $record): 
                                     $svc = $record['serviceNo'] ?? $record['service_number'] ?? '';
                                     $rankVal = $record['currentRank'] ?? $record['rank'] ?? 'N/A';
                                     $fullName = trim(($record['surname'] ?? $record['first_name'] ?? '') . ' ' . ($record['firstName'] ?? $record['last_name'] ?? ''));
                                 ?>
                                 <tr>
                                     <td>
                                         <strong><?php echo htmlspecialchars($svc); ?></strong>
                                     </td>
                                     <td><?php echo htmlspecialchars($rankVal); ?></td>
                                     <td><?php echo htmlspecialchars($fullName); ?></td>
                                     <td><?php echo htmlspecialchars($record['gender'] ?? 'N/A'); ?></td>
                                     <td><?php echo htmlspecialchars($record['state_of_origin'] ?? 'N/A'); ?></td>
                                     <td><?php echo getDisciplinaryBadgeHTML($record['disciplinary_status'] ?? 'Clean', $record['disciplinary_remarks'] ?? ''); ?></td>
                                     <td>
                                         <div class="btn-group">
                                             <a href="edit_employment?serviceNo=<?php echo urlencode($record['serviceNo']); ?>&token=<?php echo generateRecordToken($record['serviceNo']); ?>" 
                                                class="btn btn-primary btn-sm" title="Edit">
                                                 <i class="fas fa-edit"></i>
                                             </a>
                                             <a href="view_personnel?id=<?php echo $record['id']; ?>&token=<?php echo generateRecordToken($record['id']); ?>" 
                                                class="btn btn-info btn-sm" title="View">
                                                 <i class="fas fa-eye"></i>
                                             </a>
                                              <?php if ($canDeletePersonnel): ?>
                                              <a href="delete_personnel?id=<?php echo $record['id']; ?>&token=<?php echo generateRecordToken($record['id']); ?>" 
                                                 class="btn btn-danger btn-sm" title="Delete"
                                                 onclick="return confirm('Are you sure you want to delete this record?')">
                                                  <i class="fas fa-trash"></i>
                                              </a>
                                              <?php endif; ?>
                                         </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const searchInput = document.getElementById('searchInput');
        const table = document.getElementById('personnelTable');
        
        if (searchInput && table) {
            searchInput.addEventListener('keyup', function() {
                const filter = this.value.toLowerCase();
                const rows = table.querySelectorAll('tbody tr');
                
                rows.forEach(row => {
                    const text = row.textContent.toLowerCase();
                    row.style.display = text.includes(filter) ? '' : 'none';
                });
            });
        }
    });
    </script>

<?php include 'includes/footer.php'; ?>
</body>
</html>