<?php
require 'config/db.php';
require 'includes/auth.php';
require_login();

$isAdmin   = is_admin();
$pageTitle = $isAdmin ? 'Reservations' : 'My Reservations';
$today     = date('Y-m-d');

$search   = trim($_GET['q'] ?? '');
$status   = $_GET['status'] ?? '';
$statuses = ['pending', 'approved', 'rejected', 'cancelled'];

// ----- READ: admins see all, students see their own -----
$sql = "SELECT res.*, r.room_name, t.start_time, t.end_time,
               u.full_name, u.student_no, a.full_name AS handled_by
        FROM reservations res
        JOIN rooms r      ON res.room_id = r.room_id
        JOIN time_slots t ON res.slot_id = t.slot_id
        JOIN users u      ON res.user_id = u.user_id
        LEFT JOIN users a ON res.approved_by = a.user_id";
$where  = [];
$params = [];

if (!$isAdmin) {
    $where[]  = 'res.user_id = ?';
    $params[] = $_SESSION['user_id'];
}
if ($search !== '') {
    $where[] = '(r.room_name LIKE ? OR res.purpose LIKE ? OR u.full_name LIKE ? OR u.student_no LIKE ?)';
    array_push($params, "%$search%", "%$search%", "%$search%", "%$search%");
}
if (in_array($status, $statuses, true)) {
    $where[]  = 'res.status = ?';
    $params[] = $status;
}
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
// Pending requests first, then newest dates
$sql .= " ORDER BY (res.status = 'pending') DESC, res.reservation_date DESC, t.start_time";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$reservations = $stmt->fetchAll();

// Small form with one button that posts to reservation_action.php
$returnUrl  = 'reservations.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '');
$actionForm = function ($id, $action, $label, $btnClass, $question) use ($returnUrl) {
    return '<form method="post" action="reservation_action.php" style="display:inline"'
         . ' onsubmit="return confirm(\'' . $question . '\')">'
         . csrf_field()
         . '<input type="hidden" name="reservation_id" value="' . (int) $id . '">'
         . '<input type="hidden" name="action" value="' . $action . '">'
         . '<input type="hidden" name="return" value="' . e($returnUrl) . '">'
         . '<button type="submit" class="btn btn-sm ' . $btnClass . '">' . $label . '</button>'
         . '</form>';
};

require 'includes/header.php';
?>

<?= show_flash() ?>

<div class="toolbar">
    <form class="search" method="get">
        <input type="search" name="q" value="<?= e($search) ?>"
               placeholder="<?= $isAdmin ? 'Search by student, room or purpose' : 'Search by room or purpose' ?>">
        <select name="status">
            <option value="">All statuses</option>
            <?php foreach ($statuses as $s): ?>
                <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-outline">Filter</button>
        <?php if ($search !== '' || $status !== ''): ?>
            <a href="reservations.php" class="btn btn-outline">Clear</a>
        <?php endif; ?>
    </form>
    <a href="reservation_create.php" class="btn btn-primary">+ New reservation</a>
</div>

<section class="card flush">
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Room</th>
                    <?php if ($isAdmin): ?><th>Student</th><?php endif; ?>
                    <th>Purpose</th>
                    <th>Group</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$reservations): ?>
                    <tr>
                        <td colspan="8" class="empty">
                            No reservations yet. <a href="reservation_create.php">Book a room</a>
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($reservations as $row): ?>
                    <?php
                        $isOwner   = (int) $row['user_id'] === (int) $_SESSION['user_id'];
                        $canDecide = $isAdmin && $row['status'] === 'pending';
                        $canCancel = $isOwner
                                  && in_array($row['status'], ['pending', 'approved'], true)
                                  && $row['reservation_date'] >= $today;
                    ?>
                    <tr>
                        <td><?= e(date('M j, Y', strtotime($row['reservation_date']))) ?></td>
                        <td><?= e(date('g:i A', strtotime($row['start_time']))) ?> – <?= e(date('g:i A', strtotime($row['end_time']))) ?></td>
                        <td><?= e($row['room_name']) ?></td>
                        <?php if ($isAdmin): ?>
                            <td>
                                <?= e($row['full_name']) ?>
                                <span class="sub"><?= e($row['student_no'] ?? '—') ?></span>
                            </td>
                        <?php endif; ?>
                        <td><?= e($row['purpose']) ?></td>
                        <td><?= (int) $row['group_size'] ?></td>
                        <td>
                            <span class="badge badge-<?= e($row['status']) ?>"><?= e(ucfirst($row['status'])) ?></span>
                            <?php if ($row['handled_by'] && in_array($row['status'], ['approved', 'rejected'], true)): ?>
                                <span class="sub">by <?= e($row['handled_by']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="display:flex; gap:6px; flex-wrap:wrap">
                                <?php if ($canDecide): ?>
                                    <?= $actionForm($row['reservation_id'], 'approve', 'Approve', 'btn-primary', 'Approve this reservation?') ?>
                                    <?= $actionForm($row['reservation_id'], 'reject', 'Reject', 'btn-outline', 'Reject this reservation?') ?>
                                <?php endif; ?>
                                <?php if ($canCancel): ?>
                                    <?= $actionForm($row['reservation_id'], 'cancel', 'Cancel', 'btn-outline', 'Cancel this reservation?') ?>
                                <?php endif; ?>
                                <?php if (!$canDecide && !$canCancel): ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require 'includes/footer.php'; ?>