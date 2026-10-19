<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php database/create_user.php <email> <name>\n");
    exit(1);
}

$email = strtolower(trim($argv[1]));
$name = trim($argv[2]);

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '') {
    fwrite(STDERR, "Provide a valid email address and a non-empty name.\n");
    exit(1);
}

fwrite(STDOUT, 'Password (input will be visible): ');
$password = rtrim((string) fgets(STDIN), "\r\n");

if (strlen($password) < 12) {
    fwrite(STDERR, "Use a password with at least 12 characters.\n");
    exit(1);
}

require_once __DIR__ . '/../config/database.php';

try {
    $statement = getDatabaseConnection()->prepare(
        'INSERT INTO users (name, email, password_hash) VALUES (:name, :email, :password_hash)'
    );
    $statement->execute([
        'name' => $name,
        'email' => $email,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    ]);
} catch (PDOException $exception) {
    fwrite(STDERR, 'Could not create user: ' . $exception->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, "Created user {$email}.\n");
