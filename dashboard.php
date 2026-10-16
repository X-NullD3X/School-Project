<?php
require 'config/db.php';
require 'includes/auth.php';
require_login();

$pageTitle = 'Dashboard';
$userId    = $_SESSION['user_id'];
$isAdmin   = $_SESSION['role'] === 'admin';

function count_rows(PDO $pdo, $sql, $params = []) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

$availableRooms = count_rows($pdo, "SELECT COUNT(*) FROM rooms WHERE status = 'available'");

if ($isAdmin) {
    $stats = [
        ['Available rooms', $availableRooms, 'Open for booking'],
        ['Pending requests', count_rows($pdo, "SELECT COUNT(*) FROM reservations WHERE status = 'pending'"), 'Waiting for approval'],
        ['Approved today', count_rows($pdo, "SELECT COUNT(*) FROM reservations WHERE status = 'approved' AND reservation_date = CURDATE()"), 'Bookings for today'],
        ['Registered users', count_rows($pdo, "SELECT COUNT(*) FROM users"), 'All accounts'],
    ];
} else {
    $stats = [
        ['Available rooms', $availableRooms, 'Open for booking'],
        ['My pending', count_rows($pdo, "SELECT COUNT(*) FROM reservations WHERE user_id = ? AND status = 'pending'", [$userId]), 'Waiting for approval'],
        ['My upcoming', count_rows($pdo, "SELECT COUNT(*) FROM reservations WHERE user_id = ? AND status = 'approved' AND reservation_date >= CURDATE()", [$userId]), 'Approved bookings'],
        ['My total', count_rows($pdo, "SELECT COUNT(*) FROM reservations WHERE user_id = ?", [$userId]), 'All-time requests'],
    ];
}

require 'includes/header.php';
?>

<section class="card welcome">
    <h2>Welcome back, <?= e($_SESSION['full_name']) ?></h2>
    <p><?= $isAdmin ? 'Here is what is happening in the library today.' : 'Ready to book a room for your group?' ?></p>
</section>

<section class="stats">
    <?php foreach ($stats as [$label, $value, $hint]): ?>
        <div class="card stat">
            <div class="stat-label"><?= e($label) ?></div>
            <div class="stat-value"><?= $value ?></div>
            <div class="stat-hint"><?= e($hint) ?></div>
        </div>
    <?php endforeach; ?>
</section>

<?php require 'includes/footer.php'; ?>