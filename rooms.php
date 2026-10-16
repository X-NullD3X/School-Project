<?php
require 'config/db.php';
require 'includes/auth.php';
require_login();

$pageTitle = 'Rooms';
$isAdmin   = is_admin();
$errors    = [];
$form      = ['room_name' => '', 'capacity' => '', 'floor' => '', 'status' => 'available'];

// ----- CREATE: add a room (admin only) -----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    verify_csrf();

    $form['room_name'] = trim($_POST['room_name'] ?? '');
    $form['capacity']  = trim($_POST['capacity'] ?? '');
    $form['floor']     = trim($_POST['floor'] ?? '');
    $form['status']    = $_POST['status'] ?? 'available';

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
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM rooms WHERE room_name = ?');
        $stmt->execute([$form['room_name']]);
        if ($stmt->fetchColumn() > 0) {
            $errors[] = 'A room with this name already exists.';
        }
    }

    if (!$errors) {
        $stmt = $pdo->prepare('INSERT INTO rooms (room_name, capacity, floor, status) VALUES (?, ?, ?, ?)');
        $stmt->execute([$form['room_name'], (int) $form['capacity'], $form['floor'], $form['status']]);

        log_action($pdo, $_SESSION['user_id'], 'CREATE', 'Added room: ' . $form['room_name']);
        set_flash('success', 'Room "' . $form['room_name'] . '" was added.');
        header('Location: rooms.php');
        exit;
    }
}

// ----- READ: list rooms, with search -----
$search = trim($_GET['q'] ?? '');

$sql = "SELECT r.*,
               (SELECT COUNT(*) FROM reservations res
                 WHERE res.room_id = r.room_id
                   AND res.status IN ('pending', 'approved')
                   AND res.reservation_date >= CURDATE()) AS upcoming
        FROM rooms r";
$params = [];

if ($search !== '') {
    $sql .= ' WHERE r.room_name LIKE ? OR r.floor LIKE ?';
    $params = ["%$search%", "%$search%"];
}
$sql .= ' ORDER BY r.room_name';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rooms = $stmt->fetchAll();

require 'includes/header.php';
?>

<?= show_flash() ?>

<div class="toolbar">
    <form class="search" method="get">
        <input type="search" name="q" value="<?= e($search) ?>" placeholder="Search by room name or floor">
        <button type="submit" class="btn btn-outline">Search</button>
        <?php if ($search !== ''): ?>
            <a href="rooms.php" class="btn btn-outline">Clear</a>
        <?php endif; ?>
    </form>
</div>

<div class="<?= $isAdmin ? 'layout-2' : '' ?>">
    <section class="card flush">
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Room</th>
                        <th>Floor</th>
                        <th>Capacity</th>
                        <th>Status</th>
                        <th>Upcoming</th>
                        <th><?= $isAdmin ? 'Actions' : '' ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$rooms): ?>
                        <tr><td colspan="6" class="empty">No rooms found.</td></tr>
                    <?php endif; ?>

                    <?php foreach ($rooms as $room): ?>
                        <tr>
                            <td><strong><?= e($room['room_name']) ?></strong></td>
                            <td><?= e($room['floor']) ?></td>
                            <td><?= (int) $room['capacity'] ?> people</td>
                            <td><span class="badge badge-<?= e($room['status']) ?>"><?= e(ucfirst($room['status'])) ?></span></td>
                            <td><?= (int) $room['upcoming'] ?></td>
                            <td>
                                <?php if ($isAdmin): ?>
                                    <div style="display:flex; gap:6px; flex-wrap:wrap">
                                        <a class="btn btn-outline btn-sm" href="room_edit.php?id=<?= (int) $room['room_id'] ?>">Edit</a>
                                        <form method="post" action="room_delete.php" style="display:inline"
                                              onsubmit="return confirm(<?= e(json_encode('Delete ' . $room['room_name'] . '? This cannot be undone.')) ?>)">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="room_id" value="<?= (int) $room['room_id'] ?>">
                                            <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                        </form>
                                    </div>
                                <?php elseif ($room['status'] === 'available'): ?>
                                    <a class="btn btn-primary btn-sm" href="reservation_create.php?room_id=<?= (int) $room['room_id'] ?>">Book</a>
                                <?php else: ?>
                                    <span class="muted">Unavailable</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <?php if ($isAdmin): ?>
        <section class="card">
            <h2>Add a room</h2>

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
                    <input type="text" id="room_name" name="room_name" maxlength="50" value="<?= e($form['room_name']) ?>" placeholder="Discussion Room E" required>
                </div>
                <div class="field-row">
                    <div class="field">
                        <label for="capacity">Capacity</label>
                        <input type="number" id="capacity" name="capacity" min="1" max="50" value="<?= e($form['capacity']) ?>" placeholder="6" required>
                    </div>
                    <div class="field">
                        <label for="floor">Floor</label>
                        <input type="text" id="floor" name="floor" maxlength="20" value="<?= e($form['floor']) ?>" placeholder="1st Floor" required>
                    </div>
                </div>
                <div class="field">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="available"   <?= $form['status'] === 'available' ? 'selected' : '' ?>>Available</option>
                        <option value="maintenance" <?= $form['status'] === 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary btn-block">Add room</button>
            </form>
        </section>
    <?php endif; ?>
</div>

<?php require 'includes/footer.php'; ?>