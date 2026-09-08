<?php
require_once __DIR__ . '/../includes/config.php';

echo "=== NIS-PPMS High-Performance Database Indexing ===" . PHP_EOL;

$indexes = [
    'tbl_employment' => [
        'idx_emp_serviceno' => 'CREATE INDEX idx_emp_serviceno ON tbl_employment(serviceNo)',
        'idx_emp_surname_firstname' => 'CREATE INDEX idx_emp_surname_firstname ON tbl_employment(surname, firstName)',
        'idx_emp_rank' => 'CREATE INDEX idx_emp_rank ON tbl_employment(currentRank)',
        'idx_emp_presentposting' => 'CREATE INDEX idx_emp_presentposting ON tbl_employment(presentPosting)',
        'idx_emp_dofa_dob' => 'CREATE INDEX idx_emp_dofa_dob ON tbl_employment(dofa, dob)'
    ],
    'tbl_emppersonal' => [
        'idx_emppersonal_serviceno' => 'CREATE INDEX idx_emppersonal_serviceno ON tbl_emppersonal(serviceNo)',
        'idx_emppersonal_name' => 'CREATE INDEX idx_emppersonal_name ON tbl_emppersonal(surname, firstName)'
    ],
    'current_posting' => [
        'idx_currpost_serviceno' => 'CREATE INDEX idx_currpost_serviceno ON current_posting(serviceNo)',
        'idx_currpost_loc' => 'CREATE INDEX idx_currpost_loc ON current_posting(posting_location)',
        'idx_currpost_updated' => 'CREATE INDEX idx_currpost_updated ON current_posting(updated_at)'
    ],
    'posting_history' => [
        'idx_posthist_serviceno' => 'CREATE INDEX idx_posthist_serviceno ON posting_history(serviceNo, posting_date DESC)',
        'idx_posthist_loc' => 'CREATE INDEX idx_posthist_loc ON posting_history(posting_location)',
        'idx_posthist_type' => 'CREATE INDEX idx_posthist_type ON posting_history(posting_type)'
    ],
    'post_order_documents' => [
        'idx_docs_serviceno' => 'CREATE INDEX idx_docs_serviceno ON post_order_documents(serviceNo)',
        'idx_docs_uploaded' => 'CREATE INDEX idx_docs_uploaded ON post_order_documents(uploaded_at)'
    ],
    'bulk_posting_batches' => [
        'idx_batches_created' => 'CREATE INDEX idx_batches_created ON bulk_posting_batches(created_at DESC)',
        'idx_batches_status' => 'CREATE INDEX idx_batches_status ON bulk_posting_batches(status)'
    ],
    'bulk_posting_details' => [
        'idx_batch_details_batch_serviceno' => 'CREATE INDEX idx_batch_details_batch_serviceno ON bulk_posting_details(batch_id, serviceNo)'
    ]
];

foreach ($indexes as $table => $tableIndexes) {
    echo "Processing table `$table`..." . PHP_EOL;
    // Check existing indexes
    $existingIndexes = [];
    try {
        $stmt = $pdo->query("SHOW INDEX FROM `$table`");
        while ($row = $stmt->fetch()) {
            $existingIndexes[$row['Key_name']] = true;
        }
    } catch (PDOException $e) {
        echo "   Table `$table` check skipped: " . $e->getMessage() . PHP_EOL;
        continue;
    }

    foreach ($tableIndexes as $indexName => $sql) {
        if (isset($existingIndexes[$indexName])) {
            echo "   Index `$indexName` already exists. Skipping." . PHP_EOL;
        } else {
            try {
                $pdo->exec($sql);
                echo "   [SUCCESS] Created index `$indexName`." . PHP_EOL;
            } catch (PDOException $e) {
                echo "   [INFO] Could not create `$indexName`: " . $e->getMessage() . PHP_EOL;
            }
        }
    }
}

echo "=== Indexing Complete ===" . PHP_EOL;
