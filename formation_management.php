<?php
ini_set('display_errors', 0);
session_start();

require_once 'includes/config.php';
require_once 'includes/auth.php';
require_once 'includes/permissions.php';

if (!isLoggedIn()) { header('Location: login'); exit(); }

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

$userRole = $user['role_name'] ?? '';
$userId = $_SESSION['user_id'] ?? 0;
$error = '';
$success = '';
$csrfToken = generateCSRFToken();

// ONLY ADMIN OR SERVICE HQ CAN ACCESS FORMATION MANAGEMENT
if (!in_array(strtolower($userRole), ['admin', 'service hq', 'shq admin', 'super admin'])) {
    header('Location: dashboard');
    exit();
}

// --------------------------------------------
// HANDLE FORMATION UPDATE
// --------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_formation']) && !validateCSRFToken($_POST['csrf_token'] ?? '')) {
    $error = "Security validation failed. Please refresh the page and try again.";
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_formation'])) {
    $formationId = intval($_POST['formation_id']);
    $authorizedStrength = intval($_POST['authorized_strength']);
    $minimumStrength = intval($_POST['minimum_strength']);
    $criticalThreshold = intval($_POST['critical_vacancy_threshold']);
    
    try {
        $stmt = $pdo->prepare("UPDATE formation_structure SET authorized_strength = ?, minimum_strength = ?, critical_vacancy_threshold = ? WHERE id = ?");
        $stmt->execute([$authorizedStrength, $minimumStrength, $criticalThreshold, $formationId]);
        $success = "Formation strength parameters updated successfully!";
    } catch (Exception $e) {
        $error = "Error updating formation: " . $e->getMessage();
    }
}

// --------------------------------------------
// HANDLE REQUIREMENT SUBMISSION
// --------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_requirement']) && !validateCSRFToken($_POST['csrf_token'] ?? '')) {
    $error = "Security validation failed. Please refresh the page and try again.";
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_requirement'])) {
    $formationId = intval($_POST['formation_id']);
    $rankRequired = trim($_POST['rank_required']);
    $quantityRequired = intval($_POST['quantity_required']);
    $priority = $_POST['priority'] ?? 'Medium';
    $notes = trim($_POST['notes'] ?? '');
    
    if (empty($formationId)) {
        $error = "Please select a target formation.";
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO formation_requirements (formation_id, rank_required, quantity_required, priority, notes, requested_by) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$formationId, $rankRequired, $quantityRequired, $priority, $notes, $userId]);
            $success = "Staffing requirement submitted successfully!";
        } catch (Exception $e) {
            $error = "Error submitting requirement: " . $e->getMessage();
        }
    }
}

// --------------------------------------------
// HANDLE REQUIREMENT FILL
// --------------------------------------------
if (isset($_GET['fill_requirement']) && is_numeric($_GET['fill_requirement'])) {
    try {
        $stmt = $pdo->prepare("UPDATE formation_requirements SET status = 'Filled', updated_at = NOW() WHERE id = ?");
        $stmt->execute([$_GET['fill_requirement']]);
        $success = "Requirement marked as fulfilled!";
    } catch (Exception $e) {
        $error = "Error updating requirement status.";
    }
}

// --------------------------------------------
// HANDLE ALERT RESOLVE
// --------------------------------------------
if (isset($_GET['resolve_alert']) && is_numeric($_GET['resolve_alert'])) {
    try {
        $stmt = $pdo->prepare("UPDATE formation_alerts SET is_resolved = 1, resolved_at = NOW() WHERE id = ?");
        $stmt->execute([$_GET['resolve_alert']]);
        $success = "Formation alert resolved!";
    } catch (Exception $e) {
        $error = "Error resolving alert.";
    }
}

