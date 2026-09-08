<?php
// Enable error reporting at the very top
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start session
session_start();

// Include files with error handling
try {
    require_once 'includes/config.php';
    require_once 'includes/auth.php';
    require_once 'includes/permissions.php';
} catch (Exception $e) {
    die("Error loading required files: " . $e->getMessage());
}

// Check if user is logged in
if (!isLoggedIn()) {
    header('Location: login');
    exit();
}

if (!hasPermission('view_reports')) {
    header("HTTP/1.1 403 Forbidden");
    echo "<div style='font-family:sans-serif; text-align:center; padding:3rem;'>
            <h1 style='color:#dc2626;'>403 Forbidden - Access Denied</h1>
            <p>You do not have permission ('view_reports') to access Custom Reports & Printing.</p>
            <a href='dashboard' style='display:inline-block; margin-top:1rem; padding:0.5rem 1rem; background:#1a5632; color:#fff; text-decoration:none; border-radius:6px;'>Return to Dashboard</a>
          </div>";
    exit();
}

// Check permission for reports
// TEMPORARY FIX: Allow access for testing - remove this in production
$permission_granted = true;

// Uncomment this for production
/*
if (!hasPermission('view_reports')) {
    $_SESSION['error_message'] = "You don't have permission to access the reports dashboard.";
    header('Location: dashboard');
    exit();
}
*/

// Initialize variables with default values
$user = [];
$error = null;
$success = null;

// Date range filter
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$formation_type = isset($_GET['formation_type']) ? $_GET['formation_type'] : 'all';
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'summary';

// Initialize report data arrays
$summary_stats = [];
$detailed_stats = [];
$formation_stats = [];
$monthly_trends = [];
$comparison_data = [];
$rank_analysis = [];
$zone_analysis = [];
$gender_analysis = [];
$age_distribution = [];

