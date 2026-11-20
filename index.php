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
    'settings' => __DIR__ . '/app/Views/pages/hmo_settings.php',
    'hmo_settings' => __DIR__ . '/app/Views/pages/hmo_settings.php',
    'analytics' => __DIR__ . '/app/Views/pages/analytics.php',
];

$currentPage = $_GET['page'] ?? 'login';
$settingsSection = 'organization';
if (is_string($currentPage) && $currentPage === 'hmo_settings') {
    $currentPage = 'settings';
    $settingsSection = 'benefits';
} elseif (is_string($_GET['section'] ?? null)) {
    $requestedSection = $_GET['section'];
    if (in_array($requestedSection, ['organization', 'work_locations', 'payroll', 'compensation', 'claims', 'benefits'], true)) {
        $settingsSection = $requestedSection;
    }
}
$returnPageInput = $_GET['return_to'] ?? 'dashboard';
$settingsReturnPage = is_string($returnPageInput)
    && in_array($returnPageInput, ['dashboard', 'payroll', 'compensation', 'claims', 'benefits', 'analytics'], true)
        ? $returnPageInput
        : 'dashboard';

if (
    !is_string($currentPage)
    || (!in_array($currentPage, ['logout', 'claim_receipt', 'benefits_export', 'benefits_birth_certificate', 'benefits_employee_search'], true) && !isset($pages[$currentPage]))
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

    require_once __DIR__ . '/app/Controllers/AuthController.php';
    AuthController::beginLoginPage();

    if (isset($_SESSION['user'])) {
        header('Location: index.php?page=dashboard');
        exit;
    }

    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    $loginError = $_SESSION['login_error'] ?? null;
    unset($_SESSION['login_error']);
    $loginNotice = $_SESSION['login_notice'] ?? null;
    unset($_SESSION['login_notice']);
    $loginMode = $_SESSION['password_reset_mode'] ?? 'login';
    $passwordResetEmail = $_SESSION['password_reset_email'] ?? '';
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

if ($currentPage === 'benefits_export') {
    require_once __DIR__ . '/app/Controllers/HmoController.php';
    HmoController::exportDeductions();
}

if ($currentPage === 'benefits_birth_certificate') {
    require_once __DIR__ . '/app/Controllers/HmoController.php';
    HmoController::downloadBirthCertificate();
}

if ($currentPage === 'benefits_employee_search') {
    require_once __DIR__ . '/app/Controllers/HmoController.php';
    HmoController::searchEligibleEmployees();
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if ($currentPage === 'claims') {
    require_once __DIR__ . '/app/Controllers/ClaimsController.php';
    $claimsPageData = ClaimsController::handleRequest();
    require_once __DIR__ . '/app/Controllers/SettingsController.php';
    $claimsThresholds = SettingsController::getClaimsThresholds();
}

if ($currentPage === 'compensation') {
    require_once __DIR__ . '/app/Controllers/CompensationController.php';
    $compensationPageData = CompensationController::handleRequest();
}

if (in_array($currentPage, ['benefits', 'settings'], true)) {
    require_once __DIR__ . '/app/Controllers/SettingsController.php';
    if ($currentPage === 'settings') {
        $compensationSettingsAction = $settingsSection === 'compensation'
            && $_SERVER['REQUEST_METHOD'] === 'POST'
            && in_array($_POST['action'] ?? '', ['save_structure', 'save_matrix', 'save_employee_compensation'], true);
        if ($compensationSettingsAction) {
            require_once __DIR__ . '/app/Controllers/CompensationController.php';
            $compensationSettingsPageData = CompensationController::handleRequest(true);
        } else {
            $settingsPageData = SettingsController::handleRequest($settingsSection);
            if ($settingsSection === 'compensation') {
                require_once __DIR__ . '/app/Controllers/CompensationController.php';
                $compensationSettingsPageData = CompensationController::handleRequest(true);
            }
        }
    }
}

if ($currentPage === 'benefits' || ($currentPage === 'settings' && $settingsSection === 'benefits')) {
    require_once __DIR__ . '/app/Controllers/HmoController.php';
    $hmoPageData = HmoController::handleRequest($currentPage === 'settings' || $currentPage === 'hmo_settings');
}

require $pages[$currentPage];