// --------------------------------------------
// SMART FORMATION STRENGTH CALCULATOR
// --------------------------------------------
$formationData = [];
try {
    $sql = "SELECT * FROM formation_structure WHERE is_active = 1 ORDER BY parent_zone, formation_name";
    $rawFormations = $pdo->query($sql)->fetchAll();
    
    foreach ($rawFormations as $f) {
        $name = $f['formation_name'];
        $code = $f['formation_code'] ?? '';
        
        $cleanName = preg_replace('/\b(State|Command|Headquarters|Directorate|HQ|Zone|Lagos|Border)\b/i', '', $name);
        $cleanName = trim(preg_replace('/\s+/', ' ', $cleanName));
        if (strlen($cleanName) < 3) { $cleanName = $name; }
        
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM tbl_employment WHERE presentPosting LIKE ? OR (presentPosting LIKE ? AND ? != '')");
        $stmt->execute(['%' . $name . '%', '%' . $cleanName . '%', $cleanName]);
        $count = intval($stmt->fetchColumn() ?? 0);
        
        // Specific Fallbacks
        if ($count == 0 && strpos($name, 'Jibia') !== false) {
            $count = intval($pdo->query("SELECT COUNT(*) FROM tbl_employment WHERE presentPosting LIKE '%Jibi%'")->fetchColumn() ?? 0);
        } elseif ($count == 0 && strpos($name, 'Ogun') !== false) {
            $count = intval($pdo->query("SELECT COUNT(*) FROM tbl_employment WHERE presentPosting LIKE '%Ogun%'")->fetchColumn() ?? 0);
        } elseif ($count == 0 && strpos($name, 'Niger') !== false) {
            $count = intval($pdo->query("SELECT COUNT(*) FROM tbl_employment WHERE presentPosting LIKE '%Niger%'")->fetchColumn() ?? 0);
        }
        
        $f['current_strength'] = $count;
        $formationData[] = $f;
        
        // Auto alert evaluation
        $authorizedCount = intval($f['authorized_strength']);
        $minimumCount = intval($f['minimum_strength']);
        $difference = $count - $authorizedCount;
        
        if ($authorizedCount > 0) {
            $pdo->prepare("UPDATE formation_alerts SET is_resolved = 1, resolved_at = NOW() WHERE formation_id = ? AND is_resolved = 0")->execute([$f['id']]);
            
            if ($count > ($authorizedCount * 1.1)) {
                $msg = "OVERSTAFFED: {$name} has {$count} officers against authorized {$authorizedCount} (+{$difference}).";
                $pdo->prepare("INSERT INTO formation_alerts (formation_id, alert_type, severity, message, current_count, authorized_count, difference) VALUES (?, 'overstaffed', 'Warning', ?, ?, ?, ?)")
                    ->execute([$f['id'], $msg, $count, $authorizedCount, $difference]);
            } elseif ($count < $minimumCount && $minimumCount > 0) {
                $sev = $count < ($minimumCount * 0.5) ? 'Critical' : 'Warning';
                $msg = "CRITICAL VACANCY: {$name} has only {$count} officers (Minimum required: {$minimumCount}).";
                $pdo->prepare("INSERT INTO formation_alerts (formation_id, alert_type, severity, message, current_count, authorized_count, difference) VALUES (?, 'critical_vacancy', ?, ?, ?, ?, ?)")
                    ->execute([$f['id'], $sev, $msg, $count, $authorizedCount, $count - $minimumCount]);
            } elseif ($count < ($authorizedCount * 0.8) && $count >= $minimumCount) {
                $msg = "UNDERSTAFFED: {$name} has {$count} officers (Authorized: {$authorizedCount}).";
                $pdo->prepare("INSERT INTO formation_alerts (formation_id, alert_type, severity, message, current_count, authorized_count, difference) VALUES (?, 'understaffed', 'Info', ?, ?, ?, ?)")
                    ->execute([$f['id'], $msg, $count, $authorizedCount, $difference]);
            }
        }
    }
} catch (Exception $e) { $formationData = []; }

// Active Alerts
$alerts = [];
try {
    $sql = "SELECT fa.*, fs.formation_name, fs.formation_code 
            FROM formation_alerts fa 
            JOIN formation_structure fs ON fa.formation_id = fs.id 
            WHERE fa.is_resolved = 0 
            ORDER BY CASE fa.severity WHEN 'Critical' THEN 1 WHEN 'Warning' THEN 2 ELSE 3 END, fa.created_at DESC";
    $alerts = $pdo->query($sql)->fetchAll();
} catch (Exception $e) { $alerts = []; }

// Requirements
$requirements = [];
try {
    $sql = "SELECT fr.*, fs.formation_name 
            FROM formation_requirements fr 
            JOIN formation_structure fs ON fr.formation_id = fs.id 
            WHERE fr.status = 'Open' 
            ORDER BY CASE fr.priority WHEN 'Critical' THEN 1 WHEN 'High' THEN 2 WHEN 'Medium' THEN 3 ELSE 4 END, fr.requested_at DESC";
    $requirements = $pdo->query($sql)->fetchAll();
} catch (Exception $e) { $requirements = []; }

