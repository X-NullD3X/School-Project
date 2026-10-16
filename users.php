<?php
require 'config/db.php';
require 'includes/auth.php';
require_admin();

$pageTitle = 'Users';
$errors    = [];

$roles     = $pdo->query('SELECT role_id, role_name FROM roles ORDER BY role_id')->fetchAll();
$rolesById = array_column($roles, null, 'role_id');

$form = ['full_name' => '', 'student_no' => '', 'email' => '', 'role_id' => ''];

// ----- CREATE: admin adds an account -----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    foreach ($form as $key => $value) {
        $form[$key] = trim($_POST[$key] ?? '');
    }
    $password = $_POST['password'] ?? '';
    $role     = $rolesById[(int) $form['role_id']] ?? null;

    if ($form['full_name'] === '' || mb_strlen($form['full_name']) > 100) {
        $errors[] = 'Full name is required (up to 100 characters).';
    }
    if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if (!$role) {
        $errors[] = 'Please choose a role.';
    } elseif ($role['role_name'] === 'user' && $form['student_no'] === '') {
        $errors[] = 'Student accounts need a student number.';
    }
    if (mb_strlen($form['student_no']) > 20) {
        $errors[] = 'Student number can be up to 20 characters.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }

    if (!$errors) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
        $stmt->execute([$form['email']]);
        if ($stmt->fetchColumn() > 0) {
            $errors[] = 'An account with this email already exists.';
        }

        if ($form['student_no'] !== '') {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE student_no = ?');
            $stmt->execute([$form['student_no']]);
            if ($stmt->fetchColumn() > 0) {
                $errors[] = 'This student number is already registered.';
            }
        }
    }

    if (!$errors) {
        $stmt = $pdo->prepare(
            'INSERT INTO users (role_id, student_no, full_name, email, password_hash)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $role['role_id'],
            $form['student_no'] === '' ? null : $form['student_no'],
            $form['full_name'],
            $form['email'],
            password_hash($password, PASSWORD_DEFAULT),
        ]);

        log_action($pdo, $_SESSION['user_id'], 'CREATE',
            'Created ' . $role['role_name'] . ' account: ' . $form['email']);
        set_flash('success', 'Account for ' . $form['full_name'] . ' was created.');
        header('Location: users.php');
        exit;
    }
}

// ----- READ: list users, with search and role filter -----
$search     = trim($_GET['q'] ?? '');
$roleFilter = $_GET['role'] ?? '';

$sql = "SELECT u.user_id, u.full_name, u.email, u.student_no, u.created_at, r.role_name,
               (SELECT COUNT(*) FROM reservations res WHERE res.user_id = u.user_id) AS total_reservations
        FROM users u
        JOIN roles r ON u.role_id = r.role_id";
$where  = [];
$params = [];

if ($search !== '') {
    $where[] = '(u.full_name LIKE ? OR u.email LIKE ? OR u.student_no LIKE ?)';
    array_push($params, "%$search%", "%$search%", "%$search%");
}
if (isset($rolesById[(int) $roleFilter])) {
    $where[]  = 'u.role_id = ?';
    $params[] = (int) $roleFilter;
}
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY r.role_name, u.full_name';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

require 'includes/header.php';
?>

<?= show_flash() ?>

<div class="toolbar">
    <form class="search" method="get">
        <input type="search" name="q" value="<?= e($search) ?>" placeholder="Search by name, email or student number">
        <select name="role">
            <option value="">All roles</option>
            <?php foreach ($roles as $r): ?>
                <option value="<?= (int) $r['role_id'] ?>" <?= (string) $roleFilter === (string) $r['role_id'] ? 'selected' : '' ?>>
                    <?= $r['role_name'] === 'user' ? 'Students' : 'Admins' ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-outline">Filter</button>
        <?php if ($search !== '' || $roleFilter !== ''): ?>
            <a href="users.php" class="btn btn-outline">Clear</a>
        <?php endif; ?>
    </form>
</div>

<div class="layout-2">
    <section class="card flush">
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Student no.</th>
                        <th>Role</th>
                        <th>Bookings</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$users): ?>
                        <tr><td colspan="6" class="empty">No users found.</td></tr>
                    <?php endif; ?>

                    <?php foreach ($users as $u): ?>
                        <?php $isSelf = (int) $u['user_id'] === (int) $_SESSION['user_id']; ?>
                        <tr>
                            <td>
                                <strong><?= e($u['full_name']) ?></strong>
                                <span class="sub"><?= $isSelf ? 'You' : 'Joined ' . e(date('M j, Y', strtotime($u['created_at']))) ?></span>
                            </td>
                            <td><?= e($u['email']) ?></td>
                            <td><?= e($u['student_no'] ?? '—') ?></td>
                            <td>
                                <span class="badge <?= $u['role_name'] === 'admin' ? 'badge-pending' : 'badge-approved' ?>">
                                    <?= $u['role_name'] === 'admin' ? 'Admin' : 'Student' ?>
                                </span>
                            </td>
                            <td><?= (int) $u['total_reservations'] ?></td>
                            <td>
                                <div style="display:flex; gap:6px; flex-wrap:wrap">
                                    <a class="btn btn-outline btn-sm" href="user_edit.php?id=<?= (int) $u['user_id'] ?>">Edit</a>
                                    <?php if (!$isSelf): ?>
                                        <form method="post" action="user_delete.php" style="display:inline"
                                              onsubmit="return confirm(<?= e(json_encode('Delete ' . $u['full_name'] . '? Their ' . $u['total_reservations'] . ' reservation(s) will also be removed.')) ?>)">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="user_id" value="<?= (int) $u['user_id'] ?>">
                                            <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="card">
        <h2>Add an account</h2>

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
                <label for="full_name">Full name</label>
                <input type="text" id="full_name" name="full_name" maxlength="100" value="<?= e($form['full_name']) ?>" required>
            </div>
            <div class="field">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" maxlength="100" value="<?= e($form['email']) ?>" placeholder="name@liceo.edu.ph" required>
            </div>
            <div class="field-row">
                <div class="field">
                    <label for="role_id">Role</label>
                    <select id="role_id" name="role_id" required>
                        <option value="">Choose</option>
                        <?php foreach ($roles as $r): ?>
                            <option value="<?= (int) $r['role_id'] ?>" <?= (string) $form['role_id'] === (string) $r['role_id'] ? 'selected' : '' ?>>
                                <?= $r['role_name'] === 'user' ? 'Student' : 'Admin' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="student_no">Student no.</label>
                    <input type="text" id="student_no" name="student_no" maxlength="20" value="<?= e($form['student_no']) ?>" placeholder="Students only">
                </div>
            </div>
            <div class="field">
                <label for="password">Temporary password</label>
                <input type="password" id="password" name="password" minlength="8" placeholder="At least 8 characters" required>
            </div>
            <button type="submit" class="btn btn-primary btn-block">Create account</button>
        </form>
    </section>
</div>

<?php require 'includes/footer.php'; ?>