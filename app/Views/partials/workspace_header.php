<?php
$currentUser = $_SESSION['user'] ?? [];
$userName = isset($currentUser['name']) && is_string($currentUser['name']) ? $currentUser['name'] : 'User';
$userEmail = isset($currentUser['email']) && is_string($currentUser['email']) ? $currentUser['email'] : '';
$userInitials = strtoupper(substr($userName, 0, 2));
?>
<header class="sticky top-0 z-20 flex h-16 shrink-0 items-center justify-end border-b border-gray-200 bg-white px-5 sm:px-8">
    <div class="flex items-center gap-3">
        <div class="relative">
            <button id="notificationButton" type="button" aria-label="Notifications" aria-expanded="false" aria-controls="notificationMenu" class="flex h-10 w-10 items-center justify-center rounded-lg text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-blue-500">
                <i class="bi bi-bell text-xl" aria-hidden="true"></i>
            </button>
            <div id="notificationMenu" class="absolute right-0 mt-2 hidden w-72 rounded-xl border border-gray-200 bg-white p-4 shadow-lg">
                <p class="text-sm font-semibold text-gray-900">Notifications</p>
                <p class="mt-2 text-sm text-gray-500">You’re all caught up.</p>
            </div>
        </div>

        <div class="relative">
            <button id="profileButton" type="button" aria-label="Open account menu" aria-expanded="false" aria-controls="profileMenu" class="flex items-center gap-3 rounded-full p-1 pr-2 text-left transition-colors hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500">
                <span class="flex h-10 w-10 items-center justify-center rounded-full bg-slate-900 text-xs font-bold text-white"><?= htmlspecialchars($userInitials, ENT_QUOTES, 'UTF-8') ?></span>
                <span class="hidden min-w-0 sm:block">
                    <span class="block max-w-40 truncate text-xs font-semibold text-gray-900"><?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="block max-w-40 truncate text-[11px] text-gray-500"><?= htmlspecialchars($userEmail, ENT_QUOTES, 'UTF-8') ?></span>
                </span>
                <i class="bi bi-chevron-down text-xs text-gray-400" aria-hidden="true"></i>
            </button>
            <div id="profileMenu" class="absolute right-0 mt-2 hidden w-56 rounded-xl border border-gray-200 bg-white p-2 shadow-lg">
                <div class="border-b border-gray-100 px-3 py-2 sm:hidden">
                    <p class="truncate text-xs font-semibold text-gray-900"><?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="truncate text-[11px] text-gray-500"><?= htmlspecialchars($userEmail, ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <button id="signOutButton" type="button" class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50">
                    <i class="bi bi-box-arrow-right text-base" aria-hidden="true"></i>
                    Sign out
                </button>
            </div>
        </div>
    </div>
</header>

<dialog id="signOutDialog" class="m-auto w-[min(22rem,calc(100vw-2rem))] max-w-none rounded-xl border border-gray-200 p-0 shadow-2xl backdrop:bg-slate-950/40">
    <div class="p-5">
        <div class="flex items-start gap-3">
            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-amber-50 text-amber-600">
                <i class="bi bi-box-arrow-right text-lg" aria-hidden="true"></i>
            </div>
            <div class="pt-0.5">
                <h2 class="text-base font-semibold text-gray-900">Sign out?</h2>
                <p class="mt-1 text-sm leading-5 text-gray-500">Are you sure you want to sign out?</p>
            </div>
        </div>
        <div class="mt-5 flex justify-end gap-2">
            <button id="cancelSignOut" type="button" class="rounded-lg border border-gray-300 px-3.5 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">Cancel</button>
            <form method="post" action="index.php?page=logout">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                <button type="submit" class="rounded-lg bg-indigo-600 px-3.5 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Sign out</button>
            </form>
        </div>
    </div>
</dialog>
