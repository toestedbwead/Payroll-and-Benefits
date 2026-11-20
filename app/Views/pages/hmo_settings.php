<?php
require_once __DIR__ . '/../partials/view_guard.php';
preventDirectViewAccess(__FILE__);

$hmo = $hmoPageData ?? [];
$settings = $settingsPageData ?? [];
$section = isset($settingsSection) && is_string($settingsSection) ? $settingsSection : 'organization';
$organization = $settings['organization'] ?? [];
$payroll = $settings['payroll'] ?? [];
$compensationPage = $compensationSettingsPageData ?? [];
$compensationTab = in_array($compensationPage['activeTab'] ?? '', ['structure', 'matrix', 'employees'], true)
    ? $compensationPage['activeTab']
    : 'structure';
$structureFormValues = is_array($compensationPage['structureFormValues'] ?? null)
    ? $compensationPage['structureFormValues']
    : [];
$claimsThresholds = $settings['claimsThresholds'] ?? [];
$workLocations = $settings['workLocations'] ?? [];
$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$money = static fn (float|int|string $amount): string => '₱' . number_format((float) $amount, 2);
$plans = $hmo['plans'] ?? [];
$flash = $section === 'benefits' ? ($hmo['flash'] ?? null) : ($settings['flash'] ?? null);
$csrfToken = isset($_SESSION['csrf_token']) && is_string($_SESSION['csrf_token']) ? $_SESSION['csrf_token'] : '';
$returnPage = isset($settingsReturnPage) && is_string($settingsReturnPage) ? $settingsReturnPage : 'dashboard';
$sectionNames = [
    'organization' => ['Organization Profile', 'Manage the business details shown across company documents and payslips.', 'bi-building'],
    'work_locations' => ['Work Locations', 'Manage office addresses and choose the primary location for payslip records.', 'bi-geo-alt'],
    'payroll' => ['Payroll', 'Set your default pay schedule and currency.', 'bi-wallet2'],
    'compensation' => ['Compensation', 'Manage salary structures, merit matrices, and employee inputs.', 'bi-cash-coin'],
    'claims' => ['Claims & Reimbursement', 'Configure reimbursement ceilings and claim filing windows.', 'bi-clipboard-check'],
    'benefits' => ['HMO & Benefits', 'Configure HMO provider plans and availability.', 'bi-heart-pulse'],
];
$sectionName = $sectionNames[$section][0] ?? 'Settings';
$dependentRatesByTier = $section === 'benefits' ? HmoService::DEPENDENT_MONTHLY_PREMIUMS : [];
$dependentRatesJson = $escape(json_encode($dependentRatesByTier, JSON_THROW_ON_ERROR));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $escape($sectionName) ?> Settings | Payroll & Benefits</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 font-sans antialiased">
    <div class="flex h-screen flex-col overflow-hidden">
        <?php require __DIR__ . '/../partials/workspace_header.php'; ?>
        <div class="flex min-h-0 flex-1 overflow-hidden">
                <aside class="w-64 shrink-0 overflow-y-auto border-r border-gray-200 bg-white px-3 py-4">
                    <a href="index.php?page=<?= rawurlencode($returnPage) ?>" class="mb-5 inline-flex items-center gap-2 rounded-lg px-3 py-2.5 text-sm font-medium text-blue-600 hover:bg-gray-50">
                        <i class="bi bi-arrow-left" aria-hidden="true"></i>Back
                    </a>
                    <h1 class="px-3 text-xl font-bold text-gray-900">Settings</h1>
                    <nav class="mt-4 space-y-1" aria-label="Settings sections">
                        <?php foreach ($sectionNames as $sectionKey => [$name, $_description, $icon]): ?>
                            <a href="index.php?page=settings&amp;section=<?= rawurlencode($sectionKey) ?>&amp;return_to=<?= rawurlencode($returnPage) ?>"
                               aria-current="<?= $section === $sectionKey ? 'page' : 'false' ?>"
                               class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors <?= $section === $sectionKey ? 'bg-blue-50 text-blue-600' : 'text-gray-600 hover:bg-gray-50' ?>">
                                <i class="bi <?= $icon ?>" aria-hidden="true"></i><?= $escape($name) ?>
                            </a>
                        <?php endforeach; ?>
                    </nav>
                </aside>
                <main class="flex min-w-0 flex-1 flex-col overflow-hidden">
                <div class="min-w-0 flex-1 overflow-y-auto p-5 sm:p-8">
                    <div class="mx-auto max-w-6xl">
                    <div class="flex flex-wrap items-end justify-between gap-4">
                        <div>
                            <h2 class="text-2xl font-bold text-gray-900"><?= $escape($sectionName) ?></h2>
                            <p class="mt-1 text-sm text-gray-500"><?= $escape($sectionNames[$section][1] ?? '') ?></p>
                        </div>
                        <?php if ($section === 'benefits' && ($hmo['canManage'] ?? false)): ?>
                            <button id="openAddPlan" type="button" class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700">
                                <i class="bi bi-plus-lg" aria-hidden="true"></i>Add plan
                            </button>
                        <?php endif; ?>
                    </div>

                    <?php if (is_array($flash)): ?>
                        <div role="<?= ($flash['type'] ?? '') === 'success' ? 'status' : 'alert' ?>" class="mt-5 rounded-xl border px-4 py-3 text-sm <?= ($flash['type'] ?? '') === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-rose-200 bg-rose-50 text-rose-800' ?>">
                            <?= $escape((string) ($flash['message'] ?? '')) ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!($settings['canManage'] ?? false)): ?>
                        <div role="alert" class="mt-6 rounded-xl border border-rose-200 bg-rose-50 p-5 text-sm text-rose-800">Your account cannot access settings.</div>
                    <?php elseif ($settings['loadError'] ?? false): ?>
                        <div role="alert" class="mt-6 rounded-xl border border-rose-200 bg-rose-50 p-5 text-sm text-rose-800"><?= $escape((string) ($flash['message'] ?? 'Settings could not be loaded.')) ?></div>
                    <?php elseif ($section === 'benefits' && !($hmo['canManage'] ?? false)): ?>
                        <div role="alert" class="mt-6 rounded-xl border border-rose-200 bg-rose-50 p-5 text-sm text-rose-800">Your account cannot access HMO settings.</div>
                    <?php elseif ($section === 'benefits' && ($hmo['loadError'] ?? false)): ?>
                        <div role="alert" class="mt-6 rounded-xl border border-rose-200 bg-rose-50 p-5 text-sm text-rose-800"><?= $escape((string) ($hmo['flash']['message'] ?? 'HMO settings could not be loaded. Check the database configuration.')) ?></div>
                    <?php else: ?>
                        <?php if ($section === 'organization'): ?>
                            <form method="post" action="index.php?page=settings&amp;section=organization&amp;return_to=<?= rawurlencode($returnPage) ?>" class="mt-6 max-w-4xl rounded-2xl border border-gray-200 bg-white p-5 shadow-sm sm:p-7">
                                <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                                <input type="hidden" name="return_to" value="<?= $escape($returnPage) ?>">
                                <input type="hidden" name="action" value="save_organization">
                                <div class="mb-6 border-b border-gray-100 pb-4">
                                    <h3 class="font-semibold text-gray-900">Organization details</h3>
                                    <p class="mt-1 text-sm text-gray-500">Organization details saved for use across company records and payslips.</p>
                                </div>
                                <div class="grid gap-5 sm:grid-cols-2">
                                    <label class="text-sm font-medium text-gray-700 sm:col-span-2">Organization name <span class="text-rose-600">*</span><input name="name" maxlength="160" required value="<?= $escape((string) ($organization['name'] ?? '')) ?>" class="mt-1.5 block w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm"></label>
                                    <label class="text-sm font-medium text-gray-700">Business location <span class="text-rose-600">*</span><input name="business_location" maxlength="120" required value="<?= $escape((string) ($organization['business_location'] ?? '')) ?>" class="mt-1.5 block w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm"></label>
                                    <label class="text-sm font-medium text-gray-700">Industry<input name="industry" maxlength="120" value="<?= $escape((string) ($organization['industry'] ?? '')) ?>" class="mt-1.5 block w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm"></label>
                                    <label class="text-sm font-medium text-gray-700">Tax ID<input name="tax_id" maxlength="80" value="<?= $escape((string) ($organization['tax_id'] ?? '')) ?>" class="mt-1.5 block w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm"></label>
                                    <label class="text-sm font-medium text-gray-700">Contact email<input name="email" type="email" maxlength="254" value="<?= $escape((string) ($organization['email'] ?? '')) ?>" class="mt-1.5 block w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm"></label>
                                    <label class="text-sm font-medium text-gray-700">Contact phone<input name="phone" maxlength="40" value="<?= $escape((string) ($organization['phone'] ?? '')) ?>" class="mt-1.5 block w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm"></label>
                                    <label class="text-sm font-medium text-gray-700">Date format<select name="date_format" class="mt-1.5 block w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm"><?php foreach (['Y-m-d' => 'YYYY-MM-DD', 'm/d/Y' => 'MM/DD/YYYY', 'd/m/Y' => 'DD/MM/YYYY'] as $value => $label): ?><option value="<?= $value ?>" <?= ($organization['date_format'] ?? 'Y-m-d') === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
                                </div>
                                <div class="mt-6 flex justify-end"><button type="submit" class="rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700">Save organization</button></div>
                            </form>
                        <?php elseif ($section === 'work_locations'): ?>
                            <section class="mt-6 grid gap-5 xl:grid-cols-[minmax(18rem,0.8fr)_minmax(0,1.2fr)]">
                                <form method="post" action="index.php?page=settings&amp;section=work_locations&amp;return_to=<?= rawurlencode($returnPage) ?>" class="h-fit rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                                    <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>"><input type="hidden" name="action" value="save_location">
                                    <input type="hidden" name="return_to" value="<?= $escape($returnPage) ?>">
                                    <h3 class="font-semibold text-gray-900">Add a work location</h3>
                                    <div class="mt-4 space-y-3">
                                        <label class="block text-sm font-medium text-gray-700">Location name<input name="location_name" maxlength="120" required class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"></label>
                                        <label class="block text-sm font-medium text-gray-700">Street address<input name="address" maxlength="255" class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"></label>
                                        <div class="grid grid-cols-2 gap-3"><label class="text-sm font-medium text-gray-700">City<input name="city" maxlength="100" class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"></label><label class="text-sm font-medium text-gray-700">Province<input name="province" maxlength="100" class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"></label></div>
                                        <label class="block text-sm font-medium text-gray-700">Postal code<input name="postal_code" maxlength="20" class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"></label>
                                        <label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" name="is_primary" value="1" class="rounded border-gray-300">Set as primary work location</label>
                                    </div>
                                    <button type="submit" class="mt-5 w-full rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700">Add location</button>
                                </form>
                                <div class="space-y-4">
                                    <?php if ($workLocations === []): ?>
                                        <div class="rounded-xl border border-dashed border-gray-300 bg-white p-8 text-center text-sm text-gray-500">No work locations have been added.</div>
                                    <?php endif; ?>
                                    <?php foreach ($workLocations as $location): ?>
                                        <form method="post" action="index.php?page=settings&amp;section=work_locations&amp;return_to=<?= rawurlencode($returnPage) ?>" class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                                            <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>"><input type="hidden" name="action" value="save_location"><input type="hidden" name="location_id" value="<?= (int) $location['id'] ?>">
                                            <input type="hidden" name="return_to" value="<?= $escape($returnPage) ?>">
                                            <div class="mb-4 flex items-center justify-between gap-3"><h3 class="font-semibold text-gray-900"><?= $escape((string) $location['name']) ?></h3><?php if ((int) $location['is_primary'] === 1): ?><span class="rounded-full bg-blue-50 px-2.5 py-1 text-xs font-semibold text-blue-700">Primary</span><?php endif; ?></div>
                                            <div class="grid gap-3 sm:grid-cols-2">
                                                <label class="text-xs font-medium text-gray-600 sm:col-span-2">Location name<input name="location_name" maxlength="120" required value="<?= $escape((string) $location['name']) ?>" class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"></label>
                                                <label class="text-xs font-medium text-gray-600 sm:col-span-2">Street address<input name="address" maxlength="255" value="<?= $escape((string) $location['address']) ?>" class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"></label>
                                                <label class="text-xs font-medium text-gray-600">City<input name="city" maxlength="100" value="<?= $escape((string) $location['city']) ?>" class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"></label>
                                                <label class="text-xs font-medium text-gray-600">Province<input name="province" maxlength="100" value="<?= $escape((string) $location['province']) ?>" class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"></label>
                                                <label class="text-xs font-medium text-gray-600">Postal code<input name="postal_code" maxlength="20" value="<?= $escape((string) $location['postal_code']) ?>" class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"></label>
                                                <label class="flex items-center gap-2 self-end pb-2 text-xs text-gray-700"><input type="checkbox" name="is_primary" value="1" <?= (int) $location['is_primary'] === 1 ? 'checked' : '' ?> class="rounded border-gray-300">Primary location</label>
                                            </div>
                                            <div class="mt-4 flex justify-end gap-2">
                                                <button type="submit" class="rounded-lg bg-blue-600 px-3.5 py-2 text-sm font-semibold text-white hover:bg-blue-700">Save</button>
                                            </div>
                                        </form>
                                        <form method="post" action="index.php?page=settings&amp;section=work_locations&amp;return_to=<?= rawurlencode($returnPage) ?>" class="flex justify-end -mt-3">
                                            <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>"><input type="hidden" name="action" value="delete_location"><input type="hidden" name="location_id" value="<?= (int) $location['id'] ?>">
                                            <input type="hidden" name="return_to" value="<?= $escape($returnPage) ?>">
                                            <button type="submit" class="rounded-b-lg border border-t-0 border-gray-200 bg-white px-4 py-2 text-xs font-semibold text-rose-700 hover:bg-rose-50" onclick="return confirm('Remove this work location?')">Remove location</button>
                                        </form>
                                    <?php endforeach; ?>
                                </div>
                            </section>
                        <?php elseif ($section === 'claims'): ?>
                            <form method="post" action="index.php?page=settings&amp;section=claims&amp;return_to=<?= rawurlencode($returnPage) ?>" class="mt-6 max-w-5xl rounded-2xl border border-gray-200 bg-white shadow-sm">
                                <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>"><input type="hidden" name="action" value="save_claims">
                                <input type="hidden" name="return_to" value="<?= $escape($returnPage) ?>">
                                <div class="border-b border-gray-100 px-5 py-4"><h3 class="font-semibold text-gray-900">Reimbursement thresholds</h3><p class="mt-1 text-sm text-gray-500">These values are used by the Claims review pre-checks and threshold summary.</p></div>
                                <div class="divide-y divide-gray-100">
                                    <?php foreach ($claimsThresholds as $category => $threshold): ?>
                                        <?php $fieldKey = strtolower(str_replace(' ', '_', (string) $category)); ?>
                                        <div class="grid gap-4 px-5 py-4 sm:grid-cols-[minmax(0,1fr)_12rem_12rem] sm:items-end">
                                            <div><h4 class="text-sm font-semibold text-gray-900"><?= $escape((string) $threshold['label']) ?></h4><p class="mt-1 text-xs text-gray-500">Maximum claim amount and days allowed after the expense date.</p></div>
                                            <label class="text-xs font-medium text-gray-600">Maximum amount (₱)<input type="number" name="amount_<?= $escape($fieldKey) ?>" min="0" max="100000000" step="1" required value="<?= $escape((string) $threshold['amount']) ?>" class="claims-whole-amount mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"></label>
                                            <label class="text-xs font-medium text-gray-600">Filing window (days)<input type="number" name="days_<?= $escape($fieldKey) ?>" min="1" max="365" required value="<?= (int) $threshold['days'] ?>" class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"></label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="flex justify-end border-t border-gray-100 bg-gray-50 px-5 py-4"><button type="submit" class="rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700">Save claims settings</button></div>
                            </form>
                        <?php elseif ($section === 'payroll'): ?>
                            <form method="post" action="index.php?page=settings&amp;section=payroll&amp;return_to=<?= rawurlencode($returnPage) ?>" class="mt-6 max-w-3xl rounded-2xl border border-gray-200 bg-white p-5 shadow-sm sm:p-7">
                                <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>"><input type="hidden" name="action" value="save_payroll">
                                <input type="hidden" name="return_to" value="<?= $escape($returnPage) ?>">
                                <h3 class="font-semibold text-gray-900">Payroll defaults</h3><p class="mt-1 text-sm text-gray-500">Set the default currency and pay schedule for payroll planning.</p>
                                <div class="mt-5 grid gap-5 sm:grid-cols-2">
                                    <label class="text-sm font-medium text-gray-700">Currency<select name="currency" class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm"><?php foreach (['PHP' => 'Philippine Peso (PHP)', 'USD' => 'US Dollar (USD)', 'EUR' => 'Euro (EUR)'] as $value => $label): ?><option value="<?= $value ?>" <?= ($payroll['currency'] ?? 'PHP') === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
                                    <label class="text-sm font-medium text-gray-700">Pay frequency<select name="frequency" class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm"><?php foreach (['weekly' => 'Weekly', 'biweekly' => 'Biweekly', 'semi_monthly' => 'Semi-monthly', 'monthly' => 'Monthly'] as $value => $label): ?><option value="<?= $value ?>" <?= ($payroll['frequency'] ?? 'semi_monthly') === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
                                    <label class="text-sm font-medium text-gray-700 sm:col-span-2">Pay day(s)<input name="pay_day" maxlength="20" required value="<?= $escape((string) ($payroll['pay_day'] ?? '15,30')) ?>" placeholder="e.g. 15,30 or Friday" class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm"><span class="mt-1 block text-xs font-normal text-gray-500">For semi-monthly schedules, enter both days separated by a comma.</span></label>
                                </div>
                                <div class="mt-6 flex justify-end"><button type="submit" class="rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700">Save payroll settings</button></div>
                            </form>
                        <?php elseif ($section === 'compensation'): ?>
                            <?php if (($compensationPage['migrationRequired'] ?? false) === true): ?>
                                <div role="alert" class="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900">Apply <code>database/007_compensation_migration.sql</code> to enable salary structures and merit matrices.</div>
                            <?php elseif (is_array($compensationPage['flash'] ?? null)): ?>
                                <?php $compensationFlash = $compensationPage['flash']; ?>
                                <div role="<?= ($compensationFlash['type'] ?? '') === 'success' ? 'status' : 'alert' ?>" class="mt-6 rounded-xl border px-4 py-3 text-sm <?= ($compensationFlash['type'] ?? '') === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-rose-200 bg-rose-50 text-rose-800' ?>">
                                    <?= $escape((string) ($compensationFlash['message'] ?? '')) ?>
                                </div>
                            <?php endif; ?>
                            <?php if (!(($compensationPage['migrationRequired'] ?? false) === true)): ?>
                                <nav class="mt-8 flex gap-2 overflow-x-auto border-b border-gray-200" aria-label="Compensation settings sections">
                                    <?php foreach (['structure' => 'Salary structure', 'matrix' => 'Merit matrix', 'employees' => 'Employee inputs'] as $tabKey => $tabLabel): ?>
                                        <?php $tabSelected = $compensationTab === $tabKey; ?>
                                        <a href="index.php?page=settings&amp;section=compensation&amp;tab=<?= $tabKey ?>&amp;return_to=<?= rawurlencode($returnPage) ?>" <?= $tabSelected ? 'aria-current="page"' : '' ?> class="shrink-0 border-b-2 px-4 py-2.5 text-sm font-semibold <?= $tabSelected ? 'border-blue-600 text-blue-700' : 'border-transparent text-gray-500 hover:text-gray-800' ?>">
                                            <?= $escape($tabLabel) ?>
                                        </a>
                                    <?php endforeach; ?>
                                </nav>
                                <?php if (($compensationPage['workflowMigrationRequired'] ?? false) === true): ?>
                                    <div role="alert" class="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">Apply <code>database/008_compensation_workflow_migration.sql</code> and <code>database/009_department_migration.sql</code> to load salary grade details.</div>
                                <?php endif; ?>

                                <?php if ($compensationTab === 'structure'): ?>
                                    <?php
                                    $activeStructure = $compensationPage['activeStructures'][0] ?? null;
                                    $activeGrades = $compensationPage['activeGrades'] ?? [];
                                    $salaryStructures = $compensationPage['salaryStructures'] ?? [];
                                    $archivedStructureGrades = $compensationPage['archivedStructureGrades'] ?? [];
                                    ?>
                                    <section class="mt-5 space-y-5" aria-label="Salary structure">
                                        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                                            <div class="flex flex-wrap items-center justify-between gap-4 border-b border-gray-200 p-5">
                                                <div><h3 class="font-semibold text-gray-900">Current active salary structure</h3><p class="mt-1 text-xs text-gray-500">Existing compensation cycles keep the version they were created with.</p></div>
                                                <details data-version-editor <?= $structureFormValues !== [] ? 'open' : '' ?>>
                                                    <summary data-version-editor-trigger class="ml-auto flex w-fit cursor-pointer list-none items-center gap-2 rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800"><i class="bi bi-plus-lg" aria-hidden="true"></i>Create new version</summary>
                                                    <form id="salaryStructureForm" method="post" action="index.php?page=settings&amp;section=compensation&amp;tab=structure" class="mt-4 w-full overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm sm:min-w-[38rem]">
                                                        <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>"><input type="hidden" name="action" value="save_structure"><input type="hidden" name="return_to" value="settings"><input type="hidden" name="settings_return_to" value="<?= $escape($returnPage) ?>">
                                                        <div class="space-y-4 p-5">
                                                            <div><h4 class="font-semibold text-gray-900">New salary structure version</h4><p class="mt-1 text-sm text-gray-500">Set the effective date and salary range for each grade you use.</p></div>
                                                            <label class="block max-w-xs text-sm font-medium text-gray-700">Effective from<input type="date" name="effective_from" required value="<?= $escape((string) ($structureFormValues['effective_from'] ?? '')) ?>" class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100"></label>
                                                            <p class="text-xs text-gray-500">Enter at least one complete grade row. Every row you use needs a grade code. Leave unused rows completely blank.</p>
                                                            <p data-structure-form-error role="alert" hidden class="hidden rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"></p>
                                                            <div class="overflow-x-auto">
                                                                <table class="min-w-full divide-y divide-gray-200 text-left text-sm">
                                                                    <thead class="bg-gray-50"><tr><th scope="col" class="px-3 py-3 text-xs font-semibold text-gray-500">Grade code <span class="text-rose-600">*</span></th><th scope="col" class="px-3 py-3 text-xs font-semibold text-gray-500">Employee job tier</th><th scope="col" class="px-3 py-3 text-xs font-semibold text-gray-500">Minimum</th><th scope="col" class="px-3 py-3 text-xs font-semibold text-gray-500">Midpoint</th><th scope="col" class="px-3 py-3 text-xs font-semibold text-gray-500">Maximum</th></tr></thead>
                                                                    <tbody class="divide-y divide-gray-100">
                                                                        <?php for ($row = 0; $row < 5; $row++): ?>
                                                                            <tr>
                                                                                <td class="px-3 py-3"><label class="block min-w-32"><span class="mb-1 block text-xs font-medium text-gray-600">Grade <?= $row + 1 ?> code <span class="text-rose-600">*</span></span><input name="grade_code[]" maxlength="10" aria-label="Grade code for row <?= $row + 1 ?>, required if this row is used" value="<?= $escape((string) ($structureFormValues['grade_code'][$row] ?? '')) ?>" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100"></label></td>
                                                                                <td class="px-3 py-3"><select name="grade_job_tier[]" aria-label="Employee job tier for grade <?= $row + 1 ?>" class="w-full min-w-36 rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100"><option value="">Select tier</option><?php foreach (['Entry-Level', 'Supervisory', 'Executive'] as $tier): ?><option value="<?= $escape($tier) ?>" <?= ($structureFormValues['grade_job_tier'][$row] ?? '') === $tier ? 'selected' : '' ?>><?= $escape($tier) ?></option><?php endforeach; ?></select></td>
                                                                                <?php foreach (['grade_minimum[]', 'grade_midpoint[]', 'grade_maximum[]'] as $field): ?><?php $fieldKey = str_replace('[]', '', $field); ?><td class="px-3 py-3"><input type="number" name="<?= $escape($field) ?>" aria-label="<?= $escape(ucfirst(str_replace('grade_', '', $fieldKey))) ?> salary for grade <?= $row + 1 ?>" min="0" step="0.01" placeholder="0.00" value="<?= $escape((string) ($structureFormValues[$fieldKey][$row] ?? '')) ?>" class="w-32 rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100"></td><?php endforeach; ?>
                                                                            </tr>
                                                                        <?php endfor; ?>
                                                                    </tbody>
                                                                </table>
                                                            </div>
                                                        </div>
                                                        <div class="flex justify-end gap-2 border-t border-gray-200 bg-gray-50 px-5 py-4">
                                                            <button type="button" data-close-version-editor class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-white">Cancel</button>
                                                            <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700">Save new structure version</button>
                                                        </div>
                                                    </form>
                                                </details>
                                            </div>
                                            <div class="p-5">
                                                <?php if (is_array($activeStructure)): ?>
                                                    <div class="mb-4 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm"><span class="inline-flex items-center gap-2 font-semibold text-gray-800"><i class="bi bi-check-circle-fill text-xs text-emerald-600" aria-hidden="true"></i>Version <?= (int) $activeStructure['version'] ?><span class="font-medium text-emerald-700">Active</span></span><span class="hidden h-4 border-l border-gray-200 sm:block" aria-hidden="true"></span><span class="text-gray-500">Effective from <span class="font-medium text-gray-700"><?= $escape((string) $activeStructure['effective_from']) ?></span></span></div>
                                                    <div class="overflow-x-auto"><table class="min-w-full divide-y divide-gray-200 text-left text-sm">
                                                        <thead class="bg-gray-50"><tr><th class="px-4 py-3 text-xs font-semibold text-gray-500">Grade</th><th class="px-4 py-3 text-xs font-semibold text-gray-500">Job tier</th><th class="px-4 py-3 text-right text-xs font-semibold text-gray-500">Minimum</th><th class="px-4 py-3 text-right text-xs font-semibold text-gray-500">Midpoint</th><th class="px-4 py-3 text-right text-xs font-semibold text-gray-500">Maximum</th></tr></thead>
                                                        <tbody class="divide-y divide-gray-100"><?php foreach ($activeGrades as $grade): ?><tr><td class="px-4 py-3 font-medium text-gray-900"><?= $escape((string) $grade['grade_code']) ?></td><td class="px-4 py-3 text-gray-700"><?= $escape((string) $grade['target_job_tier']) ?></td><td class="px-4 py-3 text-right">₱<?= number_format((float) $grade['minimum_salary'], 2) ?></td><td class="px-4 py-3 text-right">₱<?= number_format((float) $grade['midpoint_salary'], 2) ?></td><td class="px-4 py-3 text-right">₱<?= number_format((float) $grade['maximum_salary'], 2) ?></td></tr><?php endforeach; ?><?php if ($activeGrades === []): ?><tr><td colspan="5" class="px-4 py-8 text-center text-sm text-gray-500">The active structure has no grades.</td></tr><?php endif; ?></tbody>
                                                    </table></div>
                                                <?php else: ?><p class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900">No active salary structure exists yet. Create a version to make grades available for employee assignment and new cycles.</p><?php endif; ?>
                                            </div>
                                        </div>
                                        <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                                            <div class="border-b border-gray-200 px-5 py-4"><h3 class="font-semibold text-gray-900">Archived salary structures</h3><p class="mt-1 text-xs text-gray-500">Kept as records for cycles created with older salary ranges.</p></div>
                                            <?php $archivedStructures = array_values(array_filter($salaryStructures, static fn (array $structure): bool => $structure['status'] === 'Archived')); ?>
                                            <?php if ($archivedStructures !== []): ?>
                                                <div class="overflow-x-auto">
                                                    <table class="w-full table-fixed divide-y divide-gray-200 text-left text-sm">
                                                        <colgroup><col class="w-[20%]"><col class="w-[25%]"><col class="w-[15%]"><col class="w-[20%]"><col class="w-[20%]"></colgroup>
                                                        <thead class="bg-gray-50"><tr><th class="px-5 py-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Version</th><th class="px-5 py-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Effective from</th><th class="px-5 py-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Grades</th><th class="px-5 py-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Status</th><th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wide text-gray-500">Action</th></tr></thead>
                                                        <tbody class="divide-y divide-gray-100">
                                                            <?php foreach ($archivedStructures as $structure): ?>
                                                                <?php $versionGrades = $archivedStructureGrades[(int) $structure['id']] ?? []; $dialogId = 'settings-archived-structure-' . (int) $structure['id']; ?>
                                                                <tr>
                                                                    <td class="px-5 py-3 font-medium text-gray-900">Version <?= (int) $structure['version'] ?></td>
                                                                    <td class="whitespace-nowrap px-5 py-3 text-gray-700"><?= $escape((string) $structure['effective_from']) ?></td>
                                                                    <td class="px-5 py-3 text-gray-700"><?= (int) $structure['grade_count'] ?></td>
                                                                    <td class="px-5 py-3"><span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-600">Archived</span></td>
                                                                    <td class="px-5 py-3 text-left">
                                                                        <button type="button" data-open-version-dialog="<?= $dialogId ?>" class="inline-flex items-center gap-1 rounded-lg border border-gray-300 px-2 py-1 text-xs font-semibold text-gray-700 hover:bg-gray-50"><i class="bi bi-eye" aria-hidden="true"></i>View</button>
                                                                        <dialog id="<?= $dialogId ?>" data-version-dialog class="m-auto w-[min(48rem,calc(100vw-2rem))] max-w-none overflow-hidden rounded-2xl border border-gray-200 bg-white p-0 text-gray-900 shadow-2xl backdrop:bg-slate-950/40">
                                                                            <div class="flex max-h-[85vh] flex-col">
                                                                                <div class="flex items-start justify-between gap-4 border-b border-gray-200 px-5 py-4"><div><h3 class="font-semibold">Salary structure · Version <?= (int) $structure['version'] ?></h3><p class="mt-1 text-sm text-gray-500">Archived · Effective <?= $escape((string) $structure['effective_from']) ?></p></div><button type="button" data-close-version-dialog aria-label="Close version details" class="rounded-lg p-2 text-gray-500 hover:bg-gray-100"><i class="bi bi-x-lg" aria-hidden="true"></i></button></div>
                                                                                <div class="overflow-auto p-5">
                                                                                    <?php if ($versionGrades !== []): ?>
                                                                                        <table class="min-w-full divide-y divide-gray-200 text-left text-sm">
                                                                                            <thead class="bg-gray-50"><tr><th class="px-3 py-2 text-[11px] font-semibold uppercase text-gray-500">Grade</th><th class="px-3 py-2 text-[11px] font-semibold uppercase text-gray-500">Job tier</th><th class="px-3 py-2 text-right text-[11px] font-semibold uppercase text-gray-500">Minimum</th><th class="px-3 py-2 text-right text-[11px] font-semibold uppercase text-gray-500">Midpoint</th><th class="px-3 py-2 text-right text-[11px] font-semibold uppercase text-gray-500">Maximum</th></tr></thead>
                                                                                            <tbody class="divide-y divide-gray-100"><?php foreach ($versionGrades as $grade): ?><tr><td class="px-3 py-2 font-medium"><?= $escape((string) $grade['grade_code']) ?></td><td class="px-3 py-2"><?= $escape((string) $grade['target_job_tier']) ?></td><td class="px-3 py-2 text-right">₱<?= number_format((float) $grade['minimum_salary'], 2) ?></td><td class="px-3 py-2 text-right">₱<?= number_format((float) $grade['midpoint_salary'], 2) ?></td><td class="px-3 py-2 text-right">₱<?= number_format((float) $grade['maximum_salary'], 2) ?></td></tr><?php endforeach; ?></tbody>
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
                                            <?php else: ?><p class="px-5 py-4 text-sm text-gray-500">No archived versions yet. Older versions will appear here when replaced.</p><?php endif; ?>
                                        </section>
                                    </section>
                                <?php elseif ($compensationTab === 'matrix'): ?>
                                    <?php
                                    $activeMatrix = $compensationPage['activeMatrices'][0] ?? null;
                                    $matrixDefaults = [
                                        5 => ['10.00', '8.00', '5.00'],
                                        4 => ['7.00', '5.00', '3.00'],
                                        3 => ['4.00', '3.00', '2.00'],
                                        2 => ['1.00', '0.00', '0.00'],
                                        1 => ['0.00', '0.00', '0.00'],
                                    ];
                                    $matrixBands = [];
                                    foreach ($compensationPage['activeMatrixBands'] ?? [] as $band) {
                                        $matrixBands[(int) $band['performance_rating']][] = $band;
                                    }
                                    $meritMatrices = $compensationPage['meritMatrices'] ?? [];
                                    $archivedMatrixBands = $compensationPage['archivedMatrixBands'] ?? [];
                                    ?>
                                    <section class="mt-5 space-y-5" aria-label="Merit matrix">
                                        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                                            <div class="flex flex-wrap items-center justify-between gap-4 border-b border-gray-200 p-5">
                                                <div><h3 class="font-semibold text-gray-900">Current active merit matrix</h3><p class="mt-1 text-xs text-gray-500">Existing cycles retain their selected matrix version.</p></div>
                                                <details data-version-editor class="w-full max-w-5xl">
                                                    <summary data-version-editor-trigger class="ml-auto flex w-fit cursor-pointer list-none items-center gap-2 rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800"><i class="bi bi-plus-lg" aria-hidden="true"></i>Create new version</summary>
                                                    <form method="post" action="index.php?page=settings&amp;section=compensation&amp;tab=matrix" class="mt-4 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                                                        <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>"><input type="hidden" name="action" value="save_matrix"><input type="hidden" name="return_to" value="settings"><input type="hidden" name="settings_return_to" value="<?= $escape($returnPage) ?>">
                                                        <div class="grid gap-4 border-b border-gray-200 bg-gray-50 p-5 md:grid-cols-[minmax(0,1fr)_16rem] md:items-end">
                                                            <div><h4 class="font-semibold text-gray-900">New merit matrix version</h4><p class="mt-1 text-sm text-gray-500">Set the increase percentage for each performance rating and compa-ratio range.</p></div>
                                                            <label class="block text-sm font-medium text-gray-700">Effective from<input type="date" name="matrix_effective_from" required class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100"></label>
                                                        </div>
                                                        <div class="p-5">
                                                            <div class="overflow-x-auto">
                                                                <table class="min-w-[48rem] w-full table-fixed divide-y divide-gray-200 text-left text-sm">
                                                                    <thead>
                                                                        <tr class="bg-white">
                                                                            <th scope="col" class="w-40 px-4 py-3 text-xs font-semibold uppercase tracking-wide text-gray-500">Performance rating</th>
                                                                            <?php foreach (['Below 0.90', '0.90 to 1.10', 'Above 1.10'] as $bandLabel): ?>
                                                                                <th scope="col" class="px-4 py-3 text-center text-xs font-semibold text-gray-700"><span class="block"><?= $escape($bandLabel) ?></span><span class="mt-1 block text-[11px] font-normal text-gray-500"><?= $bandLabel === 'Below 0.90' ? 'Below midpoint' : ($bandLabel === 'Above 1.10' ? 'Above midpoint' : 'Around midpoint') ?></span></th>
                                                                            <?php endforeach; ?>
                                                                        </tr>
                                                                    </thead>
                                                                    <tbody class="divide-y divide-gray-100">
                                                                        <?php foreach ([5, 4, 3, 2, 1] as $rating): ?>
                                                                            <tr class="<?= $rating % 2 === 0 ? 'bg-gray-50/70' : 'bg-white' ?>">
                                                                                <th scope="row" class="px-4 py-3 text-sm font-semibold text-gray-800">Rating <?= $rating ?></th>
                                                                                <?php foreach (['Below 0.90', '0.90 to 1.10', 'Above 1.10'] as $bandIndex => $bandLabel): ?>
                                                                                    <td class="px-4 py-3">
                                                                                        <input type="hidden" name="matrix[<?= $rating ?>][<?= $bandIndex ?>][compa_ratio_from]" value="<?= ['0.00', '0.90', '1.11'][$bandIndex] ?>">
                                                                                        <input type="hidden" name="matrix[<?= $rating ?>][<?= $bandIndex ?>][compa_ratio_to]" value="<?= ['0.90', '1.11', ''][$bandIndex] ?>">
                                                                                        <label class="relative block">
                                                                                            <span class="sr-only">Rating <?= $rating ?> increase for <?= $escape($bandLabel) ?> compa-ratio</span>
                                                                                            <input type="number" name="matrix[<?= $rating ?>][<?= $bandIndex ?>][increase_percentage]" min="0" max="999.99" step="0.01" required value="<?= $escape($matrixDefaults[$rating][$bandIndex]) ?>" aria-label="Rating <?= $rating ?>, <?= $escape($bandLabel) ?> increase percentage" class="w-full rounded-lg border border-gray-300 bg-white py-2.5 pl-3 pr-8 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100">
                                                                                            <span aria-hidden="true" class="pointer-events-none absolute right-3 top-2.5 text-sm text-gray-400">%</span>
                                                                                        </label>
                                                                                    </td>
                                                                                <?php endforeach; ?>
                                                                            </tr>
                                                                        <?php endforeach; ?>
                                                                    </tbody>
                                                                </table>
                                                            </div>
                                                            <p class="mt-3 text-xs text-gray-500">Percentages start at 0%. All five ratings and all three compa-ratio ranges are required.</p>
                                                        </div>
                                                        <div class="flex justify-end gap-2 border-t border-gray-200 bg-gray-50 px-5 py-4">
                                                            <button type="button" data-close-version-editor class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-white">Cancel</button>
                                                            <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">Save new matrix version</button>
                                                        </div>
                                                    </form>
                                                </details>
                                            </div>
                                            <div class="p-5">
                                                <?php if (is_array($activeMatrix)): ?>
                                                    <div class="mb-4 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm"><span class="inline-flex items-center gap-2 font-semibold text-gray-800"><i class="bi bi-check-circle-fill text-xs text-emerald-600" aria-hidden="true"></i>Version <?= (int) $activeMatrix['version'] ?><span class="font-medium text-emerald-700">Active</span></span><span class="hidden h-4 border-l border-gray-200 sm:block" aria-hidden="true"></span><span class="text-gray-500">Effective from <span class="font-medium text-gray-700"><?= $escape((string) $activeMatrix['effective_from']) ?></span></span></div>
                                                    <div class="overflow-x-auto"><table class="min-w-full divide-y divide-gray-200 text-left text-sm"><thead class="bg-gray-50"><tr><th class="px-4 py-3 text-xs font-semibold text-gray-500">Performance rating</th><th class="px-4 py-3 text-xs font-semibold text-gray-500">Compa-ratio band</th><th class="px-4 py-3 text-right text-xs font-semibold text-gray-500">Increase</th></tr></thead><tbody class="divide-y divide-gray-100">
                                                        <?php foreach ([5, 4, 3, 2, 1] as $rating): ?><?php $ratingBands = $matrixBands[$rating] ?? []; ?><?php if ($ratingBands === []): ?><tr><th class="px-4 py-3 text-left">Rating <?= $rating ?></th><td colspan="2" class="px-4 py-3 text-gray-500">No compa-ratio bands are defined.</td></tr><?php else: ?><?php foreach ($ratingBands as $bandIndex => $band): ?><?php $bandFrom = (float) $band['compa_ratio_from']; $bandTo = $band['compa_ratio_to'] === null ? null : (float) $band['compa_ratio_to']; $bandDescription = $bandTo !== null && $bandTo <= 1.0 ? 'Below the grade midpoint' : ($bandFrom > 1.0 ? 'Above the grade midpoint' : 'Around the grade midpoint'); ?><tr><?php if ($bandIndex === 0): ?><th rowspan="<?= count($ratingBands) ?>" class="px-4 py-3 text-left align-top">Rating <?= $rating ?></th><?php endif; ?><td class="px-4 py-3 text-gray-700"><?= number_format($bandFrom, 2) ?><?php if ($bandTo === null): ?> and above<?php else: ?> to less than <?= number_format($bandTo, 2) ?><?php endif; ?><span class="mt-1 block text-xs text-gray-500"><?= $escape($bandDescription) ?></span></td><td class="px-4 py-3 text-right font-medium"><?= number_format((float) $band['increase_percentage'], 2) ?>%</td></tr><?php endforeach; ?><?php endif; ?><?php endforeach; ?>
                                                    </tbody></table></div>
                                                <?php else: ?><p class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900">No active merit matrix exists yet. Create a version before setting up a compensation cycle.</p><?php endif; ?>
                                            </div>
                                        </div>
                                        <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                                            <div class="border-b border-gray-200 px-5 py-4"><h3 class="font-semibold text-gray-900">Archived merit matrices</h3><p class="mt-1 text-xs text-gray-500">Kept so existing cycles retain the increase rules they were created with.</p></div>
                                            <?php $archivedMatrices = array_values(array_filter($meritMatrices, static fn (array $matrix): bool => $matrix['status'] === 'Archived')); ?>
                                            <?php if ($archivedMatrices !== []): ?>
                                                <div class="overflow-x-auto">
                                                    <table class="w-full table-fixed divide-y divide-gray-200 text-left text-sm">
                                                        <colgroup><col class="w-[20%]"><col class="w-[25%]"><col class="w-[15%]"><col class="w-[20%]"><col class="w-[20%]"></colgroup>
                                                        <thead class="bg-gray-50"><tr><th class="px-5 py-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Version</th><th class="px-5 py-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Effective from</th><th class="px-5 py-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Bands</th><th class="px-5 py-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Status</th><th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wide text-gray-500">Action</th></tr></thead>
                                                        <tbody class="divide-y divide-gray-100">
                                                            <?php foreach ($archivedMatrices as $matrix): ?>
                                                                <?php $versionBands = $archivedMatrixBands[(int) $matrix['id']] ?? []; $dialogId = 'settings-archived-matrix-' . (int) $matrix['id']; ?>
                                                                <tr>
                                                                    <td class="px-5 py-3 font-medium text-gray-900">Version <?= (int) $matrix['version'] ?></td>
                                                                    <td class="whitespace-nowrap px-5 py-3 text-gray-700"><?= $escape((string) $matrix['effective_from']) ?></td>
                                                                    <td class="px-5 py-3 text-gray-700"><?= (int) $matrix['band_count'] ?></td>
                                                                    <td class="px-5 py-3"><span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-600">Archived</span></td>
                                                                    <td class="px-5 py-3 text-left">
                                                                        <button type="button" data-open-version-dialog="<?= $dialogId ?>" class="inline-flex items-center gap-1 rounded-lg border border-gray-300 px-2 py-1 text-xs font-semibold text-gray-700 hover:bg-gray-50"><i class="bi bi-eye" aria-hidden="true"></i>View</button>
                                                                        <dialog id="<?= $dialogId ?>" data-version-dialog class="m-auto w-[min(42rem,calc(100vw-2rem))] max-w-none overflow-hidden rounded-2xl border border-gray-200 bg-white p-0 text-gray-900 shadow-2xl backdrop:bg-slate-950/40">
                                                                            <div class="flex max-h-[85vh] flex-col">
                                                                                <div class="flex items-start justify-between gap-4 border-b border-gray-200 px-5 py-4"><div><h3 class="font-semibold">Merit matrix · Version <?= (int) $matrix['version'] ?></h3><p class="mt-1 text-sm text-gray-500">Archived · Effective <?= $escape((string) $matrix['effective_from']) ?></p></div><button type="button" data-close-version-dialog aria-label="Close version details" class="rounded-lg p-2 text-gray-500 hover:bg-gray-100"><i class="bi bi-x-lg" aria-hidden="true"></i></button></div>
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
                                            <?php else: ?><p class="px-5 py-4 text-sm text-gray-500">No archived versions yet. Older versions will appear here when replaced.</p><?php endif; ?>
                                        </section>
                                    </section>
                                <?php else: ?>
                                    <?php
                                    $employeeData = $compensationPage['employeeData'] ?? [];
                                    $activeGradesByTier = [];
                                    foreach ($compensationPage['activeGrades'] ?? [] as $grade) {
                                        $activeGradesByTier[$grade['target_job_tier']][] = $grade;
                                    }
                                    $employeeNamesById = [];
                                    foreach ($employeeData as $employee) {
                                        $employeeNamesById[(int) $employee['id']] = (string) $employee['first_name'] . ' ' . (string) $employee['last_name'];
                                    }
                                    $gradeOptionsJson = json_encode(
                                        $activeGradesByTier,
                                        JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_THROW_ON_ERROR
                                    );
                                    ?>
                                    <?php if (($compensationPage['workflowMigrationRequired'] ?? false) === true): ?>
                                        <div class="mt-5 rounded-xl border border-gray-200 bg-white p-6 text-sm text-gray-600">Employee compensation inputs are unavailable until the compensation workflow and department migrations are applied.</div>
                                    <?php else: ?>
                                        <section class="mt-5 overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm" aria-label="Employee compensation inputs">
                                            <div class="border-b border-gray-200 p-5">
                                                <h3 class="text-lg font-semibold text-gray-900">Employee compensation inputs</h3>
                                                <p class="mt-1 text-sm text-gray-500">These source inputs feed the Generate recommendations step for a cycle. Blank values remain unknown; employees missing required eligibility data appear in the exclusion list.</p>
                                            </div>
                                            <div class="overflow-x-auto">
                                                <table class="min-w-full divide-y divide-gray-200 text-left text-sm">
                                                    <thead class="bg-gray-50">
                                                        <tr><th class="whitespace-nowrap px-3 py-2.5 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Employee</th><th class="whitespace-nowrap px-3 py-2.5 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Employment</th><th class="whitespace-nowrap px-3 py-2.5 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Salary</th><th class="whitespace-nowrap px-3 py-2.5 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Grade</th><th class="whitespace-nowrap px-3 py-2.5 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Rating</th><th class="px-3 py-2.5 text-right text-[11px] font-semibold uppercase tracking-wide text-gray-500">Actions</th></tr>
                                                    </thead>
                                                    <tbody class="divide-y divide-gray-100 bg-white">
                                                        <?php foreach ($employeeData as $employee): ?>
                                                            <?php
                                                            $employeeId = (int) $employee['id'];
                                                            $managerId = $employee['manager_id'] === null ? '' : (string) (int) $employee['manager_id'];
                                                            $currentSalary = $employee['current_salary'] === null ? '' : (string) $employee['current_salary'];
                                                            $salaryGradeCode = (string) ($employee['salary_grade_code'] ?? '');
                                                            $regularizationStatus = (string) ($employee['regularization_status'] ?? '');
                                                            $performanceRating = $employee['performance_rating'] === null ? '' : (string) (int) $employee['performance_rating'];
                                                            $employeeDetailsJson = json_encode([
                                                                'id' => $employeeId,
                                                                'name' => (string) $employee['first_name'] . ' ' . (string) $employee['last_name'],
                                                                'code' => (string) $employee['employee_code'],
                                                                'department' => (string) $employee['department'],
                                                                'employmentStatus' => (string) ($employee['employment_status'] ?? 'Unknown'),
                                                                'employmentType' => (string) ($employee['employment_type'] ?? 'Unknown'),
                                                                'jobTier' => (string) ($employee['job_tier'] ?? ''),
                                                                'salary' => $currentSalary,
                                                                'salaryDisplay' => $currentSalary === '' ? 'Not set' : $money($currentSalary),
                                                                'gradeCode' => $salaryGradeCode === '' ? 'Unassigned' : $salaryGradeCode,
                                                                'regularizationStatus' => $regularizationStatus === '' ? 'Unknown' : $regularizationStatus,
                                                                'managerId' => $managerId,
                                                                'managerName' => $managerId === '' ? 'Unassigned' : ($employeeNamesById[(int) $managerId] ?? 'Unknown'),
                                                                'performanceRating' => $performanceRating === '' ? 'Unknown' : $performanceRating,
                                                            ], JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_THROW_ON_ERROR);
                                                            ?>
                                                            <tr data-employee-details="<?= $escape($employeeDetailsJson) ?>">
                                                                <td class="whitespace-nowrap px-3 py-2.5"><span class="font-medium text-gray-900"><?= $escape((string) $employee['first_name'] . ' ' . (string) $employee['last_name']) ?></span><span class="block text-xs text-gray-500"><?= $escape((string) $employee['employee_code']) ?> · <?= $escape((string) $employee['department']) ?></span></td>
                                                                <td class="whitespace-nowrap px-3 py-2.5 text-xs text-gray-600"><?= $escape((string) ($employee['employment_status'] ?? 'Unknown')) ?> · <?= $escape((string) ($employee['employment_type'] ?? 'Unknown')) ?></td>
                                                                <td class="whitespace-nowrap px-3 py-2.5 text-xs text-gray-700"><?= $currentSalary === '' ? 'Not set' : $money($currentSalary) ?></td>
                                                                <td class="whitespace-nowrap px-3 py-2.5 text-xs text-gray-700"><?= $salaryGradeCode === '' ? 'Unassigned' : $escape($salaryGradeCode) ?></td>
                                                                <td class="px-3 py-2.5 text-xs text-gray-700"><?= $performanceRating === '' ? 'Unknown' : $escape($performanceRating) ?></td>
                                                                <td class="whitespace-nowrap px-3 py-2.5 text-right"><div class="inline-flex gap-2"><button type="button" data-employee-input-action="view" aria-label="View <?= $escape((string) $employee['employee_code']) ?>" class="rounded-md border border-gray-300 px-2.5 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50">View</button><button type="button" data-employee-input-action="edit" aria-label="Edit <?= $escape((string) $employee['employee_code']) ?>" class="rounded-md border border-blue-200 px-2.5 py-1.5 text-xs font-semibold text-blue-700 hover:bg-blue-50">Edit</button></div></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                        <?php if ($employeeData === []): ?><tr><td colspan="6" class="px-3 py-10 text-center text-sm text-gray-500">No employee records were found.</td></tr><?php endif; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </section>
                                        <dialog id="employeeInputDialog" aria-labelledby="employeeInputDialogTitle" class="m-auto max-h-[90vh] w-[calc(100%-2rem)] max-w-2xl overflow-y-auto rounded-2xl border border-gray-200 bg-white p-0 text-gray-900 shadow-2xl backdrop:bg-slate-950/40">
                                            <form id="employeeInputForm" method="post" action="index.php?page=settings&amp;section=compensation&amp;tab=employees">
                                                <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>"><input type="hidden" name="action" value="save_employee_compensation"><input type="hidden" name="employee_id" id="employeeInputId"><input type="hidden" name="return_to" value="settings"><input type="hidden" name="settings_return_to" value="<?= $escape($returnPage) ?>">
                                                <div class="flex items-start justify-between border-b border-gray-200 px-5 py-4">
                                                    <div><h3 id="employeeInputDialogTitle" class="font-semibold text-gray-900">Employee compensation details</h3><p id="employeeInputDialogSubtitle" class="mt-1 text-xs text-gray-500"></p></div>
                                                    <button type="button" data-close-employee-input aria-label="Close employee details" class="rounded-lg p-2 text-gray-500 hover:bg-gray-100"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                                                </div>
                                                <dl id="employeeInputView" class="grid gap-x-6 gap-y-4 px-5 py-5 sm:grid-cols-2">
                                                    <?php foreach ([
                                                        'employeeViewName' => 'Employee',
                                                        'employeeViewDepartment' => 'Department',
                                                        'employeeViewEmployment' => 'Employment',
                                                        'employeeViewJobTier' => 'Job tier',
                                                        'employeeViewSalary' => 'Current salary',
                                                        'employeeViewGrade' => 'Salary grade',
                                                        'employeeViewRegularization' => 'Regularization',
                                                        'employeeViewManager' => 'Manager',
                                                        'employeeViewRating' => 'Performance rating',
                                                    ] as $elementId => $label): ?>
                                                        <div><dt class="text-xs font-medium uppercase tracking-wide text-gray-500"><?= $escape($label) ?></dt><dd id="<?= $elementId ?>" class="mt-1 text-sm font-medium text-gray-900"></dd></div>
                                                    <?php endforeach; ?>
                                                </dl>
                                                <div id="employeeInputEditFields" class="hidden space-y-4 px-5 py-5">
                                                    <p class="text-sm text-gray-500">Update salary and eligibility inputs for this employee. Changes are used in future compensation cycle recommendations.</p>
                                                    <div class="grid gap-4 sm:grid-cols-2">
                                                        <label class="text-sm font-medium text-gray-700">Current salary<input id="employeeEditSalary" type="number" name="current_salary" min="0" step="0.01" class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"></label>
                                                        <label class="text-sm font-medium text-gray-700">Salary grade<select id="employeeEditGrade" name="salary_grade_code" data-grade-options="<?= $escape($gradeOptionsJson) ?>" class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"><option value="">Unassigned</option></select></label>
                                                        <label class="text-sm font-medium text-gray-700">Regularization status<select id="employeeEditRegularization" name="regularization_status" class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"><option value="">Unknown</option><option value="Regularized">Regularized</option><option value="Probationary">Probationary</option></select></label>
                                                        <label class="text-sm font-medium text-gray-700">Manager<select id="employeeEditManager" name="manager_id" class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"><option value="">Unassigned</option><?php foreach ($employeeData as $manager): ?><?php if ($manager['employment_status'] === 'Active'): ?><option value="<?= (int) $manager['id'] ?>" data-manager-id="<?= (int) $manager['id'] ?>"><?= $escape((string) $manager['employee_code'] . ' · ' . $manager['first_name'] . ' ' . $manager['last_name']) ?></option><?php endif; ?><?php endforeach; ?></select></label>
                                                        <label class="text-sm font-medium text-gray-700 sm:col-span-2">Performance rating<select id="employeeEditRating" name="performance_rating" class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"><option value="">Unknown</option><?php for ($rating = 1; $rating <= 5; $rating++): ?><option value="<?= $rating ?>"><?= $rating ?></option><?php endfor; ?></select></label>
                                                    </div>
                                                </div>
                                                <div id="employeeInputViewActions" class="flex justify-end border-t border-gray-200 bg-gray-50 px-5 py-4"><button type="button" data-close-employee-input class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-white">Close</button></div>
                                                <div id="employeeInputEditActions" class="hidden justify-end gap-2 border-t border-gray-200 bg-gray-50 px-5 py-4"><button type="button" data-close-employee-input class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-white">Cancel</button><button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">Save changes</button></div>
                                            </form>
                                        </dialog>
                                    <?php endif; ?>
                                <?php endif; ?>
                            <?php endif; ?>
                        <?php elseif ($section === 'benefits'): ?>
                            <section id="providerPlans" class="min-w-0 overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-5 py-4">
                                    <div>
                                        <h3 class="font-semibold text-gray-900">Provider plans</h3>
                                        <p class="mt-1 text-xs text-gray-500">Manage plan details, employee tier, and enrollment availability.</p>
                                    </div>
                                    <span class="text-xs text-gray-500"><?= count($plans) ?> plan(s)</span>
                                </div>

                                <?php if ($plans === []): ?>
                                    <div class="p-10 text-center">
                                        <i class="bi bi-clipboard2-plus text-2xl text-gray-400" aria-hidden="true"></i>
                                        <p class="mt-2 text-sm font-medium text-gray-800">No provider plans yet</p>
                                        <p class="mt-1 text-xs text-gray-500">Add a plan to make eligible employees available for enrollment.</p>
                                    </div>
                                <?php else: ?>
                                    <div class="divide-y divide-gray-100">
                                        <?php foreach ($plans as $plan): ?>
                                            <details class="group">
                                                <summary class="grid cursor-pointer list-none grid-cols-1 items-center gap-3 px-5 py-4 hover:bg-gray-50 sm:grid-cols-[minmax(0,1fr)_10rem_10rem_7rem_5rem]">
                                                    <span>
                                                        <span class="block text-sm font-medium text-blue-700"><?= $escape((string) $plan['provider_name']) ?></span>
                                                        <span class="mt-0.5 block text-xs text-gray-500"><?= $escape((string) $plan['plan_tier_name']) ?></span>
                                                    </span>
                                                    <span class="text-xs text-gray-700"><?= $escape((string) $plan['target_job_tier']) ?></span>
                                                    <span class="text-xs text-gray-700"><?= $money((float) $plan['annual_premium_cost']) ?> / year</span>
                                                    <span class="text-xs <?= (int) $plan['is_active'] === 1 ? 'text-emerald-700' : 'text-gray-500' ?>"><?= (int) $plan['is_active'] === 1 ? 'Active' : 'Inactive' ?></span>
                                                    <span class="text-xs font-semibold text-gray-600 group-open:text-blue-700">Edit <i class="bi bi-chevron-down ml-1" aria-hidden="true"></i></span>
                                                </summary>
                                                <form method="post" action="index.php?page=settings&amp;section=benefits&amp;return_to=<?= rawurlencode($returnPage) ?>" class="border-t border-gray-100 bg-gray-50 px-5 py-5">
                                                    <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                                                    <input type="hidden" name="return_to" value="<?= $escape($returnPage) ?>">
                                                    <input type="hidden" name="action" value="save_plan">
                                                    <input type="hidden" name="plan_id" value="<?= (int) $plan['id'] ?>">
                                                    <input type="hidden" name="parent_dependents_allowed" value="0">
                                                    <input type="hidden" name="is_active" value="0">
                                                    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                                                        <label class="text-xs font-semibold text-gray-600">Provider name<input name="provider_name" maxlength="100" required value="<?= $escape((string) $plan['provider_name']) ?>" class="mt-1 block w-full border border-gray-300 bg-white px-3 py-2 text-sm font-normal text-gray-800"></label>
                                                        <label class="text-xs font-semibold text-gray-600">Plan name<input name="plan_tier_name" maxlength="100" required value="<?= $escape((string) $plan['plan_tier_name']) ?>" class="mt-1 block w-full border border-gray-300 bg-white px-3 py-2 text-sm font-normal text-gray-800"></label>
                                                        <label class="text-xs font-semibold text-gray-600">Employee tier<select name="target_job_tier" data-dependent-rates="<?= $dependentRatesJson ?>" required class="hmo-plan-tier mt-1 block w-full border border-gray-300 bg-white px-3 py-2 text-sm font-normal text-gray-800"><?php foreach (HmoService::JOB_TIERS as $tier): ?><option value="<?= $escape($tier) ?>" <?= $plan['target_job_tier'] === $tier ? 'selected' : '' ?>><?= $escape($tier) ?></option><?php endforeach; ?></select><span class="hmo-dependent-rate mt-1 block font-normal text-gray-500"></span></label>
                                                        <label class="text-xs font-semibold text-gray-600">Coverage limit per illness<input type="number" name="max_coverage_limit" min="0.01" step="0.01" required value="<?= $escape((string) $plan['max_coverage_limit']) ?>" class="mt-1 block w-full border border-gray-300 bg-white px-3 py-2 text-sm font-normal text-gray-800"></label>
                                                        <label class="text-xs font-semibold text-gray-600">Room & board<input name="room_and_board" maxlength="100" required value="<?= $escape((string) $plan['room_and_board']) ?>" class="mt-1 block w-full border border-gray-300 bg-white px-3 py-2 text-sm font-normal text-gray-800"></label>
                                                        <label class="text-xs font-semibold text-gray-600">Annual primary premium<input type="number" name="annual_premium_cost" min="0.01" step="0.01" required value="<?= $escape((string) $plan['annual_premium_cost']) ?>" class="mt-1 block w-full border border-gray-300 bg-white px-3 py-2 text-sm font-normal text-gray-800"></label>
                                                    </div>
                                                    <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                                                        <label class="flex items-center gap-2 text-xs font-medium text-gray-600"><input type="checkbox" name="parent_dependents_allowed" value="1" <?= (int) $plan['parent_dependents_allowed'] === 1 ? 'checked' : '' ?> class="rounded border-gray-300">Allow parent dependents age 65 or below</label>
                                                        <label class="flex items-center gap-2 text-xs font-medium text-gray-600"><input type="checkbox" name="is_active" value="1" <?= (int) $plan['is_active'] === 1 ? 'checked' : '' ?> class="rounded border-gray-300">Available for new enrollments</label>
                                                    </div>
                                                    <div class="mt-4 flex justify-end">
                                                        <button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">Save changes</button>
                                                    </div>
                                                </form>
                                            </details>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </section>
                        <dialog id="addPlanDialog" class="m-auto w-[min(48rem,calc(100vw-2rem))] max-w-none rounded-xl border border-gray-200 p-0 shadow-2xl backdrop:bg-slate-950/40">
                            <form method="post" action="index.php?page=settings&amp;section=benefits&amp;return_to=<?= rawurlencode($returnPage) ?>">
                                <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                                <input type="hidden" name="return_to" value="<?= $escape($returnPage) ?>">
                                <input type="hidden" name="action" value="save_plan">
                                <input type="hidden" name="plan_id" value="">
                                <input type="hidden" name="parent_dependents_allowed" value="0">
                                <input type="hidden" name="is_active" value="1">
                                <div class="flex items-start justify-between border-b border-gray-200 px-5 py-4">
                                    <div><h3 class="font-semibold text-gray-900">Add provider plan</h3><p class="mt-1 text-xs text-gray-500">The employee’s primary premium is company-paid; dependent deductions follow the fixed employee-tier rate.</p></div>
                                    <button id="closeAddPlan" type="button" aria-label="Close add plan form" class="rounded-lg p-2 text-gray-500 hover:bg-gray-100"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                                </div>
                                <div class="grid gap-4 p-5 sm:grid-cols-2">
                                    <label class="text-xs font-semibold text-gray-600">Provider name<input name="provider_name" maxlength="100" required class="mt-1 block w-full border border-gray-300 bg-white px-3 py-2 text-sm font-normal text-gray-800"></label>
                                    <label class="text-xs font-semibold text-gray-600">Plan name<input name="plan_tier_name" maxlength="100" required class="mt-1 block w-full border border-gray-300 bg-white px-3 py-2 text-sm font-normal text-gray-800"></label>
                                    <label class="text-xs font-semibold text-gray-600">Employee tier<select name="target_job_tier" data-dependent-rates="<?= $dependentRatesJson ?>" required class="hmo-plan-tier mt-1 block w-full border border-gray-300 bg-white px-3 py-2 text-sm font-normal text-gray-800"><?php foreach (HmoService::JOB_TIERS as $tier): ?><option value="<?= $escape($tier) ?>"><?= $escape($tier) ?></option><?php endforeach; ?></select><span class="hmo-dependent-rate mt-1 block font-normal text-gray-500"></span></label>
                                    <label class="text-xs font-semibold text-gray-600">Coverage limit per illness<input type="number" name="max_coverage_limit" min="0.01" step="0.01" required class="mt-1 block w-full border border-gray-300 bg-white px-3 py-2 text-sm font-normal text-gray-800"></label>
                                    <label class="text-xs font-semibold text-gray-600">Room & board<input name="room_and_board" maxlength="100" required class="mt-1 block w-full border border-gray-300 bg-white px-3 py-2 text-sm font-normal text-gray-800"></label>
                                    <label class="text-xs font-semibold text-gray-600">Annual primary premium<input type="number" name="annual_premium_cost" min="0.01" step="0.01" required class="mt-1 block w-full border border-gray-300 bg-white px-3 py-2 text-sm font-normal text-gray-800"></label>
                                    <label class="flex items-center gap-2 text-xs font-medium text-gray-600 sm:col-span-2"><input type="checkbox" name="parent_dependents_allowed" value="1" class="rounded border-gray-300">Allow parent dependents age 65 or below</label>
                                </div>
                                <div class="flex justify-end gap-2 border-t border-gray-200 bg-gray-50 px-5 py-4">
                                    <button id="cancelAddPlan" type="button" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-white">Cancel</button>
                                    <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">Add plan</button>
                                </div>
                            </form>
                        </dialog>
                        <?php endif; ?>
                    <?php endif; ?>
                    </div>
                </div>
                </main>
        </div>
    </div>
    <?php require __DIR__ . '/../partials/workspace_scripts.php'; ?>
    <?php if ($section === 'compensation' && in_array($compensationTab, ['structure', 'matrix'], true)): ?>
        <script>
            (() => {
                document.querySelectorAll('[data-version-editor]').forEach((editor) => {
                    const trigger = editor.querySelector('[data-version-editor-trigger]');
                    const closeButton = editor.querySelector('[data-close-version-editor]');
                    if (!trigger || !closeButton) return;

                    const updateTriggerVisibility = () => {
                        trigger.hidden = editor.open;
                        trigger.classList.toggle('hidden', editor.open);
                    };
                    updateTriggerVisibility();
                    editor.addEventListener('toggle', updateTriggerVisibility);
                    closeButton.addEventListener('click', () => {
                        editor.open = false;
                        updateTriggerVisibility();
                        trigger.focus();
                    });
                });
            })();
        </script>
    <?php endif; ?>
    <?php if ($section === 'compensation' && $compensationTab === 'structure' && !($compensationPage['migrationRequired'] ?? false)): ?>
        <script>
            (() => {
                const form = document.getElementById('salaryStructureForm');
                if (!form) return;
                const error = form.querySelector('[data-structure-form-error]');
                const gradeRows = [...form.querySelectorAll('tbody tr')].map((row) => [
                    row.querySelector('input[name="grade_code[]"]'),
                    row.querySelector('select[name="grade_job_tier[]"]'),
                    row.querySelector('input[name="grade_minimum[]"]'),
                    row.querySelector('input[name="grade_midpoint[]"]'),
                    row.querySelector('input[name="grade_maximum[]"]')
                ]);
                const setError = (message) => {
                    error.textContent = message;
                    error.hidden = message === '';
                    error.classList.toggle('hidden', message === '');
                };

                form.addEventListener('input', () => setError(''));
                form.addEventListener('change', () => setError(''));
                form.addEventListener('submit', (event) => {
                    setError('');
                    let firstMissingField = null;
                    const hasStartedRow = gradeRows.some((fields) =>
                        fields.some((field) => field.value.trim() !== '')
                    );
                    if (!hasStartedRow) {
                        firstMissingField = gradeRows[0][0];
                        setError('Enter at least one complete salary grade before saving.');
                    } else {
                        for (let rowIndex = 0; rowIndex < gradeRows.length; rowIndex++) {
                            const fields = gradeRows[rowIndex];
                            if (!fields.some((field) => field.value.trim() !== '')) continue;
                            firstMissingField = fields.find((field) => field.value.trim() === '') || null;
                            if (firstMissingField) {
                                setError(`Grade row ${rowIndex + 1} is incomplete. Fill in the grade, job tier, minimum, midpoint, and maximum, or clear that row. Other blank rows are allowed.`);
                                break;
                            }
                        }
                    }
                    if (firstMissingField) {
                        event.preventDefault();
                        firstMissingField.focus();
                    }
                });
            })();
        </script>
    <?php endif; ?>
    <?php if ($section === 'compensation' && $compensationTab === 'employees' && !($compensationPage['workflowMigrationRequired'] ?? false)): ?>
        <script>
            (() => {
                const dialog = document.getElementById('employeeInputDialog');
                const form = document.getElementById('employeeInputForm');
                const gradeSelect = document.getElementById('employeeEditGrade');
                const managerSelect = document.getElementById('employeeEditManager');
                const viewPanel = document.getElementById('employeeInputView');
                const editFields = document.getElementById('employeeInputEditFields');
                const viewActions = document.getElementById('employeeInputViewActions');
                const editActions = document.getElementById('employeeInputEditActions');
                const setVisible = (element, visible) => {
                    element.hidden = !visible;
                    element.classList.toggle('hidden', !visible);
                };
                const setViewValue = (id, value) => {
                    document.getElementById(id).textContent = value || 'Unknown';
                };
                const setMode = (editing) => {
                    setVisible(viewPanel, !editing);
                    setVisible(editFields, editing);
                    setVisible(viewActions, !editing);
                    setVisible(editActions, editing);
                    document.getElementById('employeeInputDialogTitle').textContent = editing
                        ? 'Edit employee compensation inputs'
                        : 'Employee compensation details';
                };
                const openDialog = (button, editing) => {
                    const row = button.closest('[data-employee-details]');
                    const employee = JSON.parse(row.dataset.employeeDetails);
                    document.getElementById('employeeInputDialogSubtitle').textContent =
                        `${employee.name} · ${employee.code}`;
                    setViewValue('employeeViewName', `${employee.name} (${employee.code})`);
                    setViewValue('employeeViewDepartment', employee.department);
                    setViewValue('employeeViewEmployment', `${employee.employmentStatus} · ${employee.employmentType}`);
                    setViewValue('employeeViewJobTier', employee.jobTier);
                    setViewValue('employeeViewSalary', employee.salaryDisplay);
                    setViewValue('employeeViewGrade', employee.gradeCode);
                    setViewValue('employeeViewRegularization', employee.regularizationStatus);
                    setViewValue('employeeViewManager', employee.managerName);
                    setViewValue('employeeViewRating', employee.performanceRating);

                    if (editing) {
                        document.getElementById('employeeInputId').value = employee.id;
                        document.getElementById('employeeEditSalary').value = employee.salary;
                        document.getElementById('employeeEditRegularization').value = employee.regularizationStatus === 'Unknown'
                            ? ''
                            : employee.regularizationStatus;
                        document.getElementById('employeeEditRating').value = employee.performanceRating === 'Unknown'
                            ? ''
                            : employee.performanceRating;
                        const gradesByTier = JSON.parse(gradeSelect.dataset.gradeOptions);
                        gradeSelect.replaceChildren(new Option('Unassigned', ''));
                        (gradesByTier[employee.jobTier] ?? []).forEach((grade) => {
                            gradeSelect.add(new Option(`${grade.grade_code} · v${grade.version}`, grade.grade_code));
                        });
                        gradeSelect.value = employee.gradeCode === 'Unassigned' ? '' : employee.gradeCode;
                        Array.from(managerSelect.options).forEach((option) => {
                            option.disabled = option.dataset.managerId === String(employee.id);
                        });
                        managerSelect.value = employee.managerId;
                    }

                    setMode(editing);
                    dialog.showModal();
                };

                document.querySelectorAll('[data-employee-input-action]').forEach((button) => {
                    button.addEventListener('click', () => {
                        openDialog(button, button.dataset.employeeInputAction === 'edit');
                    });
                });
                document.querySelectorAll('[data-close-employee-input]').forEach((button) => {
                    button.addEventListener('click', () => dialog.close());
                });
                dialog.addEventListener('click', (event) => {
                    if (event.target === dialog) dialog.close();
                });
                dialog.addEventListener('close', () => {
                    form.reset();
                    setMode(false);
                });
            })();
        </script>
    <?php endif; ?>
    <?php if ($section === 'claims'): ?>
        <script>
            document.querySelectorAll('.claims-whole-amount').forEach((input) => {
                const removeFraction = () => {
                    if (input.value !== '' && Number.isFinite(input.valueAsNumber)) {
                        input.value = String(Math.trunc(input.valueAsNumber));
                    }
                };
                input.addEventListener('input', removeFraction);
                input.addEventListener('blur', removeFraction);
            });
        </script>
    <?php endif; ?>
    <?php if ($hmo['canManage'] ?? false): ?>
        <script>
            (() => {
                const dialog = document.getElementById('addPlanDialog');
                document.getElementById('openAddPlan').addEventListener('click', () => dialog.showModal());
                document.getElementById('closeAddPlan').addEventListener('click', () => dialog.close());
                document.getElementById('cancelAddPlan').addEventListener('click', () => dialog.close());
                dialog.addEventListener('click', (event) => {
                    if (event.target === dialog) dialog.close();
                });
                document.querySelectorAll('.hmo-plan-tier').forEach((select) => {
                    const rateLabel = select.parentElement.querySelector('.hmo-dependent-rate');
                    const rates = JSON.parse(select.dataset.dependentRates);
                    const updateRate = () => {
                        const amount = Number(rates[select.value]);
                        rateLabel.textContent = `Dependent deduction: ₱${amount.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} per dependent / month.`;
                    };
                    select.addEventListener('change', updateRate);
                    updateRate();
                });
            })();
        </script>
    <?php endif; ?>
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
        })();
    </script>
</body>
</html>
