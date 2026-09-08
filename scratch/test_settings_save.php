<?php
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/settings_helper.php';

// Test 1: Turn Maintenance Mode ON (1)
updateSystemSetting('maintenance_mode', '1', 'general');
$mode = getSystemSetting('maintenance_mode');
echo "MODE_AFTER_ON: " . var_export($mode, true) . "\n";

// Test 2: Turn Maintenance Mode OFF (0)
updateSystemSetting('maintenance_mode', '0', 'general');
$modeOff = getSystemSetting('maintenance_mode');
echo "MODE_AFTER_OFF: " . var_export($modeOff, true) . "\n";
