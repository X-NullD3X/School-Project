<?php
require 'config/db.php';
require 'includes/auth.php';
require_admin();

$pageTitle = 'Edit User';
$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT u.*, r.role_name FROM users u JOIN roles r ON u.role_id = r.role_id WHERE u.user_id = ?'
);
$stmt->execute([$id]);
$user = $stmt->fetch();

if (!$user) {
    set_flash('error', 'User not found.');
    header('Location: users.php');
    exit;
}

$roles     = $pdo->query('SELECT role_id, role_name FROM roles ORDER BY role_id')->fetchAll();
$rolesById = array_column($roles, null, 'role_id');
$isSelf    = (int) $user['user_id'] === (int) $_SESSION['user_id'];
$errors    = [];

$form = [
    'full_name'  => $user['full_name'],
    'student_no' => $user['student_no'] ?? '',
    'email'      => $user['email'],
    'role_id'    => (string) $user['role_id'],
];

// ----- UPDATE -----
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
    if ($password !== '' && strlen($password) < 8) {
        $errors[] = 'New password must be at least 8 characters.';
    }

    // Never leave the system without an admin
    if ($role && $role['role_name'] !== 'admin' && $user['role_name'] === 'admin') {
        if ($isSelf) {
            $errors[] = 'You cannot remove your own admin role.';
        } else {
            $admins = (int) $pdo->query(
                "SELECT COUNT(*) FROM users u JOIN roles r ON u.role_id = r.role_id WHERE r.role_name = 'admin'"
            )->fetchColumn();
            if ($admins <= 1) {
                $errors[] = 'There must always be at least one admin account.';
            }
        }
    }

    if (!$errors) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ? AND user_id <> ?');
        $stmt->execute([$form['email'], $id]);
        if ($stmt->fetchColumn() > 0) {
            $errors[] = 'Another account already uses this email.';
        }

        if ($form['student_no'] !== '') {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE student_no = ? AND user_id <> ?');
            $stmt->execute([$form['student_no'], $id]);
            if ($stmt->fetchColumn() > 0) {
                $errors[] = 'Another account already uses this student number.';
            }
        }
    }

    if (!$errors) {
        $fields = 'role_id = ?, student_no = ?, full_name = ?, email = ?';
        $params = [
            $role['role_id'],
            $form['student_no'] === '' ? null : $form['student_no'],
            $form['full_name'],
            $form['email'],
        ];
        if ($password !== '') {
            $fields  .= ', password_hash = ?';
            $params[] = password_hash($password, PASSWORD_DEFAULT);
        }
        $params[] = $id;

        $stmt = $pdo->prepare("UPDATE users SET $fields WHERE user_id = ?");
        $stmt->execute($params);

        // Log which fields changed (never the password itself)
        $changes = [];
        if ($user['full_name'] !== $form['full_name'])                 { $changes[] = 'name'; }
        if ($user['email'] !== $form['email'])                         { $changes[] = 'email'; }
        if ((string) $user['student_no'] !== $form['student_no'])      { $changes[] = 'student number'; }
        if ((string) $user['role_id'] !== (string) $role['role_id'])   { $changes[] = 'role → ' . $role['role_name']; }
        if ($password !== '')                                          { $changes[] = 'password reset'; }

        log_action($pdo, $_SESSION['user_id'], 'UPDATE',
            'Updated account ' . $user['email'] . ($changes ? ' (' . implode(', ', $changes) . ')' : ' (no changes)'));

        set_flash('success', 'Account for ' . $form['full_name'] . ' was updated.');
        header('Location: users.php');
        exit;
    }
}

require 'includes/header.php';
?>

<section class="card form-card">
    <h2>Edit <?= e($user['full_name']) ?><?= $isSelf ? ' (you)' : '' ?></h2>

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
            <input type="email" id="email" name="email" maxlength="100" value="<?= e($form['email']) ?>" required>
        </div>
        <div class="field-row">
            <div class="field">
                <label for="role_id">Role</label>
                <select id="role_id" name="role_id" required>
                    <?php foreach ($roles as $r): ?>
                        <option value="<?= (int) $r['role_id'] ?>" <?= $form['role_id'] === (string) $r['role_id'] ? 'selected' : '' ?>>
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
            <label for="password">New password</label>
            <input type="password" id="password" name="password" minlength="8" placeholder="Leave blank to keep the current password">
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save changes</button>
            <a href="users.php" class="btn btn-outline">Cancel</a>
        </div>
    </form>
</section>

<?php require 'includes/footer.php'; ?>