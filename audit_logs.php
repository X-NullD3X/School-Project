<?php
require 'config/db.php';
require 'includes/auth.php';
require_admin();

$pageTitle = 'Audit Trail';
$perPage   = 20;

function is_valid_date($value) {
    $d = DateTime::createFromFormat('Y-m-d', $value);
    return $d && $d->format('Y-m-d') === $value;
}

$search = trim($_GET['q'] ?? '');
$action = $_GET['action'] ?? '';
$from   = $_GET['from'] ?? '';
$to     = $_GET['to'] ?? '';
$page   = max(1, (int) ($_GET['page'] ?? 1));

$actions = $pdo->query('SELECT DISTINCT action FROM audit_logs ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);

// ----- Build filters -----
$where  = [];
$params = [];

if ($search !== '') {
    $where[] = '(u.full_name LIKE ? OR u.email LIKE ? OR l.description LIKE ?)';
    array_push($params, "%$search%", "%$search%", "%$search%");
}
if (in_array($action, $actions, true)) {
    $where[]  = 'l.action = ?';
    $params[] = $action;
} else {
    $action = '';
}
if (is_valid_date($from)) {
    $where[]  = 'l.created_at >= ?';
    $params[] = $from . ' 00:00:00';
} else {
    $from = '';
}
if (is_valid_date($to)) {
    $where[]  = 'l.created_at < ?';
    $params[] = date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00';
} else {
    $to = '';
}

$base = ' FROM audit_logs l
          LEFT JOIN users u ON l.user_id = u.user_id
          LEFT JOIN roles r ON u.role_id = r.role_id'
      . ($where ? ' WHERE ' . implode(' AND ', $where) : '');

// ----- Count and paginate -----
$stmt = $pdo->prepare('SELECT COUNT(*)' . $base);
$stmt->execute($params);
$total  = (int) $stmt->fetchColumn();
$pages  = max(1, (int) ceil($total / $perPage));
$page   = min($page, $pages);
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare(
    'SELECT l.log_id, l.action, l.description, l.created_at, u.full_name, u.email, r.role_name'
    . $base
    . " ORDER BY l.created_at DESC, l.log_id DESC LIMIT $perPage OFFSET $offset"
);
$stmt->execute($params);
$logs = $stmt->fetchAll();

$actionClass = [
    'LOGIN'  => 'badge-approved',
    'LOGOUT' => 'badge-neutral',
    'CREATE' => 'badge-info',
    'UPDATE' => 'badge-pending',
    'DELETE' => 'badge-rejected',
    'REPORT' => 'badge-neutral',
];

$pageUrl = function ($p) {
    $query = $_GET;
    $query['page'] = $p;
    return 'audit_logs.php?' . http_build_query($query);
};

require 'includes/header.php';
?>

<div class="toolbar">
    <form class="search" method="get">
        <label class="filter grow">Search
            <input type="search" name="q" value="<?= e($search) ?>" placeholder="User, email or description">
        </label>
        <label class="filter">Action
            <select name="action">
                <option value="">All actions</option>
                <?php foreach ($actions as $a): ?>
                    <option value="<?= e($a) ?>" <?= $action === $a ? 'selected' : '' ?>><?= e($a) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="filter">From
            <input type="date" name="from" value="<?= e($from) ?>">
        </label>
        <label class="filter">To
            <input type="date" name="to" value="<?= e($to) ?>">
        </label>
        <button type="submit" class="btn btn-outline">Filter</button>
        <?php if ($search !== '' || $action !== '' || $from !== '' || $to !== ''): ?>
            <a href="audit_logs.php" class="btn btn-outline">Clear</a>
        <?php endif; ?>
    </form>
</div>

<section class="card flush">
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Date and time</th>
                    <th>User</th>
                    <th>Role</th>
                    <th>Action</th>
                    <th>Description</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$logs): ?>
                    <tr><td colspan="5" class="empty">No activity found.</td></tr>
                <?php endif; ?>

                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td>
                            <?= e(date('M j, Y', strtotime($log['created_at']))) ?>
                            <span class="sub"><?= e(date('g:i:s A', strtotime($log['created_at']))) ?></span>
                        </td>
                        <td>
                            <?php if ($log['full_name']): ?>
                                <?= e($log['full_name']) ?>
                                <span class="sub"><?= e($log['email']) ?></span>
                            <?php else: ?>
                                <span class="muted">Deleted user</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $log['role_name'] ? e($log['role_name'] === 'admin' ? 'Admin' : 'Student') : '—' ?></td>
                        <td>
                            <span class="badge <?= $actionClass[$log['action']] ?? 'badge-neutral' ?>"><?= e($log['action']) ?></span>
                        </td>
                        <td><?= e($log['description']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="pagination">
        <span class="info">
            <?php if ($total): ?>
                Showing <?= $offset + 1 ?>–<?= min($offset + $perPage, $total) ?> of <?= $total ?> entries
            <?php else: ?>
                0 entries
            <?php endif; ?>
        </span>
        <?php if ($page > 1): ?>
            <a href="<?= e($pageUrl($page - 1)) ?>">Previous</a>
        <?php endif; ?>
        <?php for ($p = max(1, $page - 2); $p <= min($pages, $page + 2); $p++): ?>
            <?php if ($p === $page): ?>
                <span class="current"><?= $p ?></span>
            <?php else: ?>
                <a href="<?= e($pageUrl($p)) ?>"><?= $p ?></a>
            <?php endif; ?>
        <?php endfor; ?>
        <?php if ($page < $pages): ?>
            <a href="<?= e($pageUrl($page + 1)) ?>">Next</a>
        <?php endif; ?>
    </div>
</section>

<?php require 'includes/footer.php'; ?>