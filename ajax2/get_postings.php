<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    echo '<div style="color: red; text-align: center; padding: 2rem;">Please login</div>';
    exit();
}

$commandId = intval($_POST['command_id'] ?? 0);

if ($commandId <= 0) {
    echo '<div style="text-align: center; padding: 2rem; color: #94a3b8;">No command selected</div>';
    exit();
}

// Get command info
$stmt = $pdo->prepare("
    SELECT c.*, z.name as zone_name 
    FROM commands c 
    LEFT JOIN zones z ON c.zone_id = z.id 
    WHERE c.id = ?
");
$stmt->execute([$commandId]);
$command = $stmt->fetch();

if (!$command) {
    echo '<div style="color: red; text-align: center;">Command not found</div>';
    exit();
}

// Verify access
$userRole = getUserRole($_SESSION['user_id']);
$hasAccess = false;

if (in_array($userRole['name'], ['admin', 'Service HQ'])) {
    $hasAccess = true;
} else {
    $hasAccess = canPostToCommand($_SESSION['user_id'], $commandId);
}

if (!$hasAccess) {
    echo '<div style="color: red; text-align: center; padding: 2rem;">You are not authorized to view this command</div>';
    exit();
}

// Get officers
$officers = getCommandOfficers($commandId);

// Output
?>
<div style="margin-bottom: 1rem; padding: 0.75rem; background: #f8fafc; border-radius: 6px; display: flex; justify-content: space-between; align-items: center;">
    <div>
        <strong><?php echo htmlspecialchars($command['name']); ?></strong>
        <?php if ($command['zone_name']): ?>
            <span style="color: #64748b;"> | <?php echo htmlspecialchars($command['zone_name']); ?></span>
        <?php endif; ?>
    </div>
    <span class="badge badge-success"><?php echo count($officers); ?> Officers</span>
</div>

<?php if (empty($officers)): ?>
    <div style="text-align: center; padding: 2rem; color: #94a3b8;">
        <i class="fas fa-user-slash" style="font-size: 2rem; display: block; margin-bottom: 0.5rem;"></i>
        No officers currently posted to this command
    </div>
<?php else: ?>
    <div style="overflow-x: auto;">
        <table class="postings-table">
            <thead>
                <tr>
                    <th>S/N</th>
                    <th>Service No</th>
                    <th>Surname</th>
                    <th>First Name</th>
                    <th>Middle Name</th>
                    <th>Rank</th>
                    <th>Gender</th>
                    <th>Date Posted</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($officers as $i => $o): ?>
                <tr>
                    <td><?php echo $i + 1; ?></td>
                    <td><strong><?php echo htmlspecialchars($o['service_number']); ?></strong></td>
                    <td><?php echo htmlspecialchars($o['surname']); ?></td>
                    <td><?php echo htmlspecialchars($o['firstname']); ?></td>
                    <td><?php echo htmlspecialchars($o['middlename'] ?? '-'); ?></td>
                    <td><span class="badge badge-primary"><?php echo htmlspecialchars($o['rank']); ?></span></td>
                    <td><?php echo $o['gender']; ?></td>
                    <td><?php echo date('d M Y', strtotime($o['date_of_posting'])); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>