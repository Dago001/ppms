<?php
require_once 'includes/auth.php';
require_once 'includes/permissions.php';
require_once 'includes/config.php';

if (!isLoggedIn()) {
    header('Location: login');
    exit();
}

// Clear any previous output
if (ob_get_level()) ob_end_clean();

// Set headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=personnel_records_' . date('Y-m-d') . '.csv');

try {
    // Query to match "All Personnel Records"
    $query = "
        SELECT 
            p.id AS 'ID',
            p.service_number AS 'Service Number',
            p.rank AS 'Rank',
            p.surname AS 'Surname',
            p.other_names AS 'Other Names',
            DATE_FORMAT(p.date_of_birth, '%d-%m-%Y') AS 'Date of Birth',
            p.gender AS 'Gender',
            p.marital_status AS 'Marital Status',
            DATE_FORMAT(p.date_of_first_appointment, '%d-%m-%Y') AS 'First Appointment Date',
            DATE_FORMAT(po.date_of_posting, '%d-%m-%Y') AS 'Posting Date',
            COALESCE(po.posting_status, 'Not Posted') AS 'Posting Status',
            COALESCE(f.formation_name, 'Not Assigned') AS 'Formation',
            COALESCE(f.formation_type, 'Not Assigned') AS 'Formation Type',
            COALESCE(z.name, 'Not Assigned') AS 'Zone'
        FROM personnel p
        LEFT JOIN postings po ON p.id = po.personnel_id
        LEFT JOIN formations f ON po.formation_id = f.id
        LEFT JOIN zones z ON f.zone = z.name
        ORDER BY p.surname, p.other_names
    ";

    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($records)) {
        $output = fopen('php://output', 'w');

        // Add BOM for Excel UTF-8 compatibility
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

        // Write title and date
        fputcsv($output, ['PERSONNEL RECORDS']);
        fputcsv($output, ['Generated on: ' . date('d-m-Y')]);
        fputcsv($output, []); // Empty line

        // Write headers
        fputcsv($output, array_keys($records[0]));

        // Write data rows
        foreach ($records as $row) {
            $row = array_map(function($value) {
                return $value === null ? 'N/A' : $value;
            }, $row);
            fputcsv($output, $row);
        }

        fclose($output);

        // Log the export activity
        $log = $pdo->prepare("INSERT INTO activity_logs (user_id, activity_type, description) VALUES (?, 'EXPORT', 'Exported personnel records to CSV')");
        $log->execute([$_SESSION['user_id']]);
    } else {
        header('Content-Type: text/plain');
        echo "No records found to export.";
    }
} catch (Exception $e) {
    header('Content-Type: text/plain');
    echo "Error: " . $e->getMessage();
    exit;
}
?>
