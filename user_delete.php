<?php
require 'config/db.php';
require 'includes/auth.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: users.php');
    exit;
}
verify_csrf();

$id = (int) ($_POST['user_id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT u.*, r.role_name FROM users u JOIN roles r ON u.role_id = r.role_id WHERE u.user_id = ?'
);
$stmt->execute([$id]);
$user = $stmt->fetch();

$error = '';
if (!$user) {
    $error = 'User not found.';
} elseif ((int) $user['user_id'] === (int) $_SESSION['user_id']) {
    $error = 'You cannot delete your own account while signed in.';
} elseif ($user['role_name'] === 'admin') {
    $admins = (int) $pdo->query(
        "SELECT COUNT(*) FROM users u JOIN roles r ON u.role_id = r.role_id WHERE r.role_name = 'admin'"
    )->fetchColumn();
    if ($admins <= 1) {
        $error = 'There must always be at least one admin account.';
    }
}

if ($error) {
    set_flash('error', $error);
} else {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM reservations WHERE user_id = ?');
    $stmt->execute([$id]);
    $bookings = (int) $stmt->fetchColumn();

    // ----- DELETE (reservations cascade, audit logs are kept) -----
    $stmt = $pdo->prepare('DELETE FROM users WHERE user_id = ?');
    $stmt->execute([$id]);

    log_action($pdo, $_SESSION['user_id'], 'DELETE',
        'Deleted account ' . $user['email'] . ' (' . $bookings . ' reservation(s) removed)');
    set_flash('success', 'Account for ' . $user['full_name'] . ' was deleted.');
}

header('Location: users.php');
exit;