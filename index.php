<?php

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

$pages = [
    'login' => __DIR__ . '/app/Views/pages/login.php',
    'dashboard' => __DIR__ . '/app/Views/pages/dashboard.php',
    'payroll' => __DIR__ . '/app/Views/pages/payroll.php',
    'compensation' => __DIR__ . '/app/Views/pages/compensation.php',
    'claims' => __DIR__ . '/app/Views/pages/claims.php',
    'benefits' => __DIR__ . '/app/Views/pages/benefits.php',
    'analytics' => __DIR__ . '/app/Views/pages/analytics.php',
];

$currentPage = $_GET['page'] ?? 'login';

if (
    !is_string($currentPage)
    || (!in_array($currentPage, ['logout', 'claim_receipt'], true) && !isset($pages[$currentPage]))
) {
    http_response_code(404);
    exit('Page not found.');
}

if ($currentPage === 'logout') {
    require __DIR__ . '/app/Controllers/AuthController.php';
    AuthController::logout();
}

if ($currentPage === 'login') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require __DIR__ . '/app/Controllers/AuthController.php';
        AuthController::login();
    }

    if (isset($_SESSION['user'])) {
        header('Location: index.php?page=dashboard');
        exit;
    }

    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    $loginError = $_SESSION['login_error'] ?? null;
    unset($_SESSION['login_error']);
    require $pages[$currentPage];
    exit;
}

if (!isset($_SESSION['user'])) {
    header('Location: index.php?page=login');
    exit;
}

if ($currentPage === 'claim_receipt') {
    require_once __DIR__ . '/app/Controllers/ClaimsController.php';
    ClaimsController::downloadReceipt();
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if ($currentPage === 'claims') {
    require_once __DIR__ . '/app/Controllers/ClaimsController.php';
    $claimsPageData = ClaimsController::handleRequest();
}

require $pages[$currentPage];
