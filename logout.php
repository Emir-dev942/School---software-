<?php
// parent/logout.php - Destroy parent session
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

$_SESSION = [];
session_destroy();

header('Location: ' . BASE_URL . 'parent/login.php');
exit;