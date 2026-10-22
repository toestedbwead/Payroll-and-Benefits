<?php
require_once __DIR__ . '/../partials/view_guard.php';
preventDirectViewAccess(__FILE__);

$claims = $claimsPageData['claims'] ?? [];
$counts = $claimsPageData['counts'] ?? [];
$selectedStatus = $claimsPageData['status'] ?? null;
$search = $claimsPageData['search'] ?? '';
$currentPageNumber = max(1, (int) ($claimsPageData['page'] ?? 1));
$totalPages = max(1, (int) ($claimsPageData['totalPages'] ?? 1));
$totalClaims = max(0, (int) ($claimsPageData['totalClaims'] ?? count($claims)));
$flash = $claimsPageData['flash'] ?? null;
$canReview = $claimsPageData['canReview'] ?? false;
$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$statusClasses = [
    'Pending Approval' => 'bg-amber-50 text-amber-700 ring-amber-600/20',
    'Approved' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
    'Rejected' => 'bg-rose-50 text-rose-700 ring-rose-600/20',
    'Returned for Revision' => 'bg-indigo-50 text-indigo-700 ring-indigo-600/20',
];
$expenseThresholds = [
    'Medical' => [
        'label' => 'Medical / Health',
        'amount' => 5000,
        'days' => 60,
        'icon' => 'bi-heart-pulse',
    ],
    'Transportation' => [
        'label' => 'Transportation',
        'amount' => 1000,
        'days' => 30,
        'icon' => 'bi-train-front',
    ],
    'Official Business' => [
        'label' => 'Official Business',
        'amount' => 10000,
        'days' => 60,
        'icon' => 'bi-briefcase',
    ],
    'Miscellaneous' => [
        'label' => 'Miscellaneous',
        'amount' => 500,
        'days' => 30,
        'icon' => 'bi-receipt',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Claims & Reimbursement</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 font-sans antialiased">
    <div class="flex h-screen overflow-hidden">
        <?php require __DIR__ . '/../partials/sidebar.php'; ?>
        <main class="flex min-w-0 flex-1 flex-col overflow-hidden">
            <?php require __DIR__ . '/../partials/workspace_header.php'; ?>
            <div class="flex-1 space-y-6 overflow-y-auto p-5 sm:p-8">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h2 class="text-2xl font-bold text-gray-900">Claims & Reimbursement</h2>
                        <p class="mt-1 text-sm text-gray-500">Review expense requests submitted by employees through ESS.</p>
                    </div>
                </div>

                <div class="flex gap-2 border-b border-gray-200" role="tablist" aria-label="Claims workspace">
                    <button id="claimsQueueTab" type="button" role="tab" aria-selected="true" aria-controls="claimsQueuePanel" class="border-b-2 border-blue-600 px-4 py-2.5 text-sm font-semibold text-blue-700">
                        Claims
                    </button>
                    <button id="claimsThresholdsTab" type="button" role="tab" aria-selected="false" aria-controls="claimsThresholdsPanel" tabindex="-1" class="border-b-2 border-transparent px-4 py-2.5 text-sm font-semibold text-gray-500 hover:text-gray-800">
                        Base thresholds
                    </button>
                </div>

                <?php if (is_array($flash)): ?>
                    <div role="<?= ($flash['type'] ?? '') === 'success' ? 'status' : 'alert' ?>" class="rounded-xl border px-4 py-3 text-sm <?= ($flash['type'] ?? '') === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-rose-200 bg-rose-50 text-rose-800' ?>">
                        <?= $escape((string) ($flash['message'] ?? '')) ?>
                    </div>
                <?php endif; ?>

                <section id="claimsQueuePanel" role="tabpanel" aria-labelledby="claimsQueueTab" class="space-y-6">
                <?php if ($canReview && $counts !== []): ?>
                    <section class="grid grid-cols-2 gap-3 xl:grid-cols-4" aria-label="Claim status totals">
                        <?php foreach ([
                            'Pending Approval' => ['Pending review', 'bi-hourglass-split', 'text-amber-600', 'bg-amber-50'],
                            'Returned for Revision' => ['Returned', 'bi-arrow-return-left', 'text-indigo-600', 'bg-indigo-50'],
                            'Approved' => ['Approved', 'bi-check2-circle', 'text-emerald-600', 'bg-emerald-50'],
                            'Rejected' => ['Rejected', 'bi-x-circle', 'text-rose-600', 'bg-rose-50'],
                        ] as $status => [$label, $icon, $color, $background]): ?>
                            <a href="index.php?page=claims&amp;status=<?= rawurlencode($status) ?>" class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm transition hover:border-gray-300">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="text-xs font-medium text-gray-500"><?= $label ?></span>
                                    <span class="flex h-8 w-8 items-center justify-center rounded-lg <?= $background ?> <?= $color ?>"><i class="bi <?= $icon ?>" aria-hidden="true"></i></span>
                                </div>
                                <p class="mt-2 text-2xl font-bold text-gray-900"><?= (int) ($counts[$status] ?? 0) ?></p>
                            </a>
                        <?php endforeach; ?>
                    </section>
                <?php endif; ?>

                <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                    <div class="space-y-4 border-b border-gray-200 p-4 sm:p-5">
                        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h3 class="font-semibold text-gray-900">Expense requests</h3>
                                <p class="mt-1 text-xs text-gray-500">
                                    <?= $totalClaims === 0 ? 'No requests' : 'Showing ' . (($currentPageNumber - 1) * 10 + 1) . '–' . min($currentPageNumber * 10, $totalClaims) . ' of ' . $totalClaims . ' requests' ?>
                                    <?= $selectedStatus !== null ? ' in ' . $escape($selectedStatus) : '' ?>
                                </p>
                            </div>
                            <form id="claimFiltersForm" method="get" action="index.php" class="flex flex-wrap gap-2">
                                <input type="hidden" name="page" value="claims">
                                <label class="sr-only" for="claimStatus">Filter by status</label>
                                <select id="claimStatus" name="status" class="border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100">
                                    <option value="">All statuses</option>
                                    <?php foreach (\ClaimsService::STATUSES as $status): ?>
                                        <option value="<?= $escape($status) ?>" <?= $selectedStatus === $status ? 'selected' : '' ?>><?= $escape($status) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <label class="sr-only" for="claimSearch">Search employees</label>
                                <div class="flex items-center gap-2 border border-gray-300 px-3 focus-within:border-blue-500 focus-within:ring-2 focus-within:ring-blue-100">
                                    <i class="bi bi-search text-sm text-gray-400" aria-hidden="true"></i>
                                    <input id="claimSearch" name="search" value="<?= $escape($search) ?>" placeholder="Search employee or ID" class="w-full py-2 text-sm outline-none sm:w-52">
                                </div>
                                <button type="submit" aria-label="Search claims" class="flex h-10 w-10 shrink-0 items-center justify-center bg-slate-900 text-white hover:bg-slate-800">
                                    <i class="bi bi-arrow-right" aria-hidden="true"></i>
                                </button>
                            </form>
                        </div>
                    </div>

                    <?php if (!$canReview): ?>
                        <div class="p-8 text-center text-sm text-gray-500">Your account can’t access claim reviews.</div>
                    <?php elseif ($claims === []): ?>
                        <div class="px-6 py-14 text-center">
                            <i class="bi bi-inbox text-3xl text-gray-300" aria-hidden="true"></i>
                            <p class="mt-3 text-sm font-semibold text-gray-700">No claims found</p>
                            <p class="mt-1 text-sm text-gray-500">New ESS submissions will appear here when available.</p>
                        </div>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 text-left">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th scope="col" class="whitespace-nowrap px-5 py-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Employee</th>
                                        <th scope="col" class="whitespace-nowrap px-5 py-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Expense</th>
                                        <th scope="col" class="whitespace-nowrap px-5 py-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Amount</th>
                                        <th scope="col" class="whitespace-nowrap px-5 py-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Submitted</th>
                                        <th scope="col" class="whitespace-nowrap px-5 py-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Status</th>
                                        <th scope="col" class="whitespace-nowrap px-5 py-3 text-right text-[11px] font-semibold uppercase tracking-wide text-gray-500">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 bg-white">
                                    <?php foreach ($claims as $claim): ?>
                                        <?php
                                        $status = (string) $claim['status'];
                                        $canReviewClaim = in_array($status, ['Pending Approval', 'Returned for Revision'], true);
                                        $receiptName = basename((string) $claim['receipt_attachment']);
                                        $claimId = (int) $claim['id'];
                                        $expenseType = (string) $claim['expense_type'];
                                        $claimAmountCents = (int) round((float) $claim['claim_amount'] * 100);
                                        $threshold = $expenseThresholds[$expenseType] ?? null;
                                        $limitAmount = $threshold['amount'] ?? null;
                                        $limitAmountCents = $limitAmount === null ? null : $limitAmount * 100;
                                        $categoryCheck = $limitAmountCents === null
                                            ? 'REVIEW'
                                            : ($claimAmountCents <= $limitAmountCents ? 'PASS' : 'FAIL');
                                        $expenseDate = new DateTimeImmutable((string) $claim['expense_date']);
                                        $submittedAt = new DateTimeImmutable((string) $claim['created_at']);
                                        $daysElapsed = $expenseDate <= $submittedAt ? $expenseDate->diff($submittedAt)->days : null;
                                        $timelineLimit = $threshold['days'] ?? null;
                                        $timelineCheck = $daysElapsed === null || $timelineLimit === null
                                            ? 'REVIEW'
                                            : ($daysElapsed <= $timelineLimit ? 'PASS' : 'FAIL');
                                        $hasReceipt = $receiptName !== '.' && $receiptName !== '';
                                        $employmentType = (string) ($claim['employment_type'] ?? 'Unknown');
                                        $employmentStatus = isset($claim['employment_status']) && is_string($claim['employment_status'])
                                            ? $claim['employment_status']
                                            : '';
                                        $employmentCheck = match (true) {
                                            $employmentStatus !== 'Active' => [
                                                'status' => 'FAIL',
                                                'message' => 'Employment status is inactive or unverified. Only employees explicitly marked Active are eligible.',
                                            ],
                                            $employmentType === 'Full-time'
                                                && in_array($expenseType, ['Medical', 'Transportation', 'Official Business'], true) => [
                                                    'status' => 'PASS',
                                                    'message' => 'Active full-time regular standing qualifies for medical, transportation, and official business claims.',
                                                ],
                                            $employmentType === 'Part-time'
                                                && $expenseType === 'Transportation'
                                                && $categoryCheck === 'PASS' => [
                                                    'status' => 'PASS',
                                                    'message' => 'Active part-time staff may claim transportation within its category ceiling.',
                                                ],
                                            $employmentType === 'Part-time'
                                                && $expenseType === 'Transportation' => [
                                                    'status' => 'FAIL',
                                                    'message' => 'Part-time transportation claims must be within the category ceiling.',
                                                ],
                                            $employmentType === 'Part-time' => [
                                                'status' => 'FAIL',
                                                'message' => 'Part-time staff are limited to transportation; medical, official business, and miscellaneous claims are ineligible.',
                                            ],
                                            $employmentType === 'Contractual'
                                                && in_array($expenseType, ['Medical', 'Miscellaneous', 'Transportation'], true) => [
                                                    'status' => 'FAIL',
                                                    'message' => 'Contractual/project-based employees are ineligible for standard medical, miscellaneous, and transportation claims.',
                                                ],
                                            $employmentType === 'Contractual' => [
                                                'status' => 'FAIL',
                                                'message' => 'Project pre-approval cannot be verified from the claim record; contractual claims fail eligibility until approval can be validated.',
                                            ],
                                            default => [
                                                'status' => 'FAIL',
                                                'message' => 'Unrecognized employment tier or expense category is ineligible under the configured rules.',
                                            ],
                                        };
                                        $claimNumber = 'CLAIM-' . str_pad((string) $claimId, 5, '0', STR_PAD_LEFT);
                                        $claimDetails = [
                                            'id' => $claimId,
                                            'number' => $claimNumber,
                                            'employee' => (string) $claim['first_name'] . ' ' . (string) $claim['last_name'],
                                            'employeeCode' => (string) $claim['employee_code'],
                                            'department' => (string) $claim['department'],
                                            'employmentType' => $employmentType,
                                            'employmentStatus' => $employmentStatus !== '' ? $employmentStatus : 'Unverified',
                                            'expenseType' => $expenseType,
                                            'amount' => number_format((float) $claim['claim_amount'], 2),
                                            'expenseDate' => $expenseDate->format('M j, Y'),
                                            'submittedDate' => $submittedAt->format('M j, Y'),
                                            'daysElapsed' => $daysElapsed,
                                            'timelineLimit' => $timelineLimit,
                                            'categoryMessage' => $categoryCheck === 'FAIL' && $expenseType === 'Official Business'
                                                ? 'Above the ceiling; secondary executive or director-level pre-approval is required.'
                                                : ($categoryCheck === 'FAIL'
                                                    ? 'Above the base ceiling; verify the applicable exception or required pre-approval.'
                                                    : ($categoryCheck === 'PASS'
                                                        ? 'Within the base per-claim ceiling. Confirm any separate accumulation limits.'
                                                        : 'No matching base ceiling is configured; HR verification is required.')),
                                            'categoryStatus' => $categoryCheck,
                                            'categoryAmount' => number_format((float) $claim['claim_amount'], 2),
                                            'categoryLimit' => $limitAmount === null ? null : number_format($limitAmount, 2),
                                            'timelineStatus' => $timelineCheck,
                                            'receiptStatus' => $hasReceipt ? 'REVIEW' : 'FAIL',
                                            'receiptMessage' => $hasReceipt
                                                ? 'Receipt attached. HR must verify it is official and unedited, and confirm its date, vendor, itemization, and total match the claim.'
                                                : 'No receipt attached; primary proof is required.',
                                            'employmentCheckStatus' => $employmentCheck['status'],
                                            'employmentMessage' => $employmentCheck['message'],
                                            'receiptName' => $hasReceipt ? $receiptName : '',
                                            'receiptUrl' => $hasReceipt ? 'index.php?page=claim_receipt&id=' . $claimId : '',
                                            'status' => $status,
                                            'reviewable' => $canReviewClaim,
                                            'reviewNotes' => trim((string) ($claim['review_notes'] ?? '')),
                                        ];
                                        $claimDetailsJson = json_encode(
                                            $claimDetails,
                                            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
                                        );
                                        ?>
                                        <tr class="align-top hover:bg-gray-50/70">
                                            <td class="whitespace-nowrap px-5 py-4">
                                                <p class="text-sm font-semibold text-gray-900"><?= $escape((string) $claim['first_name'] . ' ' . (string) $claim['last_name']) ?></p>
                                                <p class="mt-1 text-xs text-gray-500"><?= $escape((string) $claim['employee_code']) ?> · <?= $escape((string) $claim['department']) ?></p>
                                            </td>
                                            <td class="whitespace-nowrap px-5 py-4">
                                                <p class="text-sm font-medium text-gray-800"><?= $escape((string) $claim['expense_type']) ?></p>
                                                <p class="mt-1 text-xs text-gray-500">Expense date <?= $escape((string) $claim['expense_date']) ?></p>
                                                <p class="mt-2 text-xs <?= $receiptName !== '.' && $receiptName !== '' ? 'text-gray-500' : 'text-rose-600' ?>">
                                                    <?= $receiptName !== '.' && $receiptName !== '' ? 'Receipt attached' : 'No receipt attached' ?>
                                                </p>
                                            </td>
                                            <td class="whitespace-nowrap px-5 py-4 text-sm font-semibold text-gray-900">₱<?= number_format((float) $claim['claim_amount'], 2) ?></td>
                                            <td class="whitespace-nowrap px-5 py-4 text-xs text-gray-600"><?= $escape($submittedAt->format('M j, Y')) ?></td>
                                            <td class="whitespace-nowrap px-5 py-4">
                                                <span class="inline-flex <?= $status === 'Pending Approval' ? 'rounded-none' : 'rounded-full' ?> px-2.5 py-1 text-[11px] font-semibold ring-1 ring-inset <?= $statusClasses[$status] ?? 'bg-gray-100 text-gray-700 ring-gray-500/20' ?>"><?= $escape($status) ?></span>
                                                <?php if (!empty($claim['review_notes'])): ?>
                                                    <details class="mt-2 max-w-56">
                                                        <summary class="cursor-pointer text-xs font-medium text-gray-500 hover:text-gray-800">Review history</summary>
                                                        <pre class="mt-2 max-h-32 overflow-y-auto overscroll-contain whitespace-pre-wrap break-words rounded-lg bg-gray-50 p-2 text-[11px] leading-5 text-gray-600"><?= $escape((string) $claim['review_notes']) ?></pre>
                                                    </details>
                                                <?php endif; ?>
                                            </td>
                                            <td class="whitespace-nowrap px-5 py-4 text-right">
                                                <button type="button" data-claim-details="<?= $escape((string) $claimDetailsJson) ?>" aria-label="View <?= $escape($claimNumber) ?>" class="inline-flex items-center gap-2 rounded-lg border border-gray-300 px-3 py-2 text-xs font-semibold text-gray-700 hover:border-blue-300 hover:bg-blue-50 hover:text-blue-700">
                                                    <i class="bi bi-eye" aria-hidden="true"></i><span>View</span>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                    <?php if ($canReview && $totalPages > 1): ?>
                        <?php
                        $paginationBase = ['page' => 'claims'];
                        if ($selectedStatus !== null) {
                            $paginationBase['status'] = $selectedStatus;
                        }
                        if ($search !== '') {
                            $paginationBase['search'] = $search;
                        }
                        $previousQuery = $paginationBase + ['page_number' => $currentPageNumber - 1];
                        $nextQuery = $paginationBase + ['page_number' => $currentPageNumber + 1];
                        ?>
                        <nav class="flex items-center justify-between border-t border-gray-200 px-4 py-3 sm:px-5" aria-label="Claims pagination">
                            <a href="index.php?<?= $escape(http_build_query($previousQuery)) ?>"
                                class="inline-flex items-center gap-2 border border-gray-300 px-3 py-2 text-sm font-medium <?= $currentPageNumber <= 1 ? 'pointer-events-none text-gray-300' : 'text-gray-700 hover:bg-gray-50' ?>"
                                <?= $currentPageNumber <= 1 ? 'aria-disabled="true" tabindex="-1"' : '' ?>>
                                <i class="bi bi-chevron-left" aria-hidden="true"></i>Previous
                            </a>
                            <span class="text-sm text-gray-600">Page <?= $currentPageNumber ?> of <?= $totalPages ?></span>
                            <a href="index.php?<?= $escape(http_build_query($nextQuery)) ?>"
                                class="inline-flex items-center gap-2 border border-gray-300 px-3 py-2 text-sm font-medium <?= $currentPageNumber >= $totalPages ? 'pointer-events-none text-gray-300' : 'text-gray-700 hover:bg-gray-50' ?>"
                                <?= $currentPageNumber >= $totalPages ? 'aria-disabled="true" tabindex="-1"' : '' ?>>
                                Next<i class="bi bi-chevron-right" aria-hidden="true"></i>
                            </a>
                        </nav>
                    <?php endif; ?>
                </section>
                </section>

                <section id="claimsThresholdsPanel" role="tabpanel" aria-labelledby="claimsThresholdsTab" class="hidden space-y-6" hidden>
                    <div class="flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900">Base reimbursement thresholds</h3>
                            <p class="mt-1 text-sm text-gray-500">Quick reference for HR review. Exceptions and incomplete records need human verification.</p>
                        </div>

                    </div>

                    <section aria-labelledby="financialThresholdHeading">
                        <div class="mb-3 flex items-center justify-between">
                            <h4 id="financialThresholdHeading" class="text-sm font-semibold uppercase tracking-wide text-gray-500">Financial ceilings</h4>
                            <span class="text-xs text-gray-400">per policy category</span>
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                            <?php foreach ($expenseThresholds as $expenseThreshold): ?>
                                <article class="border border-gray-200 bg-white p-5 shadow-sm">
                                    <div class="flex items-center justify-between gap-3">
                                        <span class="flex h-10 w-10 items-center justify-center bg-slate-50 text-slate-600">
                                            <i class="bi <?= $escape($expenseThreshold['icon']) ?> text-lg" aria-hidden="true"></i>
                                        </span>
                                        <span class="text-[11px] font-semibold uppercase tracking-wide text-gray-400">Up to</span>
                                    </div>
                                    <h5 class="mt-4 text-sm font-semibold text-gray-800"><?= $escape($expenseThreshold['label']) ?></h5>
                                    <p class="mt-1 text-2xl font-bold tracking-tight text-gray-950">₱<?= number_format($expenseThreshold['amount'], 2) ?></p>
                                    <p class="mt-3 border-t border-gray-100 pt-3 text-xs text-gray-500">File within <span class="font-semibold text-gray-700"><?= (int) $expenseThreshold['days'] ?> days</span> of transaction</p>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <section aria-labelledby="reviewRulesHeading">
                        <div class="mb-3 flex items-center justify-between">
                            <h4 id="reviewRulesHeading" class="text-sm font-semibold uppercase tracking-wide text-gray-500">Additional review rules</h4>
                            <span class="text-xs text-gray-400">verify before final decision</span>
                        </div>
                        <div class="grid gap-3 lg:grid-cols-3">
                            <article class="border border-gray-200 bg-white p-5">
                                <div class="flex items-center gap-3">
                                    <span class="flex h-9 w-9 items-center justify-center bg-blue-50 text-blue-700"><i class="bi bi-file-earmark-check" aria-hidden="true"></i></span>
                                    <h5 class="text-sm font-semibold text-gray-900">Proof & amount</h5>
                                </div>
                                <ul class="mt-4 space-y-2 text-sm text-gray-600">
                                    <li class="flex gap-2"><i class="bi bi-dot text-amber-600" aria-hidden="true"></i><span>HR must verify the receipt is official and unedited (or a valid BIR invoice / medical certificate).</span></li>
                                    <li class="flex gap-2"><i class="bi bi-check2 text-emerald-600" aria-hidden="true"></i><span>Date, vendor, and itemized details must be visible.</span></li>
                                    <li class="flex gap-2"><i class="bi bi-check2 text-emerald-600" aria-hidden="true"></i><span>Request total must match proof exactly (₱0.00 variance).</span></li>
                                </ul>
                            </article>

                            <article class="border border-gray-200 bg-white p-5">
                                <div class="flex items-center gap-3">
                                    <span class="flex h-9 w-9 items-center justify-center bg-amber-50 text-amber-700"><i class="bi bi-person-check" aria-hidden="true"></i></span>
                                    <h5 class="text-sm font-semibold text-gray-900">Employment eligibility</h5>
                                </div>
                                <ul class="mt-4 space-y-2 text-sm text-gray-600">
                                    <li class="flex gap-2"><i class="bi bi-dot text-blue-600" aria-hidden="true"></i><span><strong class="font-semibold text-gray-800">Full-time regularized:</strong> Active employees PASS for medical, transportation, and official business. Other expense categories FAIL.</span></li>
                                    <li class="flex gap-2"><i class="bi bi-dot text-amber-600" aria-hidden="true"></i><span><strong class="font-semibold text-gray-800">Part-time:</strong> Transportation PASSes only within its ceiling; medical, official business, and miscellaneous FAIL.</span></li>
                                    <li class="flex gap-2"><i class="bi bi-dot text-rose-600" aria-hidden="true"></i><span><strong class="font-semibold text-gray-800">Contractual / project-based:</strong> Medical, miscellaneous, and transportation FAIL. Official business also fails unless project approval can be verified.</span></li>
                                    <li class="flex gap-2"><i class="bi bi-dot text-gray-500" aria-hidden="true"></i><span><strong class="font-semibold text-gray-800">Inactive / unverified:</strong> All claims FAIL.</span></li>
                                </ul>
                            </article>

                            <article class="border border-gray-200 bg-white p-5">
                                <div class="flex items-center gap-3">
                                    <span class="flex h-9 w-9 items-center justify-center bg-rose-50 text-rose-700"><i class="bi bi-clock-history" aria-hidden="true"></i></span>
                                    <h5 class="text-sm font-semibold text-gray-900">Exceptions</h5>
                                </div>
                                <ul class="mt-4 space-y-2 text-sm text-gray-600">
                                    <li class="flex gap-2"><i class="bi bi-dot text-rose-600" aria-hidden="true"></i><span>Late claims require special HR sign-off.</span></li>
                                    <li class="flex gap-2"><i class="bi bi-dot text-amber-600" aria-hidden="true"></i><span>Medical monthly accumulation needs a separate total check.</span></li>
                                    <li class="flex gap-2"><i class="bi bi-dot text-amber-600" aria-hidden="true"></i><span>Official Business over ₱10,000 requires executive/director pre-approval.</span></li>
                                </ul>
                            </article>
                        </div>
                    </section>
                </section>
            </div>
        </main>
    </div>

    <?php if ($canReview): ?>
        <dialog id="claimReviewDialog" aria-labelledby="reviewDialogTitle" class="m-auto max-h-[calc(100dvh-2rem)] w-[min(56rem,calc(100vw-2rem))] max-w-none overflow-hidden rounded-xl border border-gray-200 bg-white p-0 shadow-2xl backdrop:bg-slate-950/50">
            <form id="claimReviewForm" method="post" action="index.php?page=claims" class="flex max-h-[calc(100dvh-2rem)] flex-col">
                <input type="hidden" name="csrf_token" value="<?= $escape((string) ($_SESSION['csrf_token'] ?? '')) ?>">
                <input type="hidden" name="claim_id" id="reviewClaimId">
                <div class="flex shrink-0 items-start justify-between gap-4 border-b border-gray-200 px-5 py-3.5 sm:px-6">
                    <div>
                        <h2 id="reviewDialogTitle" class="text-base font-bold text-gray-900 sm:text-lg">Claim Adjudication</h2>
                        <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1">
                            <p class="text-sm text-gray-500"><span id="reviewClaimNumber"></span><span aria-hidden="true"> · </span>Submitted <span id="reviewSubmittedDate"></span></p>
                            <span id="reviewClaimStatus" class="inline-flex items-center border px-2 py-0.5 text-[10px] font-semibold"></span>
                        </div>
                    </div>
                    <button id="closeClaimReview" type="button" aria-label="Close claim details" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100 hover:text-gray-900">
                        <i class="bi bi-x-lg" aria-hidden="true"></i>
                    </button>
                </div>

                <div class="grid min-h-0 flex-1 gap-5 overflow-hidden px-5 py-4 sm:px-6 lg:grid-cols-2 lg:grid-rows-1">
                    <section aria-labelledby="claimContextHeading" class="claim-modal-scroll-area min-h-0 overflow-y-auto overscroll-contain pr-2">
                        <h3 id="claimContextHeading" class="sr-only">Claim context</h3>
                        <div class="border border-gray-200 bg-gray-50 p-3.5">
                            <p id="reviewEmployeeName" class="text-sm font-semibold text-gray-900"></p>
                            <p class="mt-1 text-sm text-gray-500"><span id="reviewEmployeeCode"></span><span aria-hidden="true"> · </span><span id="reviewDepartment"></span><span aria-hidden="true"> · </span><span id="reviewEmploymentType"></span></p>
                        </div>
                        <div class="mt-4">
                            <h3 class="text-sm font-semibold text-gray-900">Request summary</h3>
                            <dl class="mt-2.5 space-y-2 text-sm">
                                <div class="flex justify-between gap-4"><dt class="text-gray-500">Expense type</dt><dd id="reviewExpenseType" class="text-right font-medium text-gray-800"></dd></div>
                                <div class="flex justify-between gap-4"><dt class="text-gray-500">Claimed amount</dt><dd class="text-right font-semibold text-gray-900">₱<span id="reviewAmount"></span></dd></div>
                                <div class="flex justify-between gap-4"><dt class="text-gray-500">Expense date</dt><dd id="reviewExpenseDate" class="text-right text-gray-800"></dd></div>
                            </dl>
                        </div>
                        <div class="mt-4">
                            <h3 class="text-sm font-semibold text-gray-900">Attachment</h3>
                            <div id="reviewReceipt" class="mt-2 text-sm text-gray-500"></div>
                        </div>
                        <div id="reviewNotesPanel" class="mt-4">
                            <h3 class="text-sm font-semibold text-gray-900">Review history</h3>
                            <pre id="reviewNotes" class="mt-2 whitespace-pre-wrap break-words border border-gray-200 bg-gray-50 p-3 text-xs leading-5 text-gray-600"></pre>
                        </div>
                    </section>

                    <section aria-labelledby="policyChecksHeading" class="claim-modal-scroll-area min-h-0 overflow-y-auto overscroll-contain pr-2">
                        <h3 id="policyChecksHeading" class="mb-2.5 text-sm font-semibold text-gray-900">Policy pre-check</h3>
                        <div class="space-y-2.5">
                            <article class="border border-gray-200 p-3">
                                <div class="flex items-start justify-between gap-3"><h4 class="text-sm font-medium text-gray-800">Category limit</h4><span id="categoryCheckStatus" class="shrink-0 px-2 py-1 text-[10px] font-bold tracking-wide"></span></div>
                                <p id="categoryCheckSummary" class="mt-1.5 text-sm font-semibold text-gray-900"></p>
                                <p id="categoryCheckMessage" class="mt-1 text-xs text-gray-500"></p>
                            </article>
                            <article class="border border-gray-200 p-3">
                                <div class="flex items-start justify-between gap-3"><h4 class="text-sm font-medium text-gray-800">Timeline validity</h4><span id="timelineCheckStatus" class="shrink-0 px-2 py-1 text-[10px] font-bold tracking-wide"></span></div>
                                <p id="timelineCheckSummary" class="mt-1.5 text-sm font-semibold text-gray-900"></p>
                                <p id="timelineCheckMessage" class="mt-1 text-xs text-gray-500"></p>
                            </article>
                            <article class="border border-gray-200 p-3">
                                <div class="flex items-start justify-between gap-3"><h4 class="text-sm font-medium text-gray-800">Documentation</h4><span id="receiptCheckStatus" class="shrink-0 px-2 py-1 text-[10px] font-bold tracking-wide"></span></div>
                                <p id="receiptCheckMessage" class="mt-1.5 text-sm text-gray-700"></p>

                            </article>
                            <article class="border border-gray-200 p-3">
                                <div class="flex items-start justify-between gap-3"><h4 class="text-sm font-medium text-gray-800">Employment standing</h4><span id="employmentCheckStatus" class="shrink-0 px-2 py-1 text-[10px] font-bold tracking-wide"></span></div>
                                <p id="employmentCheckSummary" class="mt-1.5 text-sm font-semibold text-gray-900"></p>
                                <p id="employmentCheckMessage" class="mt-1 text-xs text-gray-500"></p>
                            </article>
                        </div>
                    </section>
                </div>

                <div id="claimReviewActions" class="shrink-0 border-t border-gray-200 bg-white px-5 py-3.5 sm:px-6">
                    <label for="reviewNote" class="block text-sm font-medium text-gray-800">Review notes / remarks <span id="reviewNoteRequirement" class="text-rose-600"></span></label>
                    <textarea id="reviewNote" name="review_note" rows="2" maxlength="4000" class="mt-1.5 block w-full resize-y border border-gray-300 px-3 py-2 text-sm text-gray-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100" placeholder="Enter reason or override notes here..."></textarea>
                    <div class="mt-3 flex flex-wrap justify-end gap-2">
                        <button id="cancelClaimReview" type="button" class="border border-gray-300 px-3.5 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">Cancel</button>
                        <button type="submit" name="action" value="return" data-review-submit="return" class="bg-indigo-600 px-3.5 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Return for Revision</button>
                        <button type="submit" name="action" value="reject" data-review-submit="reject" class="bg-rose-600 px-3.5 py-2 text-sm font-semibold text-white hover:bg-rose-700">Reject</button>
                        <button type="submit" name="action" value="approve" data-review-submit="approve" class="bg-emerald-600 px-3.5 py-2 text-sm font-semibold text-white hover:bg-emerald-700">Approve</button>
                    </div>
                </div>
                <div id="claimFinalState" class="shrink-0 border-t border-gray-200 bg-white px-5 py-4 text-sm text-gray-600 sm:px-6" hidden>
                    This claim has been finalized. Review actions are no longer available.
                </div>
            </form>
        </dialog>
    <?php endif; ?>

    <style>
        .claim-modal-scroll-area {
            scrollbar-width: thin;
            scrollbar-color: transparent transparent;
            scrollbar-gutter: auto;
            transition: scrollbar-color 160ms ease;
        }

        .claim-modal-scroll-area:hover,
        .claim-modal-scroll-area:focus-within {
            scrollbar-color: #94a3b8 #f1f5f9;
        }

        .claim-modal-scroll-area::-webkit-scrollbar {
            width: 8px;
        }

        .claim-modal-scroll-area::-webkit-scrollbar-track {
            background: transparent;
        }

        .claim-modal-scroll-area::-webkit-scrollbar-thumb {
            border: 2px solid transparent;
            background: transparent;
            background-clip: padding-box;
            transition: background-color 160ms ease;
        }

        .claim-modal-scroll-area:hover::-webkit-scrollbar-track,
        .claim-modal-scroll-area:focus-within::-webkit-scrollbar-track {
            background: #f1f5f9;
        }

        .claim-modal-scroll-area:hover::-webkit-scrollbar-thumb,
        .claim-modal-scroll-area:focus-within::-webkit-scrollbar-thumb {
            background: #94a3b8;
            background-clip: padding-box;
        }

        .claim-modal-scroll-area:hover::-webkit-scrollbar-thumb:hover,
        .claim-modal-scroll-area:focus-within::-webkit-scrollbar-thumb:hover {
            background: #64748b;
            background-clip: padding-box;
        }
    </style>

    <script>
        (() => {
            const claimFiltersForm = document.getElementById('claimFiltersForm');
            const claimStatus = document.getElementById('claimStatus');
            claimStatus.addEventListener('change', () => claimFiltersForm.submit());

            const tabs = [
                { button: document.getElementById('claimsQueueTab'), panel: document.getElementById('claimsQueuePanel') },
                { button: document.getElementById('claimsThresholdsTab'), panel: document.getElementById('claimsThresholdsPanel') }
            ];

            const activateTab = (activeTab) => {
                tabs.forEach(({ button, panel }) => {
                    const selected = button === activeTab;
                    button.setAttribute('aria-selected', String(selected));
                    button.tabIndex = selected ? 0 : -1;
                    button.classList.toggle('border-blue-600', selected);
                    button.classList.toggle('text-blue-700', selected);
                    button.classList.toggle('border-transparent', !selected);
                    button.classList.toggle('text-gray-500', !selected);
                    panel.hidden = !selected;
                    panel.classList.toggle('hidden', !selected);
                });
            };

            tabs.forEach(({ button }, index) => {
                button.addEventListener('click', () => activateTab(button));
                button.addEventListener('keydown', (event) => {
                    if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
                    event.preventDefault();
                    const nextIndex = event.key === 'Home'
                        ? 0
                        : event.key === 'End'
                            ? tabs.length - 1
                            : (index + (event.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length;
                    tabs[nextIndex].button.focus();
                    activateTab(tabs[nextIndex].button);
                });
            });
        })();
    </script>
    <?php require __DIR__ . '/../partials/workspace_scripts.php'; ?>
    <?php if ($canReview): ?>
        <script>
            (() => {
                const dialog = document.getElementById('claimReviewDialog');
                const form = document.getElementById('claimReviewForm');
                const claimId = document.getElementById('reviewClaimId');
                const note = document.getElementById('reviewNote');
                const requirement = document.getElementById('reviewNoteRequirement');
                const cancelButton = document.getElementById('cancelClaimReview');
                const statusClasses = {
                    PASS: 'bg-emerald-50 text-emerald-700',
                    FAIL: 'bg-rose-50 text-rose-700',
                    REVIEW: 'bg-amber-50 text-amber-800'
                };
                const claimStatusClasses = {
                    'Pending Approval': 'border-amber-200 bg-amber-50 text-amber-800',
                    'Approved': 'border-emerald-200 bg-emerald-50 text-emerald-800',
                    'Rejected': 'border-rose-200 bg-rose-50 text-rose-800',
                    'Returned for Revision': 'border-indigo-200 bg-indigo-50 text-indigo-800'
                };
                const setText = (id, value) => {
                    document.getElementById(id).textContent = value ?? '';
                };
                const setCheck = (id, status) => {
                    const element = document.getElementById(id);
                    element.textContent = status;
                    element.className = `shrink-0 px-2 py-1 text-[10px] font-bold tracking-wide ${statusClasses[status] ?? statusClasses.REVIEW}`;
                };

                document.querySelectorAll('[data-claim-details]').forEach((button) => {
                    button.addEventListener('click', () => {
                        const claim = JSON.parse(button.dataset.claimDetails);
                        claimId.value = claim.id;
                        setText('reviewClaimNumber', claim.number);
                        setText('reviewSubmittedDate', claim.submittedDate);
                        const reviewClaimStatus = document.getElementById('reviewClaimStatus');
                        reviewClaimStatus.textContent = claim.status;
                        reviewClaimStatus.className = `inline-flex items-center border px-2 py-0.5 text-[10px] font-semibold ${claimStatusClasses[claim.status] ?? 'border-gray-200 bg-gray-50 text-gray-700'}`;
                        setText('reviewEmployeeName', claim.employee);
                        setText('reviewEmployeeCode', claim.employeeCode);
                        setText('reviewDepartment', claim.department);
                        setText('reviewEmploymentType', claim.employmentType);
                        setText('reviewExpenseType', claim.expenseType);
                        setText('reviewAmount', claim.amount);
                        setText('reviewExpenseDate', claim.expenseDate);
                        setCheck('categoryCheckStatus', claim.categoryStatus);
                        setText('categoryCheckSummary', claim.categoryLimit === null
                            ? `${claim.expenseType} · no configured ceiling`
                            : `₱${claim.categoryAmount} / ₱${claim.categoryLimit}`);
                        setText('categoryCheckMessage', claim.categoryMessage);
                        setCheck('timelineCheckStatus', claim.timelineStatus);
                        setText('timelineCheckSummary', claim.daysElapsed === null
                            ? `Expense date is after submission${claim.timelineLimit === null ? '' : ` (limit: ${claim.timelineLimit} days)`}`
                            : claim.timelineLimit === null
                                ? `${claim.daysElapsed} days (No timeline threshold configured)`
                            : `${claim.daysElapsed} day${claim.daysElapsed === 1 ? '' : 's'} (Limit: ${claim.timelineLimit} days)`);
                        setText('timelineCheckMessage', claim.timelineStatus === 'FAIL'
                            ? 'Late submission: special HR sign-off is required.'
                            : claim.timelineStatus === 'PASS'
                                ? 'Submitted within the applicable time window.'
                                : 'Could not validate the date range; check the submission record.');
                        setCheck('receiptCheckStatus', claim.receiptStatus);
                        setText('receiptCheckMessage', claim.receiptMessage);
                        setCheck('employmentCheckStatus', claim.employmentCheckStatus);
                        setText('employmentCheckSummary', `${claim.employmentStatus} · ${claim.employmentType}`);
                        setText('employmentCheckMessage', claim.employmentMessage);
                        const receiptContainer = document.getElementById('reviewReceipt');
                        receiptContainer.replaceChildren();
                        if (claim.receiptName !== '') {
                            const link = document.createElement('a');
                            link.href = claim.receiptUrl;
                            link.className = 'inline-flex items-center gap-2 font-medium text-blue-700 hover:text-blue-900';
                            link.innerHTML = '<i class="bi bi-file-earmark-text" aria-hidden="true"></i>';
                            link.append(document.createTextNode(`${claim.receiptName} (View)`));
                            receiptContainer.append(link);
                        } else {
                            receiptContainer.textContent = 'No receipt attached.';
                        }
                        setText('reviewNotes', claim.reviewNotes || 'No review history recorded for this claim.');
                        document.getElementById('claimReviewActions').hidden = !claim.reviewable;
                        document.getElementById('claimFinalState').hidden = claim.reviewable;
                        form.querySelectorAll('[data-review-submit]').forEach((actionButton) => {
                            actionButton.disabled = !claim.reviewable;
                        });
                        note.required = false;
                        requirement.textContent = '';
                        note.value = '';
                        dialog.showModal();
                    });
                });

                form.querySelectorAll('[data-review-submit]').forEach((button) => {
                    button.addEventListener('click', () => {
                        note.required = button.value !== 'approve';
                        requirement.textContent = note.required ? '(required for return or rejection)' : '(optional)';
                    });
                });

                cancelButton.addEventListener('click', () => dialog.close());
                document.getElementById('closeClaimReview').addEventListener('click', () => dialog.close());
                dialog.addEventListener('click', (event) => {
                    if (event.target === dialog) dialog.close();
                });
                dialog.addEventListener('close', () => {
                    form.reset();
                    note.required = false;
                    requirement.textContent = '';
                });
            })();
        </script>
    <?php endif; ?>
</body>
</html>
