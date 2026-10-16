<?php
require 'config/db.php';
require 'includes/auth.php';

if (!empty($_SESSION['user_id'])) {
    log_action($pdo, $_SESSION['user_id'], 'LOGOUT', 'User logged out');
}

$_SESSION = [];
session_destroy();
header('Location: login.php');
exit;