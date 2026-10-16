<?php
require 'config/db.php';
require 'includes/auth.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$email = '';
$justRegistered = isset($_GET['registered']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Please enter your email and password.';
    } else {
        $stmt = $pdo->prepare(
            'SELECT u.user_id, u.full_name, u.password_hash, r.role_name
             FROM users u
             JOIN roles r ON u.role_id = r.role_id
             WHERE u.email = ?'
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);   // new session ID on login (prevents session fixation)
            $_SESSION['user_id']       = $user['user_id'];
            $_SESSION['full_name']     = $user['full_name'];
            $_SESSION['role']          = $user['role_name'];
            $_SESSION['last_activity'] = time();

            log_action($pdo, $user['user_id'], 'LOGIN', 'User logged in');
            header('Location: dashboard.php');
            exit;
        }
        $error = 'Invalid email or password.';
    }
}

$hasLogo = file_exists(__DIR__ . '/assets/img/logo.png');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log in | LDCU Room Reservation</title>
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
            <h2>Book a discussion room, Licean.</h2>
            <p>Reserve library discussion rooms online, follow your request's approval, and skip the sign-up sheet at the front desk.</p>
            <p class="motto">Nil Sine Numine</p>
        </div>

        <?php require 'includes/shelf.php'; ?>
    </section>

    <section class="auth-form-wrap">
        <form class="auth-card" method="post">
            <h1>Sign in</h1>
            <p class="muted">Use your library account to continue.</p>

            <?= show_flash() ?>
            <?php if ($justRegistered): ?>
                <div class="alert alert-success">Account created. You can sign in now.</div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-error"><?= e($error) ?></div>
            <?php endif; ?>

            <div class="field">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="<?= e($email) ?>" placeholder="you@liceo.edu.ph" required autofocus>
            </div>
            <div class="field">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" placeholder="••••••••" required>
            </div>
            <button type="submit" class="btn btn-primary btn-block">Log in</button>

            <p class="auth-switch">New here? <a href="register.php">Create an account</a></p>
        </form>
    </section>
</div>
</body>
</html>