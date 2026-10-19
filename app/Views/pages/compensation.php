<?php
require_once __DIR__ . '/../partials/view_guard.php';
preventDirectViewAccess(__FILE__);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Compensation Planning</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 font-sans antialiased">
    <div class="flex h-screen overflow-hidden">
        <?php require __DIR__ . '/../partials/sidebar.php'; ?>
        <main class="flex min-w-0 flex-1 flex-col overflow-hidden">
            <?php require __DIR__ . '/../partials/workspace_header.php'; ?>
            <div class="flex-1 overflow-y-auto p-8">
                <h2 class="text-2xl font-bold text-gray-900">Compensation Planning</h2>
                <p class="text-sm text-gray-500 mt-1">Configure salary structures, pay grades, and performance merit bonuses.</p>
            </div>
        </main>
    </div>
    <?php require __DIR__ . '/../partials/workspace_scripts.php'; ?>
</body>
</html>