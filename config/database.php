<?php

require_once __DIR__ . '/env.php';
loadEnvironmentFile(__DIR__ . '/../.env');

function getDatabaseConnection(): PDO
{
    $driver = strtolower(getenv('PAYROLL_DB_DRIVER') ?: 'mysql');
    $password = getenv('PAYROLL_DB_PASSWORD');

    if ($password === false) {
        $password = getenv('DB_PASSWORD');
    }

    if ($password === false) {
        $password = '';
    }

    if ($driver === 'pgsql') {
        $host = getenv('PAYROLL_DB_HOST');
        $database = getenv('PAYROLL_DB_NAME');
        $username = getenv('PAYROLL_DB_USER');

        foreach ([
            'PAYROLL_DB_HOST' => $host,
            'PAYROLL_DB_NAME' => $database,
            'PAYROLL_DB_USER' => $username,
            'PAYROLL_DB_PASSWORD' => getenv('PAYROLL_DB_PASSWORD'),
        ] as $name => $value) {
            if ($value === false || trim($value) === '') {
                throw new RuntimeException($name . ' must be configured for PostgreSQL.');
            }
        }

        $port = getenv('PAYROLL_DB_PORT') ?: '5432';
        $sslMode = getenv('PAYROLL_DB_SSLMODE') ?: 'require';
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s;sslmode=%s;connect_timeout=10',
            $host,
            $port,
            $database,
            $sslMode
        );
    } elseif ($driver === 'mysql') {
        $host = getenv('PAYROLL_DB_HOST') ?: getenv('DB_HOST') ?: '127.0.0.1';
        $port = getenv('PAYROLL_DB_PORT') ?: getenv('DB_PORT') ?: '3307';
        $database = getenv('PAYROLL_DB_NAME') ?: getenv('DB_DATABASE') ?: 'payroll_db';
        $username = getenv('PAYROLL_DB_USER') ?: getenv('DB_USERNAME') ?: 'root';
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $host,
            $port,
            $database
        );
    } else {
        throw new RuntimeException('Unsupported database driver: ' . $driver);
    }

    return new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}
