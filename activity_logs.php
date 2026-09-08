<?php
session_start();
require_once 'includes/config.php';
require_once 'includes/auth.php';
require_once 'includes/permissions.php';

if (!isLoggedIn()) {
    header('Location: login');
    exit();
}

$userRole = $_SESSION['role_name'] ?? '';
$isAdmin = in_array(strtolower($userRole), ['admin', 'service hq', 'shq admin', 'super admin', 'user']);

// --------------------------------------------
// AUDIT LOG FILTERS
// --------------------------------------------
$searchQuery  = trim($_GET['q'] ?? '');
$actionFilter = trim($_GET['action_type'] ?? '');
$dateFrom     = trim($_GET['date_from'] ?? '');
$dateTo       = trim($_GET['date_to'] ?? '');

$filterSQL = " WHERE 1=1";
$filterParams = [];

if (!empty($searchQuery)) {
    $filterSQL .= " AND (u.username LIKE ? OR al.action LIKE ? OR al.description LIKE ? OR al.ip_address LIKE ?)";
    $filterParams[] = "%$searchQuery%";
    $filterParams[] = "%$searchQuery%";
    $filterParams[] = "%$searchQuery%";
    $filterParams[] = "%$searchQuery%";
}
if (!empty($actionFilter)) {
    $filterSQL .= " AND al.action = ?";
    $filterParams[] = $actionFilter;
}
if (!empty($dateFrom)) {
    $filterSQL .= " AND DATE(al.created_at) >= ?";
    $filterParams[] = $dateFrom;
}
if (!empty($dateTo)) {
    $filterSQL .= " AND DATE(al.created_at) <= ?";
    $filterParams[] = $dateTo;
}

// --------------------------------------------
// EXPORT AUDIT TRAIL TO CSV
// --------------------------------------------
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=Audit_Trail_Log_' . date('Y-m-d') . '.csv');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Log ID', 'Timestamp', 'User', 'Action Type', 'Description / Details', 'IP Address']);
    
    try {
        $exportStmt = $pdo->prepare("
            SELECT al.id, al.created_at, u.username, al.action, al.description, al.ip_address 
            FROM activity_logs al
            LEFT JOIN users u ON al.user_id = u.id
            $filterSQL
            ORDER BY al.created_at DESC
        ");
        $exportStmt->execute($filterParams);
        
        while ($row = $exportStmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, [
                $row['id'],
                date('Y-m-d H:i:s', strtotime($row['created_at'])),
                $row['username'] ?? 'System',
                $row['action'],
                $row['description'] ?? '',
                $row['ip_address'] ?? 'N/A'
            ]);
        }
    } catch (Exception $e) {}
    fclose($output);
    exit();
}

