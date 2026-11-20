<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if (!in_array($argc, [3, 5], true)) {
    fwrite(STDERR, "Usage: php database/create_user.php <email> <name> [manager <employee_id>]\n");
    exit(1);
}

$email = strtolower(trim($argv[1]));
$name = trim($argv[2]);
$role = $argc === 5 ? strtolower(trim($argv[3])) : 'admin';
$employeeId = $argc === 5 ? filter_var($argv[4], FILTER_VALIDATE_INT) : null;

if (
    !filter_var($email, FILTER_VALIDATE_EMAIL)
    || $name === ''
    || ($argc === 5 && ($role !== 'manager' || $employeeId === false || $employeeId < 1))
) {
    fwrite(STDERR, "Provide a valid email and name; optional manager accounts require a valid employee ID.\n");
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
    $connection = getDatabaseConnection();
    if ($role === 'manager') {
        $column = $connection->query("SHOW COLUMNS FROM users LIKE 'employee_id'")->fetch();
        if ($column === false) {
            throw new RuntimeException('Apply database/008_compensation_workflow_migration.sql before creating a manager login.');
        }
        $employee = $connection->prepare(
            'SELECT 1 FROM employees WHERE id = :employee_id AND employment_status = \'Active\''
        );
        $employee->execute(['employee_id' => $employeeId]);
        if ($employee->fetchColumn() === false) {
            throw new RuntimeException('The manager employee ID must belong to an Active employee.');
        }
        $statement = $connection->prepare(
            'INSERT INTO users (name, email, password_hash, role, employee_id)
             VALUES (:name, :email, :password_hash, \'manager\', :employee_id)'
        );
    } else {
        $statement = $connection->prepare(
            'INSERT INTO users (name, email, password_hash) VALUES (:name, :email, :password_hash)'
        );
    }
    $statement->execute([
        'name' => $name,
        'email' => $email,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    ] + ($role === 'manager' ? ['employee_id' => $employeeId] : []));
} catch (PDOException $exception) {
    fwrite(STDERR, 'Could not create user: ' . $exception->getMessage() . "\n");
    exit(1);
} catch (RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, "Created user {$email}.\n");
