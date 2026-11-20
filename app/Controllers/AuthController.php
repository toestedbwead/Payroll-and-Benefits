<?php

require_once __DIR__ . '/../Services/AuthService.php';
require_once __DIR__ . '/../Services/PasswordResetService.php';
require_once __DIR__ . '/../../config/database.php';

class AuthController
{
    public static function login(): void
    {
        self::validateCsrfToken();

        $action = $_POST['action'] ?? 'login';
        if ($action === 'request_password_reset') {
            self::requestPasswordReset();
        }
        if ($action === 'complete_password_reset') {
            self::completePasswordReset();
        }
        if ($action !== 'login') {
            self::redirectWithError('Choose a valid sign-in action.');
        }

        $email = $_POST['email'] ?? null;
        $password = $_POST['password'] ?? null;

        if (
            !is_string($email)
            || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || !is_string($password)
            || $password === ''
        ) {
            self::redirectWithError('Enter a valid work email and password.');
        }

        try {
            $auth = new AuthService(new UserRepository());
            $user = $auth->authenticate(strtolower(trim($email)), $password);
        } catch (PDOException $exception) {
            error_log('Payroll authentication database error: ' . $exception->getMessage());
            self::redirectWithError('Sign-in is temporarily unavailable. Check the database setup and try again.');
        }

        if ($user === null) {
            self::redirectWithError('The email or password is incorrect, or the account is inactive.');
        }

        session_regenerate_id(true);
        $_SESSION['user'] = $user;
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        unset(
            $_SESSION['login_error'],
            $_SESSION['login_notice'],
            $_SESSION['password_reset_email'],
            $_SESSION['password_reset_mode']
        );

        header('Location: index.php?page=dashboard');
        exit;
    }

    public static function beginLoginPage(): void
    {
        $requestedMode = $_GET['reset'] ?? null;
        if ($requestedMode === 'login') {
            unset(
                $_SESSION['login_error'],
                $_SESSION['password_reset_email'],
                $_SESSION['password_reset_mode']
            );
        } elseif ($requestedMode === 'request') {
            $_SESSION['password_reset_mode'] = 'request';
        }
    }

    public static function logout(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            header('Allow: POST');
            exit('Method not allowed.');
        }

        $postedToken = $_POST['csrf_token'] ?? null;
        $sessionToken = $_SESSION['csrf_token'] ?? null;

        if (
            !is_string($postedToken)
            || !is_string($sessionToken)
            || !hash_equals($sessionToken, $postedToken)
        ) {
            http_response_code(403);
            exit('Invalid or expired sign-out request. Refresh the page and try again.');
        }

        $_SESSION = [];
        session_regenerate_id(true);

        header('Location: index.php?page=login');
        exit;
    }

    private static function redirectWithError(string $message): never
    {
        $_SESSION['login_error'] = $message;
        header('Location: index.php?page=login');
        exit;
    }

    private static function requestPasswordReset(): never
    {
        $email = $_POST['email'] ?? null;
        if (!is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $_SESSION['password_reset_mode'] = 'request';
            self::redirectWithError('Enter a valid work email address.');
        }

        $email = strtolower(trim($email));
        $_SESSION['password_reset_email'] = $email;
        try {
            $service = new PasswordResetService(getDatabaseConnection(), new SmtpMailer());
            $service->requestCode($email);
        } catch (Throwable $exception) {
            error_log('Password reset email delivery failed: ' . $exception->getMessage());
            $_SESSION['password_reset_mode'] = 'request';
            self::redirectWithError(
                'The reset email could not be sent. Check the email setup or contact your administrator.'
            );
        }

        $_SESSION['password_reset_mode'] = 'verify';
        $_SESSION['login_notice'] = 'If an active account matches that email, a code was sent if eligible. Requests are limited to one per minute; check your inbox or wait before trying again.';
        header('Location: index.php?page=login');
        exit;
    }

    private static function completePasswordReset(): never
    {
        $email = $_SESSION['password_reset_email'] ?? null;
        $code = $_POST['reset_code'] ?? null;
        $password = $_POST['new_password'] ?? null;
        $confirmation = $_POST['confirm_password'] ?? null;
        $_SESSION['password_reset_mode'] = 'verify';

        if (!is_string($email) || !is_string($code) || !is_string($password) || !is_string($confirmation)) {
            self::redirectWithError('Enter the reset code and both password fields.');
        }
        if (strlen($password) < 12) {
            self::redirectWithError('Use a new password with at least 12 characters.');
        }
        if (!hash_equals($password, $confirmation)) {
            self::redirectWithError('The passwords do not match.');
        }

        try {
            $service = new PasswordResetService(getDatabaseConnection(), new SmtpMailer());
            if (!$service->resetPassword($email, trim($code), $password)) {
                self::redirectWithError('That code is invalid or expired. Request a new code and try again.');
            }
        } catch (Throwable $exception) {
            error_log('Password reset verification failed: ' . $exception->getMessage());
            self::redirectWithError('Password reset is temporarily unavailable. Please try again later.');
        }

        unset($_SESSION['password_reset_email'], $_SESSION['password_reset_mode']);
        $_SESSION['login_notice'] = 'Your password has been reset. Sign in with your new password.';
        header('Location: index.php?page=login&reset=login');
        exit;
    }

    private static function validateCsrfToken(): void
    {
        $postedToken = $_POST['csrf_token'] ?? null;
        $sessionToken = $_SESSION['csrf_token'] ?? null;
        if (
            !is_string($postedToken)
            || !is_string($sessionToken)
            || !hash_equals($sessionToken, $postedToken)
        ) {
            http_response_code(403);
            exit('Invalid or expired request. Refresh the page and try again.');
        }
    }
}
