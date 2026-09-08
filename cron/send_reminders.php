<?php
// cron/send_reminders.php
// Run this every day via cron: 0 8 * * * php /path/to/cron/send_reminders.php

require_once '../includes/config.php';
require_once '../includes/NotificationService.php';

$notificationService = new NotificationService($pdo);
$result = $notificationService->processAutoReminders();

echo date('Y-m-d H:i:s') . " - Reminders sent: " . ($result['reminders_sent'] ?? 0) . "\n";