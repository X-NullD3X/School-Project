<?php
require 'config/db.php';
require 'includes/auth.php';
require_admin();

$pageTitle = 'Reports';

function is_valid_date($value) {
    $d = DateTime::createFromFormat('Y-m-d', $value);
    return $d && $d->format('Y-m-d') === $value;
}

// Stops Excel from treating text like "=SUM(...)" as a formula (CSV injection)
function csv_safe($value) {
    $value = (string) $value;
    return ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) ? "'" . $value : $value;
}

// ----- Read the filters -----
$type = ($_GET['type'] ?? '') === 'rooms' ? 'rooms' : 'reservations';
$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to'] ?? date('Y-m-t');
if (!is_valid_date($from)) { $from = date('Y-m-01'); }
if (!is_valid_date($to))   { $to   = date('Y-m-t'); }
if ($from > $to) { [$from, $to] = [$to, $from]; }

$statuses = ['pending', 'approved', 'rejected', 'cancelled'];
$status   = $_GET['status'] ?? '';
if (!in_array($status, $statuses, true)) { $status = ''; }

$rangeLabel = date('M j, Y', strtotime($from)) . ' – ' . date('M j, Y', strtotime($to));

// ----- Build the report -----
if ($type === 'reservations') {
    $title = 'Reservations Report';

    $sql = "SELECT res.reservation_date, t.start_time, t.end_time, r.room_name,
                   u.full_name, u.student_no, res.purpose, res.group_size, res.status
            FROM reservations res
            JOIN rooms r      ON res.room_id = r.room_id
            JOIN time_slots t ON res.slot_id = t.slot_id
            JOIN users u      ON res.user_id = u.user_id
            WHERE res.reservation_date BETWEEN ? AND ?";
    $params = [$from, $to];
    if ($status !== '') {
        $sql .= ' AND res.status = ?';
        $params[] = $status;
    }
    $sql .= ' ORDER BY res.reservation_date, t.start_time, r.room_name';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll();

    $columns = ['Date', 'Time', 'Room', 'Student', 'Student No.', 'Purpose', 'People', 'Status'];
    $rows = [];
    foreach ($data as $d) {
        $rows[] = [
            date('M j, Y', strtotime($d['reservation_date'])),
            date('g:i A', strtotime($d['start_time'])) . ' – ' . date('g:i A', strtotime($d['end_time'])),
            $d['room_name'],
            $d['full_name'],
            $d['student_no'] ?? '—',
            $d['purpose'],
            $d['group_size'],
            ucfirst($d['status']),
        ];
    }

    $counts  = array_count_values(array_column($data, 'status'));
    $summary = [
        ['Total reservations', count($data)],
        ['Approved', $counts['approved'] ?? 0],
        ['Pending', $counts['pending'] ?? 0],
        ['Rejected / cancelled', ($counts['rejected'] ?? 0) + ($counts['cancelled'] ?? 0)],
    ];
} else {
    $title = 'Room Utilization Report';

    $days      = (new DateTime($from))->diff(new DateTime($to))->days + 1;
    $slotCount = (int) $pdo->query('SELECT COUNT(*) FROM time_slots')->fetchColumn();
    $available = $days * $slotCount;   // bookable hours per room in this range

    $stmt = $pdo->prepare(
        "SELECT r.room_name, r.capacity,
                COUNT(res.reservation_id) AS total,
                COALESCE(SUM(res.status = 'approved'), 0)  AS approved,
                COALESCE(SUM(res.status = 'pending'), 0)   AS pending,
                COALESCE(SUM(res.status = 'rejected'), 0)  AS rejected,
                COALESCE(SUM(res.status = 'cancelled'), 0) AS cancelled,
                COALESCE(SUM(CASE WHEN res.status = 'approved' THEN res.group_size END), 0) AS people
         FROM rooms r
         LEFT JOIN reservations res
                ON res.room_id = r.room_id
               AND res.reservation_date BETWEEN ? AND ?
         GROUP BY r.room_id, r.room_name, r.capacity
         ORDER BY r.room_name"
    );
    $stmt->execute([$from, $to]);
    $data = $stmt->fetchAll();

    $columns = ['Room', 'Capacity', 'Requests', 'Approved', 'Pending', 'Rejected', 'Cancelled', 'People served', 'Utilization'];
    $rows = [];
    $totalRequests = 0;
    $totalApproved = 0;
    $busiest       = null;

    foreach ($data as $d) {
        $util = $available ? $d['approved'] / $available * 100 : 0;
        $rows[] = [
            $d['room_name'], $d['capacity'], $d['total'], $d['approved'],
            $d['pending'], $d['rejected'], $d['cancelled'], $d['people'],
            number_format($util, 1) . '%',
        ];
        $totalRequests += $d['total'];
        $totalApproved += $d['approved'];
        if ($d['approved'] > 0 && (!$busiest || $d['approved'] > $busiest['approved'])) {
            $busiest = $d;
        }
    }

    $overall = ($available && $data) ? $totalApproved / ($available * count($data)) * 100 : 0;
    $summary = [
        ['Total requests', $totalRequests],
        ['Approved bookings', $totalApproved],
        ['Overall utilization', number_format($overall, 1) . '%'],
        ['Busiest room', $busiest ? $busiest['room_name'] : '—'],
    ];
}

