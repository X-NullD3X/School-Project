<?php
require 'config/db.php';
require 'includes/auth.php';
require_login();

$pageTitle = 'New Reservation';
$errors    = [];

$rooms = $pdo->query("SELECT room_id, room_name, capacity, floor FROM rooms WHERE status = 'available' ORDER BY room_name")->fetchAll();
$slots = $pdo->query('SELECT slot_id, start_time, end_time FROM time_slots ORDER BY start_time')->fetchAll();

$roomsById = array_column($rooms, null, 'room_id');
$slotsById = array_column($slots, null, 'slot_id');

$today   = date('Y-m-d');
$maxDate = date('Y-m-d', strtotime('+30 days'));

$form = [
    'room_id'          => $_GET['room_id'] ?? '',
    'reservation_date' => '',
    'slot_id'          => '',
    'group_size'       => '',
    'purpose'          => '',
];

// ----- CREATE: submit a reservation request -----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($form as $key => $value) {
        $form[$key] = trim($_POST[$key] ?? '');
    }

    $room = $roomsById[(int) $form['room_id']] ?? null;
    $slot = $slotsById[(int) $form['slot_id']] ?? null;

    if (!$room) {
        $errors[] = 'Please choose an available room.';
    }

    $date = DateTime::createFromFormat('Y-m-d', $form['reservation_date']);
    if (!$date || $date->format('Y-m-d') !== $form['reservation_date']) {
        $errors[] = 'Please choose a valid date.';
    } elseif ($form['reservation_date'] < $today) {
        $errors[] = 'You cannot book a date in the past.';
    } elseif ($form['reservation_date'] > $maxDate) {
        $errors[] = 'You can only book up to 30 days ahead.';
    }

    if (!$slot) {
        $errors[] = 'Please choose a time slot.';
    } elseif ($form['reservation_date'] === $today && $slot['start_time'] <= date('H:i:s')) {
        $errors[] = 'That time slot has already started today. Please pick a later one.';
    }

    if (!ctype_digit($form['group_size']) || (int) $form['group_size'] < 1) {
        $errors[] = 'Group size must be at least 1.';
    } elseif ($room && (int) $form['group_size'] > (int) $room['capacity']) {
        $errors[] = $room['room_name'] . ' only fits ' . $room['capacity'] . ' people.';
    }

    if ($form['purpose'] === '' || mb_strlen($form['purpose']) > 255) {
        $errors[] = 'Please describe the purpose (up to 255 characters).';
    }

    // Prevent double-booking
    if (!$errors) {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM reservations
             WHERE room_id = ? AND reservation_date = ? AND slot_id = ?
               AND status IN ('pending', 'approved')"
        );
        $stmt->execute([$room['room_id'], $form['reservation_date'], $slot['slot_id']]);
        if ($stmt->fetchColumn() > 0) {
            $errors[] = 'That room is already booked for this date and time. Please choose another slot.';
        }

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM reservations
             WHERE user_id = ? AND reservation_date = ? AND slot_id = ?
               AND status IN ('pending', 'approved')"
        );
        $stmt->execute([$_SESSION['user_id'], $form['reservation_date'], $slot['slot_id']]);
        if ($stmt->fetchColumn() > 0) {
            $errors[] = 'You already have a reservation at this date and time.';
        }
    }

    if (!$errors) {
        $stmt = $pdo->prepare(
            'INSERT INTO reservations (user_id, room_id, slot_id, reservation_date, purpose, group_size)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $_SESSION['user_id'], $room['room_id'], $slot['slot_id'],
            $form['reservation_date'], $form['purpose'], (int) $form['group_size'],
        ]);

        $when = date('M j, Y', strtotime($form['reservation_date'])) . ' ' . date('g:i A', strtotime($slot['start_time']));
        log_action($pdo, $_SESSION['user_id'], 'CREATE', 'Requested ' . $room['room_name'] . ' on ' . $when);
        set_flash('success', 'Reservation submitted. It is now pending approval.');
        header('Location: reservations.php');
        exit;
    }
}

require 'includes/header.php';
?>

<section class="card form-card">
    <?php if ($errors): ?>
        <div class="alert alert-error">
            <ul class="error-list">
                <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if (!$rooms): ?>
        <p class="empty">No rooms are available for booking right now.</p>
    <?php else: ?>
        <form method="post">
            <div class="field">
                <label for="room_id">Room</label>
                <select id="room_id" name="room_id" required>
                    <option value="">Choose a room</option>
                    <?php foreach ($rooms as $r): ?>
                        <option value="<?= (int) $r['room_id'] ?>" <?= (string) $form['room_id'] === (string) $r['room_id'] ? 'selected' : '' ?>>
                            <?= e($r['room_name']) ?> — <?= e($r['floor']) ?> (up to <?= (int) $r['capacity'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field-row">
                <div class="field">
                    <label for="reservation_date">Date</label>
                    <input type="date" id="reservation_date" name="reservation_date"
                           min="<?= $today ?>" max="<?= $maxDate ?>" value="<?= e($form['reservation_date']) ?>" required>
                </div>
                <div class="field">
                    <label for="slot_id">Time slot</label>
                    <select id="slot_id" name="slot_id" required>
                        <option value="">Choose a time</option>
                        <?php foreach ($slots as $s): ?>
                            <option value="<?= (int) $s['slot_id'] ?>" <?= (string) $form['slot_id'] === (string) $s['slot_id'] ? 'selected' : '' ?>>
                                <?= date('g:i A', strtotime($s['start_time'])) ?> – <?= date('g:i A', strtotime($s['end_time'])) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="field">
                <label for="group_size">Number of people</label>
                <input type="number" id="group_size" name="group_size" min="1" max="50" value="<?= e($form['group_size']) ?>" placeholder="4" required>
            </div>

            <div class="field">
                <label for="purpose">Purpose</label>
                <textarea id="purpose" name="purpose" rows="3" maxlength="255" placeholder="e.g. Group study for CE 101 midterms" required><?= e($form['purpose']) ?></textarea>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Submit request</button>
                <a href="reservations.php" class="btn btn-outline">Cancel</a>
            </div>
        </form>
    <?php endif; ?>
</section>

<?php require 'includes/footer.php'; ?>