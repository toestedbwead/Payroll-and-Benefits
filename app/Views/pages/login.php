<?php
require_once __DIR__ . '/../partials/view_guard.php';
preventDirectViewAccess(__FILE__);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in | Payroll & Benefits</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-white font-sans antialiased text-slate-950">
    <main class="grid min-h-screen lg:grid-cols-2">
        <section class="relative hidden min-h-screen overflow-hidden bg-indigo-50 p-10 lg:flex lg:flex-col xl:p-14">
            <div class="pointer-events-none absolute -right-36 -top-36 h-[34rem] w-[34rem] rounded-full bg-indigo-200/50 blur-3xl"></div>
            <div class="pointer-events-none absolute -bottom-48 -left-36 h-[32rem] w-[32rem] rounded-full bg-blue-100/70 blur-3xl"></div>

            <div class="relative mx-auto flex w-full max-w-2xl flex-1 flex-col">
                

                <h2 class="mt-6 max-w-xl text-3xl font-semibold leading-tight tracking-tight text-slate-950 xl:text-4xl">
                    Everything your team needs, ready when they sign in.
                </h2>

                

                <div class="mt-7 flex-1 rounded-2xl border border-slate-200/90 bg-white shadow-xl shadow-indigo-900/5">
                    

                    <div class="grid min-h-[22rem] grid-cols-[8.5rem_1fr]">
                        <aside class="border-r border-slate-100 bg-slate-50/70 p-3">
                            <div class="flex items-center gap-2 px-1 py-2">
                                <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-indigo-600 text-[10px] font-bold text-white">PB</span>
                                <span class="text-[10px] font-semibold text-slate-600">Workspace</span>
                            </div>
                            <div class="mt-5 space-y-1 text-[10px] font-medium text-slate-400">
                                <div class="rounded-lg px-2.5 py-2">Payroll</div>
                                <div class="rounded-lg bg-indigo-50 px-2.5 py-2 font-semibold text-indigo-600">Compensation</div>
                                <div class="rounded-lg px-2.5 py-2">Claims</div>
                                <div class="rounded-lg px-2.5 py-2">HMO</div>
                                <div class="rounded-lg px-2.5 py-2">HR Analytics</div>
                            </div>
                        </aside>

                        <div class="p-5 xl:p-6">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <p class="text-[9px] font-semibold uppercase tracking-wide text-slate-400">Illustrative overview</p>
                                    <h3 class="mt-2 text-base font-semibold text-slate-800">Make payroll feel clear.</h3>
                                    <p class="mt-1 max-w-xs text-[10px] leading-5 text-slate-400">Review each input, spot exceptions, and move every run forward with confidence.</p>
                                </div>
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-indigo-50 text-indigo-600" aria-hidden="true">
                                    <i class="bi bi-wallet2 text-base" aria-hidden="true"></i>
                                </span>
                            </div>

                            <div class="mt-5 grid grid-cols-2 gap-3">
                                <div class="rounded-xl border border-slate-200 p-3.5">
                                    <p class="text-[9px] text-slate-400">Net payroll</p>
                                    <p class="mt-1 text-xl font-bold tracking-tight text-slate-900">₱6.30M</p>
                                    <p class="mt-1 text-[9px] font-medium text-emerald-600">248 employees</p>
                                </div>
                                <div class="rounded-xl border border-slate-200 p-3.5">
                                    <p class="text-[9px] text-slate-400">Recent periods</p>
                                    <div class="mt-3 flex h-10 items-end gap-1.5" aria-hidden="true">
                                        <span class="h-5 flex-1 rounded-t bg-indigo-400"></span>
                                        <span class="h-7 flex-1 rounded-t bg-indigo-400"></span>
                                        <span class="h-6 flex-1 rounded-t bg-indigo-400"></span>
                                        <span class="h-8 flex-1 rounded-t bg-indigo-400"></span>
                                        <span class="h-7 flex-1 rounded-t bg-indigo-400"></span>
                                        <span class="h-10 flex-1 rounded-t bg-indigo-400"></span>
                                        <span class="h-9 flex-1 rounded-t bg-indigo-400"></span>
                                        <span class="h-11 flex-1 rounded-t bg-indigo-500"></span>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-3 rounded-xl border border-slate-200 px-3.5">
                                <div class="flex items-center justify-between border-b border-slate-100 py-3 text-[9px] text-slate-500">
                                    <span class="flex items-center gap-2"><span class="flex h-5 w-5 items-center justify-center rounded-full bg-emerald-50 text-emerald-600">✓</span>Employee entries</span>
                                    <span class="font-semibold text-slate-600">248 / 248</span>
                                </div>
                                <div class="flex items-center justify-between py-3 text-[9px] text-slate-500">
                                    <span class="flex items-center gap-2"><span class="flex h-5 w-5 items-center justify-center rounded-full bg-emerald-50 text-emerald-600">✓</span>Ready for approval</span>
                                    <span class="font-semibold text-slate-600">75%</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="flex min-h-screen items-center justify-center px-6 py-12 sm:px-10">
            <div class="w-full max-w-lg">
                

                <div class="mb-9">
                    <h1 class="text-4xl font-semibold tracking-tight text-slate-950 sm:text-5xl">Welcome back.</h1>
                    <p class="mt-4 max-w-lg text-base leading-7 text-slate-500">
                        Sign in to continue managing your organization’s people, payroll, benefits, and claims.
                    </p>
                </div>

                <?php if (isset($loginError) && is_string($loginError)): ?>
                    <p role="alert" class="mb-5 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                        <?= htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8') ?>
                    </p>
                <?php endif; ?>

                <form method="post" action="index.php?page=login">
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>"
                    >
                    <div class="space-y-6">
                        <div>
                            <label for="email" class="mb-2.5 block text-sm font-semibold text-slate-700">Work email</label>
                            <div class="flex items-center gap-3 rounded-xl border border-slate-200 bg-white px-4 shadow-sm transition focus-within:border-indigo-400 focus-within:ring-4 focus-within:ring-indigo-50">
                                <i class="bi bi-envelope h-5 w-5 shrink-0 text-slate-400" aria-hidden="true"></i>
                                <input
                                    id="email"
                                    name="email"
                                    type="email"
                                    autocomplete="username"
                                    placeholder="you@company.com"
                                    required
                                    class="w-full border-0 bg-transparent py-4 text-sm text-slate-900 outline-none placeholder:text-slate-400"
                                >
                            </div>
                        </div>

                        <div>
                            <div class="mb-2.5 flex items-center justify-between">
                                <label for="password" class="block text-sm font-semibold text-slate-700">Password</label>
                                
                            </div>
                            <div class="flex items-center gap-3 rounded-xl border border-slate-200 bg-white px-4 shadow-sm transition focus-within:border-indigo-400 focus-within:ring-4 focus-within:ring-indigo-50">
                                <i class="bi bi-lock h-5 w-5 shrink-0 text-slate-400" aria-hidden="true"></i>
                                <input
                                    id="password"
                                    name="password"
                                    type="password"
                                    autocomplete="current-password"
                                    placeholder="Enter your password"
                                    required
                                    class="w-full border-0 bg-transparent py-4 text-sm text-slate-900 outline-none placeholder:text-slate-400"
                                >
                            </div>
                        </div>

                        <button
                            type="submit"
                            class="flex w-full items-center justify-center gap-2 rounded-xl bg-indigo-600 px-5 py-4 text-sm font-semibold text-white shadow-lg shadow-indigo-200 transition hover:bg-indigo-700 focus:outline-none focus:ring-4 focus:ring-indigo-100"
                        >
                            Sign in to workspace
                            <i class="bi bi-arrow-right text-base" aria-hidden="true"></i>
                        </button>
                    </div>
                </form>

                
            </div>
        </section>
    </main>
</body>
</html>
