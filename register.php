<?php
require 'config/db.php';
require 'includes/auth.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$errors = [];
$fullName  = '';
$studentNo = '';
$email     = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName  = trim($_POST['full_name'] ?? '');
    $studentNo = trim($_POST['student_no'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $password  = $_POST['password'] ?? '';
    $confirm   = $_POST['confirm_password'] ?? '';

    // ----- Validation -----
    if ($fullName === '' || mb_strlen($fullName) > 100) {
        $errors[] = 'Please enter your full name (up to 100 characters).';
    }
    if ($studentNo === '' || mb_strlen($studentNo) > 20) {
        $errors[] = 'Please enter your student number (up to 20 characters).';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }

    // ----- Check for duplicates -----
    if (!$errors) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetchColumn() > 0) {
            $errors[] = 'An account with this email already exists.';
        }

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE student_no = ?');
        $stmt->execute([$studentNo]);
        if ($stmt->fetchColumn() > 0) {
            $errors[] = 'This student number is already registered.';
        }
    }

    // ----- Create the account (always as a standard user) -----
    if (!$errors) {
        $roleId = $pdo->query("SELECT role_id FROM roles WHERE role_name = 'user'")->fetchColumn();

        $stmt = $pdo->prepare(
            'INSERT INTO users (role_id, student_no, full_name, email, password_hash)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$roleId, $studentNo, $fullName, $email, password_hash($password, PASSWORD_DEFAULT)]);

        $newUserId = $pdo->lastInsertId();
        log_action($pdo, $newUserId, 'CREATE', 'Registered a new student account');

        header('Location: login.php?registered=1');
        exit;
    }
}

$hasLogo = file_exists(__DIR__ . '/assets/img/logo.png');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create account | LDCU Room Reservation</title>
    <?php if ($hasLogo): ?>
        <link rel="icon" href="assets/img/logo.png">
    <?php endif; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="auth-page">
    <section class="auth-brand">
        <div class="brand">
            <?php if ($hasLogo): ?>
                <img class="brand-logo" src="assets/img/logo.png" alt="Liceo de Cagayan University seal">
            <?php else: ?>
                <span class="brand-mark">LDCU</span>
            <?php endif; ?>
            <div>
                <strong>Liceo de Cagayan University</strong>
                <small>University Library</small>
            </div>
        </div>

        <div class="auth-copy">
            <h2>Join the reading room, Licean.</h2>
            <p>Create a student account to reserve discussion rooms for your group and keep track of your bookings.</p>
            <p class="motto">Nil Sine Numine</p>
        </div>

        <?php require 'includes/shelf.php'; ?>
    </section>

    <section class="auth-form-wrap">
        <form class="auth-card" method="post">
            <h1>Create account</h1>
            <p class="muted">For Liceo students. It only takes a minute.</p>

            <?php if ($errors): ?>
                <div class="alert alert-error">
                    <ul class="error-list">
                        <?php foreach ($errors as $err): ?>
                            <li><?= e($err) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="field">
                <label for="full_name">Full name</label>
                <input type="text" id="full_name" name="full_name" value="<?= e($fullName) ?>" placeholder="Juan Dela Cruz" required>
            </div>
            <div class="field">
                <label for="student_no">Student number</label>
                <input type="text" id="student_no" name="student_no" value="<?= e($studentNo) ?>" placeholder="2026-00001" required>
            </div>
            <div class="field">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="<?= e($email) ?>" placeholder="you@liceo.edu.ph" required>
            </div>
            <div class="field-row">
                <div class="field">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" minlength="8" placeholder="At least 8 characters" required>
                </div>
                <div class="field">
                    <label for="confirm_password">Confirm password</label>
                    <input type="password" id="confirm_password" name="confirm_password" minlength="8" placeholder="Type it again" required>
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-block">Create account</button>

            <p class="auth-switch">Already have an account? <a href="login.php">Sign in</a></p>
        </form>
    </section>
</div>
</body>
</html>