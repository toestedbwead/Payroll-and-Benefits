<?php
$activePage = isset($currentPage) && is_string($currentPage)
    ? $currentPage
    : (isset($_GET['page']) && is_string($_GET['page']) ? $_GET['page'] : 'dashboard');
?>
<aside id="appSidebar" class="relative flex h-screen w-64 shrink-0 flex-col border-r border-gray-200 bg-white transition-[width] duration-200">
    <div class="flex h-16 items-center gap-3 border-b border-gray-100 px-4">
        <img src="public/assets/priority-handling-mark.png" alt="Priority Handling logo" class="h-10 w-10 shrink-0 object-contain">
        <div class="sidebar-label min-w-0">
            <h1 class="whitespace-nowrap text-sm font-semibold text-gray-900">Payroll & Benefits</h1>
            <span class="whitespace-nowrap text-xs text-gray-500">Priority Handling Inc</span>
        </div>
    </div>

    <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-3" aria-label="Main navigation">
        <p class="sidebar-label mb-2 mt-1 px-3 text-[10px] font-semibold uppercase tracking-wider text-gray-400">Main</p>

        <a href="index.php?page=dashboard" data-tooltip="Dashboard" aria-label="Dashboard" aria-current="<?= $activePage === 'dashboard' ? 'page' : 'false' ?>" class="sidebar-link flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors <?= $activePage === 'dashboard' ? 'bg-blue-50 text-blue-600' : 'text-gray-600 hover:bg-gray-50' ?>">
            <i class="bi bi-grid-1x2-fill sidebar-icon" aria-hidden="true"></i><span class="sidebar-label whitespace-nowrap">Dashboard</span>
        </a>

        <p class="sidebar-label mb-2 mt-6 px-3 text-[10px] font-semibold uppercase tracking-wider text-gray-400">Modules & Operations</p>

        <a href="index.php?page=payroll" data-tooltip="Payroll Management" aria-label="Payroll Management" aria-current="<?= $activePage === 'payroll' ? 'page' : 'false' ?>" class="sidebar-link flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors <?= $activePage === 'payroll' ? 'bg-blue-50 text-blue-600' : 'text-gray-600 hover:bg-gray-50' ?>">
            <i class="bi bi-wallet2 sidebar-icon" aria-hidden="true"></i><span class="sidebar-label whitespace-nowrap">Payroll Management</span>
        </a>

        <a href="index.php?page=compensation" data-tooltip="Compensation Planning" aria-label="Compensation Planning" aria-current="<?= $activePage === 'compensation' ? 'page' : 'false' ?>" class="sidebar-link flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors <?= $activePage === 'compensation' ? 'bg-blue-50 text-blue-600' : 'text-gray-600 hover:bg-gray-50' ?>">
            <i class="bi bi-cash-coin sidebar-icon" aria-hidden="true"></i><span class="sidebar-label whitespace-nowrap">Compensation Planning</span>
        </a>

        <a href="index.php?page=claims" data-tooltip="Claims & Reimbursement" aria-label="Claims & Reimbursement" aria-current="<?= $activePage === 'claims' ? 'page' : 'false' ?>" class="sidebar-link flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors <?= $activePage === 'claims' ? 'bg-blue-50 text-blue-600' : 'text-gray-600 hover:bg-gray-50' ?>">
            <i class="bi bi-clipboard-check sidebar-icon" aria-hidden="true"></i><span class="sidebar-label whitespace-nowrap">Claims & Reimbursement</span>
        </a>

        <a href="index.php?page=benefits" data-tooltip="HMO & Benefits" aria-label="HMO & Benefits" aria-current="<?= $activePage === 'benefits' ? 'page' : 'false' ?>" class="sidebar-link flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors <?= $activePage === 'benefits' ? 'bg-blue-50 text-blue-600' : 'text-gray-600 hover:bg-gray-50' ?>">
            <i class="bi bi-heart-pulse sidebar-icon" aria-hidden="true"></i><span class="sidebar-label whitespace-nowrap">HMO & Benefits</span>
        </a>

        <a href="index.php?page=analytics" data-tooltip="HR Analytics" aria-label="HR Analytics" aria-current="<?= $activePage === 'analytics' ? 'page' : 'false' ?>" class="sidebar-link flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors <?= $activePage === 'analytics' ? 'bg-blue-50 text-blue-600' : 'text-gray-600 hover:bg-gray-50' ?>">
            <i class="bi bi-bar-chart-line sidebar-icon" aria-hidden="true"></i><span class="sidebar-label whitespace-nowrap">HR Analytics</span>
        </a>
    </nav>

    <div class="border-t border-gray-100 p-3">
        <button id="sidebarToggle" type="button" aria-label="Collapse sidebar" aria-expanded="true" class="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-gray-500 transition-colors hover:bg-gray-50 hover:text-gray-900">
            <i id="sidebarToggleIcon" class="bi bi-chevron-left sidebar-icon" aria-hidden="true"></i>
            <span class="sidebar-label whitespace-nowrap">Collapse sidebar</span>
        </button>
    </div>
</aside>
