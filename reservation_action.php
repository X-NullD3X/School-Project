<?php
require 'config/db.php';
require 'includes/auth.php';
require_login();

// Only accept real form submissions
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: reservations.php');
    exit;
}
verify_csrf();

// Go back to the same filtered list afterwards (only allow reservations.php)
$return = $_POST['return'] ?? 'reservations.php';
if (!preg_match('/^reservations\.php(\?[^\r\n]*)?$/', $return)) {
    $return = 'reservations.php';
}

$id     = (int) ($_POST['reservation_id'] ?? 0);
$action = $_POST['action'] ?? '';

$stmt = $pdo->prepare(
    'SELECT res.*, r.room_name, t.start_time, u.full_name
     FROM reservations res
     JOIN rooms r      ON res.room_id = r.room_id
     JOIN time_slots t ON res.slot_id = t.slot_id
     JOIN users u      ON res.user_id = u.user_id
     WHERE res.reservation_id = ?'
);
$stmt->execute([$id]);
$res = $stmt->fetch();

if (!$res) {
    set_flash('error', 'Reservation not found.');
    header('Location: ' . $return);
    exit;
}

$label = $res['room_name'] . ' on ' . date('M j, Y', strtotime($res['reservation_date']))
       . ' at ' . date('g:i A', strtotime($res['start_time']));
$error = '';

// ----- UPDATE: approve or reject (admin only) -----
if ($action === 'approve' || $action === 'reject') {
    require_admin();

    if ($res['status'] !== 'pending') {
        $error = 'Only pending reservations can be approved or rejected.';
    } elseif ($action === 'approve') {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM reservations
             WHERE room_id = ? AND reservation_date = ? AND slot_id = ?
               AND status = 'approved' AND reservation_id <> ?"
        );
        $stmt->execute([$res['room_id'], $res['reservation_date'], $res['slot_id'], $id]);
        if ($stmt->fetchColumn() > 0) {
            $error = 'Another reservation is already approved for that room and time.';
        }
    }

    if (!$error) {
        $newStatus = $action === 'approve' ? 'approved' : 'rejected';
        $stmt = $pdo->prepare(
            "UPDATE reservations SET status = ?, approved_by = ?
             WHERE reservation_id = ? AND status = 'pending'"
        );
        $stmt->execute([$newStatus, $_SESSION['user_id'], $id]);

        log_action($pdo, $_SESSION['user_id'], 'UPDATE',
            ucfirst($newStatus) . ' reservation #' . $id . ' (' . $res['full_name'] . ', ' . $label . ')');
        set_flash('success', 'Reservation for ' . $res['full_name'] . ' was ' . $newStatus . '.');
    }

// ----- UPDATE: cancel (the student who made it, or an admin) -----
} elseif ($action === 'cancel') {
    $isOwner = (int) $res['user_id'] === (int) $_SESSION['user_id'];

    if (!$isOwner && !is_admin()) {
        http_response_code(403);
        exit('Access denied.');
    }
    if (!in_array($res['status'], ['pending', 'approved'], true)) {
        $error = 'This reservation can no longer be cancelled.';
    } elseif ($res['reservation_date'] < date('Y-m-d')) {
        $error = 'Past reservations cannot be cancelled.';
    }

    if (!$error) {
        $stmt = $pdo->prepare("UPDATE reservations SET status = 'cancelled' WHERE reservation_id = ?");
        $stmt->execute([$id]);

        log_action($pdo, $_SESSION['user_id'], 'UPDATE', 'Cancelled reservation #' . $id . ' (' . $label . ')');
        set_flash('success', 'Reservation for ' . $label . ' was cancelled.');
    }
} else {
    $error = 'Unknown action.';
}

if ($error) {
    set_flash('error', $error);
}
header('Location: ' . $return);
exit;