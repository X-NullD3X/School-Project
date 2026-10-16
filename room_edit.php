<?php
require 'config/db.php';
require 'includes/auth.php';
require_admin();

$pageTitle = 'Edit Room';
$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM rooms WHERE room_id = ?');
$stmt->execute([$id]);
$room = $stmt->fetch();

if (!$room) {
    set_flash('error', 'Room not found.');
    header('Location: rooms.php');
    exit;
}

$errors = [];
$form = [
    'room_name' => $room['room_name'],
    'capacity'  => (string) $room['capacity'],
    'floor'     => $room['floor'],
    'status'    => $room['status'],
];

// ----- UPDATE -----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $form['room_name'] = trim($_POST['room_name'] ?? '');
    $form['capacity']  = trim($_POST['capacity'] ?? '');
    $form['floor']     = trim($_POST['floor'] ?? '');
    $form['status']    = $_POST['status'] ?? '';

    if ($form['room_name'] === '' || mb_strlen($form['room_name']) > 50) {
        $errors[] = 'Room name is required (up to 50 characters).';
    }
    if (!ctype_digit($form['capacity']) || (int) $form['capacity'] < 1 || (int) $form['capacity'] > 50) {
        $errors[] = 'Capacity must be a whole number from 1 to 50.';
    }
    if ($form['floor'] === '' || mb_strlen($form['floor']) > 20) {
        $errors[] = 'Floor is required (up to 20 characters).';
    }
    if (!in_array($form['status'], ['available', 'maintenance'], true)) {
        $errors[] = 'Please choose a valid status.';
    }

    if (!$errors) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM rooms WHERE room_name = ? AND room_id <> ?');
        $stmt->execute([$form['room_name'], $id]);
        if ($stmt->fetchColumn() > 0) {
            $errors[] = 'Another room already uses this name.';
        }
    }

    if (!$errors) {
        $stmt = $pdo->prepare('UPDATE rooms SET room_name = ?, capacity = ?, floor = ?, status = ? WHERE room_id = ?');
        $stmt->execute([$form['room_name'], (int) $form['capacity'], $form['floor'], $form['status'], $id]);

        // Record exactly what changed in the audit trail
        $changes = [];
        foreach (['room_name' => 'name', 'capacity' => 'capacity', 'floor' => 'floor', 'status' => 'status'] as $field => $label) {
            if ((string) $room[$field] !== (string) $form[$field]) {
                $changes[] = "$label: {$room[$field]} → {$form[$field]}";
            }
        }
        log_action($pdo, $_SESSION['user_id'], 'UPDATE',
            'Updated room ' . $room['room_name'] . ($changes ? ' (' . implode(', ', $changes) . ')' : ' (no changes)'));

        set_flash('success', 'Room "' . $form['room_name'] . '" was updated.');
        header('Location: rooms.php');
        exit;
    }
}

require 'includes/header.php';
?>

<section class="card form-card">
    <h2>Edit <?= e($room['room_name']) ?></h2>

    <?php if ($errors): ?>
        <div class="alert alert-error">
            <ul class="error-list">
                <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post">
        <?= csrf_field() ?>
        <div class="field">
            <label for="room_name">Room name</label>
            <input type="text" id="room_name" name="room_name" maxlength="50" value="<?= e($form['room_name']) ?>" required>
        </div>
        <div class="field-row">
            <div class="field">
                <label for="capacity">Capacity</label>
                <input type="number" id="capacity" name="capacity" min="1" max="50" value="<?= e($form['capacity']) ?>" required>
            </div>
            <div class="field">
                <label for="floor">Floor</label>
                <input type="text" id="floor" name="floor" maxlength="20" value="<?= e($form['floor']) ?>" required>
            </div>
        </div>
        <div class="field">
            <label for="status">Status</label>
            <select id="status" name="status">
                <option value="available"   <?= $form['status'] === 'available' ? 'selected' : '' ?>>Available</option>
                <option value="maintenance" <?= $form['status'] === 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
            </select>
        </div>
        <p class="muted" style="font-size:13px; margin:-6px 0 16px">
            Setting a room to Maintenance hides it from new bookings. Existing reservations are kept.
        </p>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save changes</button>
            <a href="rooms.php" class="btn btn-outline">Cancel</a>
        </div>
    </form>
</section>

<?php require 'includes/footer.php'; ?>