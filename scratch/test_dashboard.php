<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'super_admin';
$_SESSION['username'] = 'admin';
$_SESSION['role_name'] = 'Super Admin';

include __DIR__ . '/../dashboard.php';
