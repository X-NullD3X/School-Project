<?php
require 'config/db.php';
require 'includes/auth.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: rooms.php');
    exit;
}
verify_csrf();

$id = (int) ($_POST['room_id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM rooms WHERE room_id = ?');
$stmt->execute([$id]);
$room = $stmt->fetch();

if (!$room) {
    set_flash('error', 'Room not found.');
    header('Location: rooms.php');
    exit;
}

$stmt = $pdo->prepare('SELECT COUNT(*) FROM reservations WHERE room_id = ?');
$stmt->execute([$id]);
$bookings = (int) $stmt->fetchColumn();

if ($bookings > 0) {
    set_flash('error', $room['room_name'] . ' cannot be deleted because it has ' . $bookings
        . ' reservation(s) on record. Set it to Maintenance instead to stop new bookings.');
} else {
    // ----- DELETE -----
    $stmt = $pdo->prepare('DELETE FROM rooms WHERE room_id = ?');
    $stmt->execute([$id]);

    log_action($pdo, $_SESSION['user_id'], 'DELETE', 'Deleted room: ' . $room['room_name']);
    set_flash('success', 'Room "' . $room['room_name'] . '" was deleted.');
}

header('Location: rooms.php');
exit;