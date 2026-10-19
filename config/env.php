<?php

function loadEnvironmentFile(string $path): void
{
    if (!is_file($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES);

    if ($lines === false) {
        throw new RuntimeException('Could not read environment file: ' . $path);
    }

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        if (!str_contains($line, '=')) {
            throw new RuntimeException('Invalid environment entry in ' . $path);
        }

        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);

        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $name)) {
            throw new RuntimeException('Invalid environment variable name in ' . $path);
        }

        if (getenv($name) !== false) {
            continue;
        }

        if (strlen($value) >= 2 && $value[0] === '"' && $value[-1] === '"') {
            $value = substr($value, 1, -1);
            $value = stripcslashes($value);
        } elseif (strlen($value) >= 2 && $value[0] === "'" && $value[-1] === "'") {
            $value = substr($value, 1, -1);
        }

        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
    }
}
