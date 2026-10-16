<?php
// ----- Session handling -----
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,                       // cookie dies when the browser closes
        'path'     => '/',
        'httponly' => true,                    // JavaScript cannot read the session cookie
        'samesite' => 'Lax',                   // not sent on cross-site form posts
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
    ini_set('session.use_strict_mode', '1');   // reject made-up session IDs
    session_start();
}

const SESSION_TIMEOUT = 1800;   // 30 minutes of inactivity

function e($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function log_action(PDO $pdo, $userId, $action, $description = '') {
    $stmt = $pdo->prepare('INSERT INTO audit_logs (user_id, action, description) VALUES (?, ?, ?)');
    $stmt->execute([$userId, $action, $description]);
}

// One-time message shown on the next page load (e.g. "Room added.")
function set_flash($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function show_flash() {
    if (empty($_SESSION['flash'])) {
        return '';
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return '<div class="alert alert-' . e($flash['type']) . '">' . e($flash['message']) . '</div>';
}

// Wipe the session and send the user back to the login page with a message
function end_session($message) {
    $_SESSION = [];
    session_regenerate_id(true);
    set_flash('error', $message);
    header('Location: login.php');
    exit;
}

function require_login() {
    global $pdo;

    // Stop the browser from showing protected pages from cache after logout
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    if (empty($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }

    // Re-check the account on every page: deleted users are kicked out,
    // and role changes made by an admin take effect immediately
    $stmt = $pdo->prepare(
        'SELECT u.full_name, r.role_name
         FROM users u JOIN roles r ON u.role_id = r.role_id
         WHERE u.user_id = ?'
    );
    $stmt->execute([$_SESSION['user_id']]);
    $current = $stmt->fetch();

    if (!$current) {
        end_session('Your account is no longer available.');
    }

    // Inactivity timeout
    if (isset($_SESSION['last_activity']) && time() - $_SESSION['last_activity'] > SESSION_TIMEOUT) {
        log_action($pdo, $_SESSION['user_id'], 'LOGOUT', 'Session expired after 30 minutes of inactivity');
        end_session('Your session expired after 30 minutes of inactivity. Please sign in again.');
    }

    $_SESSION['full_name']     = $current['full_name'];
    $_SESSION['role']          = $current['role_name'];
    $_SESSION['last_activity'] = time();
}

function is_admin() {
    return ($_SESSION['role'] ?? '') === 'admin';
}

function require_admin() {
    require_login();
    if (!is_admin()) {
        http_response_code(403);
        exit('Access denied.');
    }
}

// ----- CSRF protection: a secret token every form must send back -----
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf() {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        exit('Invalid request. Please go back and try again.');
    }
}