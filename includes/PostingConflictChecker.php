<?php
/**
 * NIS-PPMS Posting Conflict & Frequency Rule Engine
 * Analyzes officer eligibility before initiating single or bulk posting orders.
 */

if (!class_exists('PostingConflictChecker')) {
    class PostingConflictChecker {
        /**
         * Evaluate all posting conflict rules for an officer
         * @param PDO $pdo
         * @param string $serviceNo
         * @return array Rules evaluation result with flags, details, and warnings
         */
        public static function evaluate($pdo, $serviceNo) {
            $warnings = [];
            $hasConflict = false;
            $conflicts = [];

            $serviceNo = trim($serviceNo);
            if (empty($serviceNo)) {
                return [
                    'valid' => false,
                    'has_conflict' => true,
                    'warnings' => ['Service number is required.'],
                    'conflicts' => ['empty_service_no']
                ];
            }

            // 1. Fetch Officer Employment & Personal Information
            $stmt = $pdo->prepare("
                SELECT e.serviceNo, e.dob, e.dopa, e.presentLocation, p.surname, p.otherNames 
                FROM tbl_employment e 
                LEFT JOIN tbl_emppersonal p ON e.serviceNo = p.serviceNo 
                WHERE e.serviceNo = ?
            ");
            $stmt->execute([$serviceNo]);
            $officer = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$officer) {
                return [
                    'valid' => false,
                    'has_conflict' => true,
                    'warnings' => ["Officer with Service No '$serviceNo' does not exist in the database."],
                    'conflicts' => ['not_found']
                ];
            }

            $officerName = trim(($officer['surname'] ?? '') . ' ' . ($officer['otherNames'] ?? ''));

            // -------------------------------------------------------------
            // RULE 1: RECENT POSTING FREQUENCY CHECK (Within last 6-12 Months)
            // -------------------------------------------------------------
            try {
                $freqStmt = $pdo->prepare("
                    SELECT posting_date, created_at, status 
                    FROM posting_notifications 
                    WHERE serviceNo = ? AND posting_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                    ORDER BY posting_date DESC LIMIT 1
                ");
                $freqStmt->execute([$serviceNo]);
                $recentPosting = $freqStmt->fetch(PDO::FETCH_ASSOC);

                if ($recentPosting) {
                    $postingDate = !empty($recentPosting['posting_date']) ? $recentPosting['posting_date'] : $recentPosting['created_at'];
                    $monthsAgo = floor((time() - strtotime($postingDate)) / (30 * 24 * 3600));
                    
                    $hasConflict = true;
                    $conflicts[] = 'recent_posting';
                    $warnings[] = "Frequent Posting Notice: Officer was previously posted {$monthsAgo} month(s) ago (Date: " . date('d M Y', strtotime($postingDate)) . ").";
                }
            } catch (Exception $e) {}

            // -------------------------------------------------------------
            // RULE 2: PENDING / UNREPORTED POSTING ORDER CHECK
            // -------------------------------------------------------------
            try {
                $pendingStmt = $pdo->prepare("
                    SELECT id, status, posting_date, created_at 
                    FROM posting_notifications 
                    WHERE serviceNo = ? AND status IN ('pending', 'not_reported', 'reported')
                    ORDER BY id DESC LIMIT 1
                ");
                $pendingStmt->execute([$serviceNo]);
                $pendingOrder = $pendingStmt->fetch(PDO::FETCH_ASSOC);

                if ($pendingOrder) {
                    $hasConflict = true;
                    $conflicts[] = 'pending_order';
                    $warnings[] = "Active Posting Conflict: Officer currently has an unfulfilled posting order (Status: " . strtoupper($pendingOrder['status']) . ").";
                }
            } catch (Exception $e) {}

            // -------------------------------------------------------------
            // RULE 3: STATUTORY RETIREMENT WATCHLIST CHECK (Within 12 Months)
            // -------------------------------------------------------------
            $dob = !empty($officer['dob']) ? $officer['dob'] : null;
            $dopa = !empty($officer['dopa']) ? $officer['dopa'] : null;

            $ageYears = 0;
            $serviceYears = 0;

            if ($dob && $dob != '0000-00-00') {
                $ageYears = (new DateTime($dob))->diff(new DateTime())->y;
            }
            if ($dopa && $dopa != '0000-00-00') {
                $serviceYears = (new DateTime($dopa))->diff(new DateTime())->y;
            }

            // Retirement threshold: Age >= 59 or Service Years >= 34 (Within 12 months of statutory 60 / 35 limit)
            if ($ageYears >= 59 || $serviceYears >= 34) {
                $hasConflict = true;
                $conflicts[] = 'near_retirement';
                $retireReason = [];
                if ($ageYears >= 59) $retireReason[] = "Age: {$ageYears} yrs";
                if ($serviceYears >= 34) $retireReason[] = "Service: {$serviceYears} yrs";
                
                $warnings[] = "Retirement Watchlist Warning: Officer is within 12 months of statutory retirement (" . implode(', ', $retireReason) . ").";
            }

            return [
                'valid' => true,
                'serviceNo' => $serviceNo,
                'officer_name' => $officerName,
                'present_location' => $officer['presentLocation'] ?? 'N/A',
                'has_conflict' => $hasConflict,
                'conflicts' => $conflicts,
                'warnings' => $warnings,
                'age_years' => $ageYears,
                'service_years' => $serviceYears
            ];
        }
    }
}
