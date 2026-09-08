<?php
function getDashboardAnalytics($pdo) {
    if (class_exists('CacheManager')) {
        $cachedData = CacheManager::get('dashboard_analytics', 300);
        if ($cachedData !== null) {
            return $cachedData;
        }
    }

    $analytics = [
        'summary' => [
            'total_personnel' => 0,
            'total_users' => 0,
            'total_active_postings' => 0
        ],
        'charts' => [
            'rank_distribution' => [],
            'gender_distribution' => [],
            'regional_distribution' => [],
            'command_distribution' => [],
            'department_distribution' => [],
            'age_distribution' => [],
            'service_years' => [],
            'present_posting_distribution' => [] // UPDATED: Changed from location_distribution
        ],
        'recent_activities' => []
    ];

    try {
        // Check what tables exist
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        
        // Determine personnel table name
        $personnelTable = null;
        $personnelTables = ['tbl_emppersonal', 'personnel', 'employees', 'staff'];
        foreach ($personnelTables as $table) {
            if (in_array($table, $tables)) {
                $personnelTable = $table;
                break;
            }
        }
        
        if (!$personnelTable) {
            return $analytics; // Return empty analytics if no personnel table
        }
        
        // Get columns in personnel table
        $columns = $pdo->query("SHOW COLUMNS FROM $personnelTable")->fetchAll(PDO::FETCH_COLUMN);

        // Get summary statistics
        $analytics['summary']['total_personnel'] = $pdo->query("
            SELECT COUNT(*) as count FROM $personnelTable
        ")->fetch()['count'] ?? 0;
        
        $analytics['summary']['total_users'] = $pdo->query("
            SELECT COUNT(*) as count FROM users
        ")->fetch()['count'] ?? 0;

        // Helper function to get column name
        function getColumnName($possibleNames, $columns) {
            foreach ($possibleNames as $name) {
                if (in_array($name, $columns)) {
                    return $name;
                }
            }
            return null;
        }

        // Rank distribution
        $rankColumn = getColumnName(['currentRank', 'rank', 'Rank', 'current_rank'], $columns);
        if ($rankColumn) {
            $analytics['charts']['rank_distribution'] = $pdo->query("
                SELECT 
                    COALESCE(NULLIF(TRIM($rankColumn), ''), 'Not Specified') as label,
                    COUNT(*) as value
                FROM $personnelTable 
                WHERE $rankColumn IS NOT NULL
                GROUP BY COALESCE(NULLIF(TRIM($rankColumn), ''), 'Not Specified')
                ORDER BY value DESC
                LIMIT 15
            ")->fetchAll(PDO::FETCH_ASSOC);
        }

        // Gender distribution
        $genderColumn = getColumnName(['gender', 'sex', 'Gender', 'Sex'], $columns);
        if ($genderColumn) {
            $analytics['charts']['gender_distribution'] = $pdo->query("
                SELECT 
                    COALESCE(NULLIF(TRIM($genderColumn), ''), 'Not Specified') as label,
                    COUNT(*) as value
                FROM $personnelTable 
                GROUP BY COALESCE(NULLIF(TRIM($genderColumn), ''), 'Not Specified')
                ORDER BY value DESC
            ")->fetchAll(PDO::FETCH_ASSOC);
        }

        // Regional distribution
        $regionColumn = getColumnName(['state_of_origin', 'state', 'State', 'region', 'Region'], $columns);
        if ($regionColumn) {
            $analytics['charts']['regional_distribution'] = $pdo->query("
                SELECT 
                    COALESCE(NULLIF(TRIM($regionColumn), ''), 'Not Specified') as label,
                    COUNT(*) as value
                FROM $personnelTable
                GROUP BY COALESCE(NULLIF(TRIM($regionColumn), ''), 'Not Specified')
                ORDER BY value DESC
                LIMIT 10
            ")->fetchAll(PDO::FETCH_ASSOC);
        }

        // Command distribution
        $commandColumn = getColumnName(['command', 'Command', 'command_name', 'formation'], $columns);
        if ($commandColumn) {
            $analytics['charts']['command_distribution'] = $pdo->query("
                SELECT 
                    COALESCE(NULLIF(TRIM($commandColumn), ''), 'Not Specified') as label,
                    COUNT(*) as value
                FROM $personnelTable
                GROUP BY COALESCE(NULLIF(TRIM($commandColumn), ''), 'Not Specified')
                ORDER BY value DESC
                LIMIT 10
            ")->fetchAll(PDO::FETCH_ASSOC);
        }

        // Department distribution
        $deptColumn = getColumnName(['department', 'Department', 'dept', 'Dept'], $columns);
        if ($deptColumn) {
            $analytics['charts']['department_distribution'] = $pdo->query("
                SELECT 
                    COALESCE(NULLIF(TRIM($deptColumn), ''), 'Not Specified') as label,
                    COUNT(*) as value
                FROM $personnelTable
                GROUP BY COALESCE(NULLIF(TRIM($deptColumn), ''), 'Not Specified')
                ORDER BY value DESC
                LIMIT 8
            ")->fetchAll(PDO::FETCH_ASSOC);
        }

        // Age distribution
        if (in_array('dateOfBirth', $columns)) {
            $analytics['charts']['age_distribution'] = $pdo->query("
                SELECT 
                    CASE 
                        WHEN dateOfBirth IS NULL OR dateOfBirth = '0000-00-00' OR dateOfBirth = '' THEN 'Not Specified'
                        WHEN YEAR(CURDATE()) - YEAR(dateOfBirth) < 20 THEN 'Under 20'
                        WHEN YEAR(CURDATE()) - YEAR(dateOfBirth) BETWEEN 20 AND 29 THEN '20-29'
                        WHEN YEAR(CURDATE()) - YEAR(dateOfBirth) BETWEEN 30 AND 39 THEN '30-39'
                        WHEN YEAR(CURDATE()) - YEAR(dateOfBirth) BETWEEN 40 AND 49 THEN '40-49'
                        WHEN YEAR(CURDATE()) - YEAR(dateOfBirth) >= 50 THEN '50+'
                        ELSE 'Not Specified'
                    END as label,
                    COUNT(*) as value
                FROM $personnelTable 
                GROUP BY 
                    CASE 
                        WHEN dateOfBirth IS NULL OR dateOfBirth = '0000-00-00' OR dateOfBirth = '' THEN 'Not Specified'
                        WHEN YEAR(CURDATE()) - YEAR(dateOfBirth) < 20 THEN 'Under 20'
                        WHEN YEAR(CURDATE()) - YEAR(dateOfBirth) BETWEEN 20 AND 29 THEN '20-29'
                        WHEN YEAR(CURDATE()) - YEAR(dateOfBirth) BETWEEN 30 AND 39 THEN '30-39'
                        WHEN YEAR(CURDATE()) - YEAR(dateOfBirth) BETWEEN 40 AND 49 THEN '40-49'
                        WHEN YEAR(CURDATE()) - YEAR(dateOfBirth) >= 50 THEN '50+'
                        ELSE 'Not Specified'
                    END
                ORDER BY 
                    CASE 
                        WHEN label = 'Under 20' THEN 1
                        WHEN label = '20-29' THEN 2
                        WHEN label = '30-39' THEN 3
                        WHEN label = '40-49' THEN 4
                        WHEN label = '50+' THEN 5
                        ELSE 6
                    END
            ")->fetchAll(PDO::FETCH_ASSOC);
        }

        // Service years distribution
        $serviceYearColumn = getColumnName(['yearOfEngagement', 'year_of_engagement', 'service_year', 'engagement_year'], $columns);
        if ($serviceYearColumn) {
            $analytics['charts']['service_years'] = $pdo->query("
                SELECT 
                    CASE 
                        WHEN $serviceYearColumn IS NULL OR $serviceYearColumn = 0 OR $serviceYearColumn = '' THEN 'Not Specified'
                        WHEN YEAR(CURDATE()) - $serviceYearColumn < 5 THEN '0-4 years'
                        WHEN YEAR(CURDATE()) - $serviceYearColumn BETWEEN 5 AND 9 THEN '5-9 years'
                        WHEN YEAR(CURDATE()) - $serviceYearColumn BETWEEN 10 AND 14 THEN '10-14 years'
                        WHEN YEAR(CURDATE()) - $serviceYearColumn BETWEEN 15 AND 19 THEN '15-19 years'
                        WHEN YEAR(CURDATE()) - $serviceYearColumn >= 20 THEN '20+ years'
                        ELSE 'Not Specified'
                    END as label,
                    COUNT(*) as value
                FROM $personnelTable 
                GROUP BY 
                    CASE 
                        WHEN $serviceYearColumn IS NULL OR $serviceYearColumn = 0 OR $serviceYearColumn = '' THEN 'Not Specified'
                        WHEN YEAR(CURDATE()) - $serviceYearColumn < 5 THEN '0-4 years'
                        WHEN YEAR(CURDATE()) - $serviceYearColumn BETWEEN 5 AND 9 THEN '5-9 years'
                        WHEN YEAR(CURDATE()) - $serviceYearColumn BETWEEN 10 AND 14 THEN '10-14 years'
                        WHEN YEAR(CURDATE()) - $serviceYearColumn BETWEEN 15 AND 19 THEN '15-19 years'
                        WHEN YEAR(CURDATE()) - $serviceYearColumn >= 20 THEN '20+ years'
                        ELSE 'Not Specified'
                    END
                ORDER BY 
                    CASE 
                        WHEN label = '0-4 years' THEN 1
                        WHEN label = '5-9 years' THEN 2
                        WHEN label = '10-14 years' THEN 3
                        WHEN label = '15-19 years' THEN 4
                        WHEN label = '20+ years' THEN 5
                        ELSE 6
                    END
            ")->fetchAll(PDO::FETCH_ASSOC);
        }

        // UPDATED: Present Posting Distribution from tbl_employment table
        if (in_array('tbl_employment', $tables)) {
            // Check for presentPosting column in tbl_employment
            $employmentColumns = $pdo->query("SHOW COLUMNS FROM tbl_employment")->fetchAll(PDO::FETCH_COLUMN);
            
            if (in_array('presentPosting', $employmentColumns)) {
                $analytics['charts']['present_posting_distribution'] = $pdo->query("
                    SELECT 
                        COALESCE(NULLIF(TRIM(presentPosting), ''), 'Not Specified') as label,
                        COUNT(*) as value
                    FROM tbl_employment
                    WHERE presentPosting IS NOT NULL
                    GROUP BY COALESCE(NULLIF(TRIM(presentPosting), ''), 'Not Specified')
                    ORDER BY value DESC
                    LIMIT 10
                ")->fetchAll(PDO::FETCH_ASSOC);
            } else {
                // Fallback to other posting/location columns
                $possiblePostingCols = ['posting', 'location', 'station', 'duty_station', 'current_posting'];
                $postingColumn = null;
                
                foreach ($possiblePostingCols as $col) {
                    if (in_array($col, $employmentColumns)) {
                        $postingColumn = $col;
                        break;
                    }
                }
                
                if ($postingColumn) {
                    $analytics['charts']['present_posting_distribution'] = $pdo->query("
                        SELECT 
                            COALESCE(NULLIF(TRIM($postingColumn), ''), 'Not Specified') as label,
                            COUNT(*) as value
                        FROM tbl_employment
                        WHERE $postingColumn IS NOT NULL
                        GROUP BY COALESCE(NULLIF(TRIM($postingColumn), ''), 'Not Specified')
                        ORDER BY value DESC
                        LIMIT 10
                    ")->fetchAll(PDO::FETCH_ASSOC);
                }
            }
        }

        // Get recent activities
        if (in_array('activity_logs', $tables)) {
            $analytics['recent_activities'] = $pdo->query("
                SELECT al.*, u.username 
                FROM activity_logs al
                JOIN users u ON al.user_id = u.id
                ORDER BY al.created_at DESC
                LIMIT 10
            ")->fetchAll(PDO::FETCH_ASSOC);
        }

    } catch (PDOException $e) {
        error_log("Dashboard Analytics Error: " . $e->getMessage());
        // Return empty analytics instead of throwing exception
        return $analytics;
    }

    if (class_exists('CacheManager')) {
        CacheManager::set('dashboard_analytics', $analytics);
    }
    return $analytics;
}