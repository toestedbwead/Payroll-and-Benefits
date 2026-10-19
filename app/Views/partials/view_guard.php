<?php

function preventDirectViewAccess(string $viewFile): void
{
    $requestedFile = realpath($_SERVER['SCRIPT_FILENAME'] ?? '');

    if ($requestedFile !== false && $requestedFile === realpath($viewFile)) {
        header('Location: ../../../index.php?page=login');
        exit;
    }
}