// Summary KPIs
$totalFormations = count($formationData);
$totalAuthorized = 0;
$totalCurrent = 0;
$overstaffedCount = 0;
$understaffedCount = 0;
$criticalVacancyCount = 0;

foreach ($formationData as $f) {
    $curr = intval($f['current_strength']);
    $auth = intval($f['authorized_strength']);
    $min  = intval($f['minimum_strength']);
    
    $totalAuthorized += $auth;
    $totalCurrent += $curr;
    
    if ($auth > 0 && $curr > ($auth * 1.1)) $overstaffedCount++;
    elseif ($min > 0 && $curr < $min) $criticalVacancyCount++;
    elseif ($auth > 0 && $curr < ($auth * 0.8) && $curr >= $min) $understaffedCount++;
}

$complianceRate = $totalAuthorized > 0 ? round(($totalCurrent / $totalAuthorized) * 100, 1) : 0;
?>

<?php include 'includes/header.php'; ?>

<style>
    .page-title {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 0.75rem 1.15rem;
        margin-bottom: 1.15rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 0.75rem;
    }
    .page-title h1 {
        font-size: 1.15rem;
        font-weight: 600;
        color: #1e293b;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    .page-title h1 i { color: #1a5632; }

    .kpi-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
        gap: 0.75rem;
        margin-bottom: 1.15rem;
    }
    .kpi-card {
        background: #ffffff;
        border-radius: 8px;
        padding: 0.85rem;
        text-align: center;
        border: 1px solid #e2e8f0;
        box-shadow: 0 1px 3px rgba(0,0,0,0.03);
    }
    .kpi-card .value {
        font-size: 1.5rem;
        font-weight: 600;
        color: #1a5632;
        line-height: 1.1;
    }
    .kpi-card .label {
        font-size: 0.65rem;
        color: #64748b;
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 0.4px;
        margin-top: 0.3rem;
    }

    .compliance-bar { height: 6px; background: #e2e8f0; border-radius: 3px; margin-top: 0.4rem; overflow: hidden; }
    .compliance-fill { height: 100%; border-radius: 3px; }

    .tabs-bar {
        display: flex;
        gap: 0.35rem;
        margin-bottom: 1.15rem;
        border-bottom: 1px solid #e2e8f0;
        padding-bottom: 0.4rem;
        flex-wrap: wrap;
    }
    .tab-btn {
        padding: 0.4rem 0.85rem;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        cursor: pointer;
        font-size: 0.775rem;
        font-weight: 500;
        color: #475569;
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        transition: all 0.15s ease;
    }
    .tab-btn:hover { background: #e2e8f0; color: #1e293b; }
    .tab-btn.active { background: #1a5632; color: #ffffff; border-color: #1a5632; }

    .tab-content { display: none; }
    .tab-content.active { display: block; }

    .search-filter-card {
        background: #ffffff;
        border-radius: 8px;
        border: 1px solid #e2e8f0;
        padding: 0.75rem 1rem;
        margin-bottom: 1.15rem;
        display: flex;
        gap: 0.75rem;
        flex-wrap: wrap;
        align-items: center;
    }
    .search-filter-card input, .search-filter-card select {
        padding: 0.4rem 0.65rem;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        font-size: 0.775rem;
        font-weight: 500;
        color: #334155;
        background: #f8fafc;
    }

    .zone-section { margin-bottom: 1.5rem; }
    .zone-title {
        font-size: 0.9rem;
        font-weight: 600;
        color: #1a5632;
        margin-bottom: 0.75rem;
        padding-bottom: 0.35rem;
        border-bottom: 2px solid #1a5632;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .formation-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
        gap: 0.85rem;
    }
    .formation-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 0.95rem;
        box-shadow: 0 1px 3px rgba(0,0,0,0.02);
        transition: transform 0.15s ease, box-shadow 0.15s ease;
    }
    .formation-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0,0,0,0.05);
    }
    .formation-card h4 {
        font-size: 0.85rem;
        font-weight: 600;
        color: #1e293b;
        margin: 0 0 0.2rem 0;
    }

    .strength-bar { height: 6px; background: #e2e8f0; border-radius: 3px; overflow: hidden; margin: 0.4rem 0; }
    .strength-fill { height: 100%; border-radius: 3px; }
    .strength-good { background: #27ae60; }
    .strength-warning { background: #f39c12; }
    .strength-over { background: #dc2626; }
    .strength-critical { background: #991b1b; }

    .btn-action-sm {
        padding: 0.3rem 0.6rem;
        border-radius: 5px;
        font-size: 0.7rem;
        font-weight: 500;
        cursor: pointer;
        border: none;
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        text-decoration: none;
    }
    .btn-edit { background: #3b82f6; color: #ffffff; }
    .btn-edit:hover { background: #2563eb; }
    .btn-req { background: #1a5632; color: #ffffff; }
    .btn-req:hover { background: #154628; }
    .btn-view { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }
    .btn-view:hover { background: #e2e8f0; }

    .modal { display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.4); backdrop-filter: blur(2px); align-items: center; justify-content: center; }
    .modal-content { background: #ffffff; width: 90%; max-width: 480px; border-radius: 10px; border: 1px solid #cbd5e1; box-shadow: 0 10px 25px rgba(0,0,0,0.15); overflow: hidden; }
    .modal-header { padding: 0.85rem 1.15rem; background: #1a5632; color: #ffffff; display: flex; justify-content: space-between; align-items: center; }
    .modal-header h3 { font-size: 0.875rem; font-weight: 600; color: #ffffff; margin: 0; }
    .close-modal { background: none; border: none; color: #ffffff; font-size: 1.2rem; cursor: pointer; }
    .modal-body { padding: 1.15rem; }
    .modal-footer { padding: 0.75rem 1.15rem; background: #f8fafc; border-top: 1px solid #e2e8f0; display: flex; justify-content: flex-end; gap: 0.5rem; }

    .form-group { margin-bottom: 0.85rem; }
    .form-group label { display: block; font-weight: 500; color: #475569; margin-bottom: 0.3rem; font-size: 0.775rem; }
    .form-control { width: 100%; padding: 0.45rem 0.65rem; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.8rem; font-weight: 500; color: #334155; }
    .form-control:focus { outline: none; border-color: #1a5632; }

    .badge-soft { padding: 0.15rem 0.5rem; border-radius: 12px; font-size: 0.675rem; font-weight: 500; }
    .badge-good { background: #dcfce7; color: #166534; }
    .badge-warn { background: #fef3c7; color: #92400e; }
    .badge-crit { background: #fee2e2; color: #991b1b; }
</style>

<div class="page-title">
    <h1><i class="fas fa-building"></i> Formation Structure & Manning Management</h1>
    <div style="font-size:0.75rem; color:#64748b; font-weight:500;">
        <i class="fas fa-check-circle" style="color:#10b981;"></i> 72 Official NIS Formations
    </div>
</div>

<?php if ($error): ?>
<div style="background:#fee2e2;color:#991b1b;padding:0.65rem 1rem;border-radius:8px;margin-bottom:1rem;border-left:4px solid #dc2626;font-size:0.8rem;font-weight:500;">
    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
</div>
<?php endif; ?>

<?php if ($success): ?>
<div style="background:#dcfce7;color:#166534;padding:0.65rem 1rem;border-radius:8px;margin-bottom:1rem;border-left:4px solid #16a34a;font-size:0.8rem;font-weight:500;">
    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
</div>
<?php endif; ?>

<!-- KPI SUMMARY STATS -->
<div class="kpi-row">
    <div class="kpi-card">
        <div class="value"><?php echo $totalFormations; ?></div>
        <div class="label">Total Formations</div>
    </div>
    <div class="kpi-card">
        <div class="value"><?php echo number_format($totalCurrent); ?></div>
        <div class="label">Active Personnel</div>
        <div style="font-size:0.65rem; color:#64748b; margin-top:2px;">Auth: <?php echo number_format($totalAuthorized); ?></div>
    </div>
    <div class="kpi-card">
        <div class="value" style="color: <?php echo $complianceRate >= 80 ? '#166534' : ($complianceRate >= 60 ? '#92400e' : '#991b1b'); ?>;">
            <?php echo $complianceRate; ?>%
        </div>
        <div class="label">Manning Compliance</div>
        <div class="compliance-bar">
            <div class="compliance-fill" style="width:<?php echo min($complianceRate, 100); ?>%; background: <?php echo $complianceRate >= 80 ? '#16a34a' : ($complianceRate >= 60 ? '#f59e0b' : '#dc2626'); ?>;"></div>
        </div>
    </div>
    <div class="kpi-card">
        <div class="value" style="color:#991b1b;"><?php echo $criticalVacancyCount; ?></div>
        <div class="label">Critical Vacancies</div>
    </div>
    <div class="kpi-card">
        <div class="value" style="color:#92400e;"><?php echo $understaffedCount; ?></div>
        <div class="label">Understaffed</div>
    </div>
    <div class="kpi-card">
        <div class="value" style="color:#1d4ed8;"><?php echo $overstaffedCount; ?></div>
        <div class="label">Overstaffed</div>
    </div>
</div>

<!-- TABS BAR -->
<div class="tabs-bar">
    <button class="tab-btn active" onclick="switchTab('formationsTab', this)"><i class="fas fa-sitemap"></i> Formations Overview (<?php echo $totalFormations; ?>)</button>
    <button class="tab-btn" onclick="switchTab('alertsTab', this)"><i class="fas fa-bell"></i> Active Alerts (<?php echo count($alerts); ?>)</button>
    <button class="tab-btn" onclick="switchTab('requirementsTab', this)"><i class="fas fa-clipboard-list"></i> Requirements (<?php echo count($requirements); ?>)</button>
</div>

<!-- SEARCH & FILTER BAR -->
<div class="search-filter-card">
    <span style="font-size:0.75rem; font-weight:500; color:#475569;"><i class="fas fa-filter"></i> Quick Filter:</span>
    <input type="text" id="formationSearch" placeholder="Search formation name or code..." style="min-width:220px;" onkeyup="filterFormations()">
    <select id="zoneFilter" onchange="filterFormations()">
        <option value="">All Zones</option>
        <option value="SERVICE HEADQUARTERS">SERVICE HEADQUARTERS</option>
        <option value="ZONE A">ZONE A</option>
        <option value="ZONE B">ZONE B</option>
        <option value="ZONE C">ZONE C</option>
        <option value="ZONE D">ZONE D</option>
        <option value="ZONE E">ZONE E</option>
        <option value="ZONE F">ZONE F</option>
        <option value="ZONE G">ZONE G</option>
        <option value="ZONE H">ZONE H</option>
    </select>
</div>

<!-- TAB 1: FORMATIONS OVERVIEW -->
<div id="formationsTab" class="tab-content active">
    <?php 
    $currentZone = '';
    foreach ($formationData as $f): 
        if ($f['parent_zone'] !== $currentZone) {
            if ($currentZone !== '') echo '</div></div>';
            $currentZone = $f['parent_zone'];
            echo '<div class="zone-section" data-zone="' . htmlspecialchars($currentZone) . '">';
            echo '<div class="zone-title"><span><i class="fas fa-layer-group"></i> ' . htmlspecialchars($currentZone ?: 'Unassigned') . '</span></div>';
            echo '<div class="formation-grid">';
        }
        
        $curr = intval($f['current_strength']);
        $auth = intval($f['authorized_strength']);
        $min  = intval($f['minimum_strength']);
        $pct  = $auth > 0 ? round(($curr / $auth) * 100) : 0;
        
        $badgeClass = 'badge-good';
        if ($min > 0 && $curr < $min) $badgeClass = 'badge-crit';
        elseif ($auth > 0 && ($curr > ($auth * 1.1) || $curr < ($auth * 0.8))) $badgeClass = 'badge-warn';
        
        $barColor = 'strength-good';
        if ($pct > 110) $barColor = 'strength-over';
        elseif ($pct < 50) $barColor = 'strength-critical';
        elseif ($pct < 80) $barColor = 'strength-warning';
        
        $viewUrl = 'formation_management_view.php?formation_id=' . $f['id'] . '&formation_name=' . urlencode($f['formation_name']);
    ?>
    <div class="formation-card" data-name="<?php echo htmlspecialchars(strtolower($f['formation_name'] . ' ' . ($f['formation_code'] ?? ''))); ?>">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:0.4rem;">
            <div>
                <h4><?php echo htmlspecialchars($f['formation_name']); ?></h4>
                <span style="font-size:0.675rem; color:#64748b; font-weight:500;"><?php echo htmlspecialchars($f['formation_type']); ?> | Code: <code><?php echo htmlspecialchars($f['formation_code'] ?? 'N/A'); ?></code></span>
            </div>
            <span class="badge-soft <?php echo $badgeClass; ?>"><?php echo $pct; ?>%</span>
        </div>
        
        <div style="display:flex; align-items:center; gap:0.4rem; margin-bottom:0.25rem; font-size:0.75rem;">
            <span style="color:#64748b; font-weight:500;">Strength:</span>
            <span style="font-weight:600; color:#1a5632;"><?php echo number_format($curr); ?></span>
            <span style="font-size:0.675rem; color:#94a3b8;">/ <?php echo number_format($auth); ?> auth</span>
        </div>
        
        <div class="strength-bar"><div class="strength-fill <?php echo $barColor; ?>" style="width:<?php echo min($pct, 130); ?>%"></div></div>
        
        <div style="display:flex; justify-content:space-between; margin-top:0.3rem; font-size:0.65rem; color:#64748b; font-weight:500;">
            <span>Min: <?php echo number_format($min); ?></span>
            <span>Auth: <?php echo number_format($auth); ?></span>
            <span><?php echo $curr > $auth ? '+' . ($curr - $auth) . ' over' : ($curr < $auth ? ($auth - $curr) . ' needed' : 'Optimal'); ?></span>
        </div>
        
        <div style="margin-top:0.75rem; display:flex; gap:0.4rem; flex-wrap:wrap;">
            <button class="btn-action-sm btn-edit" onclick="openEditModal(<?php echo $f['id']; ?>, '<?php echo htmlspecialchars(addslashes($f['formation_name'])); ?>', <?php echo $f['authorized_strength']; ?>, <?php echo $f['minimum_strength']; ?>, <?php echo $f['critical_vacancy_threshold']; ?>)"><i class="fas fa-edit"></i> Edit</button>
            <button class="btn-action-sm btn-req" onclick="openRequirementModal(<?php echo $f['id']; ?>, '<?php echo htmlspecialchars(addslashes($f['formation_name'])); ?>')"><i class="fas fa-plus"></i> Request</button>
            <a href="<?php echo $viewUrl; ?>" class="btn-action-sm btn-view"><i class="fas fa-search"></i> View Officers</a>
        </div>
    </div>
    <?php endforeach; 
    if ($currentZone !== '') echo '</div></div>';
    ?>
</div>

<!-- TAB 2: ALERTS -->
<div id="alertsTab" class="tab-content">
    <div class="card" style="border-radius:10px; border:1px solid #e2e8f0; background:#fff;">
        <div class="card-header" style="padding:0.75rem 1rem; border-bottom:1px solid #e2e8f0; font-weight:600; color:#1a5632;">
            <h3 style="font-size:0.85rem; margin:0;"><i class="fas fa-bell"></i> Active Manning Alerts</h3>
        </div>
        <div style="padding:1rem;">
            <?php if (empty($alerts)): ?>
                <div style="text-align:center; padding:2rem; color:#64748b;">
                    <i class="fas fa-check-circle" style="font-size:2rem; color:#16a34a; display:block; margin-bottom:0.5rem;"></i>
                    <strong style="font-size:0.85rem;">No Unresolved Manning Alerts</strong>
                    <p style="font-size:0.75rem; margin-top:0.25rem;">All formation manning levels are operating within expected thresholds.</p>
                </div>
            <?php else: ?>
                <?php foreach ($alerts as $alert): 
                    $bg = $alert['severity'] === 'Critical' ? '#fef2f2' : ($alert['severity'] === 'Warning' ? '#fffbeb' : '#f0fdf4');
                    $border = $alert['severity'] === 'Critical' ? '#dc2626' : ($alert['severity'] === 'Warning' ? '#d97706' : '#16a34a');
                ?>
                <div style="background:<?php echo $bg; ?>; border-left:4px solid <?php echo $border; ?>; border-radius:6px; padding:0.75rem 1rem; margin-bottom:0.6rem; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.5rem;">
                    <div>
                        <div style="display:flex; align-items:center; gap:0.4rem; margin-bottom:0.2rem;">
                            <span class="badge-soft <?php echo $alert['severity'] === 'Critical' ? 'badge-crit' : 'badge-warn'; ?>"><?php echo $alert['severity']; ?></span>
                            <strong style="font-size:0.8rem; color:#1e293b;"><?php echo htmlspecialchars($alert['formation_name']); ?></strong>
                        </div>
                        <p style="font-size:0.75rem; color:#334155; margin:0; font-weight:500;"><?php echo htmlspecialchars($alert['message']); ?></p>
                    </div>
                    <a href="?resolve_alert=<?php echo $alert['id']; ?>" class="btn-action-sm btn-req" onclick="return confirm('Resolve this alert?');"><i class="fas fa-check"></i> Resolve</a>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- TAB 3: REQUIREMENTS -->
<div id="requirementsTab" class="tab-content">
    <div class="card" style="border-radius:10px; border:1px solid #e2e8f0; background:#fff;">
        <div class="card-header" style="padding:0.75rem 1rem; border-bottom:1px solid #e2e8f0; font-weight:600; color:#1a5632; display:flex; justify-content:space-between; align-items:center;">
            <h3 style="font-size:0.85rem; margin:0;"><i class="fas fa-clipboard-list"></i> Pending Staffing Requirements</h3>
            <button class="btn-action-sm btn-req" onclick="openNewRequirementModal()"><i class="fas fa-plus"></i> New Requirement</button>
        </div>
        <div style="padding:1rem;">
            <?php if (empty($requirements)): ?>
                <div style="text-align:center; padding:2rem; color:#64748b;">
                    <i class="fas fa-clipboard-list" style="font-size:2rem; display:block; margin-bottom:0.5rem;"></i>
                    <strong style="font-size:0.85rem;">No Pending Requirements</strong>
                    <p style="font-size:0.75rem; margin-top:0.25rem;">Click "New Requirement" to submit a deployment request.</p>
                </div>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table style="width:100%; border-collapse:collapse; font-size:0.775rem;">
                        <thead>
                            <tr style="background:#f8fafc; border-bottom:1px solid #e2e8f0;">
                                <th style="padding:0.5rem 0.7rem; text-align:left;">Formation</th>
                                <th style="padding:0.5rem 0.7rem; text-align:left;">Rank Required</th>
                                <th style="padding:0.5rem 0.7rem; text-align:left;">Qty</th>
                                <th style="padding:0.5rem 0.7rem; text-align:left;">Priority</th>
                                <th style="padding:0.5rem 0.7rem; text-align:left;">Status</th>
                                <th style="padding:0.5rem 0.7rem; text-align:left;">Requested</th>
                                <th style="padding:0.5rem 0.7rem; text-align:left;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($requirements as $req): ?>
                            <tr style="border-bottom:1px solid #f1f5f9;">
                                <td style="padding:0.45rem 0.7rem;"><strong><?php echo htmlspecialchars($req['formation_name']); ?></strong></td>
                                <td style="padding:0.45rem 0.7rem;"><?php echo htmlspecialchars($req['rank_required']); ?></td>
                                <td style="padding:0.45rem 0.7rem;"><?php echo $req['quantity_required']; ?></td>
                                <td style="padding:0.45rem 0.7rem;"><span class="badge-soft badge-warn"><?php echo $req['priority']; ?></span></td>
                                <td style="padding:0.45rem 0.7rem;"><span class="badge-soft badge-warn"><?php echo $req['status']; ?></span></td>
                                <td style="padding:0.45rem 0.7rem;"><?php echo date('d M Y', strtotime($req['requested_at'])); ?></td>
                                <td style="padding:0.45rem 0.7rem;"><a href="?fill_requirement=<?php echo $req['id']; ?>" class="btn-action-sm btn-req" onclick="return confirm('Mark as filled?');"><i class="fas fa-check"></i> Fill</a></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- EDIT FORMATION MODAL -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Edit Formation: <span id="editFormationName"></span></h3>
            <button class="close-modal" onclick="closeModal('editModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <div class="modal-body">
                <input type="hidden" name="formation_id" id="editFormationId">
                <div class="form-group">
                    <label>Authorized Strength</label>
                    <input type="number" name="authorized_strength" id="editAuthStrength" class="form-control" min="0" required>
                </div>
                <div class="form-group">
                    <label>Minimum Strength</label>
                    <input type="number" name="minimum_strength" id="editMinStrength" class="form-control" min="0" required>
                </div>
                <div class="form-group">
                    <label>Critical Vacancy Threshold</label>
                    <input type="number" name="critical_vacancy_threshold" id="editThreshold" class="form-control" min="0" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-action-sm btn-view" onclick="closeModal('editModal')">Cancel</button>
                <button type="submit" name="update_formation" class="btn-action-sm btn-req">Update Formation</button>
            </div>
        </form>
    </div>
</div>

<!-- REQUIREMENT MODAL -->
<div id="requirementModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Submit Requirement: <span id="reqFormationName"></span></h3>
            <button class="close-modal" onclick="closeModal('requirementModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <div class="modal-body">
                <input type="hidden" name="formation_id" id="reqFormationId">
                <div class="form-group" id="formationSelectGroup" style="display:none;">
                    <label>Select Target Formation</label>
                    <select id="reqFormationSelect" class="form-control" onchange="updateReqFormation()">
                        <option value="">Select Formation</option>
                        <?php foreach ($formationData as $af): ?>
                        <option value="<?php echo $af['id']; ?>"><?php echo htmlspecialchars($af['formation_name']); ?> (<?php echo htmlspecialchars($af['parent_zone']); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Rank Required</label>
                    <select name="rank_required" class="form-control" required>
                        <option value="">Select Rank</option>
                        <option>Comptroller General</option><option>Deputy Comptroller General</option><option>Assistant Comptroller General</option>
                        <option>Comptroller</option><option>Deputy Comptroller</option><option>Assistant Comptroller</option>
                        <option>Chief Superintendent</option><option>Superintendent</option><option>Deputy Superintendent</option>
                        <option>Assistant Superintendent 1</option><option>Assistant Superintendent 2</option>
                        <option>Inspector</option><option>Assistant Inspector</option><option>Immigration Assistant</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Quantity Required</label>
                    <input type="number" name="quantity_required" class="form-control" min="1" required>
                </div>
                <div class="form-group">
                    <label>Priority</label>
                    <select name="priority" class="form-control">
                        <option value="Medium">Medium</option>
                        <option value="Critical">Critical</option>
                        <option value="High">High</option>
                        <option value="Low">Low</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Notes</label>
                    <textarea name="notes" class="form-control" rows="3" placeholder="Additional notes..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-action-sm btn-view" onclick="closeModal('requirementModal')">Cancel</button>
                <button type="submit" name="submit_requirement" class="btn-action-sm btn-req">Submit Requirement</button>
            </div>
        </form>
    </div>
</div>

<script>
function switchTab(tabId, btn) {
    document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById(tabId).classList.add('active');
    btn.classList.add('active');
}

function filterFormations() {
    var search = document.getElementById('formationSearch').value.toLowerCase().trim();
    var zone = document.getElementById('zoneFilter').value;
    
    document.querySelectorAll('.zone-section').forEach(section => {
        var sectionZone = section.getAttribute('data-zone');
        var cards = section.querySelectorAll('.formation-card');
        var hasVisibleCard = false;
        
        cards.forEach(card => {
            var name = card.getAttribute('data-name');
            var matchesSearch = !search || name.includes(search);
            var matchesZone = !zone || sectionZone === zone;
            
            if (matchesSearch && matchesZone) {
                card.style.display = 'block';
                hasVisibleCard = true;
            } else {
                card.style.display = 'none';
            }
        });
        
        section.style.display = hasVisibleCard ? 'block' : 'none';
    });
}

function openEditModal(id, name, auth, min, threshold) {
    document.getElementById('editFormationId').value = id;
    document.getElementById('editFormationName').textContent = name;
    document.getElementById('editAuthStrength').value = auth;
    document.getElementById('editMinStrength').value = min;
    document.getElementById('editThreshold').value = threshold;
    document.getElementById('editModal').style.display = 'flex';
}

function openRequirementModal(id, name) {
    document.getElementById('reqFormationId').value = id;
    document.getElementById('reqFormationName').textContent = name;
    document.getElementById('formationSelectGroup').style.display = id ? 'none' : 'block';
    document.getElementById('requirementModal').style.display = 'flex';
}

function openNewRequirementModal() {
    document.getElementById('reqFormationId').value = '';
    document.getElementById('reqFormationName').textContent = 'Select Target Formation';
    document.getElementById('formationSelectGroup').style.display = 'block';
    document.getElementById('requirementModal').style.display = 'flex';
}

function updateReqFormation() {
    var sel = document.getElementById('reqFormationSelect');
    document.getElementById('reqFormationId').value = sel.value;
    document.getElementById('reqFormationName').textContent = sel.options[sel.selectedIndex].text;
}

function closeModal(id) { document.getElementById(id).style.display = 'none'; }
window.onclick = function(e) { if (e.target.classList.contains('modal')) e.target.style.display = 'none'; }
</script>

</div><!-- close main-content -->
</div><!-- close content-wrapper -->
</div><!-- close dashboard-container -->

<?php include 'includes/footer.php'; ?>

</body>
</html>