try {
    // Get user information
    $stmt = $pdo->prepare("
        SELECT u.*, r.name as role_name
        FROM users u 
        JOIN roles r ON u.role_id = r.id 
        WHERE u.id = ?
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user) {
        throw new Exception("User not found");
    }

    // Prepare date conditions for queries
    $date_condition = "WHERE 1=1";
    $params = [];
    
    if ($start_date && $end_date) {
        $date_condition .= " AND DATE(created_at) BETWEEN ? AND ?";
        $params[] = $start_date;
        $params[] = $end_date;
    }

    // Get summary statistics
    try {
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_personnel,
                COUNT(DISTINCT command) as total_commands,
                COUNT(DISTINCT zone) as total_zones,
                COUNT(DISTINCT passport_office) as total_passport_offices,
                COUNT(DISTINCT border) as total_borders,
                COUNT(DISTINCT headquarter) as total_headquarters,
                SUM(CASE WHEN gender = 'Male' THEN 1 ELSE 0 END) as male_count,
                SUM(CASE WHEN gender = 'Female' THEN 1 ELSE 0 END) as female_count,
                AVG(YEAR(CURDATE()) - YEAR(date_of_birth)) as avg_age
            FROM tbl_emppersonal 
            $date_condition
        ");
        $stmt->execute($params);
        $summary_stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$summary_stats) {
            $summary_stats = [];
        }
    } catch (Exception $e) {
        error_log("Summary stats query failed: " . $e->getMessage());
        $summary_stats = [];
    }

    // Get formation statistics
    try {
        $stmt = $pdo->prepare("
            SELECT 
                'Commands' as formation_type,
                COUNT(DISTINCT COALESCE(NULLIF(TRIM(command), ''), 'Not Specified')) as count,
                COUNT(*) as total_personnel
            FROM tbl_emppersonal 
            $date_condition
            UNION ALL
            SELECT 
                'Zones' as formation_type,
                COUNT(DISTINCT COALESCE(NULLIF(TRIM(zone), ''), 'Not Specified')) as count,
                COUNT(*) as total_personnel
            FROM tbl_emppersonal 
            $date_condition
            UNION ALL
            SELECT 
                'Passport Offices' as formation_type,
                COUNT(DISTINCT COALESCE(NULLIF(TRIM(passport_office), ''), 'Not Specified')) as count,
                COUNT(*) as total_personnel
            FROM tbl_emppersonal 
            $date_condition
            UNION ALL
            SELECT 
                'Borders' as formation_type,
                COUNT(DISTINCT COALESCE(NULLIF(TRIM(border), ''), 'Not Specified')) as count,
                COUNT(*) as total_personnel
            FROM tbl_emppersonal 
            $date_condition
            UNION ALL
            SELECT 
                'Headquarters' as formation_type,
                COUNT(DISTINCT COALESCE(NULLIF(TRIM(headquarter), ''), 'Not Specified')) as count,
                COUNT(*) as total_personnel
            FROM tbl_emppersonal 
            $date_condition
        ");
        $stmt->execute($params);
        $formation_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Formation stats query failed: " . $e->getMessage());
        $formation_stats = [];
    }

    // Get monthly trends (last 12 months)
    try {
        $stmt = $pdo->query("
            SELECT 
                DATE_FORMAT(created_at, '%Y-%m') as month,
                COUNT(*) as personnel_count,
                COUNT(DISTINCT command) as commands_count,
                COUNT(DISTINCT zone) as zones_count
            FROM tbl_emppersonal 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
            GROUP BY DATE_FORMAT(created_at, '%Y-%m')
            ORDER BY month ASC
        ");
        $monthly_trends = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Monthly trends query failed: " . $e->getMessage());
        $monthly_trends = [];
    }

    // Get rank analysis
    try {
        $stmt = $pdo->prepare("
            SELECT 
                COALESCE(NULLIF(TRIM(currentRank), ''), 'Not Specified') as rank_name,
                COUNT(*) as count,
                COUNT(DISTINCT command) as commands_covered,
                COUNT(DISTINCT zone) as zones_covered,
                ROUND(AVG(YEAR(CURDATE()) - YEAR(date_of_birth)), 1) as avg_age
            FROM tbl_emppersonal 
            $date_condition
            GROUP BY COALESCE(NULLIF(TRIM(currentRank), ''), 'Not Specified')
            ORDER BY count DESC
            LIMIT 20
        ");
        $stmt->execute($params);
        $rank_analysis = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Rank analysis query failed: " . $e->getMessage());
        $rank_analysis = [];
    }

    // Get zone analysis
    try {
        $stmt = $pdo->prepare("
            SELECT 
                COALESCE(NULLIF(TRIM(zone), ''), 'Not Specified') as zone_name,
                COUNT(*) as personnel_count,
                COUNT(DISTINCT command) as commands_in_zone,
                COUNT(DISTINCT COALESCE(NULLIF(TRIM(passport_office), ''), 'Not Specified')) as passport_offices,
                COUNT(DISTINCT COALESCE(NULLIF(TRIM(border), ''), 'Not Specified')) as borders,
                ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM tbl_emppersonal $date_condition), 2) as percentage
            FROM tbl_emppersonal 
            $date_condition
            GROUP BY COALESCE(NULLIF(TRIM(zone), ''), 'Not Specified')
            ORDER BY personnel_count DESC
            LIMIT 15
        ");
        $stmt->execute($params);
        $zone_analysis = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Zone analysis query failed: " . $e->getMessage());
        $zone_analysis = [];
    }

    // Get gender analysis
    try {
        $stmt = $pdo->prepare("
            SELECT 
                COALESCE(NULLIF(TRIM(gender), ''), 'Not Specified') as gender,
                COUNT(*) as count,
                ROUND(AVG(YEAR(CURDATE()) - YEAR(date_of_birth)), 1) as avg_age,
                COUNT(DISTINCT currentRank) as ranks_represented,
                COUNT(DISTINCT zone) as zones_represented
            FROM tbl_emppersonal 
            $date_condition
            GROUP BY COALESCE(NULLIF(TRIM(gender), ''), 'Not Specified')
            ORDER BY count DESC
        ");
        $stmt->execute($params);
        $gender_analysis = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Gender analysis query failed: " . $e->getMessage());
        $gender_analysis = [];
    }

    // Get age distribution
    try {
        $stmt = $pdo->prepare("
            SELECT 
                CASE 
                    WHEN YEAR(CURDATE()) - YEAR(date_of_birth) < 25 THEN 'Under 25'
                    WHEN YEAR(CURDATE()) - YEAR(date_of_birth) BETWEEN 25 AND 34 THEN '25-34'
                    WHEN YEAR(CURDATE()) - YEAR(date_of_birth) BETWEEN 35 AND 44 THEN '35-44'
                    WHEN YEAR(CURDATE()) - YEAR(date_of_birth) BETWEEN 45 AND 54 THEN '45-54'
                    WHEN YEAR(CURDATE()) - YEAR(date_of_birth) >= 55 THEN '55+'
                    ELSE 'Not Specified'
                END as age_group,
                COUNT(*) as count,
                ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM tbl_emppersonal $date_condition), 2) as percentage,
                COUNT(DISTINCT currentRank) as ranks_represented
            FROM tbl_emppersonal 
            $date_condition
            GROUP BY CASE 
                WHEN YEAR(CURDATE()) - YEAR(date_of_birth) < 25 THEN 'Under 25'
                WHEN YEAR(CURDATE()) - YEAR(date_of_birth) BETWEEN 25 AND 34 THEN '25-34'
                WHEN YEAR(CURDATE()) - YEAR(date_of_birth) BETWEEN 35 AND 44 THEN '35-44'
                WHEN YEAR(CURDATE()) - YEAR(date_of_birth) BETWEEN 45 AND 54 THEN '45-54'
                WHEN YEAR(CURDATE()) - YEAR(date_of_birth) >= 55 THEN '55+'
                ELSE 'Not Specified'
            END
            ORDER BY 
                CASE age_group
                    WHEN 'Under 25' THEN 1
                    WHEN '25-34' THEN 2
                    WHEN '35-44' THEN 3
                    WHEN '45-54' THEN 4
                    WHEN '55+' THEN 5
                    ELSE 6
                END
        ");
        $stmt->execute($params);
        $age_distribution = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Age distribution query failed: " . $e->getMessage());
        $age_distribution = [];
    }

    // Get detailed statistics based on report type
    switch($report_type) {
        case 'rank':
            $detailed_stats = $rank_analysis;
            break;
        case 'zone':
            $detailed_stats = $zone_analysis;
            break;
        case 'gender':
            $detailed_stats = $gender_analysis;
            break;
        case 'age':
            $detailed_stats = $age_distribution;
            break;
        default:
            $detailed_stats = $formation_stats;
    }

    // Get comparison data (current vs previous period)
    try {
        $comparison_start = date('Y-m-d', strtotime($start_date . ' -1 month'));
        $comparison_end = date('Y-m-d', strtotime($end_date . ' -1 month'));
        
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tbl_emppersonal WHERE DATE(created_at) BETWEEN ? AND ?");
        $stmt->execute([$start_date, $end_date]);
        $current_result = $stmt->fetch(PDO::FETCH_ASSOC);
        $current_count = $current_result ? (int)$current_result['count'] : 0;
        
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tbl_emppersonal WHERE DATE(created_at) BETWEEN ? AND ?");
        $stmt->execute([$comparison_start, $comparison_end]);
        $previous_result = $stmt->fetch(PDO::FETCH_ASSOC);
        $previous_count = $previous_result ? (int)$previous_result['count'] : 0;
        
        $comparison_data = [
            'current_period' => $current_count,
            'previous_period' => $previous_count,
            'change' => $previous_count > 0 ? round((($current_count - $previous_count) / $previous_count) * 100, 2) : 0,
            'trend' => $current_count > $previous_count ? 'up' : ($current_count < $previous_count ? 'down' : 'stable')
        ];
    } catch (Exception $e) {
        error_log("Comparison data query failed: " . $e->getMessage());
        $comparison_data = [
            'current_period' => 0,
            'previous_period' => 0,
            'change' => 0,
            'trend' => 'stable'
        ];
    }

} catch (Exception $e) {
    $error = "Error loading report data: " . $e->getMessage();
    error_log($error);
}

// Set safe defaults
if (empty($user)) {
    $user = [
        'username' => $_SESSION['username'] ?? 'User',
        'full_name' => $_SESSION['username'] ?? 'User',
        'role_name' => 'User'
    ];
}

// Set default values for summary stats if empty
if (empty($summary_stats)) {
    $summary_stats = [
        'total_personnel' => 0,
        'total_commands' => 0,
        'total_zones' => 0,
        'total_passport_offices' => 0,
        'total_borders' => 0,
        'total_headquarters' => 0,
        'male_count' => 0,
        'female_count' => 0,
        'avg_age' => 0
    ];
}

// Prepare data for JavaScript with safe defaults
$monthly_labels = json_encode(!empty($monthly_trends) ? array_column($monthly_trends, 'month') : []);
$monthly_personnel = json_encode(!empty($monthly_trends) ? array_column($monthly_trends, 'personnel_count') : []);
$monthly_commands = json_encode(!empty($monthly_trends) ? array_column($monthly_trends, 'commands_count') : []);

$rank_labels = json_encode(!empty($rank_analysis) ? array_column($rank_analysis, 'rank_name') : []);
$rank_counts = json_encode(!empty($rank_analysis) ? array_column($rank_analysis, 'count') : []);

$zone_labels = json_encode(!empty($zone_analysis) ? array_column($zone_analysis, 'zone_name') : []);
$zone_counts = json_encode(!empty($zone_analysis) ? array_column($zone_analysis, 'personnel_count') : []);

$gender_labels = json_encode(!empty($gender_analysis) ? array_column($gender_analysis, 'gender') : []);
$gender_counts = json_encode(!empty($gender_analysis) ? array_column($gender_analysis, 'count') : []);

$age_labels = json_encode(!empty($age_distribution) ? array_column($age_distribution, 'age_group') : []);
$age_counts = json_encode(!empty($age_distribution) ? array_column($age_distribution, 'count') : []);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="images/favicon.ico" type="image/x-icon">
    <title>NIS-PPMS - Reporting Dashboard</title>
    
    <!-- Chart.js for data visualization -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    
    <!-- DataTables for interactive tables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/jquery.dataTables.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <!-- Date Range Picker -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.css">
    <script src="https://cdn.jsdelivr.net/npm/moment@2.29.4/moment.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.min.js"></script>
    
    <!-- Export libraries -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.25/jspdf.plugin.autotable.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/exceljs/4.3.0/exceljs.min.js"></script>
    
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
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .user-profile {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #2c3e50 0%, #27ae60 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 1.1rem;
        }
        
        /* Report Dashboard Container */
        .report-dashboard-container {
            padding: 2rem;
            max-width: 1400px;
            margin: 0 auto;
        }
        
        /* Page Header */
        .page-header {
            margin-bottom: 2rem;
            padding: 1.5rem;
            background: rgba(255, 255, 255, 0.98);
            border-radius: 12px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            border: 1px solid rgba(39, 174, 96, 0.1);
        }
        
        .page-title-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        
        .page-title {
            color: #2c3e50;
            font-size: 1.8rem;
            font-weight: 700;
            margin: 0;
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
        
        /* Report Controls */
        .report-controls {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .control-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        
        .control-label {
            font-weight: 600;
            color: #2c3e50;
            font-size: 0.9rem;
        }
        
        .control-input {
            padding: 0.75rem 1rem;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            background: white;
        }
        
        .control-input:focus {
            outline: none;
            border-color: #27ae60;
            box-shadow: 0 0 0 3px rgba(39, 174, 96, 0.2);
        }
        
        .control-select {
            appearance: none;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            background-size: 1em;
            padding-right: 2.5rem;
        }
        
        .control-actions {
            display: flex;
            gap: 0.5rem;
            align-items: flex-end;
        }
        
        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-size: 0.95rem;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            text-align: center;
            white-space: nowrap;
        }
        
        .btn-primary {
            background: #27ae60;
            color: white;
        }
        
        .btn-primary:hover {
            background: #219a52;
            transform: translateY(-2px);
            box-shadow: 0 3px 10px rgba(39, 174, 96, 0.3);
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
            transform: translateY(-2px);
            box-shadow: 0 3px 10px rgba(108, 117, 125, 0.3);
        }
        
        .btn-export {
            background: #3498db;
            color: white;
        }
        
        .btn-export:hover {
            background: #2980b9;
            transform: translateY(-2px);
            box-shadow: 0 3px 10px rgba(52, 152, 219, 0.3);
        }
        
        /* Cards */
        .card {
            background: rgba(255, 255, 255, 0.98);
            border-radius: 12px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            margin-bottom: 1.5rem;
            overflow: hidden;
            border: 1px solid rgba(39, 174, 96, 0.1);
            transition: transform 0.3s ease;
        }
        
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.12);
        }
        
        .card-header {
            background: linear-gradient(135deg, #2c3e50 0%, #27ae60 100%);
            color: white;
            padding: 1rem 1.5rem;
            font-weight: 600;
            font-size: 1.1rem;
            border: none;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .card-body {
            padding: 1.5rem;
        }
        
        /* Summary Stats Grid */
        .summary-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .summary-stat-card {
            background: linear-gradient(135deg, rgba(44, 62, 80, 0.9) 0%, rgba(39, 174, 96, 0.9) 100%);
            color: white;
            border-radius: 12px;
            padding: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        .summary-stat-content h3 {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.3rem;
        }
        
        .summary-stat-content p {
            opacity: 0.9;
            font-size: 0.9rem;
        }
        
        .summary-stat-icon {
            background: rgba(255, 255, 255, 0.2);
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }
        
        /* Trend Indicator */
        .trend-indicator {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.3rem 0.6rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        
        .trend-up {
            background: rgba(39, 174, 96, 0.2);
            color: #155724;
        }
        
        .trend-down {
            background: rgba(220, 53, 69, 0.2);
            color: #721c24;
        }
        
        .trend-stable {
            background: rgba(108, 117, 125, 0.2);
            color: #495057;
        }
        
        /* Charts Grid */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(500px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .chart-container {
            position: relative;
            height: 350px;
            width: 100%;
            min-height: 300px;
        }
        
        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        .chart-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #2c3e50;
            margin: 0;
        }
        
        .chart-actions {
            display: flex;
            gap: 0.5rem;
        }
        
        .chart-btn {
            background: none;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 0.3rem 0.6rem;
            cursor: pointer;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 0.3rem;
            transition: all 0.2s ease;
        }
        
        .chart-btn:hover {
            background: #f8f9fa;
        }
        
        /* Detailed Report Table */
        .report-table-container {
            overflow-x: auto;
            margin-top: 1rem;
        }
        
        .report-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }
        
        .report-table th {
            background: #f8f9fa;
            padding: 0.75rem;
            text-align: left;
            font-weight: 600;
            color: #495057;
            border-bottom: 2px solid #dee2e6;
            position: sticky;
            top: 0;
        }
        
        .report-table td {
            padding: 0.75rem;
            border-bottom: 1px solid #dee2e6;
        }
        
        .report-table tr:hover {
            background-color: rgba(39, 174, 96, 0.05);
        }
        
        /* Report Tabs */
        .report-tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }
        
        .report-tab {
            padding: 0.75rem 1.5rem;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            color: #495057;
            transition: all 0.3s ease;
        }
        
        .report-tab:hover {
            background: #e9ecef;
        }
        
        .report-tab.active {
            background: #27ae60;
            color: white;
            border-color: #27ae60;
        }
        
        /* Export Modal */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1100;
            align-items: center;
            justify-content: center;
        }
        
        .modal-content {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            max-width: 500px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        
        .modal-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: #2c3e50;
            margin: 0;
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #6c757d;
        }
        
        /* Loading Spinner */
        .loading-spinner {
            display: none;
            text-align: center;
            padding: 2rem;
        }
        
        .spinner-border {
            width: 3rem;
            height: 3rem;
            border: 0.25em solid currentColor;
            border-right-color: transparent;
            border-radius: 50%;
            animation: spinner-border 0.75s linear infinite;
            color: #27ae60;
        }
        
        @keyframes spinner-border {
            to { transform: rotate(360deg); }
        }
        
        /* Responsive Design */
        @media (max-width: 1200px) {
            .charts-grid {
                grid-template-columns: repeat(auto-fill, minmax(450px, 1fr));
            }
        }
        
        @media (max-width: 992px) {
            .report-dashboard-container {
                padding: 1rem;
            }
            
            .charts-grid {
                grid-template-columns: 1fr;
            }
            
            .summary-stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 768px) {
            .header-container {
                padding: 0.8rem 1rem;
            }
            
            .header-logo {
                height: 40px;
            }
            
            .system-title {
                font-size: 1.1rem;
            }
            
            .page-title-section {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            
            .report-controls {
                grid-template-columns: 1fr;
            }
            
            .control-actions {
                flex-direction: column;
            }
            
            .summary-stats-grid {
                grid-template-columns: 1fr;
            }
            
            .chart-container {
                height: 300px;
            }
        }
        
        @media (max-width: 576px) {
            .header-content {
                flex-direction: column;
                gap: 0.8rem;
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
            
            .report-tabs {
                flex-direction: column;
            }
            
            .report-tab {
                width: 100%;
                text-align: center;
            }
        }
        
        /* Print Styles */
        @media print {
            .header-container,
            .report-controls,
            .control-actions,
            .chart-actions,
            .report-tabs {
                display: none !important;
            }
            
            .card {
                break-inside: avoid;
                box-shadow: none !important;
                border: 1px solid #ddd !important;
            }
            
            .chart-container {
                height: 250px !important;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="header-container">
        <div class="header-content">
            <div class="logo-title-container">
                <h1 class="system-title">NIS Personnel Posting Management System - Reports</h1>
            </div>
            <div class="user-info">
                <div class="user-profile">
                    <?php echo strtoupper(substr($user['username'], 0, 1)); ?>
                </div>
                <div>
                    <strong><?php echo htmlspecialchars($user['full_name'] ?? $user['username']); ?></strong><br>
                    <small><?php echo htmlspecialchars($user['role_name']); ?></small>
                </div>
            </div>
        </div>
    </div>

    <!-- Report Dashboard Container -->
    <div class="report-dashboard-container">
        <!-- Page Header -->
        <div class="page-header">
            <div class="page-title-section">
                <h1 class="page-title">
                    <i class="fas fa-chart-bar"></i> Comprehensive Reporting Dashboard
                </h1>
                <a href="dashboard" class="back-to-dashboard">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>

            <!-- Report Controls -->
            <form method="GET" action="" id="reportForm">
                <div class="report-controls">
                    <div class="control-group">
                        <label class="control-label">Date Range</label>
                        <input type="text" class="control-input" id="dateRange" name="date_range" 
                               value="<?php echo htmlspecialchars($start_date . ' to ' . $end_date); ?>">
                        <input type="hidden" name="start_date" id="startDate" value="<?php echo htmlspecialchars($start_date); ?>">
                        <input type="hidden" name="end_date" id="endDate" value="<?php echo htmlspecialchars($end_date); ?>">
                    </div>
                    
                    <div class="control-group">
                        <label class="control-label">Report Type</label>
                        <select class="control-input control-select" name="report_type" id="reportType">
                            <option value="summary" <?php echo $report_type == 'summary' ? 'selected' : ''; ?>>Summary Report</option>
                            <option value="rank" <?php echo $report_type == 'rank' ? 'selected' : ''; ?>>Rank Analysis</option>
                            <option value="zone" <?php echo $report_type == 'zone' ? 'selected' : ''; ?>>Zone Analysis</option>
                            <option value="gender" <?php echo $report_type == 'gender' ? 'selected' : ''; ?>>Gender Analysis</option>
                            <option value="age" <?php echo $report_type == 'age' ? 'selected' : ''; ?>>Age Distribution</option>
                            <option value="formation" <?php echo $report_type == 'formation' ? 'selected' : ''; ?>>Formation Analysis</option>
                        </select>
                    </div>
                    
                    <div class="control-group">
                        <label class="control-label">Formation Type</label>
                        <select class="control-input control-select" name="formation_type">
                            <option value="all" <?php echo $formation_type == 'all' ? 'selected' : ''; ?>>All Formations</option>
                            <option value="command" <?php echo $formation_type == 'command' ? 'selected' : ''; ?>>Commands Only</option>
                            <option value="zone" <?php echo $formation_type == 'zone' ? 'selected' : ''; ?>>Zones Only</option>
                            <option value="passport" <?php echo $formation_type == 'passport' ? 'selected' : ''; ?>>Passport Offices</option>
                            <option value="border" <?php echo $formation_type == 'border' ? 'selected' : ''; ?>>Borders Only</option>
                            <option value="hq" <?php echo $formation_type == 'hq' ? 'selected' : ''; ?>>Headquarters</option>
                        </select>
                    </div>
                    
                    <div class="control-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="resetFilters()">
                            <i class="fas fa-redo"></i> Reset
                        </button>
                        <button type="button" class="btn btn-export" onclick="showExportModal()">
                            <i class="fas fa-file-export"></i> Export Report
                        </button>
                    </div>
                </div>
            </form>

            <!-- Report Tabs -->
            <div class="report-tabs">
                <div class="report-tab active" onclick="showReportTab('summary')">
                    <i class="fas fa-chart-pie"></i> Summary
                </div>
                <div class="report-tab" onclick="showReportTab('trends')">
                    <i class="fas fa-chart-line"></i> Trends
                </div>
                <div class="report-tab" onclick="showReportTab('analysis')">
                    <i class="fas fa-chart-bar"></i> Analysis
                </div>
                <div class="report-tab" onclick="showReportTab('detailed')">
                    <i class="fas fa-table"></i> Detailed Data
                </div>
                <div class="report-tab" onclick="showReportTab('comparison')">
                    <i class="fas fa-balance-scale"></i> Comparisons
                </div>
            </div>
        </div>

        <!-- Alert Container -->
        <div id="alertContainer"></div>

        <!-- Loading Spinner -->
        <div id="loadingSpinner" class="loading-spinner">
            <div class="spinner-border" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-2">Generating report...</p>
        </div>

        <!-- Summary Tab Content -->
        <div id="summaryTab" class="report-tab-content">
            <!-- Summary Statistics -->
            <div class="summary-stats-grid">
                <div class="summary-stat-card">
                    <div class="summary-stat-content">
                        <h3 class="counter"><?php echo number_format($summary_stats['total_personnel'] ?? 0); ?></h3>
                        <p>Total Personnel</p>
                        <?php if (!empty($comparison_data)): ?>
                        <small>
                            <span class="trend-indicator trend-<?php echo $comparison_data['trend']; ?>">
                                <i class="fas fa-arrow-<?php echo $comparison_data['trend']; ?>"></i>
                                <?php echo abs($comparison_data['change']); ?>%
                            </span>
                            vs previous period
                        </small>
                        <?php endif; ?>
                    </div>
                    <div class="summary-stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                </div>
                
                <div class="summary-stat-card" style="background: linear-gradient(135deg, #2c3e50 0%, #4a90e2 100%);">
                    <div class="summary-stat-content">
                        <h3><?php echo number_format($summary_stats['total_commands'] ?? 0); ?></h3>
                        <p>Commands</p>
                        <small>Active formations</small>
                    </div>
                    <div class="summary-stat-icon">
                        <i class="fas fa-flag"></i>
                    </div>
                </div>
                
                <div class="summary-stat-card" style="background: linear-gradient(135deg, #27ae60 0%, #2ecc71 100%);">
                    <div class="summary-stat-content">
                        <h3><?php echo number_format($summary_stats['total_zones'] ?? 0); ?></h3>
                        <p>Zones</p>
                        <small>Geographic coverage</small>
                    </div>
                    <div class="summary-stat-icon">
                        <i class="fas fa-globe-africa"></i>
                    </div>
                </div>
                
                <div class="summary-stat-card" style="background: linear-gradient(135deg, #e74a3b 0%, #f6c23e 100%);">
                    <div class="summary-stat-content">
                        <h3><?php echo number_format($summary_stats['avg_age'] ?? 0, 1); ?></h3>
                        <p>Average Age</p>
                        <small>Years</small>
                    </div>
                    <div class="summary-stat-icon">
                        <i class="fas fa-user-clock"></i>
                    </div>
                </div>
            </div>

            <!-- Gender Distribution Card -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Gender Distribution</h5>
                    <div class="chart-actions">
                        <button class="chart-btn" onclick="exportChart('genderChart')">
                            <i class="fas fa-download"></i>
                        </button>
                        <button class="chart-btn" onclick="toggleFullscreen('genderChart')">
                            <i class="fas fa-expand"></i>
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="genderChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Trends Tab Content -->
        <div id="trendsTab" class="report-tab-content" style="display: none;">
            <div class="charts-grid">
                <!-- Monthly Personnel Trends -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Monthly Personnel Trends (Last 12 Months)</h5>
                        <div class="chart-actions">
                            <button class="chart-btn" onclick="exportChart('monthlyTrendChart')">
                                <i class="fas fa-download"></i>
                            </button>
                            <button class="chart-btn" onclick="toggleChartType('monthlyTrendChart')">
                                <i class="fas fa-exchange-alt"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="monthlyTrendChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Formation Growth -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Formation Growth Comparison</h5>
                        <div class="chart-actions">
                            <button class="chart-btn" onclick="exportChart('formationGrowthChart')">
                                <i class="fas fa-download"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="formationGrowthChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Analysis Tab Content -->
        <div id="analysisTab" class="report-tab-content" style="display: none;">
            <div class="charts-grid">
                <!-- Rank Distribution -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Rank Distribution Analysis</h5>
                        <div class="chart-actions">
                            <button class="chart-btn" onclick="exportChart('rankAnalysisChart')">
                                <i class="fas fa-download"></i>
                            </button>
                            <button class="chart-btn" onclick="toggleChartOrientation('rankAnalysisChart')">
                                <i class="fas fa-arrows-alt-v"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="rankAnalysisChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Zone Analysis -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Zone-wise Personnel Distribution</h5>
                        <div class="chart-actions">
                            <button class="chart-btn" onclick="exportChart('zoneAnalysisChart')">
                                <i class="fas fa-download"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="zoneAnalysisChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Age Distribution -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Age Group Distribution</h5>
                        <div class="chart-actions">
                            <button class="chart-btn" onclick="exportChart('ageDistributionChart')">
                                <i class="fas fa-download"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="ageDistributionChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Detailed Data Tab Content -->
        <div id="detailedTab" class="report-tab-content" style="display: none;">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">
                        <?php 
                        $report_titles = [
                            'summary' => 'Formation Summary',
                            'rank' => 'Rank Analysis',
                            'zone' => 'Zone Analysis',
                            'gender' => 'Gender Analysis',
                            'age' => 'Age Distribution',
                            'formation' => 'Formation Analysis'
                        ];
                        echo $report_titles[$report_type] ?? 'Detailed Report';
                        ?>
                    </h5>
                    <div>
                        <button class="btn btn-secondary btn-sm" onclick="exportTable('detailedTable')">
                            <i class="fas fa-file-csv"></i> CSV
                        </button>
                        <button class="btn btn-secondary btn-sm" onclick="exportTableExcel('detailedTable')">
                            <i class="fas fa-file-excel"></i> Excel
                        </button>
                        <button class="btn btn-secondary btn-sm" onclick="exportTablePDF('detailedTable')">
                            <i class="fas fa-file-pdf"></i> PDF
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="report-table-container">
                        <table class="report-table" id="detailedTable">
                            <thead>
                                <?php if ($report_type == 'rank'): ?>
                                <tr>
                                    <th>Rank</th>
                                    <th>Count</th>
                                    <th>Commands Covered</th>
                                    <th>Zones Covered</th>
                                    <th>Average Age</th>
                                </tr>
                                <?php elseif ($report_type == 'zone'): ?>
                                <tr>
                                    <th>Zone</th>
                                    <th>Personnel Count</th>
                                    <th>Commands</th>
                                    <th>Passport Offices</th>
                                    <th>Borders</th>
                                    <th>Percentage</th>
                                </tr>
                                <?php elseif ($report_type == 'gender'): ?>
                                <tr>
                                    <th>Gender</th>
                                    <th>Count</th>
                                    <th>Average Age</th>
                                    <th>Ranks Represented</th>
                                    <th>Zones Represented</th>
                                </tr>
                                <?php elseif ($report_type == 'age'): ?>
                                <tr>
                                    <th>Age Group</th>
                                    <th>Count</th>
                                    <th>Percentage</th>
                                    <th>Ranks Represented</th>
                                </tr>
                                <?php else: ?>
                                <tr>
                                    <th>Formation Type</th>
                                    <th>Count</th>
                                    <th>Total Personnel</th>
                                </tr>
                                <?php endif; ?>
                            </thead>
                            <tbody>
                                <?php if (!empty($detailed_stats)): ?>
                                <?php foreach ($detailed_stats as $stat): ?>
                                <tr>
                                    <?php if ($report_type == 'rank'): ?>
                                    <td><?php echo htmlspecialchars($stat['rank_name']); ?></td>
                                    <td><?php echo number_format($stat['count'] ?? 0); ?></td>
                                    <td><?php echo number_format($stat['commands_covered'] ?? 0); ?></td>
                                    <td><?php echo number_format($stat['zones_covered'] ?? 0); ?></td>
                                    <td><?php echo number_format($stat['avg_age'] ?? 0, 1); ?></td>
                                    <?php elseif ($report_type == 'zone'): ?>
                                    <td><?php echo htmlspecialchars($stat['zone_name']); ?></td>
                                    <td><?php echo number_format($stat['personnel_count'] ?? 0); ?></td>
                                    <td><?php echo number_format($stat['commands_in_zone'] ?? 0); ?></td>
                                    <td><?php echo number_format($stat['passport_offices'] ?? 0); ?></td>
                                    <td><?php echo number_format($stat['borders'] ?? 0); ?></td>
                                    <td><?php echo number_format($stat['percentage'] ?? 0, 2); ?>%</td>
                                    <?php elseif ($report_type == 'gender'): ?>
                                    <td><?php echo htmlspecialchars($stat['gender']); ?></td>
                                    <td><?php echo number_format($stat['count'] ?? 0); ?></td>
                                    <td><?php echo number_format($stat['avg_age'] ?? 0, 1); ?></td>
                                    <td><?php echo number_format($stat['ranks_represented'] ?? 0); ?></td>
                                    <td><?php echo number_format($stat['zones_represented'] ?? 0); ?></td>
                                    <?php elseif ($report_type == 'age'): ?>
                                    <td><?php echo htmlspecialchars($stat['age_group']); ?></td>
                                    <td><?php echo number_format($stat['count'] ?? 0); ?></td>
                                    <td><?php echo number_format($stat['percentage'] ?? 0, 2); ?>%</td>
                                    <td><?php echo number_format($stat['ranks_represented'] ?? 0); ?></td>
                                    <?php else: ?>
                                    <td><?php echo htmlspecialchars($stat['formation_type']); ?></td>
                                    <td><?php echo number_format($stat['count'] ?? 0); ?></td>
                                    <td><?php echo number_format($stat['total_personnel'] ?? 0); ?></td>
                                    <?php endif; ?>
                                </tr>
                                <?php endforeach; ?>
                                <?php else: ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; padding: 2rem;">No data available for the selected filters.</td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Comparison Tab Content -->
        <div id="comparisonTab" class="report-tab-content" style="display: none;">
            <div class="charts-grid">
                <!-- Period Comparison -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Period Comparison Analysis</h5>
                        <div class="chart-actions">
                            <button class="chart-btn" onclick="exportChart('comparisonChart')">
                                <i class="fas fa-download"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="comparisonChart"></canvas>
                        </div>
                        <div class="mt-3">
                            <div class="summary-stats-grid">
                                <div class="summary-stat-card">
                                    <div class="summary-stat-content">
                                        <h3><?php echo number_format($comparison_data['current_period'] ?? 0); ?></h3>
                                        <p>Current Period</p>
                                        <small><?php echo htmlspecialchars($start_date); ?> to <?php echo htmlspecialchars($end_date); ?></small>
                                    </div>
                                    <div class="summary-stat-icon">
                                        <i class="fas fa-calendar-alt"></i>
                                    </div>
                                </div>
                                
                                <div class="summary-stat-card" style="background: linear-gradient(135deg, #2c3e50 0%, #4a90e2 100%);">
                                    <div class="summary-stat-content">
                                        <h3><?php echo number_format($comparison_data['previous_period'] ?? 0); ?></h3>
                                        <p>Previous Period</p>
                                        <small>Last month comparison</small>
                                    </div>
                                    <div class="summary-stat-icon">
                                        <i class="fas fa-history"></i>
                                    </div>
                                </div>
                                
                                <div class="summary-stat-card" style="background: linear-gradient(135deg, #27ae60 0%, #2ecc71 100%);">
                                    <div class="summary-stat-content">
                                        <h3><?php echo number_format($comparison_data['change'] ?? 0, 2); ?>%</h3>
                                        <p>Change Rate</p>
                                        <span class="trend-indicator trend-<?php echo $comparison_data['trend'] ?? 'stable'; ?>">
                                            <i class="fas fa-arrow-<?php echo $comparison_data['trend'] ?? 'right'; ?>"></i>
                                            <?php echo abs($comparison_data['change'] ?? 0); ?>%
                                        </span>
                                    </div>
                                    <div class="summary-stat-icon">
                                        <i class="fas fa-chart-line"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Formation Comparison -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Formation Type Comparison</h5>
                        <div class="chart-actions">
                            <button class="chart-btn" onclick="exportChart('formationComparisonChart')">
                                <i class="fas fa-download"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="formationComparisonChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Export Modal -->
    <div id="exportModal" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-file-export"></i> Export Report
                </h3>
                <button type="button" class="modal-close" onclick="closeExportModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="control-group">
                    <label class="control-label">Export Format</label>
                    <select class="control-input control-select" id="exportFormat">
                        <option value="pdf">PDF Document</option>
                        <option value="excel">Excel Spreadsheet</option>
                        <option value="csv">CSV File</option>
                        <option value="json">JSON Data</option>
                    </select>
                </div>
                
                <div class="control-group">
                    <label class="control-label">Export Scope</label>
                    <select class="control-input control-select" id="exportScope">
                        <option value="current">Current View Only</option>
                        <option value="all">All Report Data</option>
                        <option value="summary">Summary Only</option>
                        <option value="detailed">Detailed Data Only</option>
                    </select>
                </div>
                
                <div class="control-group">
                    <label class="control-label">Include Charts</label>
                    <div>
                        <input type="checkbox" id="includeCharts" checked>
                        <label for="includeCharts">Include charts in export</label>
                    </div>
                </div>
                
                <div class="control-group">
                    <label class="control-label">Include Date Range</label>
                    <div>
                        <input type="checkbox" id="includeDateRange" checked>
                        <label for="includeDateRange">Include date range in export</label>
                    </div>
                </div>
                
                <div class="control-actions mt-3">
                    <button type="button" class="btn btn-primary" onclick="performExport()">
                        <i class="fas fa-download"></i> Export Now
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="closeExportModal()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
    // Chart instances
    let charts = {};
    let currentReportTab = 'summary';
    
    // Initialize date range picker
    $(function() {
        $('#dateRange').daterangepicker({
            opens: 'left',
            startDate: moment('<?php echo $start_date; ?>'),
            endDate: moment('<?php echo $end_date; ?>'),
            ranges: {
                'Today': [moment(), moment()],
                'Yesterday': [moment().subtract(1, 'days'), moment().subtract(1, 'days')],
                'Last 7 Days': [moment().subtract(6, 'days'), moment()],
                'Last 30 Days': [moment().subtract(29, 'days'), moment()],
                'This Month': [moment().startOf('month'), moment().endOf('month')],
                'Last Month': [moment().subtract(1, 'month').startOf('month'), moment().subtract(1, 'month').endOf('month')],
                'Last 3 Months': [moment().subtract(2, 'month').startOf('month'), moment().endOf('month')],
                'Last 6 Months': [moment().subtract(5, 'month').startOf('month'), moment().endOf('month')],
                'Last 12 Months': [moment().subtract(11, 'month').startOf('month'), moment().endOf('month')],
                'Year to Date': [moment().startOf('year'), moment()],
                'Full Year': [moment().startOf('year'), moment().endOf('year')]
            }
        }, function(start, end, label) {
            $('#startDate').val(start.format('YYYY-MM-DD'));
            $('#endDate').val(end.format('YYYY-MM-DD'));
        });
    });
    
    // Show loading spinner
    function showLoading() {
        document.getElementById('loadingSpinner').style.display = 'block';
    }
    
    // Hide loading spinner
    function hideLoading() {
        document.getElementById('loadingSpinner').style.display = 'none';
    }
    
    // Show alert message
    function showAlert(message, type = 'success') {
        const alertContainer = document.getElementById('alertContainer');
        alertContainer.innerHTML = `
            <div style="background: ${type === 'success' ? '#d4edda' : '#f8d7da'}; 
                        color: ${type === 'success' ? '#155724' : '#721c24'}; 
                        padding: 1rem; border-radius: 8px; margin-bottom: 1rem; 
                        border: 1px solid ${type === 'success' ? '#c3e6cb' : '#f5c6cb'}; 
                        display: flex; justify-content: space-between; align-items: center;">
                <span>${message}</span>
                <button type="button" onclick="this.parentElement.style.display='none'" 
                        style="background: none; border: none; font-size: 1.2rem; cursor: pointer; 
                               color: ${type === 'success' ? '#155724' : '#721c24'};">&times;</button>
            </div>
        `;
        
        setTimeout(() => {
            if (alertContainer.firstChild) {
                alertContainer.firstChild.style.display = 'none';
            }
        }, 5000);
    }
    
    // Reset filters
    function resetFilters() {
        document.getElementById('reportForm').reset();
        $('#dateRange').data('daterangepicker').setStartDate(moment().startOf('month'));
        $('#dateRange').data('daterangepicker').setEndDate(moment());
        $('#startDate').val(moment().startOf('month').format('YYYY-MM-DD'));
        $('#endDate').val(moment().format('YYYY-MM-DD'));
        document.getElementById('reportForm').submit();
    }
    
    // Show report tab
    function showReportTab(tabName) {
        // Hide all tabs
        document.querySelectorAll('.report-tab-content').forEach(tab => {
            tab.style.display = 'none';
        });
        
        // Remove active class from all tabs
        document.querySelectorAll('.report-tab').forEach(tab => {
            tab.classList.remove('active');
        });
        
        // Show selected tab
        document.getElementById(tabName + 'Tab').style.display = 'block';
        
        // Add active class to selected tab
        document.querySelectorAll('.report-tab').forEach(tab => {
            if (tab.textContent.includes(tabName.charAt(0).toUpperCase() + tabName.slice(1))) {
                tab.classList.add('active');
            }
        });
        
        currentReportTab = tabName;
        
        // Initialize charts for the tab if not already initialized
        setTimeout(() => {
            initializeTabCharts(tabName);
        }, 100);
    }
    
    // Initialize charts for specific tab
    function initializeTabCharts(tabName) {
        switch(tabName) {
            case 'summary':
                initializeGenderChart();
                break;
            case 'trends':
                initializeTrendsCharts();
                break;
            case 'analysis':
                initializeAnalysisCharts();
                break;
            case 'comparison':
                initializeComparisonCharts();
                break;
        }
    }
    
    // Create or update chart
    function createChart(elementId, data, type = 'bar', options = {}) {
        const ctx = document.getElementById(elementId);
        if (!ctx) return null;
        
        // Destroy existing chart if it exists
        if (charts[elementId]) {
            charts[elementId].destroy();
        }
        
        const defaultOptions = {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'top',
                },
                tooltip: {
                    mode: 'index',
                    intersect: false,
                }
            }
        };
        
        const chartOptions = Object.assign({}, defaultOptions, options);
        
        charts[elementId] = new Chart(ctx.getContext('2d'), {
            type: type,
            data: data,
            options: chartOptions
        });
        
        return charts[elementId];
    }
    
    // Initialize Gender Chart
    function initializeGenderChart() {
        const genderLabels = <?php echo $gender_labels; ?>;
        const genderData = <?php echo $gender_counts; ?>;
        
        // Check if we have data
        if (genderLabels.length === 0 || genderData.length === 0) {
            document.getElementById('genderChart').parentElement.innerHTML = 
                '<div style="display: flex; align-items: center; justify-content: center; height: 100%; color: #666;">No gender data available</div>';
            return;
        }
        
        const data = {
            labels: genderLabels,
            datasets: [{
                label: 'Gender Distribution',
                data: genderData,
                backgroundColor: [
                    'rgba(54, 162, 235, 0.8)',
                    'rgba(255, 99, 132, 0.8)',
                    'rgba(255, 206, 86, 0.8)'
                ],
                borderColor: [
                    'rgba(54, 162, 235, 1)',
                    'rgba(255, 99, 132, 1)',
                    'rgba(255, 206, 86, 1)'
                ],
                borderWidth: 1
            }]
        };
        
        createChart('genderChart', data, 'doughnut');
    }
    
    // Initialize Trends Charts
    function initializeTrendsCharts() {
        // Monthly Trends Chart
        const monthlyLabels = <?php echo $monthly_labels; ?>;
        const monthlyPersonnel = <?php echo $monthly_personnel; ?>;
        const monthlyCommands = <?php echo $monthly_commands; ?>;
        
        if (monthlyLabels.length > 0) {
            const monthlyData = {
                labels: monthlyLabels,
                datasets: [
                    {
                        label: 'Personnel Count',
                        data: monthlyPersonnel,
                        borderColor: 'rgba(54, 162, 235, 1)',
                        backgroundColor: 'rgba(54, 162, 235, 0.2)',
                        tension: 0.4
                    },
                    {
                        label: 'Commands Count',
                        data: monthlyCommands,
                        borderColor: 'rgba(255, 99, 132, 1)',
                        backgroundColor: 'rgba(255, 99, 132, 0.2)',
                        tension: 0.4
                    }
                ]
            };
            
            createChart('monthlyTrendChart', monthlyData, 'line');
        } else {
            document.getElementById('monthlyTrendChart').parentElement.innerHTML = 
                '<div style="display: flex; align-items: center; justify-content: center; height: 100%; color: #666;">No trend data available</div>';
        }
        
        // Formation Growth Chart
        const formationLabels = <?php echo json_encode(array_column($formation_stats, 'formation_type')); ?>;
        const formationData = <?php echo json_encode(array_column($formation_stats, 'count')); ?>;
        
        if (formationLabels.length > 0) {
            const formationChartData = {
                labels: formationLabels,
                datasets: [{
                    label: 'Formation Count',
                    data: formationData,
                    backgroundColor: [
                        'rgba(54, 162, 235, 0.8)',
                        'rgba(75, 192, 192, 0.8)',
                        'rgba(255, 206, 86, 0.8)',
                        'rgba(153, 102, 255, 0.8)',
                        'rgba(255, 159, 64, 0.8)'
                    ],
                    borderColor: [
                        'rgba(54, 162, 235, 1)',
                        'rgba(75, 192, 192, 1)',
                        'rgba(255, 206, 86, 1)',
                        'rgba(153, 102, 255, 1)',
                        'rgba(255, 159, 64, 1)'
                    ],
                    borderWidth: 1
                }]
            };
            
            createChart('formationGrowthChart', formationChartData, 'bar');
        } else {
            document.getElementById('formationGrowthChart').parentElement.innerHTML = 
                '<div style="display: flex; align-items: center; justify-content: center; height: 100%; color: #666;">No formation data available</div>';
        }
    }
    
    // Initialize Analysis Charts
    function initializeAnalysisCharts() {
        // Rank Analysis Chart
        const rankLabels = <?php echo $rank_labels; ?>;
        const rankData = <?php echo $rank_counts; ?>;
        
        if (rankLabels.length > 0) {
            const rankChartData = {
                labels: rankLabels,
                datasets: [{
                    label: 'Personnel Count',
                    data: rankData,
                    backgroundColor: 'rgba(54, 162, 235, 0.8)',
                    borderColor: 'rgba(54, 162, 235, 1)',
                    borderWidth: 1
                }]
            };
            
            createChart('rankAnalysisChart', rankChartData, 'bar', {
                indexAxis: 'y',
                scales: {
                    x: {
                        beginAtZero: true
                    }
                }
            });
        } else {
            document.getElementById('rankAnalysisChart').parentElement.innerHTML = 
                '<div style="display: flex; align-items: center; justify-content: center; height: 100%; color: #666;">No rank data available</div>';
        }
        
        // Zone Analysis Chart
        const zoneLabels = <?php echo $zone_labels; ?>;
        const zoneData = <?php echo $zone_counts; ?>;
        
        if (zoneLabels.length > 0) {
            const zoneChartData = {
                labels: zoneLabels,
                datasets: [{
                    label: 'Personnel Count',
                    data: zoneData,
                    backgroundColor: 'rgba(75, 192, 192, 0.8)',
                    borderColor: 'rgba(75, 192, 192, 1)',
                    borderWidth: 1
                }]
            };
            
            createChart('zoneAnalysisChart', zoneChartData, 'bar');
        } else {
            document.getElementById('zoneAnalysisChart').parentElement.innerHTML = 
                '<div style="display: flex; align-items: center; justify-content: center; height: 100%; color: #666;">No zone data available</div>';
        }
        
        // Age Distribution Chart
        const ageLabels = <?php echo $age_labels; ?>;
        const ageData = <?php echo $age_counts; ?>;
        
        if (ageLabels.length > 0) {
            const ageChartData = {
                labels: ageLabels,
                datasets: [{
                    label: 'Age Group Distribution',
                    data: ageData,
                    backgroundColor: [
                        'rgba(255, 99, 132, 0.8)',
                        'rgba(54, 162, 235, 0.8)',
                        'rgba(255, 206, 86, 0.8)',
                        'rgba(75, 192, 192, 0.8)',
                        'rgba(153, 102, 255, 0.8)'
                    ],
                    borderColor: [
                        'rgba(255, 99, 132, 1)',
                        'rgba(54, 162, 235, 1)',
                        'rgba(255, 206, 86, 1)',
                        'rgba(75, 192, 192, 1)',
                        'rgba(153, 102, 255, 1)'
                    ],
                    borderWidth: 1
                }]
            };
            
            createChart('ageDistributionChart', ageChartData, 'pie');
        } else {
            document.getElementById('ageDistributionChart').parentElement.innerHTML = 
                '<div style="display: flex; align-items: center; justify-content: center; height: 100%; color: #666;">No age data available</div>';
        }
    }
    
    // Initialize Comparison Charts
    function initializeComparisonCharts() {
        // Comparison Chart
        const currentPeriod = <?php echo $comparison_data['current_period'] ?? 0; ?>;
        const previousPeriod = <?php echo $comparison_data['previous_period'] ?? 0; ?>;
        
        const comparisonData = {
            labels: ['Current Period', 'Previous Period'],
            datasets: [{
                label: 'Personnel Count',
                data: [currentPeriod, previousPeriod],
                backgroundColor: [
                    'rgba(54, 162, 235, 0.8)',
                    'rgba(75, 192, 192, 0.8)'
                ],
                borderColor: [
                    'rgba(54, 162, 235, 1)',
                    'rgba(75, 192, 192, 1)'
                ],
                borderWidth: 1
            }]
        };
        
        createChart('comparisonChart', comparisonData, 'bar');
        
        // Formation Comparison Chart
        const formationLabels = <?php echo json_encode(array_column($formation_stats, 'formation_type')); ?>;
        const formationCounts = <?php echo json_encode(array_column($formation_stats, 'count')); ?>;
        
        if (formationLabels.length > 0) {
            const formationComparisonData = {
                labels: formationLabels,
                datasets: [{
                    label: 'Formation Types',
                    data: formationCounts,
                    backgroundColor: 'rgba(153, 102, 255, 0.8)',
                    borderColor: 'rgba(153, 102, 255, 1)',
                    borderWidth: 1
                }]
            };
            
            createChart('formationComparisonChart', formationComparisonData, 'polarArea');
        } else {
            document.getElementById('formationComparisonChart').parentElement.innerHTML = 
                '<div style="display: flex; align-items: center; justify-content: center; height: 100%; color: #666;">No formation comparison data available</div>';
        }
    }
    
    // Toggle chart type
    function toggleChartType(chartId) {
        if (charts[chartId]) {
            const currentType = charts[chartId].config.type;
            const newType = currentType === 'line' ? 'bar' : 'line';
            charts[chartId].config.type = newType;
            charts[chartId].update();
        }
    }
    
    // Toggle chart orientation
    function toggleChartOrientation(chartId) {
        if (charts[chartId]) {
            const currentAxis = charts[chartId].options.indexAxis || 'x';
            const newAxis = currentAxis === 'x' ? 'y' : 'x';
            charts[chartId].options.indexAxis = newAxis;
            charts[chartId].update();
        }
    }
    
    // Toggle fullscreen
    function toggleFullscreen(chartId) {
        const canvas = document.getElementById(chartId);
        if (!document.fullscreenElement) {
            canvas.requestFullscreen().catch(err => {
                alert(`Error attempting to enable fullscreen: ${err.message}`);
            });
        } else {
            document.exitFullscreen();
        }
    }
    
    // Export chart as image
    function exportChart(chartId) {
        if (charts[chartId]) {
            const link = document.createElement('a');
            link.download = `chart_${chartId}_${new Date().toISOString().slice(0,10)}.png`;
            link.href = charts[chartId].toBase64Image();
            link.click();
            showAlert('Chart exported successfully!');
        } else {
            showAlert('No chart data to export!', 'error');
        }
    }
    
    // Export table data as CSV
    function exportTable(tableId, format = 'csv') {
        const table = document.getElementById(tableId);
        let csvContent = '';
        
        // Get headers
        const headers = [];
        table.querySelectorAll('th').forEach(th => {
            headers.push(th.textContent);
        });
        csvContent += headers.join(',') + '\n';
        
        // Get rows
        table.querySelectorAll('tbody tr').forEach(row => {
            const rowData = [];
            row.querySelectorAll('td').forEach(td => {
                rowData.push(`"${td.textContent}"`);
            });
            csvContent += rowData.join(',') + '\n';
        });
        
        // Create download link
        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `report_${new Date().toISOString().slice(0,10)}.csv`;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
        
        showAlert('Table data exported as CSV!');
    }
    
    // Export table to Excel
    async function exportTableExcel(tableId) {
        try {
            const table = document.getElementById(tableId);
            const workbook = new ExcelJS.Workbook();
            const worksheet = workbook.addWorksheet('Report Data');
            
            // Add headers
            const headers = [];
            table.querySelectorAll('th').forEach(th => {
                headers.push(th.textContent);
            });
            worksheet.addRow(headers);
            
            // Style header row
            worksheet.getRow(1).font = { bold: true };
            worksheet.getRow(1).fill = {
                type: 'pattern',
                pattern: 'solid',
                fgColor: { argb: 'FF2C3E50' }
            };
            worksheet.getRow(1).font = { color: { argb: 'FFFFFFFF' }, bold: true };
            
            // Add data rows
            table.querySelectorAll('tbody tr').forEach(row => {
                const rowData = [];
                row.querySelectorAll('td').forEach(td => {
                    rowData.push(td.textContent);
                });
                worksheet.addRow(rowData);
            });
            
            // Auto-fit columns
            worksheet.columns.forEach(column => {
                let maxLength = 0;
                column.eachCell({ includeEmpty: true }, cell => {
                    const columnLength = cell.value ? cell.value.toString().length : 10;
                    if (columnLength > maxLength) {
                        maxLength = columnLength;
                    }
                });
                column.width = Math.min(maxLength + 2, 50);
            });
            
            // Generate and download
            const buffer = await workbook.xlsx.writeBuffer();
            const blob = new Blob([buffer], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = `report_${new Date().toISOString().slice(0,10)}.xlsx`;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);
            
            showAlert('Table data exported as Excel!');
        } catch (error) {
            console.error('Excel export error:', error);
            showAlert('Error exporting to Excel. Please try CSV export instead.', 'error');
        }
    }
    
    // Export table to PDF
    async function exportTablePDF(tableId) {
        try {
            const { jsPDF } = window.jspdf;
            const doc = new jsPDF('landscape');
            const table = document.getElementById(tableId);
            
            // Get table headers
            const headers = [];
            table.querySelectorAll('th').forEach(th => {
                headers.push(th.textContent);
            });
            
            // Get table data
            const data = [];
            table.querySelectorAll('tbody tr').forEach(row => {
                const rowData = [];
                row.querySelectorAll('td').forEach(td => {
                    rowData.push(td.textContent);
                });
                data.push(rowData);
            });
            
            // Add title
            doc.setFontSize(16);
            doc.text('NIS Personnel Report', 14, 15);
            doc.setFontSize(10);
            doc.text(`Date Range: ${$('#dateRange').val()}`, 14, 22);
            doc.text(`Generated: ${new Date().toLocaleDateString()}`, 14, 27);
            
            // Add table
            doc.autoTable({
                head: [headers],
                body: data,
                startY: 35,
                styles: { fontSize: 8 },
                headStyles: { fillColor: [44, 62, 80] },
                alternateRowStyles: { fillColor: [240, 240, 240] }
            });
            
            // Save PDF
            doc.save(`report_${new Date().toISOString().slice(0,10)}.pdf`);
            showAlert('Table data exported as PDF!');
        } catch (error) {
            console.error('PDF export error:', error);
            showAlert('Error exporting to PDF. Please try CSV export instead.', 'error');
        }
    }
    
    // Show export modal
    function showExportModal() {
        document.getElementById('exportModal').style.display = 'flex';
    }
    
    // Close export modal
    function closeExportModal() {
        document.getElementById('exportModal').style.display = 'none';
    }
    
    // Perform export based on selected options
    async function performExport() {
        const format = document.getElementById('exportFormat').value;
        const scope = document.getElementById('exportScope').value;
        const includeCharts = document.getElementById('includeCharts').checked;
        const includeDateRange = document.getElementById('includeDateRange').checked;
        
        showLoading();
        
        try {
            // Simulate export process
            await new Promise(resolve => setTimeout(resolve, 1000));
            
            // Actual implementation based on format
            switch(format) {
                case 'csv':
                    exportTable('detailedTable');
                    break;
                case 'excel':
                    await exportTableExcel('detailedTable');
                    break;
                case 'pdf':
                    await exportTablePDF('detailedTable');
                    break;
                case 'json':
                    exportAsJSON();
                    break;
                default:
                    exportTable('detailedTable');
            }
            
            hideLoading();
            closeExportModal();
        } catch (error) {
            hideLoading();
            closeExportModal();
            showAlert(`Export failed: ${error.message}`, 'error');
        }
    }
    
    // Export as JSON
    function exportAsJSON() {
        const table = document.getElementById('detailedTable');
        const data = {
            reportTitle: document.querySelector('.page-title').textContent,
            dateRange: $('#dateRange').val(),
            generated: new Date().toISOString(),
            data: []
        };
        
        // Get headers
        const headers = [];
        table.querySelectorAll('th').forEach(th => {
            headers.push(th.textContent.trim());
        });
        
        // Get rows
        table.querySelectorAll('tbody tr').forEach(row => {
            const rowData = {};
            const cells = row.querySelectorAll('td');
            cells.forEach((cell, index) => {
                if (headers[index]) {
                    rowData[headers[index]] = cell.textContent.trim();
                }
            });
            if (Object.keys(rowData).length > 0) {
                data.data.push(rowData);
            }
        });
        
        // Create download link
        const jsonString = JSON.stringify(data, null, 2);
        const blob = new Blob([jsonString], { type: 'application/json' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `report_${new Date().toISOString().slice(0,10)}.json`;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
        
        showAlert('Report data exported as JSON!');
    }
    
    // Initialize DataTables for detailed table
    function initializeDataTables() {
        $('#detailedTable').DataTable({
            pageLength: 10,
            lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "All"]],
            order: [[1, 'desc']],
            dom: '<"top"flp<"clear">>rt<"bottom"ip<"clear">>',
            language: {
                search: "_INPUT_",
                searchPlaceholder: "Search records..."
            },
            initComplete: function() {
                // Add custom search box
                $('.dataTables_filter input').addClass('control-input');
                $('.dataTables_filter input').attr('placeholder', 'Search in table...');
                $('.dataTables_length select').addClass('control-input control-select');
            }
        });
    }
    
    // Initialize when page loads
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize all charts
        initializeGenderChart();
        initializeTrendsCharts();
        initializeAnalysisCharts();
        initializeComparisonCharts();
        
        // Initialize DataTables
        initializeDataTables();
        
        // Hide loading spinner
        hideLoading();
        
        // Show error message if any
        <?php if ($error): ?>
        showAlert('<?php echo addslashes($error); ?>', 'error');
        <?php endif; ?>
    });
    
    // Handle form submission
    document.getElementById('reportForm').addEventListener('submit', function(e) {
        showLoading();
        // Form will submit normally
    });
    
    // Handle report type change
    document.getElementById('reportType').addEventListener('change', function() {
        showLoading();
        document.getElementById('reportForm').submit();
    });
    </script>
</body>
</html>