// ----- Excel download (CSV) -----
if (($_GET['export'] ?? '') === 'csv') {
    log_action($pdo, $_SESSION['user_id'], 'REPORT', "Exported $title ($rangeLabel) to Excel");

    $filename = ($type === 'rooms' ? 'room-utilization' : 'reservations') . "_{$from}_to_{$to}.csv";
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // lets Excel read special characters correctly
    fputcsv($out, ['Liceo de Cagayan University - Library Discussion Rooms'], ',', '"', '');
    fputcsv($out, [$title . ' (' . $rangeLabel . ')'], ',', '"', '');
    fputcsv($out, ['Generated ' . date('M j, Y g:i A') . ' by ' . $_SESSION['full_name']], ',', '"', '');
    fwrite($out, "\n");
    fputcsv($out, $columns, ',', '"', '');
    foreach ($rows as $row) {
        fputcsv($out, array_map('csv_safe', $row), ',', '"', '');
    }
    fclose($out);
    exit;
}

$exportUrl = 'reports.php?' . http_build_query(['type' => $type, 'from' => $from, 'to' => $to, 'status' => $status, 'export' => 'csv']);
$hasLogo   = file_exists(__DIR__ . '/assets/img/logo.png');

require 'includes/header.php';
?>

<div class="toolbar no-print">
    <form class="search" method="get">
        <label class="filter">Report
            <select name="type">
                <option value="reservations" <?= $type === 'reservations' ? 'selected' : '' ?>>Reservations</option>
                <option value="rooms" <?= $type === 'rooms' ? 'selected' : '' ?>>Room utilization</option>
            </select>
        </label>
        <label class="filter">From
            <input type="date" name="from" value="<?= e($from) ?>" required>
        </label>
        <label class="filter">To
            <input type="date" name="to" value="<?= e($to) ?>" required>
        </label>
        <label class="filter">Status
            <select name="status" <?= $type === 'rooms' ? 'disabled' : '' ?>>
                <option value="">All statuses</option>
                <?php foreach ($statuses as $s): ?>
                    <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit" class="btn btn-primary">Generate</button>
    </form>
</div>

<div class="report-actions no-print">
    <a href="<?= e($exportUrl) ?>" class="btn btn-outline">Download Excel (.csv)</a>
    <button type="button" class="btn btn-outline" onclick="window.print()">Print / Save as PDF</button>
</div>

<!-- Letterhead: only visible when printing -->
<div class="report-head print-only">
    <?php if ($hasLogo): ?>
        <img src="assets/img/logo.png" alt="">
    <?php endif; ?>
    <div>
        <h2>Liceo de Cagayan University — University Library</h2>
        <p><strong><?= e($title) ?></strong> · <?= e($rangeLabel) ?><?= $status ? ' · ' . e(ucfirst($status)) . ' only' : '' ?></p>
        <p>Generated <?= e(date('M j, Y g:i A')) ?> by <?= e($_SESSION['full_name']) ?></p>
    </div>
</div>

<section class="stats report-summary">
    <?php foreach ($summary as [$label, $value]): ?>
        <div class="card stat">
            <div class="stat-label"><?= e($label) ?></div>
            <div class="stat-value"><?= e($value) ?></div>
        </div>
    <?php endforeach; ?>
</section>

<section class="card flush">
    <div class="report-title">
        <h2><?= e($title) ?></h2>
        <span class="muted"><?= e($rangeLabel) ?></span>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <?php foreach ($columns as $col): ?><th><?= e($col) ?></th><?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="<?= count($columns) ?>" class="empty">No data for this date range.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <?php foreach ($row as $cell): ?><td><?= e($cell) ?></td><?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require 'includes/footer.php'; ?>