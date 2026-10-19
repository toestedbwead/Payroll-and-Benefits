<?php
require_once __DIR__ . '/../partials/view_guard.php';
preventDirectViewAccess(__FILE__);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payroll & Benefits Command Center</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 font-sans antialiased">

    <div class="flex h-screen overflow-hidden">
        
        <?php require __DIR__ . '/../partials/sidebar.php'; ?>

        <main class="flex min-w-0 flex-1 flex-col overflow-hidden">
            <?php require __DIR__ . '/../partials/workspace_header.php'; ?>

            <div class="max-w-7xl mx-auto w-full flex-1 space-y-6 overflow-y-auto p-8">
                
                <!-- Page Title Header -->
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-2xl font-bold text-gray-900 flex items-center gap-2">
                            Overview
                        </h2>
                        <p class="text-sm text-gray-500 mt-0.5">Manage workforce, compensation, and approval records in one command center.</p>
                    </div>

                    <!-- Department Filter Dropdown Placeholder -->
                    <div class="flex items-center gap-3">
                        <div class="bg-white border border-gray-300 rounded-lg px-4 py-2 text-sm font-medium text-gray-700 flex items-center gap-2 shadow-sm cursor-pointer hover:bg-gray-50">
                            <span>All departments</span>
                            <i class="bi bi-chevron-down text-sm text-gray-400" aria-hidden="true"></i>
                        </div>
                        <div class="bg-white border border-gray-300 rounded-lg px-4 py-2 text-sm font-medium text-gray-700 flex items-center gap-2 shadow-sm">
                            <i class="bi bi-people text-gray-500" aria-hidden="true"></i>
                            <span class="text-gray-600">98 employees in scope</span>
                        </div>
                    </div>
                </div>

                <!-- Metrics Grid Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    
                    <!-- Card 1: Net Payroll -->
                    <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm flex flex-col justify-between">
                        <div>
                            <div class="flex items-center gap-2 text-gray-500 text-sm font-medium mb-2">
                                <span class="p-1.5 bg-blue-50 text-blue-600 rounded-lg">
                                    <i class="bi bi-wallet2" aria-hidden="true"></i>
                                </span>
                                Current net payroll
                            </div>
                            <div class="text-3xl font-extrabold text-gray-900 mt-1">₱289.4K</div>
                        </div>
                        <div class="flex items-center gap-2 mt-4 text-xs font-semibold text-emerald-600">
                            <span class="flex items-center">
                                <i class="bi bi-graph-up-arrow mr-0.5" aria-hidden="true"></i>
                                -85.0%
                            </span>
                            <span class="text-gray-400 font-normal">2026-10-01 – 2026-10-15</span>
                        </div>
                    </div>

                    <!-- Card 2: Quick Status Placeholder -->
                    <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm flex flex-col justify-between">
                        <div>
                            <div class="flex items-center gap-2 text-gray-500 text-sm font-medium mb-2">
                                <span class="p-1.5 bg-indigo-50 text-indigo-600 rounded-lg">
                                    <i class="bi bi-coin" aria-hidden="true"></i>
                                </span>
                                Compensation Budget Utilization
                            </div>
                            <div class="text-3xl font-extrabold text-gray-900 mt-1">92.4%</div>
                        </div>
                        <div class="flex items-center gap-2 mt-4 text-xs font-semibold text-indigo-600">
                            <span>Status: Within safe operational limits</span>
                        </div>
                    </div>

                </div>

            </div>
        </main>
    </div>

    <?php require __DIR__ . '/../partials/workspace_scripts.php'; ?>
</body>
</html>