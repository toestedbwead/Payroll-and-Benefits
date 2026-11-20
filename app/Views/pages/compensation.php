<?php
require_once __DIR__ . '/../partials/view_guard.php';
preventDirectViewAccess(__FILE__);
$pageData = isset($compensationPageData) && is_array($compensationPageData) ? $compensationPageData : [];
$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$csrfToken = isset($_SESSION['csrf_token']) && is_string($_SESSION['csrf_token']) ? $_SESSION['csrf_token'] : '';
$activeTab = is_string($pageData['activeTab'] ?? null) ? $pageData['activeTab'] : 'structure';
$salaryStructures = $pageData['salaryStructures'] ?? [];
$meritMatrices = $pageData['meritMatrices'] ?? [];
$archivedStructureGrades = $pageData['archivedStructureGrades'] ?? [];
$archivedMatrixBands = $pageData['archivedMatrixBands'] ?? [];
$cycles = $pageData['cycles'] ?? [];
$departments = $pageData['departments'] ?? [];
$activeStructures = $pageData['activeStructures'] ?? [];
$activeMatrices = $pageData['activeMatrices'] ?? [];
$isManager = ($pageData['isManager'] ?? false) === true;
$canManage = ($pageData['canManage'] ?? false) === true;
$managerCycles = $pageData['managerCycles'] ?? [];
$managerCycle = $pageData['managerCycle'] ?? null;
$managerRecommendations = $pageData['managerRecommendations'] ?? [];
$managerEmployeeId = $pageData['managerEmployeeId'] ?? null;
$managerDepartmentBudgets = [];
$managerIncreaseTotal = 0.0;
$managerIncreaseByDepartment = [];
foreach ($pageData['managerDepartmentBudgets'] ?? [] as $managerDepartmentBudget) {
    $departmentId = (int) $managerDepartmentBudget['department_id'];
    $managerDepartmentBudgets[$departmentId] = [
        'name' => $managerDepartmentBudget['department'],
        'amount' => (float) $managerDepartmentBudget['allocated_amount'],
    ];
}
foreach ($managerRecommendations as $managerRecommendation) {
    if ($managerRecommendation['final_new_salary'] !== null) {
        $raise = (float) $managerRecommendation['final_new_salary'] - (float) $managerRecommendation['current_salary'];
        $managerIncreaseTotal += $raise;
        $departmentId = (int) ($managerRecommendation['department_id'] ?? 0);
        $managerIncreaseByDepartment[$departmentId] =
            ($managerIncreaseByDepartment[$departmentId] ?? 0) + $raise;
    }
}
$currentStructureGrades = $pageData['activeGrades'] ?? [];
$activeStructure = $activeStructures[0] ?? null;
$activeMatrix = $activeMatrices[0] ?? null;
$currentMatrixBands = $pageData['activeMatrixBands'] ?? [];
$currentMatrixBandsByRating = [];
foreach ($currentMatrixBands as $currentMatrixBand) {
    $currentMatrixBandsByRating[(int) $currentMatrixBand['performance_rating']][] = $currentMatrixBand;
}
$describeCompaBand = static function (array $band): string {
    $from = (float) $band['compa_ratio_from'];
    $to = $band['compa_ratio_to'] === null ? null : (float) $band['compa_ratio_to'];
    if ($to !== null && $to <= 1.0) {
        return 'Below the grade midpoint';
    }
    if ($from > 1.0) {
        return 'Above the grade midpoint';
    }

    return 'Around the grade midpoint';
};
$editCycle = $pageData['editCycle'] ?? null;
$editBudgets = [];
foreach ($pageData['departmentBudgets'] ?? [] as $departmentBudget) {
    $editBudgets[(int) $departmentBudget['department_id']] = $departmentBudget['allocated_amount'];
}
$defaultCycleDate = date('Y-m-01', strtotime('first day of next month'));
$cycleValues = is_array($editCycle) ? $editCycle : [
    'cycle_name' => '',
    'cycle_type' => 'Annual Merit',
    'effective_date' => $defaultCycleDate,
    'eligibility_cutoff_date' => date('Y-m-d'),
    'minimum_performance_rating' => 2,
    'budget_type' => 'Percent of Payroll',
    'budget_value' => '5.00',
    'salary_structure_id' => '',
    'merit_matrix_id' => '',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Compensation Planning</title>
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
                <?php if (!$canManage && !$isManager): ?>
                    <div class="mx-auto max-w-3xl rounded-xl border border-red-200 bg-red-50 p-6 text-sm text-red-800">
                        Your account is not authorized to view compensation planning.
                    </div>
                <?php elseif ($isManager): ?>
                    <div class="mx-auto max-w-7xl space-y-6">
                        <div>
                            <h2 class="text-2xl font-bold text-gray-900">Manager Compensation Review</h2>
                            <p class="mt-1 text-sm text-gray-500">Review and submit recommendations for your direct reports only.</p>
                        </div>
                        <?php if (is_array($pageData['flash'] ?? null)): ?>
                            <?php $flash = $pageData['flash']; ?>
                            <div role="status" class="rounded-lg border px-4 py-3 text-sm <?= ($flash['type'] ?? '') === 'success' ? 'border-green-200 bg-green-50 text-green-800' : 'border-red-200 bg-red-50 text-red-800' ?>">
                                <?= $escape($flash['message'] ?? '') ?>
                            </div>
                        <?php endif; ?>
                        <?php if (($pageData['workflowMigrationRequired'] ?? false) === true): ?>
                            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">Manager review requires <code>database/008_compensation_workflow_migration.sql</code> and <code>database/009_department_migration.sql</code>.</div>
                        <?php elseif ($managerEmployeeId === null): ?>
                            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">Your manager login is not linked to an employee record. Ask HR to assign the employee link before opening a cycle.</div>
                        <?php elseif ($managerCycles === []): ?>
                            <div class="rounded-xl border border-gray-200 bg-white p-6 text-sm text-gray-500">There are no Open compensation cycles for manager review.</div>
                        <?php else: ?>
                            <form method="get" class="flex flex-wrap items-end gap-3 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                                <input type="hidden" name="page" value="compensation">
                                <label class="text-sm font-medium text-gray-700">Open cycle
                                    <select name="manager_cycle" class="mt-1 block min-w-64 rounded-lg border-gray-300 text-sm" onchange="this.form.submit()">
                                        <?php foreach ($managerCycles as $cycle): ?>
                                            <option value="<?= (int) $cycle['id'] ?>" <?= is_array($managerCycle) && (int) $managerCycle['id'] === (int) $cycle['id'] ? 'selected' : '' ?>><?= $escape($cycle['cycle_name']) ?> · <?= $escape($cycle['effective_date']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                            </form>
                            <?php if (is_array($managerCycle)): ?>
                                <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                                    <div class="flex flex-wrap items-start justify-between gap-4">
                                        <div>
                                            <h3 class="text-lg font-semibold text-gray-900"><?= $escape($managerCycle['cycle_name']) ?></h3>
                                            <p class="mt-1 text-sm text-gray-500"><?= $escape($managerCycle['cycle_type']) ?> · Effective <?= $escape($managerCycle['effective_date']) ?></p>
                                        </div>
                                        <div class="text-sm text-gray-700">
                                            Team increase: <strong id="managerTeamTotal">₱<?= number_format($managerIncreaseTotal, 2) ?></strong>
                                            <span class="block text-xs text-gray-500">Cycle budget: ₱<?= number_format((float) $managerCycle['total_budget'], 2) ?></span>
                                            <?php if ($managerDepartmentBudgets !== []): ?>
                                                <?php foreach ($managerDepartmentBudgets as $departmentId => $allocation): ?>
                                                    <span class="block text-xs text-gray-500" data-manager-department-total="<?= (int) $departmentId ?>" data-manager-department-name="<?= $escape($allocation['name']) ?>" data-manager-department-allocation="<?= $escape($allocation['amount']) ?>"><?= $escape($allocation['name']) ?>: ₱<?= number_format($managerIncreaseByDepartment[$departmentId] ?? 0, 2) ?> / ₱<?= number_format($allocation['amount'], 2) ?></span>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <span class="block text-xs text-gray-500">No department allocations; the cycle budget applies.</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="mt-4 overflow-x-auto">
                                        <table class="min-w-full divide-y divide-gray-200 text-left text-sm">
                                            <thead class="bg-gray-50">
                                                <tr><th class="whitespace-nowrap px-4 py-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Direct report</th><th class="whitespace-nowrap px-4 py-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Current / grade</th><th class="whitespace-nowrap px-4 py-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Compa / rating</th><th class="whitespace-nowrap px-4 py-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Suggested</th><th class="whitespace-nowrap px-4 py-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Final increase</th><th class="whitespace-nowrap px-4 py-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Comment for override</th><th class="whitespace-nowrap px-4 py-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Status</th><th class="px-4 py-3"></th></tr>
                                            </thead>
                                            <tbody class="divide-y divide-gray-100 bg-white">
                                                <?php foreach ($managerRecommendations as $recommendation): ?>
                                                    <?php
                                                    $canEditRecommendation = in_array($recommendation['status'], ['Pending', 'Returned'], true);
                                                    $recommendationForm = 'manager-rec-' . (int) $recommendation['id'];
                                                    $finalPercent = $recommendation['final_increase_percentage'] ?? $recommendation['matrix_increase_percentage'];
                                                    ?>
                                                    <tr data-manager-recommendation data-manager-department="<?= (int) ($recommendation['department_id'] ?? 0) ?>">
                                                        <td class="px-4 py-3">
                                                            <span class="font-medium text-gray-900"><?= $escape($recommendation['first_name'] . ' ' . $recommendation['last_name']) ?></span>
                                                            <span class="block text-xs text-gray-500"><?= $escape($recommendation['employee_code']) ?> · <?= $escape($recommendation['department']) ?></span>
                                                        </td>
                                                        <td class="px-4 py-3">₱<?= number_format((float) $recommendation['current_salary'], 2) ?>
                                                            <span class="block text-xs text-gray-500"><?= $escape($recommendation['grade_code']) ?> · max ₱<?= number_format((float) $recommendation['grade_maximum'], 2) ?></span>
                                                        </td>
                                                        <td class="px-4 py-3"><?= $escape($recommendation['compa_ratio']) ?> · <?= (int) $recommendation['performance_rating'] ?></td>
                                                        <td class="px-4 py-3"><?= $escape($recommendation['matrix_increase_percentage']) ?>%<span class="block text-xs text-gray-500">₱<?= number_format((float) $recommendation['suggested_new_salary'], 2) ?></span></td>
                                                        <td class="px-4 py-3">
                                                            <input form="<?= $recommendationForm ?>" type="number" name="final_increase_percentage" min="0" max="999.99" step="0.01" value="<?= $escape($finalPercent) ?>" data-current-salary="<?= $escape($recommendation['current_salary']) ?>" <?= $canEditRecommendation ? 'required' : 'disabled' ?> class="w-24 rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" aria-label="Final increase percentage for <?= $escape($recommendation['employee_code']) ?>">
                                                            <span class="block text-xs text-gray-500">Final <span data-final-salary>₱<?= $recommendation['final_new_salary'] === null ? '—' : number_format((float) $recommendation['final_new_salary'], 2) ?></span></span>
                                                        </td>
                                                        <td class="px-4 py-3">
                                                            <input form="<?= $recommendationForm ?>" type="text" name="manager_comment" maxlength="500" value="<?= $escape($recommendation['manager_comment'] ?? '') ?>" <?= $canEditRecommendation ? '' : 'disabled' ?> class="w-48 rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" aria-label="Manager comment for <?= $escape($recommendation['employee_code']) ?>">
                                                            <?php if ($recommendation['override_flag']): ?><span class="block text-xs text-amber-700">Override comment required</span><?php endif; ?>
                                                        </td>
                                                        <td class="px-4 py-3">
                                                            <?= $escape($recommendation['status']) ?>
                                                            <?php if ($recommendation['flag'] !== 'None'): ?><span class="block text-xs text-red-700"><?= $escape($recommendation['flag']) ?></span><?php endif; ?>
                                                            <?php if ($recommendation['status'] === 'Returned' && $recommendation['review_note'] !== null): ?><span class="block max-w-48 text-xs text-red-700"><?= $escape($recommendation['review_note']) ?></span><?php endif; ?>
                                                        </td>
                                                        <td class="px-4 py-3 text-right">
                                                            <?php if ($canEditRecommendation): ?>
                                                                <form id="<?= $recommendationForm ?>" method="post">
                                                                    <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                                                                    <input type="hidden" name="action" value="save_recommendation">
                                                                    <input type="hidden" name="cycle_id" value="<?= (int) $managerCycle['id'] ?>">
                                                                    <input type="hidden" name="recommendation_id" value="<?= (int) $recommendation['id'] ?>">
                                                                    <button type="submit" class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:border-blue-300 hover:bg-blue-50 hover:text-blue-700">Save</button>
                                                                </form>
                                                            <?php else: ?>
                                                                <span class="text-xs text-gray-400">Locked</span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                <?php if ($managerRecommendations === []): ?>
                                                    <tr><td colspan="8" class="px-4 py-12 text-center text-sm text-gray-500">No recommendations are assigned to your direct reports in this cycle.</td></tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <?php if ($managerRecommendations !== []): ?>
                                        <form method="post" class="mt-5" data-confirm-title="Submit team for review?" data-confirm-message="This submits your team's recommendations to HR. Submitted recommendations can no longer be edited by the manager until HR returns them." data-confirm-label="Submit team">
                                            <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                                            <input type="hidden" name="action" value="submit_team">
                                            <input type="hidden" name="cycle_id" value="<?= (int) $managerCycle['id'] ?>">
                                            <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800"><i class="bi bi-send" aria-hidden="true"></i>Submit team for HR review</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                <div class="mx-auto max-w-7xl space-y-6">
                    <div>
                        <h2 class="text-2xl font-bold text-gray-900">Compensation Planning</h2>
                        <p class="mt-1 text-sm text-gray-500">Review current salary and merit structures, and manage compensation cycles.</p>
                    </div>

                    <?php if (is_array($pageData['flash'] ?? null)): ?>
                        <?php $flash = $pageData['flash']; ?>
                        <div role="status" class="rounded-lg border px-4 py-3 text-sm <?= ($flash['type'] ?? '') === 'success' ? 'border-green-200 bg-green-50 text-green-800' : 'border-red-200 bg-red-50 text-red-800' ?>">
                            <?= $escape($flash['message'] ?? '') ?>
                        </div>
                    <?php endif; ?>

                    <?php if (($pageData['migrationRequired'] ?? false) === true): ?>
                        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                            Apply <code class="rounded bg-amber-100 px-1">database/007_compensation_migration.sql</code> to the configured <code>payroll_db</code> database to enable this module.
                        </div>
                    <?php endif; ?>

                    <?php if (($pageData['workflowMigrationRequired'] ?? false) === true): ?>
                        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                            Apply <code class="rounded bg-amber-100 px-1">database/008_compensation_workflow_migration.sql</code> and then <code class="rounded bg-amber-100 px-1">database/009_department_migration.sql</code> once to enable employee source-data entry, normalized departments, and recommendation workflows.
                        </div>
                    <?php endif; ?>

                    <nav class="flex gap-2 overflow-x-auto border-b border-gray-200" role="tablist" aria-label="Compensation workspace">
                        <?php foreach ([
                            'structure' => ['Salary structure', 'bi-diagram-3'],
                            'matrix' => ['Merit matrix', 'bi-grid-3x3'],
                            'cycles' => ['Cycles & approvals', 'bi-calendar2-check'],
                        ] as $tabKey => [$tabLabel, $tabIcon]): ?>
                            <?php $tabSelected = $activeTab === $tabKey; ?>
                            <a id="compensationTab-<?= $escape($tabKey) ?>" data-compensation-tab="<?= $escape($tabKey) ?>" href="index.php?page=compensation&amp;tab=<?= $escape($tabKey) ?>" role="tab" aria-controls="compensationPanel-<?= $escape($tabKey) ?>" aria-selected="<?= $tabSelected ? 'true' : 'false' ?>" <?= $tabSelected ? 'aria-current="page"' : '' ?> class="inline-flex shrink-0 items-center gap-2 border-b-2 px-4 py-2.5 text-sm font-semibold <?= $tabSelected ? 'border-blue-600 text-blue-700' : 'border-transparent text-gray-500 hover:text-gray-800' ?>">
                                <i class="bi <?= $escape($tabIcon) ?>" aria-hidden="true"></i><?= $escape($tabLabel) ?>
                            </a>
                        <?php endforeach; ?>
                    </nav>

                    <section id="compensationPanel-structure" role="tabpanel" aria-labelledby="compensationTab-structure" <?= $activeTab !== 'structure' ? 'hidden' : '' ?> class="<?= $activeTab === 'structure' ? 'space-y-6' : 'hidden' ?>">
                        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                            <div class="flex flex-wrap items-center justify-between gap-4 border-b border-gray-200 p-5">
                                <div>
                                    <h3 class="font-semibold text-gray-900">Current active salary structure</h3>
                                    <p class="mt-1 text-xs text-gray-500">This version is available for new cycles. Existing cycles keep the version they were created with.</p>
                                </div>
                            </div>
                            <div class="p-5">
                                <?php if (is_array($activeStructure)): ?>
                                    <div class="mb-4 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
                                        <span class="inline-flex items-center gap-2 font-semibold text-gray-800"><i class="bi bi-check-circle-fill text-xs text-emerald-600" aria-hidden="true"></i>Version <?= (int) $activeStructure['version'] ?><span class="font-medium text-emerald-700">Active</span></span>
                                        <span class="hidden h-4 border-l border-gray-200 sm:block" aria-hidden="true"></span>
                                        <span class="text-gray-500">Effective from <span class="font-medium text-gray-700"><?= $escape($activeStructure['effective_from']) ?></span></span>
                                    </div>
                                    <div class="overflow-x-auto">
                                        <table class="min-w-full divide-y divide-gray-200 text-left text-sm">
                                            <thead class="bg-gray-50">
                                                <tr>
                                                    <th class="px-4 py-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Grade</th>
                                                    <th class="px-4 py-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Job tier</th>
                                                    <th class="px-4 py-3 text-right text-[11px] font-semibold uppercase tracking-wide text-gray-500">Minimum</th>
                                                    <th class="px-4 py-3 text-right text-[11px] font-semibold uppercase tracking-wide text-gray-500">Midpoint</th>
                                                    <th class="px-4 py-3 text-right text-[11px] font-semibold uppercase tracking-wide text-gray-500">Maximum</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-gray-100 bg-white">
                                                <?php foreach ($currentStructureGrades as $grade): ?>
                                                    <tr>
                                                        <td class="px-4 py-3 font-medium text-gray-900"><?= $escape($grade['grade_code']) ?></td>
                                                        <td class="px-4 py-3 text-gray-700"><?= $escape($grade['target_job_tier']) ?></td>
                                                        <td class="px-4 py-3 text-right text-gray-700">₱<?= number_format((float) $grade['minimum_salary'], 2) ?></td>
                                                        <td class="px-4 py-3 text-right text-gray-700">₱<?= number_format((float) $grade['midpoint_salary'], 2) ?></td>
                                                        <td class="px-4 py-3 text-right text-gray-700">₱<?= number_format((float) $grade['maximum_salary'], 2) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                <?php if ($currentStructureGrades === []): ?>
                                                    <tr><td colspan="5" class="px-4 py-8 text-center text-sm text-gray-500">The active structure has no grades.</td></tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <p class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900">No active salary structure exists yet. An administrator can create one in Settings &gt; Compensation.</p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                            <div class="border-b border-gray-200 px-5 py-4">
                                <h3 class="font-semibold text-gray-900">Archived salary structures</h3>
                                <p class="mt-1 text-xs text-gray-500">Kept as records for cycles created with older salary ranges.</p>
                            </div>
                            <?php $archivedStructures = array_values(array_filter($salaryStructures, static fn (array $structure): bool => $structure['status'] === 'Archived')); ?>
                            <?php if ($archivedStructures !== []): ?>
                                <div class="overflow-x-auto">
                                    <table class="w-full table-fixed divide-y divide-gray-200 text-left text-sm">
                                        <colgroup><col class="w-[20%]"><col class="w-[25%]"><col class="w-[15%]"><col class="w-[20%]"><col class="w-[20%]"></colgroup>
                                        <thead class="bg-gray-50"><tr><th class="px-5 py-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Version</th><th class="px-5 py-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Effective from</th><th class="px-5 py-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Grades</th><th class="px-5 py-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Status</th><th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wide text-gray-500">Action</th></tr></thead>
                                        <tbody class="divide-y divide-gray-100">
                                            <?php foreach ($archivedStructures as $structure): ?>
                                                <?php $versionGrades = $archivedStructureGrades[(int) $structure['id']] ?? []; $dialogId = 'archived-structure-' . (int) $structure['id']; ?>
                                                <tr>
                                                    <td class="px-5 py-3 font-medium text-gray-900">Version <?= (int) $structure['version'] ?></td>
                                                    <td class="whitespace-nowrap px-5 py-3 text-gray-700"><?= $escape($structure['effective_from']) ?></td>
                                                    <td class="px-5 py-3 text-gray-700"><?= (int) $structure['grade_count'] ?></td>
                                                    <td class="px-5 py-3"><span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-600">Archived</span></td>
                                                    <td class="px-5 py-3 text-left">
                                                        <button type="button" data-open-version-dialog="<?= $dialogId ?>" class="inline-flex items-center gap-1 rounded-lg border border-gray-300 px-2 py-1 text-xs font-semibold text-gray-700 hover:bg-gray-50"><i class="bi bi-eye" aria-hidden="true"></i>View</button>
                                                        <dialog id="<?= $dialogId ?>" data-version-dialog class="m-auto w-[min(48rem,calc(100vw-2rem))] max-w-none overflow-hidden rounded-2xl border border-gray-200 bg-white p-0 text-gray-900 shadow-2xl backdrop:bg-slate-950/40">
                                                            <div class="flex max-h-[85vh] flex-col">
                                                                <div class="flex items-start justify-between gap-4 border-b border-gray-200 px-5 py-4">
                                                                    <div><h3 class="font-semibold">Salary structure · Version <?= (int) $structure['version'] ?></h3><p class="mt-1 text-sm text-gray-500">Archived · Effective <?= $escape($structure['effective_from']) ?></p></div>
                                                                    <button type="button" data-close-version-dialog aria-label="Close version details" class="rounded-lg p-2 text-gray-500 hover:bg-gray-100"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                                                                </div>
                                                                <div class="overflow-auto p-5">
                                                                    <?php if ($versionGrades !== []): ?>
                                                                        <table class="min-w-full divide-y divide-gray-200 text-left text-sm">
                                                                            <thead class="bg-gray-50"><tr><th class="px-3 py-2 text-[11px] font-semibold uppercase text-gray-500">Grade</th><th class="px-3 py-2 text-[11px] font-semibold uppercase text-gray-500">Job tier</th><th class="px-3 py-2 text-right text-[11px] font-semibold uppercase text-gray-500">Minimum</th><th class="px-3 py-2 text-right text-[11px] font-semibold uppercase text-gray-500">Midpoint</th><th class="px-3 py-2 text-right text-[11px] font-semibold uppercase text-gray-500">Maximum</th></tr></thead>
                                                                            <tbody class="divide-y divide-gray-100"><?php foreach ($versionGrades as $grade): ?><tr><td class="px-3 py-2 font-medium"><?= $escape($grade['grade_code']) ?></td><td class="px-3 py-2"><?= $escape($grade['target_job_tier']) ?></td><td class="px-3 py-2 text-right">₱<?= number_format((float) $grade['minimum_salary'], 2) ?></td><td class="px-3 py-2 text-right">₱<?= number_format((float) $grade['midpoint_salary'], 2) ?></td><td class="px-3 py-2 text-right">₱<?= number_format((float) $grade['maximum_salary'], 2) ?></td></tr><?php endforeach; ?></tbody>
                                                                        </table>
                                                                    <?php else: ?><p class="text-sm text-gray-500">No grades are recorded for this version.</p><?php endif; ?>
                                                                </div>
                                                                <div class="flex justify-end border-t border-gray-200 px-5 py-3"><button type="button" data-close-version-dialog class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">Close</button></div>
                                                            </div>
                                                        </dialog>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <p class="px-5 py-4 text-sm text-gray-500">No archived versions yet. Older versions will appear here when replaced.</p>
                            <?php endif; ?>
                        </section>
                    </section>

                    <section id="compensationPanel-matrix" role="tabpanel" aria-labelledby="compensationTab-matrix" <?= $activeTab !== 'matrix' ? 'hidden' : '' ?> class="<?= $activeTab === 'matrix' ? 'space-y-6' : 'hidden' ?>">
                        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                            <div class="flex flex-wrap items-center justify-between gap-4 border-b border-gray-200 p-5">
                                <div>
                                    <h3 class="font-semibold text-gray-900">Current active merit matrix</h3>
                                <p class="mt-1 text-xs text-gray-500">For each performance rating, the increase is selected from one of the three compa-ratio bands. Existing cycles retain their selected matrix version.</p>
                                </div>
                            </div>
                            <div class="p-5">
                                <?php if (is_array($activeMatrix)): ?>
                                    <div class="mb-4 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
                                        <span class="inline-flex items-center gap-2 font-semibold text-gray-800"><i class="bi bi-check-circle-fill text-xs text-emerald-600" aria-hidden="true"></i>Version <?= (int) $activeMatrix['version'] ?><span class="font-medium text-emerald-700">Active</span></span>
                                        <span class="hidden h-4 border-l border-gray-200 sm:block" aria-hidden="true"></span>
                                        <span class="text-gray-500">Effective from <span class="font-medium text-gray-700"><?= $escape($activeMatrix['effective_from']) ?></span></span>
                                    </div>
                                    <div class="overflow-x-auto">
                                        <table class="min-w-full divide-y divide-gray-200 text-left text-sm">
                                            <thead class="bg-gray-50">
                                                <tr>
                                                    <th class="px-4 py-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Performance rating</th>
                                                    <th class="px-4 py-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Compa-ratio band</th>
                                                    <th class="px-4 py-3 text-right text-[11px] font-semibold uppercase tracking-wide text-gray-500">Increase</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-gray-100 bg-white">
                                                <?php foreach ([5, 4, 3, 2, 1] as $rating): ?>
                                                    <?php $ratingBands = $currentMatrixBandsByRating[$rating] ?? []; ?>
                                                    <?php if ($ratingBands === []): ?>
                                                        <tr>
                                                            <th scope="row" class="whitespace-nowrap px-4 py-3 font-semibold text-gray-900">Rating <?= $rating ?></th>
                                                            <td colspan="2" class="px-4 py-3 text-sm text-gray-500">No compa-ratio bands are defined.</td>
                                                        </tr>
                                                    <?php else: ?>
                                                        <?php foreach ($ratingBands as $bandIndex => $band): ?>
                                                            <tr>
                                                                <?php if ($bandIndex === 0): ?>
                                                                    <th scope="rowgroup" rowspan="<?= count($ratingBands) ?>" class="whitespace-nowrap px-4 py-3 align-top font-semibold text-gray-900">Rating <?= $rating ?></th>
                                                                <?php endif; ?>
                                                                <td class="px-4 py-3 text-gray-700">
                                                                    <span>
                                                                        <?= number_format((float) $band['compa_ratio_from'], 2) ?>
                                                                        <?php if ($band['compa_ratio_to'] === null): ?>
                                                                            and above
                                                                        <?php else: ?>
                                                                            to less than <?= number_format((float) $band['compa_ratio_to'], 2) ?>
                                                                        <?php endif; ?>
                                                                    </span>
                                                                    <span class="mt-1 block text-xs text-gray-500"><?= $escape($describeCompaBand($band)) ?></span>
                                                                </td>
                                                                <td class="px-4 py-3 text-right font-medium text-gray-900"><?= number_format((float) $band['increase_percentage'], 2) ?>%</td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <p class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900">No active merit matrix exists yet. An administrator can create one in Settings &gt; Compensation.</p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                            <div class="border-b border-gray-200 px-5 py-4">
                                <h3 class="font-semibold text-gray-900">Archived merit matrices</h3>
                                <p class="mt-1 text-xs text-gray-500">Kept so existing cycles retain the increase rules they were created with.</p>
                            </div>
                            <?php $archivedMatrices = array_values(array_filter($meritMatrices, static fn (array $matrix): bool => $matrix['status'] === 'Archived')); ?>
                            <?php if ($archivedMatrices !== []): ?>
                                <div class="overflow-x-auto">
                                    <table class="w-full table-fixed divide-y divide-gray-200 text-left text-sm">
                                        <colgroup><col class="w-[20%]"><col class="w-[25%]"><col class="w-[15%]"><col class="w-[20%]"><col class="w-[20%]"></colgroup>
                                        <thead class="bg-gray-50"><tr><th class="px-5 py-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Version</th><th class="px-5 py-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Effective from</th><th class="px-5 py-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Bands</th><th class="px-5 py-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Status</th><th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wide text-gray-500">Action</th></tr></thead>
                                        <tbody class="divide-y divide-gray-100">
                                            <?php foreach ($archivedMatrices as $matrix): ?>
                                                <?php $versionBands = $archivedMatrixBands[(int) $matrix['id']] ?? []; $dialogId = 'archived-matrix-' . (int) $matrix['id']; ?>
                                                <tr>
                                                    <td class="px-5 py-3 font-medium text-gray-900">Version <?= (int) $matrix['version'] ?></td>
                                                    <td class="whitespace-nowrap px-5 py-3 text-gray-700"><?= $escape($matrix['effective_from']) ?></td>
                                                    <td class="px-5 py-3 text-gray-700"><?= (int) $matrix['band_count'] ?></td>
                                                    <td class="px-5 py-3"><span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-600">Archived</span></td>
                                                    <td class="px-5 py-3 text-left">
                                                        <button type="button" data-open-version-dialog="<?= $dialogId ?>" class="inline-flex items-center gap-1 rounded-lg border border-gray-300 px-2 py-1 text-xs font-semibold text-gray-700 hover:bg-gray-50"><i class="bi bi-eye" aria-hidden="true"></i>View</button>
                                                        <dialog id="<?= $dialogId ?>" data-version-dialog class="m-auto w-[min(42rem,calc(100vw-2rem))] max-w-none overflow-hidden rounded-2xl border border-gray-200 bg-white p-0 text-gray-900 shadow-2xl backdrop:bg-slate-950/40">
                                                            <div class="flex max-h-[85vh] flex-col">
                                                                <div class="flex items-start justify-between gap-4 border-b border-gray-200 px-5 py-4">
                                                                    <div><h3 class="font-semibold">Merit matrix · Version <?= (int) $matrix['version'] ?></h3><p class="mt-1 text-sm text-gray-500">Archived · Effective <?= $escape($matrix['effective_from']) ?></p></div>
                                                                    <button type="button" data-close-version-dialog aria-label="Close version details" class="rounded-lg p-2 text-gray-500 hover:bg-gray-100"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                                                                </div>
                                                                <div class="overflow-auto p-5">
                                                                    <?php if ($versionBands !== []): ?>
                                                                        <table class="min-w-full divide-y divide-gray-200 text-left text-sm">
                                                                            <thead class="bg-gray-50"><tr><th class="px-3 py-2 text-[11px] font-semibold uppercase text-gray-500">Rating</th><th class="px-3 py-2 text-[11px] font-semibold uppercase text-gray-500">Compa-ratio band</th><th class="px-3 py-2 text-right text-[11px] font-semibold uppercase text-gray-500">Increase</th></tr></thead>
                                                                            <tbody class="divide-y divide-gray-100"><?php foreach ($versionBands as $band): ?><tr><td class="px-3 py-2 font-medium"><?= (int) $band['performance_rating'] ?></td><td class="px-3 py-2"><?= number_format((float) $band['compa_ratio_from'], 2) ?><?php if ($band['compa_ratio_to'] === null): ?> and above<?php else: ?> to less than <?= number_format((float) $band['compa_ratio_to'], 2) ?><?php endif; ?></td><td class="px-3 py-2 text-right"><?= number_format((float) $band['increase_percentage'], 2) ?>%</td></tr><?php endforeach; ?></tbody>
                                                                        </table>
                                                                    <?php else: ?><p class="text-sm text-gray-500">No merit bands are recorded for this version.</p><?php endif; ?>
                                                                </div>
                                                                <div class="flex justify-end border-t border-gray-200 px-5 py-3"><button type="button" data-close-version-dialog class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">Close</button></div>
                                                            </div>
                                                        </dialog>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <p class="px-5 py-4 text-sm text-gray-500">No archived versions yet. Older versions will appear here when replaced.</p>
                            <?php endif; ?>
                        </section>
                    </section>

                    <section id="compensationPanel-cycles" role="tabpanel" aria-labelledby="compensationTab-cycles" <?= $activeTab !== 'cycles' ? 'hidden' : '' ?> class="<?= $activeTab === 'cycles' ? 'space-y-6' : 'hidden' ?>">
                    <dialog id="cycleFormDialog" aria-labelledby="cycleFormDialogTitle" data-open-on-load="<?= is_array($editCycle) ? 'true' : 'false' ?>" class="m-auto w-[min(64rem,calc(100vw-2rem))] max-w-none overflow-hidden rounded-2xl border border-gray-200 bg-white p-0 text-gray-900 shadow-2xl backdrop:bg-slate-950/40">
                        <div class="max-h-[90vh] overflow-y-auto">
                            <div class="sticky top-0 z-10 flex items-start justify-between gap-4 border-b border-gray-200 bg-white px-5 py-4 sm:px-7">
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-wide text-blue-700">Compensation planning</p>
                                    <h3 id="cycleFormDialogTitle" class="mt-1 text-xl font-bold text-gray-900"><?= is_array($editCycle) ? 'Edit Draft cycle' : 'Create compensation cycle' ?></h3>
                                    <p class="mt-1 text-sm text-gray-500">Cycles keep the salary structure and merit matrix versions selected here.</p>
                                </div>
                                <button type="button" data-close-cycle-dialog aria-label="Close cycle form" class="rounded-lg p-2 text-gray-500 hover:bg-gray-100"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                            </div>
                            <?php if ($activeStructures === [] || $activeMatrices === []): ?>
                                <div class="p-5 sm:p-7"><p class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">Create an active salary structure and merit matrix version before setting up a cycle.</p></div>
                            <?php else: ?>
                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                                    <input type="hidden" name="action" value="save_cycle">
                                    <?php if (is_array($editCycle)): ?>
                                        <input type="hidden" name="cycle_id" value="<?= (int) $editCycle['id'] ?>">
                                    <?php endif; ?>
                                    <div class="space-y-5 p-5 sm:p-7">
                                        <section class="rounded-xl border border-gray-200 p-4 sm:p-5">
                                            <div><h4 class="text-sm font-semibold text-gray-900">Cycle details</h4><p class="mt-1 text-xs text-gray-500">Name the cycle, set its dates, and choose the minimum performance rating.</p></div>
                                            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                                                <label class="text-sm font-medium text-gray-700 sm:col-span-2">Cycle name
                                                    <input name="cycle_name" maxlength="100" required value="<?= $escape($cycleValues['cycle_name']) ?>" class="mt-1.5 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100">
                                                </label>
                                                <label class="text-sm font-medium text-gray-700">Cycle type
                                                    <select name="cycle_type" class="mt-1.5 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100">
                                                        <?php foreach (['Annual Merit', 'Mid-Year', 'Special Adjustment'] as $type): ?>
                                                            <option value="<?= $escape($type) ?>" <?= $cycleValues['cycle_type'] === $type ? 'selected' : '' ?>><?= $escape($type) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </label>
                                                <label class="text-sm font-medium text-gray-700">Minimum performance rating
                                                    <select name="minimum_performance_rating" class="mt-1.5 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100">
                                                        <?php for ($rating = 1; $rating <= 5; $rating++): ?>
                                                            <option value="<?= $rating ?>" <?= (int) $cycleValues['minimum_performance_rating'] === $rating ? 'selected' : '' ?>><?= $rating ?> or above</option>
                                                        <?php endfor; ?>
                                                    </select>
                                                    <span class="mt-1 block text-xs font-normal text-gray-500">Employees rated below this score won’t be considered for recommendations.</span>
                                                </label>
                                                <label class="text-sm font-medium text-gray-700">Effective date
                                                    <input type="date" name="effective_date" required value="<?= $escape($cycleValues['effective_date']) ?>" class="mt-1.5 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100">
                                                </label>
                                                <label class="text-sm font-medium text-gray-700">Eligibility cutoff
                                                    <input type="date" name="eligibility_cutoff_date" required value="<?= $escape($cycleValues['eligibility_cutoff_date']) ?>" class="mt-1.5 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100">
                                                </label>
                                            </div>
                                        </section>

                                        <section class="rounded-xl border border-gray-200 p-4 sm:p-5">
                                            <div><h4 class="text-sm font-semibold text-gray-900">Budget</h4><p class="mt-1 text-xs text-gray-500">Set a payroll percentage or a fixed currency amount.</p></div>
                                            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                                                <label class="text-sm font-medium text-gray-700">Budget type
                                                    <select name="budget_type" class="mt-1.5 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100">
                                                        <?php foreach (['Percent of Payroll', 'Fixed Amount'] as $type): ?>
                                                            <option value="<?= $escape($type) ?>" <?= $cycleValues['budget_type'] === $type ? 'selected' : '' ?>><?= $escape($type) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </label>
                                                <label class="text-sm font-medium text-gray-700">Budget value
                                                    <input type="number" name="budget_value" min="0" step="0.01" required value="<?= $escape($cycleValues['budget_value']) ?>" class="mt-1.5 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100">
                                                </label>
                                            </div>
                                        </section>

                                        <section class="rounded-xl border border-gray-200 p-4 sm:p-5">
                                            <div><h4 class="text-sm font-semibold text-gray-900">Version references</h4><p class="mt-1 text-xs text-gray-500">This cycle retains these versions even if you create newer ones later.</p></div>
                                            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                                                <label class="text-sm font-medium text-gray-700">Salary structure
                                                    <select name="salary_structure_id" required class="mt-1.5 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100">
                                                        <?php foreach ($activeStructures as $structure): ?>
                                                            <option value="<?= (int) $structure['id'] ?>" <?= (int) $cycleValues['salary_structure_id'] === (int) $structure['id'] ? 'selected' : '' ?>>Version <?= (int) $structure['version'] ?> · <?= $escape($structure['effective_from']) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </label>
                                                <label class="text-sm font-medium text-gray-700">Merit matrix
                                                    <select name="merit_matrix_id" required class="mt-1.5 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100">
                                                        <?php foreach ($activeMatrices as $matrix): ?>
                                                            <option value="<?= (int) $matrix['id'] ?>" <?= (int) $cycleValues['merit_matrix_id'] === (int) $matrix['id'] ? 'selected' : '' ?>>Version <?= (int) $matrix['version'] ?> · <?= $escape($matrix['effective_from']) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </label>
                                            </div>
                                        </section>

                                        <?php if ($departments !== []): ?>
                                            <fieldset class="rounded-xl border border-gray-200 p-4 sm:p-5">
                                                <legend class="px-1 text-sm font-semibold text-gray-800">Optional department allocations</legend>
                                                <p class="text-xs text-gray-500">Amounts are capped at the total cycle budget.</p>
                                                <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                                    <?php foreach ($departments as $department): ?>
                                                        <label class="text-sm font-medium text-gray-700"><?= $escape($department['department_name']) ?>
                                                            <input type="number" name="department_budget[<?= (int) $department['id'] ?>]" min="0" step="0.01" value="<?= $escape($editBudgets[(int) $department['id']] ?? '') ?>" class="mt-1.5 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100" placeholder="No allocation">
                                                        </label>
                                                    <?php endforeach; ?>
                                                </div>
                                            </fieldset>
                                        <?php endif; ?>
                                    </div>
                                    <div class="sticky bottom-0 flex flex-wrap justify-end gap-2 border-t border-gray-200 bg-white px-5 py-4 sm:px-7">
                                        <button type="button" data-close-cycle-dialog class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50">Cancel</button>
                                        <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800"><i class="bi bi-check2-circle" aria-hidden="true"></i><?= is_array($editCycle) ? 'Save Draft changes' : 'Create Draft cycle' ?></button>
                                    </div>
                                </form>
                            <?php endif; ?>
                        </div>
                    </dialog>

                    <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                        <div class="flex flex-wrap items-center justify-between gap-4 border-b border-gray-200 p-5">
                            <div>
                            <h3 class="font-semibold text-gray-900">Compensation cycles</h3>
                            <p class="mt-1 text-xs text-gray-500">Create Draft cycles, review recommendations, and advance approved cycles through publication.</p>
                            </div>
                            <?php if (!is_array($editCycle)): ?>
                                <button type="button" data-open-cycle-dialog <?= $activeStructures === [] || $activeMatrices === [] ? 'disabled' : '' ?> class="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"><i class="bi bi-plus-lg" aria-hidden="true"></i>New cycle</button>
                            <?php endif; ?>
                        </div>
                        <?php if ($activeStructures === [] || $activeMatrices === []): ?>
                            <p class="border-b border-amber-200 bg-amber-50 px-5 py-3 text-sm text-amber-900">Create an active salary structure and merit matrix version before creating a cycle.</p>
                        <?php endif; ?>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 text-left text-sm">
                                <thead class="bg-gray-50">
                                    <tr><th class="whitespace-nowrap px-5 py-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Cycle</th><th class="whitespace-nowrap px-5 py-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Effective</th><th class="whitespace-nowrap px-5 py-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Budget</th><th class="whitespace-nowrap px-5 py-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Versions</th><th class="whitespace-nowrap px-5 py-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Status</th><th class="whitespace-nowrap px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wide text-gray-500">Next step</th></tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 bg-white">
                                    <?php if ($cycles === []): ?>
                                        <tr><td colspan="6" class="px-5 py-12 text-center text-sm text-gray-500">No compensation cycles created yet.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($cycles as $cycle): ?>
                                            <tr>
                                                <td class="px-5 py-3"><span class="font-medium text-gray-900"><?= $escape($cycle['cycle_name']) ?></span><span class="block text-xs text-gray-500"><?= $escape($cycle['cycle_type']) ?></span></td>
                                                <td class="whitespace-nowrap px-5 py-3 text-gray-700"><?= $escape($cycle['effective_date']) ?></td>
                                                <td class="px-5 py-3 text-gray-800">₱<?= number_format((float) $cycle['total_budget'], 2) ?><span class="block text-xs text-gray-500"><?= $escape($cycle['budget_value']) ?> <?= $escape($cycle['budget_type']) ?></span></td>
                                                <td class="whitespace-nowrap px-5 py-3 text-xs text-gray-600">Structure v<?= (int) $cycle['structure_version'] ?><br>Matrix v<?= (int) $cycle['matrix_version'] ?></td>
                                                <td class="px-5 py-3">
                                                    <span class="inline-flex rounded-full px-2.5 py-1 text-[11px] font-semibold ring-1 ring-inset <?= match ($cycle['status']) { 'Open' => 'bg-blue-50 text-blue-700 ring-blue-600/20', 'Approved', 'Published' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20', 'Submitted' => 'bg-indigo-50 text-indigo-700 ring-indigo-600/20', 'Locked' => 'bg-gray-100 text-gray-600 ring-gray-500/20', default => 'bg-amber-50 text-amber-700 ring-amber-600/20' } ?>"><?= $escape($cycle['status']) ?></span>
                                                    <?php if ($cycle['status'] === 'Draft' && !($pageData['workflowMigrationRequired'] ?? false)): ?>
                                                        <span class="mt-1 block text-xs text-gray-500"><?= (int) $cycle['recommendation_count'] ?> recommendations generated</span>
                                                    <?php elseif ($cycle['status'] === 'Open' && !($pageData['workflowMigrationRequired'] ?? false)): ?>
                                                        <?php if ((int) $cycle['outstanding_recommendation_count'] === 0): ?>
                                                            <span class="mt-1 block text-xs text-emerald-700">All recommendations approved</span>
                                                        <?php else: ?>
                                                            <span class="mt-1 block text-xs text-gray-500"><?= (int) $cycle['outstanding_recommendation_count'] ?> recommendations need review</span>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="px-5 py-3">
                                                    <div class="flex min-w-max items-center gap-2">
                                                        <?php if ($cycle['status'] === 'Draft'): ?>
                                                            <?php if ($pageData['workflowMigrationRequired'] ?? false): ?>
                                                                <span class="text-xs text-amber-700">Apply workflow migrations to continue.</span>
                                                            <?php elseif ((int) $cycle['recommendation_count'] === 0): ?>
                                                                <form method="post" data-confirm-title="Generate recommendations?" data-confirm-message="The system will create employee recommendations using this Draft cycle's salary structure, merit matrix, and eligibility rules." data-confirm-label="Generate recommendations">
                                                                    <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                                                                    <input type="hidden" name="action" value="generate_recommendations">
                                                                    <input type="hidden" name="cycle_id" value="<?= (int) $cycle['id'] ?>">
                                                                    <button type="submit" class="inline-flex items-center gap-2 whitespace-nowrap rounded-lg bg-slate-900 px-3 py-2 text-xs font-semibold text-white hover:bg-slate-800">Generate recommendations <i class="bi bi-arrow-right" aria-hidden="true"></i></button>
                                                                </form>
                                                            <?php elseif ((float) $cycle['total_budget'] <= 0): ?>
                                                                <a class="inline-flex items-center gap-2 whitespace-nowrap rounded-lg bg-slate-900 px-3 py-2 text-xs font-semibold text-white hover:bg-slate-800" href="index.php?page=compensation&amp;tab=cycles&amp;edit_cycle=<?= (int) $cycle['id'] ?>">Set budget to continue <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                                                            <?php else: ?>
                                                                <form method="post" data-confirm-title="Open this cycle for manager review?" data-confirm-message="Opening makes this Draft cycle available to managers and locks its current details." data-confirm-label="Open for manager review">
                                                                    <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                                                                    <input type="hidden" name="action" value="open_cycle">
                                                                    <input type="hidden" name="cycle_id" value="<?= (int) $cycle['id'] ?>">
                                                                    <button type="submit" class="inline-flex items-center gap-2 whitespace-nowrap rounded-lg bg-slate-900 px-3 py-2 text-xs font-semibold text-white hover:bg-slate-800">Open for manager review <i class="bi bi-arrow-right" aria-hidden="true"></i></button>
                                                                </form>
                                                            <?php endif; ?>
                                                        <?php elseif ($cycle['status'] === 'Open'): ?>
                                                            <?php if ($pageData['workflowMigrationRequired'] ?? false): ?>
                                                                <span class="text-xs text-amber-700">Apply workflow migrations to continue.</span>
                                                            <?php elseif ((int) $cycle['outstanding_recommendation_count'] > 0): ?>
                                                                <span class="text-xs text-gray-500"><?= (int) $cycle['outstanding_recommendation_count'] ?> recommendation<?= (int) $cycle['outstanding_recommendation_count'] === 1 ? '' : 's' ?> still need manager or HR review.</span>
                                                            <?php else: ?>
                                                                <form method="post" data-confirm-title="Submit for final approval?" data-confirm-message="All recommendations are approved. This submits the cycle for final approval." data-confirm-label="Submit for final approval">
                                                                    <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                                                                    <input type="hidden" name="action" value="finalize_cycle_review">
                                                                    <input type="hidden" name="cycle_id" value="<?= (int) $cycle['id'] ?>">
                                                                    <button type="submit" class="inline-flex items-center gap-2 whitespace-nowrap rounded-lg bg-slate-900 px-3 py-2 text-xs font-semibold text-white hover:bg-slate-800">Submit for final approval <i class="bi bi-arrow-right" aria-hidden="true"></i></button>
                                                                </form>
                                                            <?php endif; ?>
                                                        <?php elseif ($cycle['status'] === 'Submitted'): ?>
                                                            <form method="post" data-confirm-title="Approve this cycle?" data-confirm-message="This approves the cycle and makes it eligible to be locked." data-confirm-label="Approve cycle">
                                                                <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                                                                <input type="hidden" name="action" value="approve_cycle">
                                                                <input type="hidden" name="cycle_id" value="<?= (int) $cycle['id'] ?>">
                                                                <button type="submit" class="inline-flex items-center gap-2 whitespace-nowrap rounded-lg bg-slate-900 px-3 py-2 text-xs font-semibold text-white hover:bg-slate-800">Approve cycle <i class="bi bi-arrow-right" aria-hidden="true"></i></button>
                                                            </form>
                                                        <?php elseif ($cycle['status'] === 'Approved'): ?>
                                                            <form method="post" data-confirm-title="Lock this cycle?" data-confirm-message="Locking preserves the approved cycle results and prevents further review changes." data-confirm-label="Lock cycle">
                                                                <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                                                                <input type="hidden" name="action" value="lock_cycle">
                                                                <input type="hidden" name="cycle_id" value="<?= (int) $cycle['id'] ?>">
                                                                <button type="submit" class="inline-flex items-center gap-2 whitespace-nowrap rounded-lg bg-slate-900 px-3 py-2 text-xs font-semibold text-white hover:bg-slate-800">Lock cycle <i class="bi bi-arrow-right" aria-hidden="true"></i></button>
                                                            </form>
                                                        <?php elseif ($cycle['status'] === 'Locked'): ?>
                                                            <form method="post" data-confirm-title="Publish this cycle?" data-confirm-message="Publishing finalizes this compensation cycle for downstream use." data-confirm-label="Publish cycle">
                                                                <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                                                                <input type="hidden" name="action" value="publish_cycle">
                                                                <input type="hidden" name="cycle_id" value="<?= (int) $cycle['id'] ?>">
                                                                <button type="submit" class="inline-flex items-center gap-2 whitespace-nowrap rounded-lg bg-slate-900 px-3 py-2 text-xs font-semibold text-white hover:bg-slate-800">Publish cycle <i class="bi bi-arrow-right" aria-hidden="true"></i></button>
                                                            </form>
                                                        <?php elseif ($cycle['status'] === 'Published'): ?>
                                                            <span class="text-xs font-medium text-emerald-700">Published · no action needed</span>
                                                        <?php else: ?>
                                                            <span class="text-xs text-gray-500">No next step available.</span>
                                                        <?php endif; ?>
                                                        <a class="inline-flex items-center gap-2 whitespace-nowrap rounded-lg border border-gray-300 bg-white px-3 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-50" href="index.php?page=compensation&amp;tab=cycles&amp;cycle_view=<?= (int) $cycle['id'] ?>#cycle-details">
                                                            <i class="bi bi-eye" aria-hidden="true"></i>View cycle details
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <?php if (is_array($pageData['selectedCycle'] ?? null)): ?>
                        <?php $selectedCycle = $pageData['selectedCycle']; ?>
                        <?php $selectedRecommendations = $pageData['recommendations'] ?? []; ?>
                        <section id="cycle-details" <?= $activeTab !== 'cycles' ? 'hidden' : '' ?> class="relative rounded-2xl border border-gray-200 bg-white shadow-sm <?= $activeTab !== 'cycles' ? 'hidden' : '' ?>">
                                <div class="relative z-20 flex flex-wrap items-start justify-between gap-4 p-5">
                                <div>
                                        <div class="flex flex-wrap items-center gap-3">
                                            <h3 class="text-lg font-semibold text-gray-900"><?= $escape($selectedCycle['cycle_name']) ?> · Recommendations</h3>
                                            <span class="inline-flex rounded-full px-2.5 py-1 text-[11px] font-semibold ring-1 ring-inset <?= match ($selectedCycle['status']) { 'Open' => 'bg-blue-50 text-blue-700 ring-blue-600/20', 'Approved', 'Published' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20', 'Submitted' => 'bg-indigo-50 text-indigo-700 ring-indigo-600/20', 'Locked' => 'bg-gray-100 text-gray-600 ring-gray-500/20', default => 'bg-amber-50 text-amber-700 ring-amber-600/20' } ?>"><?= $escape($selectedCycle['status']) ?></span>
                                        </div>
                                        <p class="mt-1 text-sm text-gray-500">Review employee recommendations and exclusions · Eligibility cutoff <?= $escape($selectedCycle['eligibility_cutoff_date']) ?></p>
                                </div>
                                    <div class="flex flex-wrap items-center gap-3">
                                        <?php if (in_array($selectedCycle['status'], ['Draft', 'Open'], true)): ?>
                                            <details class="relative shrink-0">
                                                <summary class="inline-flex w-auto cursor-pointer list-none items-center rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">Other actions <i class="bi bi-chevron-down ml-1" aria-hidden="true"></i></summary>
                                                <div class="absolute right-0 top-full z-30 mt-2 flex w-56 max-w-[90vw] flex-col gap-2 rounded-xl border border-gray-200 bg-white p-2 shadow-lg">
                                                    <?php if ($selectedCycle['status'] === 'Draft'): ?>
                                                        <a class="rounded-lg px-3 py-2 text-sm text-gray-700 hover:bg-gray-50" href="index.php?page=compensation&amp;tab=cycles&amp;edit_cycle=<?= (int) $selectedCycle['id'] ?>">Edit draft</a>
                                                        <?php if (!($pageData['workflowMigrationRequired'] ?? false) && $selectedRecommendations !== []): ?>
                                                            <form method="post" data-confirm-title="Regenerate recommendations?" data-confirm-message="This replaces the current recommendations for this Draft cycle." data-confirm-label="Regenerate">
                                                                <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                                                                <input type="hidden" name="action" value="generate_recommendations">
                                                                <input type="hidden" name="cycle_id" value="<?= (int) $selectedCycle['id'] ?>">
                                                                <button type="submit" class="w-full rounded-lg px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-50">Regenerate recommendations</button>
                                                            </form>
                                                        <?php endif; ?>
                                                        <form method="post" data-confirm-title="Delete this Draft cycle?" data-confirm-message="This permanently deletes the Draft cycle and its associated recommendations. This action cannot be undone." data-confirm-label="Delete draft" data-confirm-destructive="true">
                                                            <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                                                            <input type="hidden" name="action" value="delete_cycle">
                                                            <input type="hidden" name="cycle_id" value="<?= (int) $selectedCycle['id'] ?>">
                                                            <button type="submit" class="w-full rounded-lg px-3 py-2 text-left text-sm text-rose-700 hover:bg-rose-50">Delete draft</button>
                                                        </form>
                                                    <?php elseif ($selectedCycle['status'] === 'Open'): ?>
                                                        <form method="post" class="space-y-2 p-2">
                                                            <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                                                            <input type="hidden" name="action" value="top_up_budget">
                                                            <input type="hidden" name="cycle_id" value="<?= (int) $selectedCycle['id'] ?>">
                                                            <label class="block text-xs font-medium text-gray-700">Add to cycle budget<input type="number" name="top_up_amount" min="0.01" step="0.01" required aria-label="Budget top-up amount" class="mt-1 block w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm"></label>
                                                            <button type="submit" class="w-full rounded-lg bg-slate-900 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-800">Add budget</button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            </details>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="border-t border-gray-100 px-5 py-3">
                                    <div class="flex flex-wrap items-center gap-2 text-xs">
                                        <?php foreach ($pageData['recommendationCounts'] ?? [] as $status => $count): ?>
                                            <span class="rounded-full bg-gray-100 px-2.5 py-1 text-gray-600"><?= $escape($status) ?> <span class="font-semibold text-gray-800"><?= (int) $count ?></span></span>
                                        <?php endforeach; ?>
                                        <span class="rounded-full bg-gray-100 px-2.5 py-1 text-gray-600">Excluded <span class="font-semibold text-gray-800"><?= count($pageData['exclusions'] ?? []) ?></span></span>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-4 overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200 text-left text-sm">
                                    <thead class="bg-gray-50">
                                        <tr><th class="whitespace-nowrap px-4 py-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Employee</th><th class="whitespace-nowrap px-4 py-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Current / grade</th><th class="whitespace-nowrap px-4 py-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Compa / rating</th><th class="whitespace-nowrap px-4 py-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Matrix increase</th><th class="whitespace-nowrap px-4 py-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Suggested / final</th><th class="whitespace-nowrap px-4 py-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Manager override</th><th class="whitespace-nowrap px-4 py-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Status / flag</th><th class="px-4 py-3 text-right text-[11px] font-semibold uppercase tracking-wide text-gray-500">Review</th></tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100 bg-white">
                                        <?php foreach ($pageData['recommendations'] ?? [] as $recommendation): ?>
                                            <tr>
                                                <td class="px-4 py-3"><span class="font-medium text-gray-900"><?= $escape($recommendation['first_name'] . ' ' . $recommendation['last_name']) ?></span><span class="block text-xs text-gray-500"><?= $escape($recommendation['employee_code']) ?> · <?= $escape($recommendation['department']) ?></span></td>
                                                <td class="px-4 py-3">₱<?= number_format((float) $recommendation['current_salary'], 2) ?><span class="block text-xs text-gray-500"><?= $escape($recommendation['grade_code']) ?> · min ₱<?= number_format((float) $recommendation['grade_minimum'], 2) ?> · max ₱<?= number_format((float) $recommendation['grade_maximum'], 2) ?></span></td>
                                                <td class="px-4 py-3"><?= $escape($recommendation['compa_ratio']) ?> · <?= (int) $recommendation['performance_rating'] ?></td>
                                                <td class="px-4 py-3"><?= $escape($recommendation['matrix_increase_percentage']) ?>%</td>
                                                <td class="px-4 py-3">₱<?= number_format((float) $recommendation['suggested_new_salary'], 2) ?><span class="block text-xs text-gray-500"><?= $recommendation['final_new_salary'] === null ? 'Final: not set' : 'Final: ₱' . number_format((float) $recommendation['final_new_salary'], 2) ?></span></td>
                                                <td class="px-4 py-3"><?= $recommendation['override_flag'] ? 'Yes' : 'No' ?><span class="block max-w-48 text-xs text-gray-500"><?= $escape($recommendation['manager_comment'] ?? '') ?></span></td>
                                                <td class="px-4 py-3"><?= $escape($recommendation['status']) ?><?php if ($recommendation['flag'] !== 'None'): ?><span class="block text-xs text-red-700"><?= $escape($recommendation['flag']) ?></span><?php endif; ?><?php if ($recommendation['review_note'] !== null): ?><span class="block max-w-48 text-xs text-amber-700"><?= $escape($recommendation['review_note']) ?></span><?php endif; ?></td>
                                                <td class="px-4 py-3 text-right">
                                                    <?php if ($selectedCycle['status'] === 'Open' && $recommendation['status'] === 'Submitted'): ?>
                                                        <form method="post" class="space-y-1" data-confirm-title="Save this review decision?" data-confirm-message="This applies the selected approval or return decision to the recommendation." data-confirm-label="Save review">
                                                            <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                                                            <input type="hidden" name="action" value="review_recommendation">
                                                            <input type="hidden" name="cycle_id" value="<?= (int) $selectedCycle['id'] ?>">
                                                            <input type="hidden" name="recommendation_id" value="<?= (int) $recommendation['id'] ?>">
                                                            <select name="review_action" class="w-24 rounded border-gray-300 text-xs">
                                                                <option value="approve">Approve</option>
                                                                <option value="return">Return</option>
                                                            </select>
                                                            <input name="review_note" maxlength="500" placeholder="Required if returned" class="w-36 rounded border-gray-300 text-xs">
                                                            <button type="submit" class="rounded-lg border border-gray-300 px-2.5 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50">Save review</button>
                                                        </form>
                                                    <?php else: ?>
                                                        <span class="text-xs text-gray-400">—</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <?php if (($pageData['recommendations'] ?? []) === []): ?>
                                            <tr><td colspan="8" class="px-4 py-12 text-center text-sm text-gray-500">No recommendations have been generated for this cycle.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="mt-6 border-t border-gray-100 pt-4">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h4 class="text-sm font-semibold text-gray-900">Excluded employees</h4>
                                    <?php if (($pageData['exclusions'] ?? []) !== []): ?>
                                        <span class="inline-flex items-center gap-1.5 rounded-none bg-rose-50 px-2 py-1 text-[10px] font-bold tracking-wide text-rose-700">FAIL <span class="font-semibold"><?= count($pageData['exclusions']) ?></span></span>
                                    <?php endif; ?>
                                </div>
                                <p class="mt-1 text-xs text-gray-500"><?= ($pageData['exclusions'] ?? []) === [] ? 'No employees were excluded from this cycle.' : 'These employees were not included in the cycle. Check the reason to see what prevented eligibility.' ?></p>
                                <div class="mt-2 overflow-x-auto">
                                    <table class="min-w-full divide-y divide-gray-200 text-left text-sm">
                                        <thead class="bg-gray-50"><tr><th class="px-4 py-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Employee</th><th class="px-4 py-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Eligibility check</th></tr></thead>
                                        <tbody class="divide-y divide-gray-100 bg-white">
                                            <?php foreach ($pageData['exclusions'] ?? [] as $exclusion): ?>
                                                <tr>
                                                    <td class="px-4 py-3"><span class="font-medium text-gray-900"><?= $escape($exclusion['first_name'] . ' ' . $exclusion['last_name']) ?></span><span class="ml-2 text-xs text-gray-500"><?= $escape($exclusion['employee_code']) ?></span></td>
                                                    <td class="px-4 py-3">
                                                        <div class="flex items-center gap-2">
                                                            <span class="shrink-0 rounded-none bg-rose-50 px-2 py-1 text-[10px] font-bold tracking-wide text-rose-700">FAIL</span>
                                                            <span class="text-gray-700"><?= $escape($exclusion['exclusion_reason']) ?></span>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                            <?php if (($pageData['exclusions'] ?? []) === []): ?>
                                                <tr><td colspan="2" class="px-4 py-8 text-center text-sm text-gray-500">No exclusions were recorded.</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </section>
                    <?php endif; ?>
                    </section>
                </div>
                <?php endif; ?>
            </div>
            </main>
        </div>
    </div>
    <dialog id="compensationConfirmDialog" class="m-auto w-[calc(100%-2rem)] max-w-md rounded-2xl border border-gray-200 bg-white p-0 text-gray-900 shadow-2xl backdrop:bg-slate-950/40">
        <div class="p-6">
            <div class="flex items-start gap-4">
                <div id="compensationConfirmIcon" class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-blue-50 text-blue-700">
                    <i class="bi bi-question-lg text-lg" aria-hidden="true"></i>
                </div>
                <div>
                    <h2 id="compensationConfirmTitle" class="text-lg font-semibold">Confirm action</h2>
                    <p id="compensationConfirmMessage" class="mt-2 text-sm leading-6 text-gray-600"></p>
                </div>
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <button id="compensationConfirmCancel" type="button" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">Cancel</button>
                <button id="compensationConfirmAccept" type="button" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">Confirm</button>
            </div>
        </div>
    </dialog>
    <script>
        (() => {
            document.querySelectorAll('[data-open-version-dialog]').forEach((button) => {
                const dialog = document.getElementById(button.dataset.openVersionDialog);
                if (dialog) {
                    button.addEventListener('click', () => dialog.showModal());
                }
            });
            document.querySelectorAll('[data-close-version-dialog]').forEach((button) => {
                button.addEventListener('click', () => {
                    const dialog = button.closest('dialog');
                    if (dialog) {
                        dialog.close();
                    }
                });
            });
            document.querySelectorAll('[data-version-dialog]').forEach((dialog) => {
                dialog.addEventListener('click', (event) => {
                    if (event.target === dialog) {
                        dialog.close();
                    }
                });
            });
            const tabLinks = [...document.querySelectorAll('[data-compensation-tab]')];
            const tabNames = tabLinks.map((link) => link.dataset.compensationTab);
            const cycleDetails = document.getElementById('cycle-details');
            const activateTab = (name) => {
                if (!tabNames.includes(name)) {
                    return;
                }
                tabLinks.forEach((link) => {
                    const selected = link.dataset.compensationTab === name;
                    const panel = document.getElementById(link.getAttribute('aria-controls'));
                    link.setAttribute('aria-selected', String(selected));
                    if (selected) {
                        link.setAttribute('aria-current', 'page');
                    } else {
                        link.removeAttribute('aria-current');
                    }
                    link.classList.toggle('border-blue-600', selected);
                    link.classList.toggle('text-blue-700', selected);
                    link.classList.toggle('border-transparent', !selected);
                    link.classList.toggle('text-gray-500', !selected);
                    link.classList.toggle('hover:text-gray-800', !selected);
                    if (panel) {
                        panel.hidden = !selected;
                        panel.classList.toggle('hidden', !selected);
                        panel.classList.toggle('space-y-6', selected);
                    }
                });
                if (cycleDetails && name !== 'cycles') {
                    cycleDetails.remove();
                }
            };
            tabLinks.forEach((link) => {
                link.addEventListener('click', (event) => {
                    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                        return;
                    }
                    event.preventDefault();
                    window.location.assign(link.href);
                });
                link.addEventListener('keydown', (event) => {
                    let nextIndex = tabNames.indexOf(link.dataset.compensationTab);
                    if (event.key === 'ArrowRight') {
                        nextIndex = (nextIndex + 1) % tabNames.length;
                    } else if (event.key === 'ArrowLeft') {
                        nextIndex = (nextIndex - 1 + tabNames.length) % tabNames.length;
                    } else if (event.key === 'Home') {
                        nextIndex = 0;
                    } else if (event.key === 'End') {
                        nextIndex = tabNames.length - 1;
                    } else {
                        return;
                    }
                    event.preventDefault();
                    tabLinks[nextIndex].focus();
                    tabLinks[nextIndex].click();
                });
            });
            window.addEventListener('popstate', () => {
                const url = new URL(window.location.href);
                if (url.searchParams.get('page') === 'compensation') {
                    activateTab(url.searchParams.get('tab') || tabNames[0]);
                }
            });

            const cycleDialog = document.getElementById('cycleFormDialog');
            if (cycleDialog) {
                const closeCycleDialog = () => {
                    if (cycleDialog.dataset.openOnLoad === 'true') {
                        window.location.assign('index.php?page=compensation&tab=cycles');
                        return;
                    }
                    cycleDialog.close();
                };
                document.querySelectorAll('[data-open-cycle-dialog]').forEach((button) => {
                    button.addEventListener('click', () => cycleDialog.showModal());
                });
                document.querySelectorAll('[data-close-cycle-dialog]').forEach((button) => {
                    button.addEventListener('click', closeCycleDialog);
                });
                cycleDialog.addEventListener('cancel', () => {
                    if (cycleDialog.dataset.openOnLoad === 'true') {
                        window.location.assign('index.php?page=compensation&tab=cycles');
                    }
                });
                cycleDialog.addEventListener('click', (event) => {
                    if (event.target === cycleDialog) {
                        closeCycleDialog();
                    }
                });
                if (cycleDialog.dataset.openOnLoad === 'true') {
                    cycleDialog.showModal();
                }
            }

            const dialog = document.getElementById('compensationConfirmDialog');
            const title = document.getElementById('compensationConfirmTitle');
            const message = document.getElementById('compensationConfirmMessage');
            const acceptButton = document.getElementById('compensationConfirmAccept');
            const cancelButton = document.getElementById('compensationConfirmCancel');
            const icon = document.getElementById('compensationConfirmIcon');
            let pendingForm = null;
            let pendingSubmitter = null;

            document.addEventListener('submit', (event) => {
                const form = event.target;
                if (!(form instanceof HTMLFormElement) || form.dataset.confirmBypass === 'true') {
                    if (form instanceof HTMLFormElement) {
                        delete form.dataset.confirmBypass;
                    }
                    return;
                }
                if (!form.dataset.confirmTitle) {
                    return;
                }
                event.preventDefault();
                pendingForm = form;
                pendingSubmitter = event.submitter || null;
                title.textContent = form.dataset.confirmTitle;
                message.textContent = form.dataset.confirmMessage || 'Please confirm that you want to continue.';
                acceptButton.textContent = form.dataset.confirmLabel || 'Confirm';
                const destructive = form.dataset.confirmDestructive === 'true';
                acceptButton.className = destructive
                    ? 'rounded-lg bg-rose-700 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-800'
                    : 'rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800';
                icon.className = destructive
                    ? 'flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-rose-50 text-rose-700'
                    : 'flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-blue-50 text-blue-700';
                icon.innerHTML = destructive
                    ? '<i class="bi bi-exclamation-triangle text-lg" aria-hidden="true"></i>'
                    : '<i class="bi bi-question-lg text-lg" aria-hidden="true"></i>';
                dialog.showModal();
            }, true);

            const closeDialog = () => {
                pendingForm = null;
                pendingSubmitter = null;
                dialog.close();
            };
            cancelButton.addEventListener('click', closeDialog);
            dialog.addEventListener('cancel', () => {
                pendingForm = null;
                pendingSubmitter = null;
            });
            dialog.addEventListener('click', (event) => {
                if (event.target === dialog) {
                    closeDialog();
                }
            });
            acceptButton.addEventListener('click', () => {
                if (!pendingForm || !pendingForm.reportValidity()) {
                    return;
                }
                const form = pendingForm;
                const submitter = pendingSubmitter;
                pendingForm = null;
                pendingSubmitter = null;
                dialog.close();
                form.dataset.confirmBypass = 'true';
                if (submitter) {
                    form.requestSubmit(submitter);
                } else {
                    form.requestSubmit();
                }
            });
        })();
    </script>
    <?php if ($isManager): ?>
        <script>
            (() => {
                const rows = [...document.querySelectorAll('[data-manager-recommendation]')];
                const formatter = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });
                const refreshTeamTotals = () => {
                    let teamTotal = 0;
                    const byDepartment = new Map();
                    rows.forEach((row) => {
                        const input = row.querySelector('input[name="final_increase_percentage"]');
                        const currentSalary = Number(input?.dataset.currentSalary);
                        const percentage = Number(input?.value);
                        if (!Number.isFinite(currentSalary) || !Number.isFinite(percentage) || percentage < 0) {
                            return;
                        }
                        const finalSalary = Math.round((currentSalary * (1 + percentage / 100) + Number.EPSILON) * 100) / 100;
                        const increase = finalSalary - currentSalary;
                        row.querySelector('[data-final-salary]').textContent = formatter.format(finalSalary);
                        teamTotal += increase;
                        const department = row.dataset.managerDepartment;
                        byDepartment.set(department, (byDepartment.get(department) || 0) + increase);
                    });
                    document.getElementById('managerTeamTotal').textContent = formatter.format(teamTotal);
                    document.querySelectorAll('[data-manager-department-total]').forEach((item) => {
                        const department = item.dataset.managerDepartmentTotal;
                        const allocation = Number(item.dataset.managerDepartmentAllocation);
                        item.textContent = `${item.dataset.managerDepartmentName}: ${formatter.format(byDepartment.get(department) || 0)} / ${formatter.format(allocation)}`;
                    });
                };
                rows.forEach((row) => {
                    row.querySelector('input[name="final_increase_percentage"]')?.addEventListener('input', refreshTeamTotals);
                });
                if (rows.length > 0) {
                    refreshTeamTotals();
                }
            })();
        </script>
    <?php endif; ?>
    <?php require __DIR__ . '/../partials/workspace_scripts.php'; ?>
</body>
</html>