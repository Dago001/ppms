<?php
session_start();
require_once 'includes/config.php';
require_once 'includes/auth.php';

if (!isLoggedIn()) { header('Location: login'); exit(); }

$formationId = intval($_GET['formation_id'] ?? 0);
$formationName = $_GET['formation_name'] ?? '';

if (empty($formationName)) {
    // Get formation name from database
    try {
        $stmt = $pdo->prepare("SELECT formation_name, formation_code FROM formation_structure WHERE id = ?");
        $stmt->execute([$formationId]);
        $formation = $stmt->fetch();
        $formationName = $formation['formation_name'] ?? '';
    } catch (Exception $e) {
        $formationName = '';
    }
}

// Redirect to search with the location parameter
if (!empty($formationName)) {
    header('Location: search?location=' . urlencode($formationName));
    exit();
} else {
    header('Location: search');
    exit();
}