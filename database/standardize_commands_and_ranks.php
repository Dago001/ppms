<?php
// database/standardize_commands_and_ranks.php
// Production migration script to standardize command locations and rank titles across database tables
require_once __DIR__ . '/../includes/config.php';

try {
    $pdo->beginTransaction();

    echo "=== STANDARDIZING COMMAND LOCATIONS AND RANKS ===\n\n";

    // 1. Command Normalization Dictionary
    $locationMap = [
        'Abia' => 'Abia State Command',
        'Adamawa' => 'Adamawa State Command',
        'Akwa Ibom' => 'Akwa Ibom State Command',
        'Anambra' => 'Anambra State Command',
        'Bauchi' => 'Bauchi State Command',
        'Bayelsa' => 'Bayelsa State Command',
        'Benue' => 'Benue State Command',
        'Borno' => 'Borno State Command',
        'Cross River' => 'Cross River State Command',
        'Delta' => 'Delta State Command',
        'Ebonyi' => 'Ebonyi State Command',
        'Edo' => 'Edo State Command',
        'Ekiti' => 'Ekiti State Command',
        'Enugu' => 'Enugu State Command',
        'FCT' => 'FCT Command',
        'Gombe' => 'Gombe State Command',
        'Imo' => 'Imo State Command',
        'Jigawa' => 'Jigawa State Command',
        'Kaduna' => 'Kaduna State Command',
        'Kano' => 'Kano State Command',
        'Katsina' => 'Katsina State Command',
        'Kebbi' => 'Kebbi State Command',
        'Kogi' => 'Kogi State Command',
        'Kwara' => 'Kwara State Command',
        'Lagos' => 'Lagos State Command',
        'Nasarawa' => 'Nasarawa State Command',
        'Niger' => 'Niger State Command',
        'Ogun' => 'Ogun State Command',
        'Ondo' => 'Ondo State Command',
        'Osun' => 'Osun State Command',
        'Oyo' => 'Oyo State Command',
        'Plateau' => 'Plateau State Command',
        'Rivers' => 'Rivers State Command',
        'Rivers Command' => 'Rivers State Command',
        'Sokoto' => 'Sokoto State Command',
        'Taraba' => 'Taraba State Command',
        'Yobe' => 'Yobe State Command',
        'Zamfara' => 'Zamfara State Command',

        // Airports & Special Commands
        'MMIA' => 'Murtala Muhamed International Airport Command',
        'Murtala Muhamed International Airport' => 'Murtala Muhamed International Airport Command',
        'Jibiya Border Command' => 'Jibia Border Command',
        'Seme Border Patrol' => 'Seme Border Command',
        'Lagos Border Patrol Command' => 'Lagos State Border Patrol Command, Seme',
        'LABPC' => 'Lagos State Border Patrol Command, Seme',
        'Ikoyi Passport Office' => 'Ikoyi Passport Command',
        'Lagos Seaport & Marine Command' => 'Lagos SeaPort and Marine Command',
        'River Marine Command' => 'Rivers Marine Command Onne',
        'Rivers Marine Command' => 'Rivers Marine Command Onne',

        // Zones
        'Zone A Lagos' => 'Zone A HQ Ikeja',
        'Zone B Kaduna' => 'Zone B HQ Kaduna',
        'Zone C Bauchi' => 'Zone C HQ Bauchi',
        'Zone D Minna' => 'Zone D HQ Minna',
        'Zone E Owerri' => 'Zone E HQ Owerri',
        "Zone 'F' Ibadan" => 'Zone F HQ Ibadan',
        'Zone F Ibadan' => 'Zone F HQ Ibadan',
        'Zone G Benin City' => 'Zone G HQ Benin',
        'Zone H Makurdi' => 'Zone H HQ Makurdi',
        'Zone G Headquarters, Benin' => 'Zone G HQ Benin',

        // Headquarters & Directorates
        'Service Headquarters (SHQ)' => 'Service Headquarters',
        'SHQ' => 'Service Headquarters',
        'CGIS OFFICE' => 'CGIS Office',
        'Comptroller General of Immigration Office' => 'CGIS Office',
        'ITSK' => 'Immigration Training School Kano',
        'ICSC' => 'Immigration Command and Staff College',
        'NITSA' => 'Nigeria Immigration Training School Ahoada',
        'NITSOL' => 'Nigeria Immigration Training School Orlu'
    ];

    // 2. Rank Normalization Dictionary
    $rankMap = [
        'ASI 1' => 'Asst. Superintendent 1',
        'ASI-1' => 'Asst. Superintendent 1',
        'ASI I' => 'Asst. Superintendent 1',
        "Asst. Superintendent 1\t" => 'Asst. Superintendent 1',

        'ASI 2' => 'Asst. Superintendent 2',
        'ASI-2' => 'Asst. Superintendent 2',
        'ASI II' => 'Asst. Superintendent 2',
        "Asst. Superintendent 2\t" => 'Asst. Superintendent 2',

        'IA2' => 'Immigration Asst. 2',
        'IA-2' => 'Immigration Asst. 2',
        'IA 2' => 'Immigration Asst. 2',

        'IA1' => 'Immigration Asst. 1',
        'IA-1' => 'Immigration Asst. 1',
        'IA 1' => 'Immigration Asst. 1',

        'IA3' => 'Immigration Asst. 3',
        'IA-3' => 'Immigration Asst. 3',
        'IA 3' => 'Immigration Asst. 3',

        'Deputy Superindent' => 'Deputy Superintendent',
        "Deputy Superintendent\t" => 'Deputy Superintendent',
        'Deputy Superintendent of Immigration' => 'Deputy Superintendent',
        'DSI' => 'Deputy Superintendent',

        'Superintendent of Immigration' => 'Superintendent',
        'SI' => 'Superintendent',

        "Assistant Inspector\t" => 'Assistant Inspector',
        "Chief Superintendent\t" => 'Chief Superintendent',
        "Deputy Comptroller\t" => 'Deputy Comptroller'
    ];

    // Update tbl_employment.presentPosting
    $updatedLocs = 0;
    $stmtUpdateLoc = $pdo->prepare("UPDATE tbl_employment SET presentPosting = ? WHERE presentPosting = ?");
    foreach ($locationMap as $oldLoc => $newLoc) {
        $stmtUpdateLoc->execute([$newLoc, $oldLoc]);
        $updatedLocs += $stmtUpdateLoc->rowCount();
    }
    echo "Updated {$updatedLocs} location records in tbl_employment.presentPosting.\n";

    // Update posting_history.posting_location
    $updatedPhLocs = 0;
    $stmtUpdatePh = $pdo->prepare("UPDATE posting_history SET posting_location = ? WHERE posting_location = ?");
    foreach ($locationMap as $oldLoc => $newLoc) {
        $stmtUpdatePh->execute([$newLoc, $oldLoc]);
        $updatedPhLocs += $stmtUpdatePh->rowCount();
    }
    echo "Updated {$updatedPhLocs} location records in posting_history.posting_location.\n";

    // Update user_zones.assigned_command
    $updatedUzCmds = 0;
    $stmtUpdateUz = $pdo->prepare("UPDATE user_zones SET assigned_command = ? WHERE assigned_command = ?");
    foreach ($locationMap as $oldLoc => $newLoc) {
        $stmtUpdateUz->execute([$newLoc, $oldLoc]);
        $updatedUzCmds += $stmtUpdateUz->rowCount();
    }
    echo "Updated {$updatedUzCmds} assigned commands in user_zones.\n";

    // Update tbl_employment.currentRank
    $updatedRanks = 0;
    $stmtUpdateRank = $pdo->prepare("UPDATE tbl_employment SET currentRank = ? WHERE currentRank = ?");
    foreach ($rankMap as $oldRank => $newRank) {
        $stmtUpdateRank->execute([$newRank, $oldRank]);
        $updatedRanks += $stmtUpdateRank->rowCount();
    }
    echo "Updated {$updatedRanks} rank records in tbl_employment.currentRank.\n";

    // Trim trailing whitespace/tabs from all ranks & locations in tbl_employment
    $pdo->exec("UPDATE tbl_employment SET currentRank = TRIM(BOTH '\t' FROM TRIM(currentRank)), presentPosting = TRIM(BOTH '\t' FROM TRIM(presentPosting))");

    $pdo->commit();
    echo "\n=== DATA STANDARDIZATION MIGRATION COMPLETED SUCCESSFULLY ===\n\n";

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "ERROR during data standardization: " . $e->getMessage() . "\n";
}
