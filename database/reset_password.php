<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if ($argc !== 2) {
    fwrite(STDERR, "Usage: php database/reset_password.php <email>\n");
    exit(1);
}

$email = strtolower(trim($argv[1]));

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Provide a valid email address.\n");
    exit(1);
}

fwrite(STDOUT, 'New password (input will be visible): ');
$password = rtrim((string) fgets(STDIN), "\r\n");

if (strlen($password) < 12) {
    fwrite(STDERR, "Use a password with at least 12 characters.\n");
    exit(1);
}

require_once __DIR__ . '/../config/database.php';

try {
    $connection = getDatabaseConnection();
    $statement = $connection->prepare(
        'UPDATE users
         SET password_hash = :password_hash
         WHERE email = :email'
    );
    $statement->execute([
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'email' => $email,
    ]);
} catch (PDOException $exception) {
    fwrite(STDERR, 'Could not reset password: ' . $exception->getMessage() . "\n");
    exit(1);
}

if ($statement->rowCount() === 0) {
    $check = $connection->prepare('SELECT 1 FROM users WHERE email = :email LIMIT 1');
    $check->execute(['email' => $email]);

    if ($check->fetchColumn() === false) {
        fwrite(STDERR, "No account exists for {$email} in the configured database.\n");
        exit(1);
    }
}

fwrite(STDOUT, "Password reset for {$email}.\n");
