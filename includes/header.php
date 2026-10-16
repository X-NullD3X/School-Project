<?php
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';
$current = basename($_SERVER['PHP_SELF']);

$navLink = function ($file, $label) use ($current) {
    $class = ($current === $file) ? ' class="active"' : '';
    return '<a href="' . $file . '"' . $class . '>' . e($label) . '</a>';
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle ?? 'Dashboard') ?> | LDCU Room Reservation</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="app">
    <aside class="sidebar">
        <div class="brand">
            <span class="brand-mark">LDCU</span>
            <div>
                <strong>Liceo Library</strong>
                <small>Discussion Room Reservation</small>
            </div>
        </div>

        <nav class="nav">
            <?= $navLink('dashboard.php', 'Dashboard') ?>
            <?= $navLink('rooms.php', 'Rooms') ?>
            <?= $navLink('reservations.php', $isAdmin ? 'Reservations' : 'My Reservations') ?>

            <?php if ($isAdmin): ?>
                <span class="nav-label">Administration</span>
                <?= $navLink('users.php', 'Users') ?>
                <?= $navLink('audit_logs.php', 'Audit Trail') ?>
                <?= $navLink('reports.php', 'Reports') ?>
            <?php endif; ?>
        </nav>

        <div class="sidebar-user">
            <span class="avatar"><?= e(strtoupper(substr($_SESSION['full_name'], 0, 1))) ?></span>
            <div>
                <strong><?= e($_SESSION['full_name']) ?></strong>
                <small><?= e(ucfirst($_SESSION['role'])) ?></small>
            </div>
            <a class="logout" href="logout.php">Log out</a>
        </div>
    </aside>

    <main class="main">
        <header class="topbar">
            <h1><?= e($pageTitle ?? 'Dashboard') ?></h1>
            <span class="date"><?= date('l, F j, Y') ?></span>
        </header>