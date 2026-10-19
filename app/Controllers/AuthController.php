<?php

require_once __DIR__ . '/../Services/AuthService.php';

class AuthController
{
    public static function login(): void
    {
        $postedToken = $_POST['csrf_token'] ?? null;
        $sessionToken = $_SESSION['csrf_token'] ?? null;

        if (
            !is_string($postedToken)
            || !is_string($sessionToken)
            || !hash_equals($sessionToken, $postedToken)
        ) {
            http_response_code(403);
            exit('Invalid or expired sign-in request. Refresh the page and try again.');
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
        unset($_SESSION['login_error']);

        header('Location: index.php?page=dashboard');
        exit;
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
}
