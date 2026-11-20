<?php

require_once __DIR__ . '/env.php';
loadEnvironmentFile(__DIR__ . '/../.env');

function getDatabaseConnection(): PDO
{
    $host = getenv('PAYROLL_DB_HOST') ?: getenv('DB_HOST') ?: '127.0.0.1';
    $port = getenv('PAYROLL_DB_PORT') ?: getenv('DB_PORT') ?: '3307';
    $database = getenv('PAYROLL_DB_NAME') ?: getenv('DB_DATABASE') ?: 'payroll_db';
    $username = getenv('PAYROLL_DB_USER') ?: getenv('DB_USERNAME') ?: 'root';
    $password = getenv('PAYROLL_DB_PASSWORD');

    if ($password === false) {
        $password = getenv('DB_PASSWORD');
    }

    if ($password === false) {
        $password = '';
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $host,
        $port,
        $database
    );

    return new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}