// Fetch Action Dropdown Options
$actionTypes = [];
try {
    $actionTypes = $pdo->query("SELECT DISTINCT action FROM activity_logs WHERE action IS NOT NULL AND action != '' ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

// Pagination
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 25;
$offset = ($page - 1) * $perPage;

$totalCount = 0;
$activities = [];

try {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM activity_logs al LEFT JOIN users u ON al.user_id = u.id $filterSQL");
    $countStmt->execute($filterParams);
    $totalCount = $countStmt->fetchColumn();
    $totalPages = max(1, ceil($totalCount / $perPage));

    $sql = "
        SELECT al.*, u.username, u.full_name 
        FROM activity_logs al
        LEFT JOIN users u ON al.user_id = u.id
        $filterSQL
        ORDER BY al.created_at DESC
        LIMIT $perPage OFFSET $offset
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($filterParams);
    $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error = "Database Error: " . $e->getMessage();
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

    .filter-card {
        background: #ffffff;
        border-radius: 10px;
        border: 1px solid #e2e8f0;
        padding: 0.85rem 1.15rem;
        margin-bottom: 1.15rem;
    }
    .filter-grid {
        display: flex;
        gap: 0.55rem;
        flex-wrap: wrap;
        align-items: center;
    }
    .filter-grid input, .filter-grid select {
        padding: 0.45rem 0.65rem;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        font-size: 0.8rem;
        outline: none;
        background: #ffffff;
        color: #334155;
    }
    .filter-grid input:focus, .filter-grid select:focus { border-color: #1a5632; }

    .btn-apply {
        background: #1a5632;
        color: #ffffff;
        border: none;
        padding: 0.45rem 0.85rem;
        border-radius: 6px;
        font-size: 0.8rem;
        font-weight: 500;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
    }
    .btn-apply:hover { background: #154628; }
    .btn-reset {
        background: #f1f5f9;
        color: #475569;
        border: 1px solid #cbd5e1;
        padding: 0.45rem 0.85rem;
        border-radius: 6px;
        font-size: 0.8rem;
        font-weight: 500;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
    }
    .btn-reset:hover { background: #e2e8f0; }

    .card {
        background: #ffffff;
        border-radius: 10px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        border: 1px solid #e2e8f0;
        margin-bottom: 1.25rem;
    }
    .card-header {
        padding: 0.75rem 1.25rem;
        font-weight: 600;
        font-size: 0.85rem;
        border-bottom: 1px solid #e2e8f0;
        color: #1a5632;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 0.5rem;
    }
    .card-header h3 { font-size: 0.85rem; margin: 0; font-weight: 600; color: #1a5632; display: flex; align-items: center; gap: 0.4rem; }

    .table-container { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    table { width: 100%; border-collapse: collapse; font-size: 0.8rem; }
    table th { background: #f8fafc; padding: 0.65rem 0.85rem; text-align: left; font-weight: 600; border-bottom: 1px solid #e2e8f0; color: #475569; white-space: nowrap; }
    table td { padding: 0.65rem 0.85rem; border-bottom: 1px solid #f1f5f9; color: #334155; vertical-align: middle; }
    table tbody tr:hover { background: #f8fafc; }

    .action-badge {
        display: inline-block;
        padding: 0.15rem 0.5rem;
        border-radius: 6px;
        font-size: 0.7rem;
        font-weight: 600;
        background: #eff6ff;
        color: #1d4ed8;
        border: 1px solid #bfdbfe;
    }

    .pagination-wrapper {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.75rem 1.25rem;
        border-top: 1px solid #e2e8f0;
        background: #f8fafc;
        border-radius: 0 0 10px 10px;
        flex-wrap: wrap;
        gap: 0.5rem;
    }
    .pagination-btn {
        padding: 0.35rem 0.75rem;
        border: 1px solid #cbd5e1;
        background: #ffffff;
        color: #475569;
        border-radius: 6px;
        font-size: 0.775rem;
        font-weight: 500;
        text-decoration: none;
    }
    .pagination-btn:hover { background: #f1f5f9; color: #1e293b; }
    .pagination-btn.disabled { opacity: 0.5; pointer-events: none; }
</style>

<div class="page-title">
    <h1><i class="fas fa-user-shield"></i> Security Audit Trail & Activity Logs</h1>
    <a href="activity_logs?export=csv&q=<?php echo urlencode($searchQuery); ?>&action_type=<?php echo urlencode($actionFilter); ?>&date_from=<?php echo urlencode($dateFrom); ?>&date_to=<?php echo urlencode($dateTo); ?>" class="btn-apply" style="background:#0284c7; text-decoration:none;">
        <i class="fas fa-file-csv"></i> Export Audit Log (CSV)
    </a>
</div>

<!-- FILTER CARD -->
<div class="filter-card">
    <form method="GET" action="activity_logs" class="filter-grid">
        <input type="text" name="q" value="<?php echo htmlspecialchars($searchQuery); ?>" placeholder="Search user, action, details, IP..." style="min-width:200px;">
        
        <select name="action_type">
            <option value="">All Action Types</option>
            <?php foreach ($actionTypes as $at): ?>
                <option value="<?php echo htmlspecialchars($at); ?>" <?php echo $actionFilter === $at ? 'selected' : ''; ?>><?php echo htmlspecialchars($at); ?></option>
            <?php endforeach; ?>
        </select>
        
        <input type="date" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>" title="Date From">
        <input type="date" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>" title="Date To">
        
        <button type="submit" class="btn-apply"><i class="fas fa-search"></i> Filter Logs</button>
        <a href="activity_logs" class="btn-reset"><i class="fas fa-undo"></i> Reset</a>
    </form>
</div>

<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-list-alt"></i> Audit Logs (<?php echo number_format($totalCount); ?> Total Entries)</h3>
        <span style="font-size:0.75rem; color:#64748b; font-weight:500;">Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
    </div>
    <div class="card-body" style="padding:0;">
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Date & Time</th>
                        <th>User Account</th>
                        <th>Action Performed</th>
                        <th>Details / Audit Payload</th>
                        <th>IP Address</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($activities)): ?>
                        <tr><td colspan="5" style="text-align:center; padding:2.5rem; color:#94a3b8;">No activity log records found matching your query.</td></tr>
                    <?php else: ?>
                        <?php foreach ($activities as $act): ?>
                        <tr>
                            <td style="white-space:nowrap; font-size:0.75rem; color:#64748b; font-weight:500;">
                                <?php echo date('d M Y, H:i:s', strtotime($act['created_at'])); ?>
                            </td>
                            <td>
                                <strong><?php echo htmlspecialchars($act['full_name'] ?? $act['username'] ?? 'System'); ?></strong>
                                <?php if (!empty($act['username'])): ?>
                                    <span style="font-size:0.7rem; color:#64748b; display:block;">@<?php echo htmlspecialchars($act['username']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="action-badge"><?php echo htmlspecialchars($act['action']); ?></span>
                            </td>
                            <td style="max-width:350px;">
                                <div style="font-size:0.775rem; line-height:1.3; color:#1e293b;">
                                    <?php echo htmlspecialchars($act['description'] ?? $act['details'] ?? 'N/A'); ?>
                                </div>
                            </td>
                            <td style="white-space:nowrap; font-size:0.725rem; color:#64748b;">
                                <code><?php echo htmlspecialchars($act['ip_address'] ?? '127.0.0.1'); ?></code>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <?php if ($totalPages > 1): ?>
        <div class="pagination-wrapper">
            <a href="?page=<?php echo max(1, $page - 1); ?>&q=<?php echo urlencode($searchQuery); ?>&action_type=<?php echo urlencode($actionFilter); ?>&date_from=<?php echo urlencode($dateFrom); ?>&date_to=<?php echo urlencode($dateTo); ?>" class="pagination-btn <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                <i class="fas fa-chevron-left"></i> Previous
            </a>
            
            <span style="font-size:0.75rem; color:#64748b; font-weight:500;">Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
            
            <a href="?page=<?php echo min($totalPages, $page + 1); ?>&q=<?php echo urlencode($searchQuery); ?>&action_type=<?php echo urlencode($actionFilter); ?>&date_from=<?php echo urlencode($dateFrom); ?>&date_to=<?php echo urlencode($dateTo); ?>" class="pagination-btn <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                Next <i class="fas fa-chevron-right"></i>
            </a>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
