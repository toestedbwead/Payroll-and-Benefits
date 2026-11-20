<?php

require_once __DIR__ . '/../Services/HmoService.php';
require_once __DIR__ . '/../../config/database.php';

class HmoController
{
    private const ALLOWED_ROLES = ['admin', 'superadmin', 'hr_manager'];

    public static function searchEligibleEmployees(): void
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Content-Type: application/json; charset=utf-8');

        if (!self::canManage($_SESSION['user'] ?? [])) {
            http_response_code(403);
            echo json_encode(['error' => 'Your account is not allowed to search HMO-eligible employees.']);
            exit;
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            header('Allow: GET');
            http_response_code(405);
            echo json_encode(['error' => 'This endpoint only accepts GET requests.']);
            exit;
        }

        $query = $_GET['q'] ?? null;
        if (!is_string($query)) {
            http_response_code(400);
            echo json_encode(['error' => 'Enter an employee name, employee code, or department to search.']);
            exit;
        }
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            echo json_encode(['employees' => []]);
            exit;
        }

        try {
            $employees = (new HmoRepository(getDatabaseConnection()))->searchEligibleEmployees($query);
            echo json_encode(['employees' => $employees], JSON_THROW_ON_ERROR);
        } catch (PDOException $exception) {
            error_log('HMO eligible employee search database error: ' . $exception->getMessage());
            http_response_code(503);
            echo json_encode(['error' => 'Employee search is temporarily unavailable.']);
        } catch (JsonException $exception) {
            error_log('HMO eligible employee search response error: ' . $exception->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Employee search could not be completed.']);
        }
        exit;
    }

    public static function handleRequest(bool $settingsPage = false): array
    {
        $user = $_SESSION['user'] ?? [];
        if (!self::canManage($user)) {
            http_response_code(403);
            return [
                'canManage' => false,
                'loadError' => false,
                'plans' => [],
                'enrollments' => [],
                'dependents' => [],
                'deductions' => [],
                'deductionSummary' => ['counts' => [], 'amounts' => []],
                'report' => ['active' => 0, 'terminated' => 0, 'coveredMonths' => 0, 'companySpend' => 0.0, 'rows' => []],
                'month' => date('Y-m'),
                'year' => (int) date('Y'),
                'flash' => ['type' => 'error', 'message' => 'Your account is not allowed to manage HMO benefits.'],
            ];
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            self::processAction($user, $settingsPage);
        }

        $flash = $_SESSION['hmo_flash'] ?? null;
        unset($_SESSION['hmo_flash']);
        $monthInput = $_GET['month'] ?? date('Y-m');
        $month = is_string($monthInput) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $monthInput)
            ? $monthInput
            : date('Y-m');
        $yearInput = $_GET['year'] ?? date('Y');
        $yearValue = is_string($yearInput) ? filter_var($yearInput, FILTER_VALIDATE_INT) : false;
        $year = $yearValue !== false && $yearValue >= 2000 && $yearValue <= 2100
            ? $yearValue
            : (int) date('Y');
        $activeTabInput = $_GET['tab'] ?? 'coverage';
        $activeTab = is_string($activeTabInput) && in_array($activeTabInput, ['coverage', 'plans'], true)
            ? $activeTabInput
            : 'coverage';

        try {
            $repository = new HmoRepository(getDatabaseConnection());
            $enrollments = $repository->listEnrollments();
            $annualRows = $repository->listAnnualEnrollments($year);
            $dependents = [];
            foreach ($repository->listDependents() as $dependent) {
                $dependents[(int) $dependent['employee_hmo_id']][] = $dependent;
            }

            return [
                'canManage' => true,
                'loadError' => false,
                'plans' => $repository->listPlans(),
                'enrollments' => $enrollments,
                'dependents' => $dependents,
                'deductions' => $repository->listDeductions($month),
                'deductionSummary' => $repository->getDeductionCounts($month),
                'report' => self::buildAnnualReport($annualRows, $year),
                'month' => $month,
                'year' => $year,
                'activeTab' => $activeTab,
                'flash' => is_array($flash) ? $flash : null,
            ];
        } catch (PDOException $exception) {
            error_log('HMO page database error: ' . $exception->getMessage());

            return [
                'canManage' => true,
                'loadError' => true,
                'plans' => [],
                'enrollments' => [],
                'dependents' => [],
                'deductions' => [],
                'deductionSummary' => ['counts' => [], 'amounts' => []],
                'report' => ['active' => 0, 'terminated' => 0, 'coveredMonths' => 0, 'companySpend' => 0.0, 'rows' => []],
                'month' => $month,
                'year' => $year,
                'activeTab' => $activeTab,
                'flash' => ['type' => 'error', 'message' => 'HMO benefits could not be loaded. Apply the HMO database migration and check the database connection.'],
            ];
        }
    }

    public static function downloadBirthCertificate(): void
    {
        $user = $_SESSION['user'] ?? [];
        if (!self::canManage($user)) {
            http_response_code(403);
            exit('Your account is not allowed to access HMO documents.');
        }

        $dependentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        if ($dependentId === false || $dependentId === null || $dependentId < 1) {
            http_response_code(400);
            exit('Invalid dependent reference.');
        }

        try {
            $relativePath = (new HmoRepository(getDatabaseConnection()))->findBirthCertificate($dependentId);
        } catch (PDOException $exception) {
            error_log('HMO birth certificate database error: ' . $exception->getMessage());
            http_response_code(503);
            exit('The birth certificate is temporarily unavailable.');
        }

        $expectedPrefix = 'uploads/hmo/birth-certificates/';
        if ($relativePath === null || !str_starts_with($relativePath, $expectedPrefix)) {
            http_response_code(404);
            exit('Birth certificate not found.');
        }
        $uploadRoot = realpath(__DIR__ . '/../../uploads/hmo/birth-certificates');
        $filePath = realpath(__DIR__ . '/../../' . $relativePath);
        if (
            $uploadRoot === false
            || $filePath === false
            || !str_starts_with($filePath, $uploadRoot . DIRECTORY_SEPARATOR)
            || !is_file($filePath)
        ) {
            http_response_code(404);
            exit('Birth certificate is not available.');
        }

        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($filePath);
        $extensions = [
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
        ];
        if (!is_string($mimeType) || !isset($extensions[$mimeType])) {
            http_response_code(415);
            exit('This birth certificate file type is not supported.');
        }

        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . (string) filesize($filePath));
        header('Content-Disposition: inline; filename="dependent-birth-certificate.' . $extensions[$mimeType] . '"');
        header('X-Content-Type-Options: nosniff');
        readfile($filePath);
        exit;
    }

    public static function exportDeductions(): void
    {
        $user = $_SESSION['user'] ?? [];
        if (!self::canManage($user)) {
            http_response_code(403);
            exit('Your account is not allowed to export HMO deductions.');
        }
        $monthInput = $_GET['month'] ?? '';
        if (!is_string($monthInput)) {
            http_response_code(400);
            exit('Invalid payroll month.');
        }

        try {
            $month = HmoService::validMonth($monthInput);
            $rows = (new HmoRepository(getDatabaseConnection()))->listDeductionsForExport($month);
        } catch (InvalidArgumentException $exception) {
            http_response_code(400);
            exit('Invalid payroll month.');
        } catch (PDOException $exception) {
            error_log('HMO deduction export database error: ' . $exception->getMessage());
            http_response_code(503);
            exit('The HMO deduction export is temporarily unavailable.');
        }

        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="hmo-deductions-' . $month . '.csv"');
        header('X-Content-Type-Options: nosniff');
        $output = fopen('php://output', 'wb');
        if ($output === false) {
            http_response_code(500);
            exit('The HMO deduction export could not be created.');
        }

        fputcsv($output, ['Payroll Month', 'Employee ID', 'First Name', 'Last Name', 'Dependent', 'Relationship', 'Employee Deduction (PHP)', 'Queue Status']);
        foreach ($rows as $row) {
            fputcsv($output, [
                $row['payroll_month'],
                self::spreadsheetSafe((string) $row['employee_code']),
                self::spreadsheetSafe((string) $row['first_name']),
                self::spreadsheetSafe((string) $row['last_name']),
                self::spreadsheetSafe((string) $row['dependent_name']),
                $row['relationship'],
                number_format((float) $row['amount'], 2, '.', ''),
                $row['status'],
            ]);
        }
        fclose($output);
        exit;
    }

    private static function processAction(array $user, bool $settingsPage): void
    {
        $postedToken = $_POST['csrf_token'] ?? null;
        $sessionToken = $_SESSION['csrf_token'] ?? null;
        if (
            !is_string($postedToken)
            || !is_string($sessionToken)
            || !hash_equals($sessionToken, $postedToken)
        ) {
            http_response_code(403);
            exit('Invalid or expired HMO request. Refresh the page and try again.');
        }
        $action = $_POST['action'] ?? null;
        $month = $_POST['month'] ?? date('Y-m');
        $activeTab = self::validTab($_POST['active_tab'] ?? null);
        $destinationPage = $settingsPage ? 'hmo_settings' : 'benefits';
        $actor = isset($user['name']) && is_string($user['name']) ? $user['name'] : 'HR benefits manager';

        try {
            $connection = getDatabaseConnection();
            $service = new HmoService($connection, new HmoRepository($connection));
            if (!is_string($action)) {
                throw new InvalidArgumentException('Choose a valid HMO action.');
            }

            switch ($action) {
                case 'save_plan':
                    $planId = $service->savePlan(self::planInput(), $actor);
                    self::redirectWithFlash('success', 'HMO plan #' . $planId . ' saved.', self::validRedirectMonth($month), $activeTab, $destinationPage);
                case 'enroll':
                    $enrollmentId = $service->enroll(
                        self::enrollmentInput(false),
                        self::dependentCertificateFiles(),
                        $actor
                    );
                    self::redirectWithFlash('success', 'Employee enrolled in HMO coverage #' . $enrollmentId . '.', self::validRedirectMonth($month), $activeTab, $destinationPage);
                case 'edit_enrollment':
                    $enrollmentId = filter_var($_POST['enrollment_id'] ?? null, FILTER_VALIDATE_INT);
                    if ($enrollmentId === false || $enrollmentId === null) {
                        throw new InvalidArgumentException('Choose a valid enrollment.');
                    }
                    $service->editEnrollment(
                        $enrollmentId,
                        self::enrollmentInput(true),
                        self::dependentCertificateFiles(),
                        $actor
                    );
                    self::redirectWithFlash('success', 'HMO enrollment #' . $enrollmentId . ' updated.', self::validRedirectMonth($month), $activeTab, $destinationPage);
                case 'terminate':
                    $enrollmentId = filter_var($_POST['enrollment_id'] ?? null, FILTER_VALIDATE_INT);
                    $terminationDate = $_POST['termination_date'] ?? null;
                    if ($enrollmentId === false || $enrollmentId === null || !is_string($terminationDate)) {
                        throw new InvalidArgumentException('The termination request was invalid.');
                    }
                    $service->terminate($enrollmentId, $terminationDate, $actor);
                    self::redirectWithFlash('success', 'HMO coverage #' . $enrollmentId . ' terminated.', self::validRedirectMonth($month), $activeTab, $destinationPage);
                case 'generate_deductions':
                    if (!is_string($month)) {
                        throw new InvalidArgumentException('Choose a valid payroll month.');
                    }
                    $count = $service->generateDeductions($month, $actor);
                    self::redirectWithFlash('success', $count . ' new dependent deduction(s) queued for ' . $month . '.', self::validRedirectMonth($month), $activeTab, $destinationPage);
                case 'export_queue':
                case 'process_queue':
                    if (!is_string($month)) {
                        throw new InvalidArgumentException('Choose a valid payroll month.');
                    }
                    $count = $service->transitionDeductions(
                        $action === 'export_queue' ? 'export' : 'process',
                        $month,
                        $actor
                    );
                    $state = $action === 'export_queue' ? 'exported' : 'processed';
                    self::redirectWithFlash('success', $count . ' deduction(s) marked ' . $state . ' for ' . $month . '.', self::validRedirectMonth($month), $activeTab, $destinationPage);
                default:
                    throw new InvalidArgumentException('Choose a valid HMO action.');
            }
        } catch (InvalidArgumentException | DomainException $exception) {
            self::redirectWithFlash('error', $exception->getMessage(), self::validRedirectMonth($month), $activeTab, $destinationPage);
        } catch (PDOException $exception) {
            error_log('HMO workflow database error: ' . $exception->getMessage());
            self::redirectWithFlash('error', 'The HMO request could not be saved because of a database error.', self::validRedirectMonth($month), $activeTab, $destinationPage);
        } catch (RuntimeException $exception) {
            error_log('HMO workflow file error: ' . $exception->getMessage());
            self::redirectWithFlash('error', 'The HMO request could not be completed. Check the uploaded files and server storage.', self::validRedirectMonth($month), $activeTab, $destinationPage);
        }
    }

    private static function planInput(): array
    {
        $fields = [
            'plan_id', 'provider_name', 'plan_tier_name', 'target_job_tier',
            'max_coverage_limit', 'room_and_board', 'annual_premium_cost',
            'parent_dependents_allowed', 'is_active',
        ];
        $input = [];
        foreach ($fields as $field) {
            $value = $_POST[$field] ?? null;
            if (!is_string($value)) {
                throw new InvalidArgumentException('The plan details were incomplete.');
            }
            $input[$field] = $value;
        }

        return $input;
    }

    private static function enrollmentInput(bool $editing): array
    {
        $fields = $editing
            ? ['plan_id', 'effectivity_date']
            : ['employee_id', 'plan_id', 'effectivity_date'];
        foreach ($fields as $field) {
            if (!isset($_POST[$field]) || !is_string($_POST[$field])) {
                throw new InvalidArgumentException('The enrollment details were incomplete.');
            }
        }

        $input = [
            'plan_id' => $_POST['plan_id'],
            'effectivity_date' => $_POST['effectivity_date'],
            'dependents' => $_POST['dependents'] ?? [],
        ];
        if (!$editing) {
            $input['employee_id'] = $_POST['employee_id'];
        }

        return $input;
    }

    private static function dependentCertificateFiles(): array
    {
        if (!isset($_FILES['dependent_certificates'])) {
            return [];
        }
        $uploaded = $_FILES['dependent_certificates'];
        if (!is_array($uploaded)) {
            throw new InvalidArgumentException('Dependent birth certificate uploads are invalid.');
        }

        $files = [];
        foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $field) {
            if (!isset($uploaded[$field]) || !is_array($uploaded[$field])) {
                throw new InvalidArgumentException('Dependent birth certificate uploads are incomplete.');
            }
        }
        foreach ($uploaded['error'] as $slot => $error) {
            $name = $uploaded['name'][$slot] ?? null;
            $type = $uploaded['type'][$slot] ?? null;
            $temporaryName = $uploaded['tmp_name'][$slot] ?? null;
            $size = $uploaded['size'][$slot] ?? null;
            if (
                !is_int($error)
                || !is_string($name)
                || !is_string($type)
                || !is_string($temporaryName)
                || !is_int($size)
            ) {
                throw new InvalidArgumentException('Dependent birth certificate upload details are invalid.');
            }
            $files[$slot] = [
                'name' => $name,
                'type' => $type,
                'tmp_name' => $temporaryName,
                'error' => $error,
                'size' => $size,
            ];
        }

        return $files;
    }

    private static function buildAnnualReport(array $rows, int $year): array
    {
        $yearStart = new DateTimeImmutable($year . '-01-01');
        $yearEnd = new DateTimeImmutable($year . '-12-31');
        $report = [
            'active' => 0,
            'terminated' => 0,
            'coveredMonths' => 0,
            'companySpend' => 0.0,
            'rows' => [],
        ];
        foreach ($rows as $row) {
            $start = max(new DateTimeImmutable((string) $row['effectivity_date']), $yearStart);
            $end = $row['terminated_at'] === null
                ? $yearEnd
                : min(new DateTimeImmutable((string) $row['terminated_at']), $yearEnd);
            $monthsCovered = 0;
            if ($start <= $end) {
                for ($month = 1; $month <= 12; $month++) {
                    $monthStart = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
                    $monthEnd = $monthStart->modify('last day of this month');
                    if ($start <= $monthEnd && $end >= $monthStart) {
                        $monthsCovered++;
                    }
                }
            }
            $companySpend = (float) $row['annual_premium_cost'] / 12 * $monthsCovered;
            $report['coveredMonths'] += $monthsCovered;
            $report['companySpend'] += $companySpend;
            $report['rows'][] = [
                'employee' => (string) $row['employee_code'] . ' · ' . $row['first_name'] . ' ' . $row['last_name'],
                'plan' => (string) $row['provider_name'] . ' · ' . $row['plan_tier_name'],
                'status' => (string) $row['enrollment_status'],
                'months' => $monthsCovered,
                'companySpend' => $companySpend,
            ];
            if ($row['enrollment_status'] === 'Active') {
                $report['active']++;
            } else {
                $report['terminated']++;
            }
        }
        $report['companySpend'] = round($report['companySpend'], 2);

        return $report;
    }

    private static function validRedirectMonth(mixed $month): string
    {
        return is_string($month) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)
            ? $month
            : date('Y-m');
    }

    private static function canManage(array $user): bool
    {
        return isset($user['role'])
            && is_string($user['role'])
            && in_array($user['role'], self::ALLOWED_ROLES, true);
    }

    private static function validTab(mixed $tab): string
    {
        return is_string($tab) && in_array($tab, ['coverage', 'plans'], true)
            ? $tab
            : 'coverage';
    }

    private static function redirectWithFlash(
        string $type,
        string $message,
        string $month,
        string $activeTab,
        string $page = 'benefits'
    ): never
    {
        $_SESSION['hmo_flash'] = ['type' => $type, 'message' => $message];
        $location = 'index.php?page=' . rawurlencode($page);
        if ($page === 'benefits') {
            $location .= '&month=' . rawurlencode($month) . '&tab=' . rawurlencode($activeTab);
        } elseif ($page === 'hmo_settings') {
            $returnPage = $_POST['return_to'] ?? 'dashboard';
            $allowedReturnPages = ['dashboard', 'payroll', 'compensation', 'claims', 'benefits', 'analytics'];
            $returnPage = is_string($returnPage) && in_array($returnPage, $allowedReturnPages, true)
                ? $returnPage
                : 'dashboard';
            $location = 'index.php?page=settings&section=benefits&return_to=' . rawurlencode($returnPage);
        }
        header('Location: ' . $location);
        exit;
    }

    private static function spreadsheetSafe(string $value): string
    {
        return preg_match('/^[\s]*[=+\-@]/', $value) ? "'" . $value : $value;
    }
}
