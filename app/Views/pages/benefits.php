<?php
require_once __DIR__ . '/../partials/view_guard.php';
preventDirectViewAccess(__FILE__);

$hmo = $hmoPageData ?? [];
$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$money = static fn (float|int|string $amount): string => '₱' . number_format((float) $amount, 2);
$plans = $hmo['plans'] ?? [];
$enrollments = $hmo['enrollments'] ?? [];
$dependentsByEnrollment = $hmo['dependents'] ?? [];
$deductions = $hmo['deductions'] ?? [];
$deductionSummary = $hmo['deductionSummary'] ?? ['counts' => [], 'amounts' => []];
$report = $hmo['report'] ?? [];
$month = is_string($hmo['month'] ?? null) ? $hmo['month'] : date('Y-m');
$activeTab = is_string($hmo['activeTab'] ?? null) ? $hmo['activeTab'] : 'coverage';
$flash = $hmo['flash'] ?? null;
$csrfToken = isset($_SESSION['csrf_token']) && is_string($_SESSION['csrf_token']) ? $_SESSION['csrf_token'] : '';
$activeEnrollments = count(array_filter(
    $enrollments,
    static fn (array $enrollment): bool => ($enrollment['enrollment_status'] ?? '') === 'Active'
));
$terminatedEnrollments = count($enrollments) - $activeEnrollments;
$activePlans = count(array_filter(
    $plans,
    static fn (array $plan): bool => (int) ($plan['is_active'] ?? 0) === 1
));
$statusClasses = [
    'Active' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
    'Terminated' => 'bg-gray-100 text-gray-600 ring-gray-500/20',
    'Queued' => 'bg-amber-50 text-amber-700 ring-amber-600/20',
    'Exported' => 'bg-indigo-50 text-indigo-700 ring-indigo-600/20',
    'Processed' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
    'Voided' => 'bg-gray-100 text-gray-600 ring-gray-500/20',
];
$dependentRatesByTier = HmoService::DEPENDENT_MONTHLY_PREMIUMS;
$dependentRatesJson = $escape(json_encode($dependentRatesByTier, JSON_THROW_ON_ERROR));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HMO & Benefits Administration</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 font-sans antialiased">
    <div class="flex h-screen flex-col overflow-hidden">
        <?php require __DIR__ . '/../partials/workspace_header.php'; ?>
        <div class="flex min-h-0 flex-1 overflow-hidden">
            <?php require __DIR__ . '/../partials/sidebar.php'; ?>
            <main class="flex min-w-0 flex-1 flex-col overflow-hidden">
            <div class="flex-1 space-y-6 overflow-y-auto p-5 sm:p-8">
                <div>
                    <h2 class="text-2xl font-bold text-gray-900">HMO & Benefits Administration</h2>
                    <p class="mt-1 text-sm text-gray-500">Configure provider plans and manage eligible employee coverage.</p>
                </div>

                <?php if (is_array($flash) && !($hmo['loadError'] ?? false)): ?>
                    <div role="<?= ($flash['type'] ?? '') === 'success' ? 'status' : 'alert' ?>" class="rounded-xl border px-4 py-3 text-sm <?= ($flash['type'] ?? '') === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-rose-200 bg-rose-50 text-rose-800' ?>">
                        <?= $escape((string) ($flash['message'] ?? '')) ?>
                    </div>
                <?php endif; ?>

                <?php if (!($hmo['canManage'] ?? false)): ?>
                    <div role="alert" class="rounded-xl border border-rose-200 bg-rose-50 p-5 text-sm text-rose-800">Your account can’t access HMO administration.</div>
                <?php elseif ($hmo['loadError'] ?? false): ?>
                    <div class="rounded-xl border border-gray-200 bg-white p-6 text-sm text-gray-600">
                        <p class="font-semibold text-gray-900">HMO data is unavailable</p>
                        <p class="mt-2"><?= $escape((string) ($flash['message'] ?? 'Check the database configuration.')) ?></p>
                        <p class="mt-2">Apply <code class="rounded bg-gray-100 px-1">database/001_hmo_migration.sql</code>, <code class="rounded bg-gray-100 px-1">database/002_hmo_dependents_details_migration.sql</code>, <code class="rounded bg-gray-100 px-1">database/003_hmo_dependent_plan_rate_migration.sql</code>, <code class="rounded bg-gray-100 px-1">database/004_hmo_dependent_tier_rates_migration.sql</code>, and <code class="rounded bg-gray-100 px-1">database/005_hmo_enrollment_edit_migration.sql</code> to the existing <code class="rounded bg-gray-100 px-1">payroll_db</code> before using this module.</p>
                    </div>
                <?php else: ?>
                    <div class="flex gap-2 overflow-x-auto border-b border-gray-200" role="tablist" aria-label="HMO workspace">
                        <button id="hmoCoverageTab" type="button" role="tab" aria-selected="true" aria-controls="hmoCoveragePanel" class="shrink-0 border-b-2 border-blue-600 px-4 py-2.5 text-sm font-semibold text-blue-700">Coverage & enrollment</button>
                        <button id="hmoPlansTab" type="button" role="tab" aria-selected="false" aria-controls="hmoPlansPanel" tabindex="-1" class="shrink-0 border-b-2 border-transparent px-4 py-2.5 text-sm font-semibold text-gray-500 hover:text-gray-800">Plans & rates</button>
                    </div>

                    <section id="hmoCoveragePanel" role="tabpanel" aria-labelledby="hmoCoverageTab" class="space-y-6">
                        <section class="grid grid-cols-2 gap-3 xl:grid-cols-4" aria-label="HMO coverage summary">
                            <?php foreach ([
                                ['Active coverage', $activeEnrollments, 'bi-heart-pulse', 'text-emerald-600', 'bg-emerald-50'],
                                ['Terminated coverage', $terminatedEnrollments, 'bi-person-x', 'text-gray-600', 'bg-gray-100'],
                                ['Available plans', $activePlans, 'bi-clipboard2-check', 'text-blue-600', 'bg-blue-50'],
                                ['Annual company premium', $money((float) ($report['companySpend'] ?? 0)), 'bi-cash-stack', 'text-indigo-600', 'bg-indigo-50'],
                            ] as [$label, $value, $icon, $color, $background]): ?>
                                <article class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="text-xs font-medium text-gray-500"><?= $escape((string) $label) ?></span>
                                        <span class="flex h-8 w-8 items-center justify-center rounded-lg <?= $background ?> <?= $color ?>"><i class="bi <?= $icon ?>" aria-hidden="true"></i></span>
                                    </div>
                                    <p class="mt-2 text-xl font-bold text-gray-900"><?= $escape((string) $value) ?></p>
                                </article>
                            <?php endforeach; ?>
                        </section>

                        <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                            <div class="flex flex-wrap items-center justify-between gap-4 border-b border-gray-200 p-5">
                                <div>
                                    <h3 class="font-semibold text-gray-900">Employee coverage</h3>
                                    <p class="mt-1 text-xs text-gray-500">Primary employee premiums are company-paid; dependent premiums are fully employee-paid through payroll.</p>
                                </div>
                                <?php if (array_filter($plans, static fn (array $plan): bool => (int) ($plan['is_active'] ?? 0) === 1) !== []): ?>
                                    <button id="openEnrollmentDialog" type="button" class="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800"><i class="bi bi-person-plus" aria-hidden="true"></i>Enroll employee</button>
                                <?php endif; ?>
                            </div>
                            <?php if ($enrollments === []): ?>
                                <div class="p-10 text-center text-sm text-gray-500">No HMO enrollments have been recorded.</div>
                            <?php else: ?>
                                <div class="overflow-x-auto">
                                    <table class="w-full table-fixed divide-y divide-gray-200 text-left">
                                        <thead class="bg-gray-50"><tr>
                                            <th class="w-[17%] px-3 py-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Employee</th>
                                            <th class="w-[21%] px-3 py-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Plan / Coverage</th>
                                            <th class="w-[16%] px-3 py-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Dependents</th>
                                            <th class="w-[22%] px-3 py-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Employee deduction</th>
                                            <th class="w-[12%] px-2 py-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Status</th>
                                            <th class="w-[12%] px-2 py-3 text-left text-[11px] font-semibold uppercase tracking-wide text-gray-500">Action</th>
                                        </tr></thead>
                                        <tbody class="divide-y divide-gray-100">
                                            <?php foreach ($enrollments as $enrollment): ?>
                                                <?php $status = (string) $enrollment['enrollment_status']; ?>
                                                <?php
                                                $enrollmentDependents = array_map(
                                                    static fn (array $dependent): array => [
                                                        'id' => (int) $dependent['id'],
                                                        'last_name' => (string) ($dependent['dependent_last_name'] ?? ''),
                                                        'first_name' => (string) ($dependent['dependent_first_name'] ?? ''),
                                                        'middle_initial' => (string) ($dependent['dependent_middle_initial'] ?? ''),
                                                        'name_extension' => (string) ($dependent['dependent_name_extension'] ?? ''),
                                                        'name' => (string) $dependent['dependent_name'],
                                                        'age' => (int) $dependent['dependent_age'],
                                                        'relationship' => (string) $dependent['relationship'],
                                                        'sex' => (string) ($dependent['sex'] ?? ''),
                                                        'civil_status' => (string) ($dependent['civil_status'] ?? ''),
                                                        'active' => (int) $dependent['is_active'] === 1,
                                                        'certificate_url' => empty($dependent['birth_certificate_path'])
                                                            ? ''
                                                            : 'index.php?page=benefits_birth_certificate&id=' . (int) $dependent['id'],
                                                    ],
                                                    $dependentsByEnrollment[(int) $enrollment['id']] ?? []
                                                );
                                                $enrollmentDetailsJson = json_encode([
                                                    'id' => (int) $enrollment['id'],
                                                    'employee' => (string) $enrollment['first_name'] . ' ' . (string) $enrollment['last_name'],
                                                    'employeeCode' => (string) $enrollment['employee_code'],
                                                    'department' => (string) $enrollment['department'],
                                                    'jobTier' => (string) $enrollment['job_tier'],
                                                    'planId' => (int) $enrollment['hmo_provider_id'],
                                                    'provider' => (string) $enrollment['provider_name'],
                                                    'planTier' => (string) $enrollment['plan_tier_name'],
                                                    'effectivityDate' => (string) $enrollment['effectivity_date'],
                                                    'status' => $status,
                                                    'terminatedAt' => (string) ($enrollment['terminated_at'] ?? ''),
                                                    'dependentCount' => (int) $enrollment['dependent_count'],
                                                    'dependentPremium' => (float) $enrollment['dependent_monthly_premium'],
                                                    'dependentDeduction' => (float) $enrollment['dependent_monthly_deduction'],
                                                    'dependents' => $enrollmentDependents,
                                                ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
                                                ?>
                                                <tr>
                                                    <td class="break-words px-3 py-3">
                                                        <p class="text-sm font-semibold text-gray-900"><?= $escape((string) $enrollment['first_name'] . ' ' . $enrollment['last_name']) ?></p>
                                                        <p class="mt-1 text-xs text-gray-500"><?= $escape((string) $enrollment['employee_code'] . ' · ' . $enrollment['department']) ?></p>
                                                    </td>
                                                    <td class="break-words px-3 py-3">
                                                        <p class="text-sm font-medium text-gray-800"><?= $escape((string) $enrollment['provider_name'] . ' · ' . $enrollment['plan_tier_name']) ?></p>
                                                        <p class="mt-1 text-xs text-gray-500"><?= $escape((string) $enrollment['job_tier']) ?></p>
                                                    </td>
                                                    <td class="break-words px-3 py-3">
                                                        <p class="text-sm text-gray-800"><?= (int) $enrollment['dependent_count'] ?> / 2 enrolled</p>
                                                        <p class="mt-1 text-xs text-gray-500"><?= $money((float) $enrollment['dependent_monthly_premium']) ?> full monthly premium</p>
                                                    </td>
                                                    <td class="break-words px-3 py-3">
                                                        <p class="text-sm font-semibold text-gray-900"><?= $money((float) $enrollment['dependent_monthly_deduction']) ?> / month</p>
                                                        <p class="mt-1 text-xs text-gray-500">100% employee-paid</p>
                                                    </td>
                                                    <td class="px-2 py-3">
                                                        <span class="inline-flex rounded-full px-2.5 py-1 text-[11px] font-semibold ring-1 ring-inset <?= $statusClasses[$status] ?? 'bg-gray-100 text-gray-700 ring-gray-500/20' ?>"><?= $escape($status) ?></span>
                                                        <?php if ($enrollment['terminated_at'] !== null): ?><p class="mt-1 text-xs text-gray-500">From <?= $escape((string) $enrollment['terminated_at']) ?></p><?php endif; ?>
                                                    </td>
                                                    <td class="px-2 py-3 text-left">
                                                        <button type="button" data-enrollment-details="<?= $escape($enrollmentDetailsJson) ?>" class="inline-flex items-center gap-2 rounded-xl border border-gray-300 px-3 py-2.5 text-sm font-semibold text-gray-700 hover:border-blue-300 hover:bg-blue-50 hover:text-blue-700">
                                                            <i class="bi bi-eye" aria-hidden="true"></i><span>View</span>
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </section>
                    </section>

                    <?php if (array_filter($plans, static fn (array $plan): bool => (int) ($plan['is_active'] ?? 0) === 1) !== []): ?>
                        <dialog id="enrollmentDialog" class="m-auto w-[min(56rem,calc(100vw-2rem))] max-w-none overflow-y-auto rounded-2xl border border-gray-200 p-0 shadow-2xl backdrop:bg-slate-950/50">
                            <form id="hmoEnrollmentForm" method="post" action="index.php?page=benefits" enctype="multipart/form-data" class="max-h-[90vh] overflow-y-auto">
                                <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                                <input type="hidden" name="action" value="enroll">
                                <input type="hidden" name="month" value="<?= $escape($month) ?>">
                                <input type="hidden" name="active_tab" value="coverage">
                                <div class="sticky top-0 z-10 flex items-start justify-between gap-4 border-b border-gray-200 bg-white px-5 py-4 sm:px-7">
                                    <div>
                                        <h2 class="text-lg font-semibold text-gray-900">Enroll employee in HMO benefits</h2>
                                        <p class="mt-1 text-xs text-gray-500">Active full-time employee coverage with optional legal dependents.</p>
                                    </div>
                                    <button id="closeEnrollmentDialog" type="button" aria-label="Close enrollment form" class="rounded-lg p-2 text-gray-500 hover:bg-gray-100"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                                </div>
                                <div class="space-y-6 p-5 sm:p-7">
                                    <section>
                                        <h3 class="text-sm font-semibold text-gray-900">Employee and plan</h3>
                                        <div class="mt-3 grid gap-4 sm:grid-cols-2">
                                            <label class="text-xs font-semibold text-gray-600">Search eligible employee
                                                <input id="enrollmentEmployeeSearch" type="search" autocomplete="off" aria-autocomplete="list" aria-controls="enrollmentEmployeeResults" aria-expanded="false" placeholder="Type a name, employee code, or department" class="mt-1.5 block w-full border border-gray-300 bg-white px-3 py-2.5 text-sm font-normal text-gray-800">
                                                <input id="enrollmentEmployee" type="hidden" name="employee_id">
                                                <div id="enrollmentEmployeeResults" role="listbox" aria-label="Eligible employees" class="mt-1 hidden max-h-56 overflow-y-auto rounded-lg border border-gray-200 bg-white shadow-lg"></div>
                                                <p id="enrollmentEmployeeSearchStatus" class="mt-1.5 font-normal text-gray-500" aria-live="polite">Enter at least 2 characters to search eligible employees.</p>
                                            </label>
                                            <label class="text-xs font-semibold text-gray-600">Employee full name
                                                <input id="enrollmentFullName" readonly placeholder="Auto-filled when employee is selected" class="mt-1.5 block w-full border border-gray-300 bg-gray-100 px-3 py-2.5 text-sm font-normal text-gray-700">
                                            </label>
                                            <label class="text-xs font-semibold text-gray-600">Job tier
                                                <input id="enrollmentJobTier" readonly placeholder="Auto-assigned from employee record" class="mt-1.5 block w-full border border-gray-300 bg-gray-100 px-3 py-2.5 text-sm font-normal text-gray-700">
                                            </label>
                                            <label class="text-xs font-semibold text-gray-600">Provider plan <span class="font-normal text-gray-400">(restricted to employee's tier)</span>
                                                <select id="enrollmentPlan" name="plan_id" required disabled class="mt-1.5 block w-full border border-gray-300 bg-gray-100 px-3 py-2.5 text-sm font-normal text-gray-800">
                                                    <option value="">Select employee first</option>
                                                    <?php foreach ($plans as $plan): ?>
                                                        <?php if ((int) $plan['is_active'] === 1): ?>
                                                            <option value="<?= (int) $plan['id'] ?>" data-job-tier="<?= $escape((string) $plan['target_job_tier']) ?>" data-dependent-monthly-premium="<?= $escape($dependentRatesByTier[(string) $plan['target_job_tier']]) ?>"><?= $escape((string) $plan['provider_name'] . ' · ' . $plan['plan_tier_name']) ?></option>
                                                        <?php endif; ?>
                                                    <?php endforeach; ?>
                                                </select>
                                            </label>
                                            <label class="text-xs font-semibold text-gray-600 sm:col-span-2">Coverage effective date
                                                <input type="date" name="effectivity_date" required value="<?= $escape(date('Y-m-d')) ?>" class="mt-1.5 block w-full border border-gray-300 px-3 py-2.5 text-sm font-normal text-gray-800">
                                            </label>
                                        </div>
                                    </section>

                                    <section>
                                        <div class="flex flex-wrap items-end justify-between gap-2">
                                            <div>
                                                <h3 class="text-sm font-semibold text-gray-900">Eligible dependents</h3>
                                                <p class="mt-1 text-xs text-gray-500">Maximum 2: spouse, then children youngest-to-oldest, then eligible parent age 65 or below. Birth certificate is required for each dependent.</p>
                                            </div>
                                            <p class="text-xs font-semibold text-indigo-700">Dependent premium: 100% employee-paid via payroll</p>
                                        </div>
                                        <div class="mt-3 space-y-4">
                                            <?php for ($slot = 0; $slot < 2; $slot++): ?>
                                                <fieldset id="dependentEntry<?= $slot ?>" class="dependent-entry rounded-xl border border-gray-200 p-4" data-dependent-slot="<?= $slot ?>" <?= $slot === 1 ? 'hidden' : '' ?>>
                                                    <legend class="px-1 text-xs font-semibold text-gray-700">Dependent <?= $slot + 1 ?></legend>
                                                    <div class="dependent-fields mt-3 grid grid-cols-2 gap-3">
                                                        <label class="text-xs font-medium text-gray-600">Last name
                                                            <input name="dependents[<?= $slot ?>][last_name]" maxlength="100" <?= $slot === 1 ? 'disabled' : '' ?> class="dependent-required mt-1 block w-full border border-gray-300 px-3 py-2 text-sm font-normal text-gray-800">
                                                        </label>
                                                        <label class="text-xs font-medium text-gray-600">First name
                                                            <input name="dependents[<?= $slot ?>][first_name]" maxlength="100" <?= $slot === 1 ? 'disabled' : '' ?> class="dependent-required mt-1 block w-full border border-gray-300 px-3 py-2 text-sm font-normal text-gray-800">
                                                        </label>
                                                        <label class="text-xs font-medium text-gray-600">Middle initial <span class="font-normal text-gray-400">(optional)</span>
                                                            <input name="dependents[<?= $slot ?>][middle_initial]" maxlength="5" <?= $slot === 1 ? 'disabled' : '' ?> class="mt-1 block w-full border border-gray-300 px-3 py-2 text-sm font-normal text-gray-800">
                                                        </label>
                                                        <label class="text-xs font-medium text-gray-600">Name extension <span class="font-normal text-gray-400">(optional)</span>
                                                            <input name="dependents[<?= $slot ?>][name_extension]" maxlength="20" placeholder="Jr., III" <?= $slot === 1 ? 'disabled' : '' ?> class="mt-1 block w-full border border-gray-300 px-3 py-2 text-sm font-normal text-gray-800">
                                                        </label>
                                                        <label class="text-xs font-medium text-gray-600">Age
                                                            <input type="number" name="dependents[<?= $slot ?>][age]" min="0" max="120" <?= $slot === 1 ? 'disabled' : '' ?> class="dependent-required mt-1 block w-full border border-gray-300 px-3 py-2 text-sm font-normal text-gray-800">
                                                        </label>
                                                        <label class="text-xs font-medium text-gray-600">Relationship
                                                            <select name="dependents[<?= $slot ?>][relationship]" <?= $slot === 1 ? 'disabled' : '' ?> class="dependent-required mt-1 block w-full border border-gray-300 bg-white px-3 py-2 text-sm font-normal text-gray-800">
                                                                <option value="">Select relationship</option><option value="Spouse">Legal spouse</option><option value="Child">Child</option><option value="Parent">Parent (plan permitting)</option>
                                                            </select>
                                                        </label>
                                                        <label class="text-xs font-medium text-gray-600">Sex
                                                            <select name="dependents[<?= $slot ?>][sex]" <?= $slot === 1 ? 'disabled' : '' ?> class="dependent-required mt-1 block w-full border border-gray-300 bg-white px-3 py-2 text-sm font-normal text-gray-800"><option value="">Select sex</option><option value="Female">Female</option><option value="Male">Male</option></select>
                                                        </label>
                                                        <label class="text-xs font-medium text-gray-600">Civil status
                                                            <select name="dependents[<?= $slot ?>][civil_status]" <?= $slot === 1 ? 'disabled' : '' ?> class="dependent-required mt-1 block w-full border border-gray-300 bg-white px-3 py-2 text-sm font-normal text-gray-800"><option value="">Select status</option><option value="Single">Single</option><option value="Married">Married</option><option value="Widowed">Widowed</option><option value="Separated">Separated</option></select>
                                                        </label>
                                                        <label class="col-span-2 text-xs font-medium text-gray-600">Birth certificate <span class="font-normal text-gray-400">PDF, JPG, or PNG · max 5 MB</span>
                                                            <input type="file" name="dependent_certificates[<?= $slot ?>]" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" <?= $slot === 1 ? 'disabled' : '' ?> class="dependent-required mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-700 file:mr-3 file:border-0 file:bg-gray-100 file:px-3 file:py-1.5 file:text-xs file:font-semibold">
                                                        </label>
                                                    </div>
                                                    <?php if ($slot === 1): ?>
                                                        <button id="removeSecondDependent" type="button" class="mt-3 inline-flex items-center gap-1 text-xs font-semibold text-rose-700 hover:text-rose-900"><i class="bi bi-dash-circle" aria-hidden="true"></i>Remove dependent 2</button>
                                                    <?php endif; ?>
                                                </fieldset>
                                            <?php endfor; ?>
                                        </div>
                                        <div id="addDependentControl" class="mt-3">
                                            <button id="addSecondDependent" type="button" class="inline-flex items-center gap-2 rounded-lg border border-blue-200 px-3.5 py-2 text-sm font-semibold text-blue-700 hover:bg-blue-50"><i class="bi bi-plus-circle" aria-hidden="true"></i>Add dependent 2</button>
                                        </div>
                                        <div id="selectedDependentPremium" class="mt-3 rounded-lg bg-indigo-50 px-4 py-3 text-sm text-indigo-800" aria-live="polite">Select an employee and plan to see the plan’s dependent premium. Each dependent is charged 100% of that configured monthly rate through payroll.</div>
                                    </section>
                                </div>
                                <div class="sticky bottom-0 flex flex-wrap justify-end gap-2 border-t border-gray-200 bg-white px-5 py-4 sm:px-7">
                                    <button id="cancelEnrollmentDialog" type="button" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50">Cancel</button>
                                    <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800"><i class="bi bi-person-check" aria-hidden="true"></i>Enroll benefits</button>
                                </div>
                            </form>
                        </dialog>
                    <?php endif; ?>

                    <dialog id="enrollmentDetailsDialog" class="m-auto w-[min(52rem,calc(100vw-2rem))] max-w-none overflow-y-auto rounded-2xl border border-gray-200 bg-white p-0 shadow-2xl backdrop:bg-slate-950/50">
                        <div class="max-h-[92vh] overflow-y-auto">
                            <div class="sticky top-0 z-10 flex items-start justify-between gap-4 border-b border-gray-200 bg-white px-6 py-5 sm:px-7">
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-wide text-blue-700">Benefits enrollment</p>
                                    <h2 id="enrollmentDetailsTitle" class="mt-1 text-xl font-bold text-gray-900">HMO enrollment</h2>
                                    <p class="mt-1 text-sm text-gray-500">Review the plan, enrollment status, and people covered.</p>
                                </div>
                                <button id="closeEnrollmentDetails" type="button" aria-label="Close enrollment details" class="rounded-lg p-2 text-gray-500 hover:bg-gray-100"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                            </div>
                            <section id="enrollmentDetailsPanel" class="space-y-4 p-5 sm:p-6">
                                <div class="grid gap-x-8 gap-y-3 sm:grid-cols-2">
                                    <article class="border-b border-gray-200 pb-3"><p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500">Employee</p><p id="enrollmentDetailEmployee" class="mt-1 text-sm font-semibold text-gray-900"></p><p id="enrollmentDetailEmployeeMeta" class="mt-0.5 text-xs text-gray-500"></p></article>
                                    <article class="border-b border-gray-200 pb-3"><p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500">Coverage plan</p><p id="enrollmentDetailPlan" class="mt-1 text-sm font-semibold text-gray-900"></p><p id="enrollmentDetailCoverage" class="mt-0.5 text-xs text-gray-500"></p></article>
                                    <article class="border-b border-gray-200 pb-3"><p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500">Enrollment status</p><p id="enrollmentDetailStatus" class="mt-1 text-sm font-semibold text-gray-900"></p><p id="enrollmentDetailTerminated" class="mt-0.5 text-xs text-gray-500"></p></article>
                                    <article id="enrollmentDetailPremiumCard" class="rounded-md border border-indigo-200 bg-indigo-50 px-3 py-2.5">
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <p id="enrollmentDetailPremiumLabel" class="text-[11px] font-semibold uppercase tracking-wide text-indigo-800">Dependent premium</p>
                                            <span id="enrollmentDetailPremiumStatus" class="rounded-full bg-indigo-100 px-2 py-0.5 text-[11px] font-semibold text-indigo-800"></span>
                                        </div>
                                        <p id="enrollmentDetailDeduction" class="mt-1 text-sm font-semibold text-gray-900"></p>
                                        <p id="enrollmentDetailTierRate" class="mt-0.5 text-xs text-gray-600"></p>
                                    </article>
                                </div>
                                <section class="overflow-hidden rounded-lg border border-gray-200" aria-labelledby="enrollmentDependentsHeading">
                                    <div class="flex flex-wrap items-center justify-between gap-2 bg-gray-50 px-3 py-2.5">
                                        <div><h3 id="enrollmentDependentsHeading" class="text-sm font-semibold text-gray-900">Dependents</h3><p class="mt-0.5 text-xs text-gray-500">Includes dependents removed from coverage.</p></div>
                                        <span id="enrollmentDetailDependentCount" class="rounded-full bg-white px-2.5 py-1 text-xs font-semibold text-gray-700 ring-1 ring-gray-200"></span>
                                    </div>
                                    <div id="enrollmentDetailDependents" class="divide-y divide-gray-200 px-3"></div>
                                </section>
                                <section class="border-t border-gray-200 pt-4" aria-labelledby="manageEnrollmentHeading">
                                    <h3 id="manageEnrollmentHeading" class="text-sm font-semibold text-gray-900">Manage enrollment</h3>
                                    <div class="mt-3 grid gap-4 sm:grid-cols-2 sm:gap-0 sm:divide-x sm:divide-gray-200">
                                        <div class="flex flex-col items-start gap-2 border-b border-gray-200 pb-3 sm:border-b-0 sm:py-1 sm:pr-5 sm:pb-1">
                                            <div class="flex items-start gap-3">
                                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-blue-100 text-blue-700"><i class="bi bi-pencil-square" aria-hidden="true"></i></span>
                                                <div>
                                                    <p class="text-sm font-semibold text-gray-900">Update enrollment</p>
                                                    <p class="mt-1 text-sm text-gray-600">Change the selected plan or update covered dependents.</p>
                                                </div>
                                            </div>
                                            <button id="editEnrollmentButton" type="button" class="ml-12 inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700"><i class="bi bi-pencil-square" aria-hidden="true"></i>Edit enrollment</button>
                                        </div>
                                        <form id="terminateEnrollmentForm" method="post" action="index.php?page=benefits" class="flex flex-col items-start gap-2 sm:py-1 sm:pl-5">
                                            <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                                            <input type="hidden" name="action" value="terminate">
                                            <input type="hidden" name="enrollment_id" id="terminateEnrollmentId">
                                            <input type="hidden" name="month" value="<?= $escape($month) ?>">
                                            <input type="hidden" name="active_tab" value="coverage">
                                            <div class="flex items-start gap-3">
                                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-rose-100 text-rose-700"><i class="bi bi-person-x" aria-hidden="true"></i></span>
                                                <div>
                                                    <p class="text-sm font-semibold text-gray-900">End this coverage</p>
                                                    <p class="mt-1 text-sm text-gray-600">Coverage ends on the date you choose. This action cannot be undone.</p>
                                                </div>
                                            </div>
                                            <div class="flex w-full flex-col gap-3 sm:flex-row sm:items-end">
                                                <label class="block flex-1 text-xs font-semibold text-gray-700">Coverage end date<input id="terminationDate" type="date" name="termination_date" value="<?= $escape(date('Y-m-d')) ?>" max="<?= $escape(date('Y-m-d')) ?>" required class="mt-1 block w-full border border-gray-300 bg-white px-3 py-2.5 text-sm"></label>
                                                <button type="submit" class="inline-flex shrink-0 items-center justify-center gap-2 rounded-lg border border-rose-200 px-4 py-2.5 text-sm font-semibold text-rose-700 hover:bg-rose-50 focus:outline-none focus:ring-2 focus:ring-rose-500 focus:ring-offset-1"><i class="bi bi-person-x" aria-hidden="true"></i>End coverage</button>
                                            </div>
                                        </form>
                                    </div>
                                </section>
                            </section>
                            <form id="editEnrollmentForm" method="post" action="index.php?page=benefits" enctype="multipart/form-data" class="hidden">
                                <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                                <input type="hidden" name="action" value="edit_enrollment">
                                <input type="hidden" name="enrollment_id" id="editEnrollmentId">
                                <input type="hidden" name="month" value="<?= $escape($month) ?>">
                                <input type="hidden" name="active_tab" value="coverage">
                                <div class="space-y-4 p-5 sm:p-6">
                                    <div class="border-b border-gray-200 pb-3">
                                        <h3 class="text-base font-semibold text-gray-900">Edit enrollment details</h3>
                                        <p id="editEnrollmentEmployee" class="mt-0.5 text-sm text-gray-500"></p>
                                    </div>
                                    <section class="border-b border-gray-200 pb-4">
                                        <h3 class="text-sm font-semibold text-gray-900">Plan and effective date</h3>
                                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                            <label class="text-xs font-semibold text-gray-600">Provider plan
                                                <select id="editEnrollmentPlan" name="plan_id" required class="mt-1 block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-normal text-gray-800">
                                                    <option value="">Select plan</option>
                                                    <?php foreach ($plans as $plan): ?>
                                                        <option value="<?= (int) $plan['id'] ?>" data-job-tier="<?= $escape((string) $plan['target_job_tier']) ?>" data-is-active="<?= (int) $plan['is_active'] ?>"><?= $escape((string) $plan['provider_name'] . ' · ' . $plan['plan_tier_name'] . ((int) $plan['is_active'] === 1 ? '' : ' · Inactive')) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <span id="editDependentRateInfo" class="mt-1 block font-normal text-gray-500"></span>
                                            </label>
                                            <label class="text-xs font-semibold text-gray-600">Coverage effective date
                                                <input id="editEffectivityDate" type="date" name="effectivity_date" required class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm font-normal text-gray-800">
                                            </label>
                                        </div>
                                    </section>
                                    <section>
                                        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 pb-2">
                                            <div><h3 class="text-sm font-semibold text-gray-900">Covered dependents</h3><p class="mt-1 max-w-2xl text-xs text-gray-500">Add or update people covered by this enrollment.</p></div>
                                            <button id="addEditDependent" type="button" class="inline-flex items-center gap-1.5 px-2 py-1 text-sm font-semibold text-blue-700 hover:text-blue-900"><i class="bi bi-plus-circle" aria-hidden="true"></i>Add dependent</button>
                                        </div>
                                        <div id="editDependentFields" class="mt-2 divide-y divide-gray-200"></div>
                                    </section>
                                </div>
                                <div class="sticky bottom-0 flex flex-col-reverse gap-2 border-t border-gray-200 bg-white px-6 py-4 sm:flex-row sm:justify-end sm:px-7">
                                    <button id="cancelEnrollmentEdit" type="button" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50">Cancel</button>
                                    <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700"><i class="bi bi-check2 mr-1" aria-hidden="true"></i>Review and save</button>
                                </div>
                            </form>
                        </div>
                    </dialog>

                    <dialog id="editEnrollmentConfirmDialog" class="m-auto w-[min(28rem,calc(100vw-2rem))] max-w-none rounded-2xl border border-gray-200 bg-white p-0 shadow-2xl backdrop:bg-slate-950/50">
                        <div class="p-6">
                            <div class="flex items-start gap-4">
                                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-blue-50 text-blue-700"><i class="bi bi-pencil-square text-lg" aria-hidden="true"></i></span>
                                <div><h2 class="text-lg font-semibold text-gray-900">Save these changes?</h2><p id="editEnrollmentConfirmationText" class="mt-2 text-sm leading-6 text-gray-600"></p></div>
                            </div>
                            <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                                <button id="cancelEditEnrollmentConfirmation" type="button" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50">Go back and review</button>
                                <button id="confirmEditEnrollmentSave" type="button" class="rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700">Yes, save changes</button>
                            </div>
                        </div>
                    </dialog>
                    <dialog id="terminateEnrollmentConfirmDialog" class="m-auto w-[min(28rem,calc(100vw-2rem))] max-w-none rounded-2xl border border-gray-200 bg-white p-0 shadow-2xl backdrop:bg-slate-950/50">
                        <div class="p-6">
                            <div class="flex items-start gap-4">
                                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-rose-50 text-rose-700"><i class="bi bi-person-x text-lg" aria-hidden="true"></i></span>
                                <div><h2 class="text-lg font-semibold text-gray-900">Terminate this coverage?</h2><p id="terminateEnrollmentConfirmationText" class="mt-2 text-sm leading-6 text-gray-600"></p><p class="mt-3 rounded-lg bg-rose-50 px-3 py-2 text-xs leading-5 text-rose-800">This ends the employee’s HMO coverage. Existing payroll records remain available for audit.</p></div>
                            </div>
                            <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                                <button id="cancelTerminateEnrollmentConfirmation" type="button" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50">Keep coverage</button>
                                <button id="confirmTerminateEnrollment" type="button" class="rounded-lg bg-rose-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-rose-700">Yes, terminate coverage</button>
                            </div>
                        </div>
                    </dialog>
                    <dialog id="removeDependentConfirmDialog" class="m-auto w-[min(28rem,calc(100vw-2rem))] max-w-none rounded-2xl border border-gray-200 bg-white p-0 shadow-2xl backdrop:bg-slate-950/50">
                        <div class="p-6">
                            <div class="flex items-start gap-4">
                                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-amber-50 text-amber-700"><i class="bi bi-person-dash text-lg" aria-hidden="true"></i></span>
                                <div><h2 class="text-lg font-semibold text-gray-900">Remove this dependent?</h2><p id="removeDependentConfirmationText" class="mt-2 text-sm leading-6 text-gray-600"></p><p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-800">The dependent will only be removed after you confirm and save the enrollment changes.</p></div>
                            </div>
                            <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                                <button id="cancelRemoveDependent" type="button" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50">Keep dependent</button>
                                <button id="confirmRemoveDependent" type="button" class="rounded-lg bg-rose-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-rose-700">Remove dependent</button>
                            </div>
                        </div>
                    </dialog>

                    <section id="hmoPlansPanel" role="tabpanel" aria-labelledby="hmoPlansTab" class="hidden space-y-6" hidden>
                        <div class="flex flex-wrap items-end justify-between gap-3">
                            <div>
                                <h3 class="text-lg font-semibold text-gray-900">HMO plan reference</h3>
                                <p class="mt-1 text-sm text-gray-500">Quick reference for plan coverage and employee-tier dependent rates.</p>
                            </div>
                            <span class="text-xs text-gray-400">Primary premiums are company-paid; dependent deductions are employee-paid.</span>
                        </div>

                        <section aria-labelledby="hmoPlansHeading">
                            <div class="mb-3 flex items-center justify-between">
                                <h4 id="hmoPlansHeading" class="text-sm font-semibold uppercase tracking-wide text-gray-500">Provider plans</h4>
                                <span class="text-xs text-gray-400">by employee tier</span>
                            </div>
                            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                                <?php foreach ($plans as $plan): ?>
                                    <article class="border border-gray-200 bg-white p-5 shadow-sm">
                                        <div class="flex items-center justify-between gap-3">
                                            <span class="flex h-10 w-10 items-center justify-center bg-slate-50 text-slate-600"><i class="bi bi-heart-pulse text-lg" aria-hidden="true"></i></span>
                                            <span class="text-[11px] font-semibold uppercase tracking-wide <?= (int) $plan['is_active'] === 1 ? 'text-emerald-700' : 'text-gray-400' ?>"><?= (int) $plan['is_active'] === 1 ? 'Available' : 'Inactive' ?></span>
                                        </div>
                                        <h5 class="mt-4 text-sm font-semibold text-gray-800"><?= $escape((string) $plan['provider_name'] . ' · ' . $plan['plan_tier_name']) ?></h5>
                                        <p class="mt-1 text-xs font-medium text-gray-500"><?= $escape((string) $plan['target_job_tier']) ?></p>
                                        <p class="mt-4 text-[11px] font-semibold uppercase tracking-wide text-gray-400">Coverage per illness</p>
                                        <p class="mt-1 text-2xl font-bold tracking-tight text-gray-950"><?= $money((float) $plan['max_coverage_limit']) ?></p>
                                        <div class="mt-3 space-y-2 border-t border-gray-100 pt-3 text-xs text-gray-500">
                                            <p class="flex items-center justify-between gap-2"><span>Primary premium / year</span><span class="font-semibold text-gray-700"><?= $money((float) $plan['annual_premium_cost']) ?></span></p>
                                            <p class="flex items-center justify-between gap-2"><span>Dependent / month</span><span class="font-semibold text-gray-700"><?= $money((float) ($dependentRatesByTier[(string) $plan['target_job_tier']] ?? 0)) ?></span></p>
                                            <p class="flex items-center justify-between gap-2"><span>Room & board</span><span class="font-medium text-gray-700"><?= $escape((string) $plan['room_and_board']) ?></span></p>
                                        </div>
                                        <p class="mt-3 text-[11px] text-gray-500"><?= (int) $plan['parent_dependents_allowed'] === 1 ? 'Eligible parents age 65 or below allowed' : 'Parent dependents not covered' ?></p>
                                    </article>
                                <?php endforeach; ?>
                                <?php if ($plans === []): ?>
                                    <article class="border border-gray-200 bg-white p-5 text-sm text-gray-500 sm:col-span-2 xl:col-span-3">No HMO plans are configured yet.</article>
                                <?php endif; ?>
                            </div>
                        </section>

                        <section aria-labelledby="hmoPlanRulesHeading">
                            <div class="mb-3 flex items-center justify-between">
                                <h4 id="hmoPlanRulesHeading" class="text-sm font-semibold uppercase tracking-wide text-gray-500">Enrollment rules</h4>
                                <span class="text-xs text-gray-400">check before enrollment</span>
                            </div>
                            <div class="grid gap-3 lg:grid-cols-3">
                                <article class="border border-gray-200 bg-white p-5">
                                    <div class="flex items-center gap-3"><span class="flex h-9 w-9 items-center justify-center bg-blue-50 text-blue-700"><i class="bi bi-person-check" aria-hidden="true"></i></span><h5 class="text-sm font-semibold text-gray-900">Employee eligibility</h5></div>
                                    <ul class="mt-4 space-y-2 text-sm text-gray-600">
                                        <li class="flex gap-2"><i class="bi bi-dot text-blue-600" aria-hidden="true"></i><span>Employee must be active and full-time.</span></li>
                                        <li class="flex gap-2"><i class="bi bi-dot text-blue-600" aria-hidden="true"></i><span>Employees with active HMO coverage cannot enroll again.</span></li>
                                        <li class="flex gap-2"><i class="bi bi-dot text-blue-600" aria-hidden="true"></i><span>Selected plan must match the employee’s job tier.</span></li>
                                    </ul>
                                </article>
                                <article class="border border-gray-200 bg-white p-5">
                                    <div class="flex items-center gap-3"><span class="flex h-9 w-9 items-center justify-center bg-amber-50 text-amber-700"><i class="bi bi-people" aria-hidden="true"></i></span><h5 class="text-sm font-semibold text-gray-900">Dependent coverage</h5></div>
                                    <ul class="mt-4 space-y-2 text-sm text-gray-600">
                                        <li class="flex gap-2"><i class="bi bi-dot text-amber-600" aria-hidden="true"></i><span>Up to two eligible dependents per employee.</span></li>
                                        <li class="flex gap-2"><i class="bi bi-dot text-amber-600" aria-hidden="true"></i><span>Parent coverage depends on plan eligibility and is limited to age 65 or below.</span></li>
                                    </ul>
                                </article>
                                <article class="border border-gray-200 bg-white p-5">
                                    <div class="flex items-center gap-3"><span class="flex h-9 w-9 items-center justify-center bg-indigo-50 text-indigo-700"><i class="bi bi-cash-coin" aria-hidden="true"></i></span><h5 class="text-sm font-semibold text-gray-900">Monthly deductions</h5></div>
                                    <ul class="mt-4 space-y-2 text-sm text-gray-600">
                                        <?php foreach ($dependentRatesByTier as $tier => $rate): ?>
                                            <li class="flex items-center justify-between gap-2"><span><?= $escape($tier) ?></span><span class="font-semibold text-gray-800"><?= $money($rate) ?> / dependent</span></li>
                                        <?php endforeach; ?>
                                        <li class="border-t border-gray-100 pt-2 text-xs text-gray-500">100% employee-paid through payroll.</li>
                                    </ul>
                                </article>
                            </div>
                        </section>
                    </section>

                <?php endif; ?>
            </div>
            </main>
        </div>
    </div>
    <?php require __DIR__ . '/../partials/workspace_scripts.php'; ?>
    <?php if ($hmo['canManage'] ?? false): ?>
        <script>
            (() => {
                const tabs = [...document.querySelectorAll('[role="tab"][aria-controls^="hmo"]')];
                const activate = (selected) => {
                    tabs.forEach((tab) => {
                        const active = tab === selected;
                        const panel = document.getElementById(tab.getAttribute('aria-controls'));
                        tab.setAttribute('aria-selected', String(active));
                        tab.tabIndex = active ? 0 : -1;
                        tab.classList.toggle('border-blue-600', active);
                        tab.classList.toggle('text-blue-700', active);
                        tab.classList.toggle('border-transparent', !active);
                        tab.classList.toggle('text-gray-500', !active);
                        panel.hidden = !active;
                        panel.classList.toggle('hidden', !active);
                    });
                };
                tabs.forEach((tab) => tab.addEventListener('click', () => activate(tab)));
                const activeTabName = <?= json_encode($activeTab, JSON_THROW_ON_ERROR) ?>;
                const activeTabIds = {
                    coverage: 'hmoCoverageTab',
                    plans: 'hmoPlansTab',
                };
                const remembered = activeTabIds[activeTabName];
                const initial = tabs.find((tab) => tab.id === remembered) ?? tabs[0];
                if (initial) activate(initial);

                document.querySelectorAll('.hmo-plan-tier').forEach((tierSelect) => {
                    const rateLabel = tierSelect.parentElement.querySelector('.hmo-dependent-rate');
                    const rates = JSON.parse(tierSelect.dataset.dependentRates);
                    const updateRateLabel = () => {
                        const rate = Number(rates[tierSelect.value]);
                        rateLabel.textContent = `Dependent rate: ₱${rate.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} per dependent / month (100% employee-paid).`;
                    };
                    tierSelect.addEventListener('change', updateRateLabel);
                    updateRateLabel();
                });

                const enrollmentDialog = document.getElementById('enrollmentDialog');
                const openEnrollmentDialog = document.getElementById('openEnrollmentDialog');
                if (enrollmentDialog && openEnrollmentDialog) {
                    const employeeSearch = document.getElementById('enrollmentEmployeeSearch');
                    const employeeId = document.getElementById('enrollmentEmployee');
                    const employeeResults = document.getElementById('enrollmentEmployeeResults');
                    const employeeSearchStatus = document.getElementById('enrollmentEmployeeSearchStatus');
                    const fullName = document.getElementById('enrollmentFullName');
                    const jobTier = document.getElementById('enrollmentJobTier');
                    const planSelect = document.getElementById('enrollmentPlan');
                    const dependentPremium = document.getElementById('selectedDependentPremium');
                    const dependentTwo = document.getElementById('dependentEntry1');
                    const addDependentControl = document.getElementById('addDependentControl');
                    let employeeSearchTimer;
                    let employeeSearchRequest;
                    const resetPlanForEmployee = (tier) => {
                        planSelect.value = '';
                        planSelect.disabled = tier === '';
                        planSelect.classList.toggle('bg-gray-100', tier === '');
                        planSelect.classList.toggle('bg-white', tier !== '');
                        planSelect.options[0].textContent = tier === '' ? 'Select employee first' : 'Select matching plan';
                        dependentPremium.textContent = 'Select a plan to see the fixed dependent rate for the employee tier. The full monthly rate is deducted per dependent through payroll.';
                        [...planSelect.options].slice(1).forEach((option) => {
                            option.hidden = option.dataset.jobTier !== tier;
                            option.disabled = option.hidden;
                        });
                    };
                    const clearEmployeeSelection = () => {
                        employeeId.value = '';
                        fullName.value = '';
                        jobTier.value = '';
                        employeeSearch.setCustomValidity('');
                        resetPlanForEmployee('');
                    };
                    const closeEmployeeResults = () => {
                        employeeResults.replaceChildren();
                        employeeResults.classList.add('hidden');
                        employeeSearch.setAttribute('aria-expanded', 'false');
                    };
                    const showEmployeeResults = (employees) => {
                        employeeResults.replaceChildren();
                        employees.forEach((employee) => {
                            const option = document.createElement('button');
                            option.type = 'button';
                            option.role = 'option';
                            option.className = 'block w-full border-b border-gray-100 px-3 py-2.5 text-left last:border-b-0 hover:bg-blue-50 focus:bg-blue-50 focus:outline-none';
                            const label = document.createElement('span');
                            label.className = 'block text-sm font-semibold text-gray-800';
                            label.textContent = `${employee.employee_code} · ${employee.first_name} ${employee.last_name}`;
                            const details = document.createElement('span');
                            details.className = 'mt-0.5 block text-xs font-normal text-gray-500';
                            details.textContent = `${employee.department} · ${employee.job_tier}`;
                            option.append(label, details);
                            option.addEventListener('click', () => {
                                employeeId.value = employee.id;
                                employeeSearch.value = `${employee.employee_code} · ${employee.first_name} ${employee.last_name}`;
                                fullName.value = `${employee.first_name} ${employee.last_name}`;
                                jobTier.value = employee.job_tier;
                                employeeSearch.setCustomValidity('');
                                employeeSearchStatus.textContent = 'Employee selected. Change the search to choose someone else.';
                                resetPlanForEmployee(employee.job_tier);
                                closeEmployeeResults();
                            });
                            employeeResults.append(option);
                        });
                        employeeResults.classList.remove('hidden');
                        employeeSearch.setAttribute('aria-expanded', 'true');
                    };
                    const searchEligibleEmployees = async (query) => {
                        if (employeeSearchRequest) employeeSearchRequest.abort();
                        employeeSearchRequest = new AbortController();
                        employeeSearchStatus.textContent = 'Searching eligible employees…';
                        try {
                            const response = await fetch(`index.php?page=benefits_employee_search&q=${encodeURIComponent(query)}`, {
                                headers: { Accept: 'application/json' },
                                signal: employeeSearchRequest.signal,
                            });
                            const result = await response.json();
                            if (!response.ok) throw new Error(result.error ?? 'Employee search is unavailable.');
                            showEmployeeResults(result.employees);
                            employeeSearchStatus.textContent = result.employees.length === 0
                                ? 'No eligible employees matched that search.'
                                : `${result.employees.length === 20 ? 'Showing the first 20 matches.' : `${result.employees.length} match${result.employees.length === 1 ? '' : 'es'} found.`} Select an employee to continue.`;
                        } catch (error) {
                            if (error.name === 'AbortError') return;
                            closeEmployeeResults();
                            employeeSearchStatus.textContent = error.message || 'Employee search is unavailable. Try again.';
                        }
                    };
                    employeeSearch.addEventListener('input', () => {
                        clearEmployeeSelection();
                        closeEmployeeResults();
                        window.clearTimeout(employeeSearchTimer);
                        const query = employeeSearch.value.trim();
                        if (query.length < 2) {
                            if (employeeSearchRequest) employeeSearchRequest.abort();
                            employeeSearchStatus.textContent = 'Enter at least 2 characters to search eligible employees.';
                            return;
                        }
                        employeeSearchTimer = window.setTimeout(() => searchEligibleEmployees(query), 250);
                    });
                    document.getElementById('hmoEnrollmentForm').addEventListener('submit', (event) => {
                        if (employeeId.value === '') {
                            event.preventDefault();
                            employeeSearch.setCustomValidity('Select an eligible employee from the search results.');
                            employeeSearch.reportValidity();
                        }
                    });
                    openEnrollmentDialog.addEventListener('click', () => {
                        enrollmentDialog.showModal();
                        employeeSearch.focus();
                    });
                    document.getElementById('closeEnrollmentDialog').addEventListener('click', () => enrollmentDialog.close());
                    document.getElementById('cancelEnrollmentDialog').addEventListener('click', () => enrollmentDialog.close());
                    enrollmentDialog.addEventListener('click', (event) => {
                        if (event.target === enrollmentDialog) enrollmentDialog.close();
                    });
                    planSelect.addEventListener('change', () => {
                        const selectedPlan = planSelect.selectedOptions[0];
                        if (!selectedPlan?.value) {
                            dependentPremium.textContent = 'Select a plan to see the fixed dependent rate for the employee tier. The full monthly rate is deducted per dependent through payroll.';
                            return;
                        }
                        const monthlyRate = Number(selectedPlan.dataset.dependentMonthlyPremium ?? 0);
                        dependentPremium.textContent = monthlyRate > 0
                            ? `Dependent premium: ₱${monthlyRate.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} per dependent, per month. This plan rate is deducted at 100% from payroll.`
                            : 'The dependent premium for this employee tier is unavailable.';
                    });
                    document.querySelectorAll('.dependent-entry').forEach((entry) => {
                        const fields = [...entry.querySelectorAll('.dependent-fields input, .dependent-fields select')];
                        const updateRequirements = () => {
                            const hasDetails = fields.some((field) =>
                                field.type === 'file' ? field.files.length > 0 : field.value.trim() !== ''
                            );
                            fields.forEach((field) => {
                                field.required = hasDetails && field.classList.contains('dependent-required');
                            });
                        };
                        fields.forEach((field) => {
                            field.addEventListener('input', updateRequirements);
                            field.addEventListener('change', updateRequirements);
                        });
                        fields.forEach((field) => {
                            if (entry.hidden) field.disabled = true;
                        });
                        updateRequirements();
                    });
                    document.getElementById('addSecondDependent').addEventListener('click', () => {
                        dependentTwo.hidden = false;
                        dependentTwo.querySelectorAll('.dependent-fields input, .dependent-fields select').forEach((field) => {
                            field.disabled = false;
                        });
                        addDependentControl.hidden = true;
                        dependentTwo.querySelector('.dependent-required').focus();
                    });
                    document.getElementById('removeSecondDependent').addEventListener('click', () => {
                        dependentTwo.querySelectorAll('.dependent-fields input, .dependent-fields select').forEach((field) => {
                            if (field.type === 'file') field.value = '';
                            else field.value = '';
                            field.disabled = true;
                            field.required = false;
                        });
                        dependentTwo.hidden = true;
                        addDependentControl.hidden = false;
                    });
                }

                const detailsDialog = document.getElementById('enrollmentDetailsDialog');
                if (detailsDialog) {
                    const detailsPanel = document.getElementById('enrollmentDetailsPanel');
                    const editForm = document.getElementById('editEnrollmentForm');
                    const dependentFields = document.getElementById('editDependentFields');
                    const editPlan = document.getElementById('editEnrollmentPlan');
                    const addDependentButton = document.getElementById('addEditDependent');
                    const editConfirmationDialog = document.getElementById('editEnrollmentConfirmDialog');
                    const terminateConfirmationDialog = document.getElementById('terminateEnrollmentConfirmDialog');
                    const removeDependentConfirmationDialog = document.getElementById('removeDependentConfirmDialog');
                    const terminateForm = document.getElementById('terminateEnrollmentForm');
                    let selectedEnrollment = null;
                    let confirmEditSubmit = false;
                    let confirmTerminationSubmit = false;
                    let pendingDependentRemoval = null;
                    const setText = (id, value) => {
                        document.getElementById(id).textContent = value ?? '';
                    };
                    const tierRates = <?= json_encode(array_map('floatval', HmoService::DEPENDENT_MONTHLY_PREMIUMS), JSON_THROW_ON_ERROR) ?>;
                    const formatMoney = (value) => `₱${Number(value).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
                    const fieldClass = 'mt-1 block w-full border border-gray-300 px-3 py-2 text-sm font-normal text-gray-800';
                    const labelClass = 'text-xs font-medium text-gray-600';
                    const dependentTypes = [
                        ['last_name', 'Last name', 'text', true],
                        ['first_name', 'First name', 'text', true],
                        ['middle_initial', 'Middle initial (optional)', 'text', false],
                        ['name_extension', 'Name extension (optional)', 'text', false],
                        ['age', 'Age', 'number', true],
                        ['relationship', 'Relationship', 'select', true],
                        ['sex', 'Sex', 'select', true],
                        ['civil_status', 'Civil status', 'select', true],
                    ];
                    const optionsFor = (key) => ({
                        relationship: [['', 'Select relationship'], ['Spouse', 'Legal spouse'], ['Child', 'Child'], ['Parent', 'Parent (plan permitting)']],
                        sex: [['', 'Select sex'], ['Female', 'Female'], ['Male', 'Male']],
                        civil_status: [['', 'Select status'], ['Single', 'Single'], ['Married', 'Married'], ['Widowed', 'Widowed'], ['Separated', 'Separated']],
                    })[key];
                    const buildDependentFieldset = (dependent, index) => {
                        const fieldset = document.createElement('fieldset');
                        fieldset.className = 'edit-dependent-entry border-b border-gray-200 bg-gray-50/60 px-3 py-3 last:border-b-0';
                        fieldset.dataset.slot = String(index);
                        const legend = document.createElement('legend');
                        legend.className = 'px-1 text-xs font-semibold text-gray-700';
                        legend.textContent = `Dependent ${index + 1}`;
                        fieldset.append(legend);
                        const hiddenId = document.createElement('input');
                        hiddenId.type = 'hidden';
                        hiddenId.dataset.dependentField = 'dependent_id';
                        hiddenId.value = dependent?.id ? String(dependent.id) : '';
                        fieldset.append(hiddenId);
                        const grid = document.createElement('div');
                        grid.className = 'mt-2 grid grid-cols-1 gap-3 sm:grid-cols-2';
                        dependentTypes.forEach(([key, title, type, required]) => {
                            const label = document.createElement('label');
                            label.className = labelClass;
                            label.append(document.createTextNode(title));
                            const control = type === 'select' ? document.createElement('select') : document.createElement('input');
                            control.dataset.dependentField = key;
                            control.className = fieldClass;
                            if (type === 'select') {
                                optionsFor(key).forEach(([value, text]) => {
                                    const option = document.createElement('option');
                                    option.value = value;
                                    option.textContent = text;
                                    control.append(option);
                                });
                            } else {
                                control.type = type;
                                if (type === 'number') {
                                    control.min = '0';
                                    control.max = '120';
                                } else {
                                    control.maxLength = key === 'middle_initial' ? 5 : (key === 'name_extension' ? 20 : 100);
                                }
                            }
                            control.name = `dependents[${index}][${key}]`;
                            control.value = dependent?.[key] ?? '';
                            control.required = required;
                            label.append(control);
                            grid.append(label);
                        });
                        const certificateSection = document.createElement('div');
                        certificateSection.className = 'col-span-2 border-t border-gray-200 pt-2';
                        if (dependent?.certificate_url) {
                            const currentCertificate = document.createElement('div');
                            currentCertificate.className = 'mb-2 flex flex-wrap items-center justify-between gap-2';
                            const currentCertificateLabel = document.createElement('p');
                            currentCertificateLabel.className = 'inline-flex items-center gap-2 text-xs font-medium text-gray-700';
                            const currentCertificateIcon = document.createElement('i');
                            currentCertificateIcon.className = 'bi bi-file-earmark-check text-gray-500';
                            currentCertificateIcon.setAttribute('aria-hidden', 'true');
                            currentCertificateLabel.append(currentCertificateIcon, document.createTextNode('Birth certificate on file'));
                            const link = document.createElement('a');
                            link.href = dependent.certificate_url;
                            link.target = '_blank';
                            link.rel = 'noopener';
                            link.className = 'inline-flex items-center gap-1.5 text-xs font-semibold text-blue-700 hover:text-blue-900';
                            link.textContent = 'View current certificate';
                            currentCertificate.append(currentCertificateLabel, link);
                            certificateSection.append(currentCertificate);
                        }
                        const certificateLabel = document.createElement('label');
                        certificateLabel.className = labelClass;
                        certificateLabel.append(document.createTextNode(
                            dependent?.certificate_url
                                ? 'Replace certificate (optional)'
                                : 'Birth certificate (required)'
                        ));
                        const certificateInput = document.createElement('input');
                        certificateInput.type = 'file';
                        certificateInput.accept = '.pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png';
                        certificateInput.name = `dependent_certificates[${index}]`;
                        certificateInput.dataset.dependentFile = '1';
                        certificateInput.className = 'mt-1 block w-full rounded-md border border-gray-300 bg-white px-2 py-1.5 text-xs text-gray-700';
                        certificateInput.required = !dependent?.id;
                        certificateLabel.append(certificateInput);
                        const certificateHint = document.createElement('span');
                        certificateHint.className = 'mt-1 block text-[11px] font-normal text-gray-500';
                        certificateHint.textContent = dependent?.certificate_url
                            ? 'PDF, JPG, or PNG · max 5 MB. The saved certificate stays unless replaced.'
                            : 'Choose a PDF, JPG, or PNG file · max 5 MB.';
                        certificateLabel.append(certificateHint);
                        certificateSection.append(certificateLabel);
                        grid.append(certificateSection);
                        fieldset.append(grid);
                        const remove = document.createElement('button');
                        remove.type = 'button';
                        remove.className = 'inline-flex items-center gap-1.5 px-2 py-1 text-xs font-semibold text-rose-700 hover:text-rose-900';
                        remove.innerHTML = '<i class="bi bi-person-dash" aria-hidden="true"></i>Remove from coverage';
                        remove.addEventListener('click', () => {
                            pendingDependentRemoval = fieldset;
                            const dependentName = [dependent?.first_name, dependent?.last_name].filter(Boolean).join(' ') || 'this dependent';
                            setText('removeDependentConfirmationText', `${dependentName} will no longer be included in this enrollment after you save these changes.`);
                            removeDependentConfirmationDialog.showModal();
                        });
                        const removeSection = document.createElement('div');
                        removeSection.className = 'mt-2 flex flex-wrap items-center justify-between gap-2 border-t border-gray-200 pt-2';
                        const removeDescription = document.createElement('p');
                        removeDescription.className = 'text-[11px] text-gray-500';
                        removeDescription.textContent = 'Removal applies after confirmation and save.';
                        removeSection.append(removeDescription, remove);
                        fieldset.append(removeSection);
                        return fieldset;
                    };
                    const renumberDependentFields = () => {
                        const entries = [...dependentFields.querySelectorAll('.edit-dependent-entry')];
                        entries.forEach((entry, index) => {
                            entry.dataset.slot = String(index);
                            entry.querySelector('legend').textContent = `Dependent ${index + 1}`;
                            entry.querySelectorAll('[data-dependent-field]').forEach((field) => {
                                field.name = `dependents[${index}][${field.dataset.dependentField}]`;
                            });
                            const certificate = entry.querySelector('[data-dependent-file]');
                            certificate.name = `dependent_certificates[${index}]`;
                        });
                        addDependentButton.hidden = entries.length >= 2;
                    };
                    const updatePlanRates = () => {
                        const tier = selectedEnrollment?.jobTier ?? '';
                        [...editPlan.options].slice(1).forEach((candidate) => {
                            const tierMismatch = candidate.dataset.jobTier !== tier;
                            const inactivePlan = candidate.dataset.isActive !== '1'
                                && candidate.value !== String(selectedEnrollment?.planId);
                            candidate.hidden = tierMismatch;
                            candidate.disabled = tierMismatch || inactivePlan;
                        });
                        const monthlyRate = tierRates[tier];
                        document.getElementById('editDependentRateInfo').textContent = monthlyRate
                            ? `${formatMoney(monthlyRate)} per dependent / month · 100% employee-paid`
                            : '';
                    };
                    const populateDetails = (enrollment) => {
                        selectedEnrollment = enrollment;
                        setText('enrollmentDetailsTitle', `HMO enrollment #${enrollment.id}`);
                        document.getElementById('enrollmentDetailsTitle').classList.remove('hidden');
                        setText('enrollmentDetailEmployee', enrollment.employee);
                        setText('enrollmentDetailEmployeeMeta', `${enrollment.employeeCode} · ${enrollment.department} · ${enrollment.jobTier}`);
                        setText('enrollmentDetailPlan', `${enrollment.provider} · ${enrollment.planTier}`);
                        setText('enrollmentDetailCoverage', `Effective ${enrollment.effectivityDate}`);
                        setText('enrollmentDetailStatus', enrollment.status);
                        setText('enrollmentDetailTerminated', enrollment.terminatedAt ? `Terminated ${enrollment.terminatedAt}` : '');
                        const activeDependentCount = enrollment.dependents.filter((dependent) => dependent.active).length;
                        setText('enrollmentDetailDeduction', `${formatMoney(enrollment.dependentDeduction)} / month`);
                        setText('enrollmentDetailTierRate', `${formatMoney(tierRates[enrollment.jobTier])} per dependent / month · 100% employee-paid`);
                        const premiumCard = document.getElementById('enrollmentDetailPremiumCard');
                        const premiumLabel = document.getElementById('enrollmentDetailPremiumLabel');
                        const premiumStatus = document.getElementById('enrollmentDetailPremiumStatus');
                        premiumStatus.textContent = activeDependentCount
                            ? `${activeDependentCount} covered dependent${activeDependentCount === 1 ? '' : 's'}`
                            : 'No covered dependents';
                        premiumCard.classList.toggle('border-indigo-200', activeDependentCount > 0);
                        premiumCard.classList.toggle('bg-indigo-50', activeDependentCount > 0);
                        premiumCard.classList.toggle('border-gray-200', activeDependentCount === 0);
                        premiumCard.classList.toggle('bg-gray-50', activeDependentCount === 0);
                        premiumLabel.classList.toggle('text-indigo-800', activeDependentCount > 0);
                        premiumLabel.classList.toggle('text-gray-700', activeDependentCount === 0);
                        premiumStatus.classList.toggle('bg-indigo-100', activeDependentCount > 0);
                        premiumStatus.classList.toggle('bg-gray-200', activeDependentCount === 0);
                        premiumStatus.classList.toggle('text-indigo-800', activeDependentCount > 0);
                        premiumStatus.classList.toggle('text-gray-700', activeDependentCount === 0);
                        const listedDependentCount = enrollment.dependents.length;
                        setText(
                            'enrollmentDetailDependentCount',
                            activeDependentCount === listedDependentCount
                                ? `${listedDependentCount} ${listedDependentCount === 1 ? 'dependent' : 'dependents'} · all covered`
                                : `${listedDependentCount} listed · ${activeDependentCount} covered`
                        );
                        const dependentList = document.getElementById('enrollmentDetailDependents');
                        dependentList.replaceChildren();
                        if (!enrollment.dependents.length) {
                            const empty = document.createElement('p');
                            empty.className = 'py-3 text-sm text-gray-500';
                            empty.textContent = 'No dependents are currently covered by this enrollment.';
                            dependentList.append(empty);
                        }
                        enrollment.dependents.forEach((dependent) => {
                            const card = document.createElement('article');
                            card.className = 'flex flex-wrap items-center justify-between gap-x-4 gap-y-2 py-3';
                            const name = document.createElement('p');
                            name.className = 'text-sm font-semibold text-gray-900';
                            name.textContent = `${dependent.name} · age ${dependent.age}`;
                            const meta = document.createElement('p');
                            meta.className = 'mt-0.5 text-sm text-gray-500';
                            meta.textContent = `${dependent.relationship} · ${dependent.sex || '—'} · ${dependent.civil_status || '—'} · ${dependent.active ? 'Covered' : 'Removed from coverage'}`;
                            card.append(name, meta);
                            if (dependent.certificate_url) {
                                const link = document.createElement('a');
                                link.href = dependent.certificate_url;
                                link.target = '_blank';
                                link.rel = 'noopener';
                                link.className = 'inline-flex items-center gap-2 text-sm font-semibold text-blue-700 hover:text-blue-800';
                                const icon = document.createElement('i');
                                icon.className = 'bi bi-file-earmark-text';
                                icon.setAttribute('aria-hidden', 'true');
                                link.textContent = 'View birth certificate';
                                link.prepend(icon);
                                card.append(link);
                            }
                            dependentList.append(card);
                        });
                        const active = enrollment.status === 'Active';
                        document.getElementById('editEnrollmentButton').hidden = !active;
                        document.getElementById('terminateEnrollmentForm').hidden = !active;
                        document.getElementById('terminateEnrollmentId').value = String(enrollment.id);
                        document.getElementById('terminationDate').min = enrollment.effectivityDate;
                        detailsPanel.hidden = false;
                        editForm.classList.add('hidden');
                    };
                    document.querySelectorAll('[data-enrollment-details]').forEach((button) => {
                        button.addEventListener('click', () => {
                            populateDetails(JSON.parse(button.dataset.enrollmentDetails));
                            detailsDialog.showModal();
                        });
                    });
                    document.getElementById('closeEnrollmentDetails').addEventListener('click', () => detailsDialog.close());
                    detailsDialog.addEventListener('click', (event) => {
                        if (event.target === detailsDialog) detailsDialog.close();
                    });
                    terminateForm.addEventListener('submit', (event) => {
                        if (confirmTerminationSubmit) {
                            confirmTerminationSubmit = false;
                            return;
                        }
                        if (!terminateForm.reportValidity()) {
                            event.preventDefault();
                            return;
                        }
                        event.preventDefault();
                        const date = document.getElementById('terminationDate').value;
                        setText('terminateEnrollmentConfirmationText', `${selectedEnrollment.employee}’s HMO coverage will end on ${date}. Please confirm that you want to continue.`);
                        terminateConfirmationDialog.showModal();
                    });
                    document.getElementById('cancelTerminateEnrollmentConfirmation').addEventListener('click', () => terminateConfirmationDialog.close());
                    document.getElementById('confirmTerminateEnrollment').addEventListener('click', () => {
                        confirmTerminationSubmit = true;
                        terminateConfirmationDialog.close();
                        terminateForm.requestSubmit();
                    });
                    document.getElementById('cancelRemoveDependent').addEventListener('click', () => {
                        pendingDependentRemoval = null;
                        removeDependentConfirmationDialog.close();
                    });
                    document.getElementById('confirmRemoveDependent').addEventListener('click', () => {
                        if (pendingDependentRemoval) {
                            pendingDependentRemoval.remove();
                            pendingDependentRemoval = null;
                            renumberDependentFields();
                        }
                        removeDependentConfirmationDialog.close();
                    });
                    document.getElementById('editEnrollmentButton').addEventListener('click', () => {
                        document.getElementById('editEnrollmentId').value = String(selectedEnrollment.id);
                        document.getElementById('editEnrollmentEmployee').textContent = `${selectedEnrollment.employee} · ${selectedEnrollment.employeeCode} · ${selectedEnrollment.jobTier}`;
                        document.getElementById('editEffectivityDate').value = selectedEnrollment.effectivityDate;
                        editPlan.value = String(selectedEnrollment.planId);
                        updatePlanRates();
                        dependentFields.replaceChildren();
                        selectedEnrollment.dependents.filter((dependent) => dependent.active).forEach((dependent, index) => {
                            dependentFields.append(buildDependentFieldset(dependent, index));
                        });
                        renumberDependentFields();
                        detailsPanel.hidden = true;
                        editForm.classList.remove('hidden');
                        document.getElementById('enrollmentDetailsTitle').classList.add('hidden');
                        editForm.scrollIntoView({ block: 'start' });
                    });
                    document.getElementById('cancelEnrollmentEdit').addEventListener('click', () => {
                        editForm.classList.add('hidden');
                        document.getElementById('enrollmentDetailsTitle').classList.remove('hidden');
                        detailsPanel.hidden = false;
                    });
                    editPlan.addEventListener('change', updatePlanRates);
                    addDependentButton.addEventListener('click', () => {
                        const entries = dependentFields.querySelectorAll('.edit-dependent-entry');
                        if (entries.length < 2) {
                            dependentFields.append(buildDependentFieldset(null, entries.length));
                            renumberDependentFields();
                        }
                    });
                    editForm.addEventListener('submit', (event) => {
                        if (confirmEditSubmit) {
                            confirmEditSubmit = false;
                            return;
                        }
                        if (!editForm.reportValidity()) {
                            event.preventDefault();
                            return;
                        }
                        const fields = [...dependentFields.querySelectorAll('.edit-dependent-entry')];
                        if (fields.length === 2) {
                            const firstRelationship = fields[0].querySelector('[data-dependent-field="relationship"]').value;
                            const secondRelationship = fields[1].querySelector('[data-dependent-field="relationship"]').value;
                            if (firstRelationship === 'Parent' && secondRelationship !== 'Parent') {
                                event.preventDefault();
                                window.alert('List dependents in policy order: spouse, children youngest-to-oldest, then eligible parent.');
                                return;
                            }
                        }
                        event.preventDefault();
                        const selectedPlanLabel = editPlan.selectedOptions[0]?.textContent.trim() ?? 'the selected plan';
                        const dependentCount = dependentFields.querySelectorAll('.edit-dependent-entry').length;
                        setText(
                            'editEnrollmentConfirmationText',
                            `You’re saving ${selectedPlanLabel} for ${selectedEnrollment.employee}, effective ${document.getElementById('editEffectivityDate').value}, with ${dependentCount} covered dependent${dependentCount === 1 ? '' : 's'}.`
                        );
                        editConfirmationDialog.showModal();
                    });
                    document.getElementById('cancelEditEnrollmentConfirmation').addEventListener('click', () => editConfirmationDialog.close());
                    document.getElementById('confirmEditEnrollmentSave').addEventListener('click', () => {
                        confirmEditSubmit = true;
                        editConfirmationDialog.close();
                        editForm.requestSubmit();
                    });
                }
            })();
        </script>
    <?php endif; ?>
</body>
</html>
