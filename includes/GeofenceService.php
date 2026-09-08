<?php
/**
 * NIS-PPMS Geofence Service
 * Core mathematical boundary engine using Haversine Spherical Distance Calculation
 */

if (!class_exists('GeofenceService')) {
    class GeofenceService {

        /**
         * Calculate spherical distance in meters between two GPS coordinates using Haversine Formula
         */
        public static function calculateDistanceMeters($lat1, $lng1, $lat2, $lng2) {
            $lat1 = (float)$lat1;
            $lng1 = (float)$lng1;
            $lat2 = (float)$lat2;
            $lng2 = (float)$lng2;

            $earthRadius = 6371000; // Earth mean radius in meters

            $dLat = deg2rad($lat2 - $lat1);
            $dLng = deg2rad($lng2 - $lng1);

            $a = sin($dLat / 2) * sin($dLat / 2) +
                 cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
                 sin($dLng / 2) * sin($dLng / 2);

            $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
            return (int)round($earthRadius * $c);
        }

        /**
         * Resolve a user's active geofence target coordinates & allowed radius
         */
        public static function resolveUserGeofenceTarget($pdo, $userId) {
            try {
                $stmt = $pdo->prepare("
                    SELECT u.id, u.username, u.full_name, u.geofence_enabled, u.geofence_mode, 
                           u.custom_lat, u.custom_lng, u.custom_radius_meters, r.name as role_name,
                           (SELECT uz.assigned_command FROM user_zones uz WHERE uz.user_id = u.id LIMIT 1) as assigned_command
                    FROM users u
                    LEFT JOIN roles r ON u.role_id = r.id
                    WHERE u.id = ?
                ");
                $stmt->execute([$userId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$user) {
                    return null;
                }

                // Default Fallback: NIS Service HQ Abuja (Sauka)
                $defaultTarget = [
                    'user_id' => $user['id'],
                    'username' => $user['username'],
                    'full_name' => $user['full_name'],
                    'role_name' => $user['role_name'],
                    'geofence_enabled' => (int)($user['geofence_enabled'] ?? 1),
                    'geofence_mode' => $user['geofence_mode'] ?? 'strict',
                    'target_lat' => 9.07650000,
                    'target_lng' => 7.39860000,
                    'allowed_radius_meters' => 1000,
                    'location_name' => !empty($user['assigned_command']) ? $user['assigned_command'] : 'Service HQ Abuja',
                    'is_custom' => false
                ];

                // 1. Check if user has explicit custom coordinates configured
                if (!empty($user['custom_lat']) && !empty($user['custom_lng'])) {
                    $defaultTarget['target_lat'] = (float)$user['custom_lat'];
                    $defaultTarget['target_lng'] = (float)$user['custom_lng'];
                    $defaultTarget['allowed_radius_meters'] = !empty($user['custom_radius_meters']) ? (int)$user['custom_radius_meters'] : 1000;
                    $defaultTarget['location_name'] = 'Immigration Office (' . $defaultTarget['location_name'] . ')';
                    $defaultTarget['is_custom'] = true;
                    return $defaultTarget;
                }

                // 2. Resolve coordinates from Command / Formation assignment
                if (!empty($user['assigned_command'])) {
                    // Try `formations` table first
                    $fStmt = $pdo->prepare("SELECT name, latitude, longitude, default_radius_meters FROM formations WHERE name = ? OR code = ? LIMIT 1");
                    $fStmt->execute([$user['assigned_command'], $user['assigned_command']]);
                    $formation = $fStmt->fetch(PDO::FETCH_ASSOC);

                    if ($formation && !empty($formation['latitude']) && !empty($formation['longitude'])) {
                        $defaultTarget['target_lat'] = (float)$formation['latitude'];
                        $defaultTarget['target_lng'] = (float)$formation['longitude'];
                        $defaultTarget['allowed_radius_meters'] = !empty($formation['default_radius_meters']) ? (int)$formation['default_radius_meters'] : 1000;
                        $defaultTarget['location_name'] = $formation['name'];
                        return $defaultTarget;
                    }

                    // Try `locations` table fallback
                    $lStmt = $pdo->prepare("SELECT name, latitude, longitude, default_radius_meters FROM locations WHERE name = ? OR code = ? LIMIT 1");
                    $lStmt->execute([$user['assigned_command'], $user['assigned_command']]);
                    $location = $lStmt->fetch(PDO::FETCH_ASSOC);

                    if ($location && !empty($location['latitude']) && !empty($location['longitude'])) {
                        $defaultTarget['target_lat'] = (float)$location['latitude'];
                        $defaultTarget['target_lng'] = (float)$location['longitude'];
                        $defaultTarget['allowed_radius_meters'] = !empty($location['default_radius_meters']) ? (int)$location['default_radius_meters'] : 1000;
                        $defaultTarget['location_name'] = $location['name'];
                        return $defaultTarget;
                    }
                }

                return $defaultTarget;

            } catch (Exception $e) {
                error_log("GeofenceTarget Error: " . $e->getMessage());
                return null;
            }
        }

        /**
         * Verify officer location against assigned boundary
         */
        public static function verifyUserLocation($pdo, $userId, $userLat, $userLng, $eventType = 'login') {
            $target = self::resolveUserGeofenceTarget($pdo, $userId);

            if (!$target) {
                return [
                    'success' => true,
                    'status' => 'PASS',
                    'message' => 'Geofence bypass: User target not found'
                ];
            }

            // If geofence is disabled or mode is 'disabled'
            if ($target['geofence_enabled'] === 0 || $target['geofence_mode'] === 'disabled') {
                self::logGeofenceCheck($pdo, $userId, $target['username'], $eventType, $userLat, $userLng, $target['target_lat'], $target['target_lng'], 0, $target['allowed_radius_meters'], 'PASS');
                return [
                    'success' => true,
                    'status' => 'PASS',
                    'distance_meters' => 0,
                    'allowed_radius' => $target['allowed_radius_meters'],
                    'message' => 'Geofence disabled for account'
                ];
            }

            // Calculate distance in meters
            $distance = self::calculateDistanceMeters($userLat, $userLng, $target['target_lat'], $target['target_lng']);
            $allowedRadius = $target['allowed_radius_meters'];
            $isWithinBoundary = ($distance <= $allowedRadius);

            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'N/A';
            $deviceInfo = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown Device';

            if ($isWithinBoundary) {
                self::logGeofenceCheck($pdo, $userId, $target['username'], $eventType, $userLat, $userLng, $target['target_lat'], $target['target_lng'], $distance, $allowedRadius, 'PASS', $ipAddress, $deviceInfo);
                return [
                    'success' => true,
                    'status' => 'PASS',
                    'distance_meters' => $distance,
                    'allowed_radius' => $allowedRadius,
                    'location_name' => $target['location_name'],
                    'message' => "Officer location verified inside boundary ({$distance}m from {$target['location_name']})"
                ];
            }

            // Handle Out-of-Bounds scenario
            if ($target['geofence_mode'] === 'audit_only') {
                self::logGeofenceCheck($pdo, $userId, $target['username'], $eventType, $userLat, $userLng, $target['target_lat'], $target['target_lng'], $distance, $allowedRadius, 'AUDIT_FLAG', $ipAddress, $deviceInfo);
                
                if (function_exists('logActivity')) {
                    logActivity($pdo, $userId, 'GEOFENCE_WARNING', "Officer accessed from outside geofence boundary ({$distance}m away from {$target['location_name']})");
                }

                return [
                    'success' => true,
                    'status' => 'AUDIT_FLAG',
                    'distance_meters' => $distance,
                    'allowed_radius' => $allowedRadius,
                    'location_name' => $target['location_name'],
                    'message' => "Warning: Access granted in Audit Mode ({$distance}m away from {$target['location_name']})"
                ];
            }

            // Strict Mode: BLOCK ACCESS
            self::logGeofenceCheck($pdo, $userId, $target['username'], $eventType, $userLat, $userLng, $target['target_lat'], $target['target_lng'], $distance, $allowedRadius, 'BLOCKED', $ipAddress, $deviceInfo);
            
            if (function_exists('logActivity')) {
                logActivity($pdo, $userId, 'GEOFENCE_BLOCKED', "Access blocked: Officer is {$distance}m away from assigned {$target['location_name']} (Allowed radius: {$allowedRadius}m)");
            }

            return [
                'success' => false,
                'status' => 'BLOCKED',
                'distance_meters' => $distance,
                'allowed_radius' => $allowedRadius,
                'location_name' => $target['location_name'],
                'message' => "Access Denied: Your current location is {$distance}m away from your assigned geofenced Command ({$target['location_name']}). Max allowed boundary is {$allowedRadius}m."
            ];
        }

        /**
         * Log geofence verification details to `geofence_logs` table
         */
        private static function logGeofenceCheck($pdo, $userId, $serviceNo, $eventType, $userLat, $userLng, $targetLat, $targetLng, $distance, $allowedRadius, $status, $ipAddress = null, $deviceInfo = null) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO geofence_logs 
                    (user_id, service_no, event_type, user_lat, user_lng, target_lat, target_lng, distance_meters, allowed_radius_meters, status, ip_address, device_info) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $userId,
                    $serviceNo,
                    $eventType,
                    (float)$userLat,
                    (float)$userLng,
                    (float)$targetLat,
                    (float)$targetLng,
                    (int)$distance,
                    (int)$allowedRadius,
                    $status,
                    $ipAddress ?? ($_SERVER['REMOTE_ADDR'] ?? 'N/A'),
                    $deviceInfo ?? ($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown')
                ]);
            } catch (Exception $e) {
                error_log("GeofenceLog Error: " . $e->getMessage());
            }
        }
    }
}
