<?php

require_once __DIR__ . '/../Repositories/CompensationRepository.php';
require_once __DIR__ . '/../Services/CompensationService.php';
require_once __DIR__ . '/../../config/database.php';

class CompensationController
{
    private const ALLOWED_ROLES = ['admin', 'superadmin', 'hr_manager'];

    public static function handleRequest(bool $settingsContext = false): array
    {
        $user = $_SESSION['user'] ?? [];
        $role = isset($user['role']) && is_string($user['role']) ? $user['role'] : '';
        $canManage = in_array($role, self::ALLOWED_ROLES, true);
        $isManager = $role === 'manager';

        if (!$canManage && !$isManager) {
            http_response_code(403);
            return self::emptyPageData([
                'type' => 'error',
                'message' => 'Your account is not allowed to manage compensation planning.',
            ], false);
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            self::processPost($user, $canManage, $settingsContext);
        }

        $flash = $_SESSION['compensation_flash'] ?? null;
        unset($_SESSION['compensation_flash']);
        $structureFormValues = $_SESSION['compensation_structure_input'] ?? [];
        unset($_SESSION['compensation_structure_input']);
        $structureFormValues = is_array($structureFormValues) ? $structureFormValues : [];
        try {
            $connection = getDatabaseConnection();
            $repository = new CompensationRepository($connection);
            $workflowSchemaReady = $repository->hasWorkflowSchema();
            if ($isManager) {
                if (!$workflowSchemaReady) {
                    return array_replace(self::emptyPageData([
                        'type' => 'error',
                        'message' => 'Manager review is unavailable until database/008_compensation_workflow_migration.sql and database/009_department_migration.sql are applied.',
                    ], false), ['isManager' => true, 'workflowMigrationRequired' => true]);
                }
                $actorId = filter_var($user['id'] ?? null, FILTER_VALIDATE_INT);
                $managerEmployeeId = $actorId === false || $actorId === null
                    ? null
                    : $repository->findManagerEmployeeId($actorId);
                $managerCycles = $repository->listManagerCycles();
                $requestedCycleId = filter_input(INPUT_GET, 'manager_cycle', FILTER_VALIDATE_INT);
                $managerCycle = null;
                if ($requestedCycleId !== false && $requestedCycleId !== null) {
                    foreach ($managerCycles as $cycle) {
                        if ((int) $cycle['id'] === $requestedCycleId) {
                            $managerCycle = $cycle;
                            break;
                        }
                    }
                } elseif ($managerCycles !== []) {
                    $managerCycle = $managerCycles[0];
                }
                if ($managerCycle !== null) {
                    $managerCycle = $repository->findCycle((int) $managerCycle['id']);
                    if ($managerCycle !== null && $managerCycle['status'] !== 'Open') {
                        $managerCycle = null;
                    }
                }
                return [
                    'isManager' => true,
                    'managerEmployeeId' => $managerEmployeeId,
                    'managerCycles' => $managerCycles,
                    'managerCycle' => $managerCycle,
                    'managerRecommendations' => $managerCycle !== null && $managerEmployeeId !== null
                        ? $repository->listRecommendations((int) $managerCycle['id'], $managerEmployeeId)
                        : [],
                    'managerDepartmentBudgets' => $managerCycle !== null
                        ? $repository->listDepartmentBudgets((int) $managerCycle['id'])
                        : [],
                    'flash' => is_array($flash) ? $flash : null,
                    'canManage' => false,
                    'workflowMigrationRequired' => false,
                ];
            }

            $editCycleId = filter_input(INPUT_GET, 'edit_cycle', FILTER_VALIDATE_INT);
            $editCycle = null;
            $departmentBudgets = [];
            $selectedCycleId = filter_input(INPUT_GET, 'cycle_view', FILTER_VALIDATE_INT);
            $selectedCycle = null;
            $recommendations = [];
            $exclusions = [];
            $recommendationCounts = [];
            if ($selectedCycleId !== false && $selectedCycleId !== null && $selectedCycleId > 0) {
                $selectedCycle = $repository->findCycle($selectedCycleId);
                if ($selectedCycle !== null && $workflowSchemaReady) {
                    $recommendations = $repository->listRecommendations($selectedCycleId);
                    $exclusions = $repository->listExclusions($selectedCycleId);
                    $recommendationCounts = $repository->listRecommendationCounts($selectedCycleId);
                }
            }
            if ($editCycleId !== false && $editCycleId !== null && $editCycleId > 0) {
                $editCycle = $repository->findCycle($editCycleId);
                if ($editCycle === null || $editCycle['status'] !== 'Draft') {
                    $flash = [
                        'type' => 'error',
                        'message' => 'Only an existing Draft cycle can be edited.',
                    ];
                    $editCycle = null;
                } elseif ($workflowSchemaReady) {
                    $departmentBudgets = $repository->listDepartmentBudgets($editCycleId);
                }
            }
            $archivedStructureGrades = [];
            foreach ($repository->listArchivedSalaryStructureGrades() as $grade) {
                $archivedStructureGrades[(int) $grade['structure_id']][] = $grade;
            }
            $archivedMatrixBands = [];
            foreach ($repository->listArchivedMeritMatrixBands() as $band) {
                $archivedMatrixBands[(int) $band['matrix_id']][] = $band;
            }
            $requestedTab = $_GET['tab'] ?? '';
            $allowedTabs = $settingsContext ? ['structure', 'matrix', 'employees'] : ['structure', 'matrix', 'cycles'];
            $activeTab = is_string($requestedTab) && in_array($requestedTab, $allowedTabs, true)
                ? $requestedTab
                : ($settingsContext
                    ? 'structure'
                    : (($selectedCycle !== null || $editCycle !== null) ? 'cycles' : 'structure'));
            if ($activeTab !== 'cycles') {
                $selectedCycle = null;
                $recommendations = [];
                $exclusions = [];
                $recommendationCounts = [];
            }

            return [
                'salaryStructures' => $repository->listSalaryStructures(),
                'meritMatrices' => $repository->listMeritMatrices(),
                'archivedStructureGrades' => $archivedStructureGrades,
                'archivedMatrixBands' => $archivedMatrixBands,
                'cycles' => $repository->listCycles($workflowSchemaReady),
                'departments' => $workflowSchemaReady ? $repository->listDepartments() : [],
                'activeStructures' => $repository->listActiveStructures(),
                'activeMatrices' => $repository->listActiveMatrices(),
                'activeMatrixBands' => $repository->listActiveMatrixBands(),
                'employeeData' => $workflowSchemaReady ? $repository->listEmployeesForCompensation() : [],
                'activeGrades' => $workflowSchemaReady ? $repository->listActiveGrades() : [],
                'selectedCycle' => $selectedCycle,
                'recommendations' => $recommendations,
                'exclusions' => $exclusions,
                'recommendationCounts' => $recommendationCounts,
                'editCycle' => $editCycle,
                'activeTab' => $activeTab,
                'structureFormValues' => $structureFormValues,
                'departmentBudgets' => $departmentBudgets,
                'flash' => is_array($flash) ? $flash : null,
                'canManage' => true,
                'isManager' => false,
                'migrationRequired' => false,
                'workflowMigrationRequired' => !$workflowSchemaReady,
            ];
        } catch (PDOException $exception) {
            error_log('Compensation page database error: ' . $exception->getMessage());
            return array_replace(self::emptyPageData([
                'type' => 'error',
                'message' => 'Compensation planning could not be loaded. Check the database connection and apply the compensation migrations in order.',
            ], true), ['structureFormValues' => $structureFormValues]);
        }
    }

    private static function processPost(array $user, bool $canManage, bool $settingsContext): void
    {
        $postedToken = $_POST['csrf_token'] ?? null;
        $sessionToken = $_SESSION['csrf_token'] ?? null;
        if (
            !is_string($postedToken)
            || !is_string($sessionToken)
            || !hash_equals($sessionToken, $postedToken)
        ) {
            http_response_code(403);
            exit('Invalid or expired compensation request. Refresh the page and try again.');
        }

        $actorId = filter_var($user['id'] ?? null, FILTER_VALIDATE_INT);
        if ($actorId === false || $actorId === null || $actorId < 1) {
            self::redirectWithFlash('error', 'Your account could not be verified for this compensation action.');
        }

        $action = $_POST['action'] ?? null;
        $actionTab = self::tabForAction(is_string($action) ? $action : '');
        try {
            $connection = getDatabaseConnection();
            $service = new CompensationService($connection);
            if (!$canManage) {
                $repository = new CompensationRepository($connection);
                $managerEmployeeId = $repository->findManagerEmployeeId($actorId);
                if ($managerEmployeeId === null) {
                    throw new DomainException('Your manager login is not linked to an employee record.');
                }
                $cycleId = filter_var($_POST['cycle_id'] ?? null, FILTER_VALIDATE_INT);
                if ($cycleId === false || $cycleId === null || $cycleId < 1) {
                    throw new InvalidArgumentException('Choose a valid Open cycle.');
                }
                if ($action === 'save_recommendation') {
                    $recommendationId = filter_var($_POST['recommendation_id'] ?? null, FILTER_VALIDATE_INT);
                    if ($recommendationId === false || $recommendationId === null || $recommendationId < 1) {
                        throw new InvalidArgumentException('Choose a valid recommendation.');
                    }
                    $service->saveManagerRecommendation(
                        $cycleId,
                        $recommendationId,
                        $managerEmployeeId,
                        $_POST['final_increase_percentage'] ?? null,
                        $_POST['manager_comment'] ?? null,
                        $actorId
                    );
                    self::redirectWithFlash('success', 'Recommendation saved.', 'manager_cycle=' . $cycleId);
                }
                if ($action === 'submit_team') {
                    $service->submitManagerTeam($cycleId, $managerEmployeeId, $actorId);
                    self::redirectWithFlash('success', 'Your team recommendations were submitted.', 'manager_cycle=' . $cycleId);
                }
                throw new InvalidArgumentException('Choose a valid manager action.');
            }

            if (!$settingsContext && in_array($action, ['save_structure', 'save_matrix', 'save_employee_compensation'], true)) {
                throw new DomainException('Compensation structures, matrices, and employee inputs can only be changed in Settings.');
            }

            if ($action === 'save_employee_compensation') {
                $employeeId = filter_var($_POST['employee_id'] ?? null, FILTER_VALIDATE_INT);
                if ($employeeId === false || $employeeId === null || $employeeId < 1) {
                    throw new InvalidArgumentException('Choose a valid employee.');
                }
                $service->updateEmployeeCompensationData($employeeId, $_POST, $actorId);
                self::redirectWithFlash('success', 'Employee compensation inputs saved.',                 'tab=employees');
            }
            if ($action === 'save_structure') {
                $grades = self::postedGrades();
                $id = $service->createSalaryStructure(
                    self::postedString('effective_from'),
                    $grades,
                    $actorId
                );
                self::redirectWithFlash('success', 'Salary structure version saved.', 'tab=structure');
            }
            if ($action === 'save_matrix') {
                $bands = self::postedMatrix();
                $service->createMeritMatrix(
                    self::postedString('matrix_effective_from'),
                    $bands,
                    $actorId
                );
                self::redirectWithFlash('success', 'Merit matrix version saved.', 'tab=matrix');
            }
            if ($action === 'save_cycle') {
                $cycleId = filter_var($_POST['cycle_id'] ?? null, FILTER_VALIDATE_INT);
                if ($cycleId === false || $cycleId === null || $cycleId < 1) {
                    $cycleId = null;
                }
                $allocations = $_POST['department_budget'] ?? [];
                if (!is_array($allocations)) {
                    throw new InvalidArgumentException('Department allocations are invalid.');
                }
                $service->saveDraftCycle(
                    $cycleId,
                    $_POST,
                    $allocations,
                    $actorId
                );
                self::redirectWithFlash('success', $cycleId === null ? 'Draft compensation cycle created.' : 'Draft compensation cycle updated.', 'tab=cycles');
            }
            if ($action === 'delete_cycle') {
                $cycleId = filter_var($_POST['cycle_id'] ?? null, FILTER_VALIDATE_INT);
                if ($cycleId === false || $cycleId === null || $cycleId < 1) {
                    throw new InvalidArgumentException('Choose a valid compensation cycle.');
                }
                $service->deleteDraftCycle($cycleId, $actorId);
                self::redirectWithFlash('success', 'Draft compensation cycle deleted.', 'tab=cycles');
            }
            if ($action === 'generate_recommendations') {
                $cycleId = self::postedCycleId();
                $counts = $service->generateRecommendations($cycleId, $actorId);
                self::redirectWithFlash(
                    'success',
                    $counts['eligible'] . ' eligible recommendations generated; ' . $counts['excluded'] . ' employees listed as excluded.',
                    'tab=cycles&cycle_view=' . $cycleId
                );
            }
            if ($action === 'open_cycle') {
                $cycleId = self::postedCycleId();
                $service->openCycle($cycleId, $actorId);
                self::redirectWithFlash('success', 'Cycle opened for manager review.', 'tab=cycles&cycle_view=' . $cycleId);
            }
            if ($action === 'review_recommendation') {
                $cycleId = self::postedCycleId();
                $recommendationId = filter_var($_POST['recommendation_id'] ?? null, FILTER_VALIDATE_INT);
                if ($recommendationId === false || $recommendationId === null || $recommendationId < 1) {
                    throw new InvalidArgumentException('Choose a valid recommendation.');
                }
                $reviewAction = $_POST['review_action'] ?? null;
                $reviewNote = $_POST['review_note'] ?? '';
                if (!is_string($reviewAction) || !is_string($reviewNote)) {
                    throw new InvalidArgumentException('The recommendation review is invalid.');
                }
                $service->reviewRecommendation($cycleId, $recommendationId, $reviewAction, $reviewNote, $actorId);
                self::redirectWithFlash('success', 'Recommendation review saved.', 'tab=cycles&cycle_view=' . $cycleId);
            }
            if ($action === 'finalize_cycle_review') {
                $cycleId = self::postedCycleId();
                $service->finalizeCycleReview($cycleId, $actorId);
                self::redirectWithFlash('success', 'Cycle submitted for final approval.', 'tab=cycles&cycle_view=' . $cycleId);
            }
            if (in_array($action, ['approve_cycle', 'lock_cycle', 'publish_cycle'], true)) {
                $cycleId = self::postedCycleId();
                $service->advanceCycle($cycleId, $action, $actorId);
                self::redirectWithFlash('success', 'Cycle status updated.', 'tab=cycles&cycle_view=' . $cycleId);
            }
            if ($action === 'top_up_budget') {
                $cycleId = self::postedCycleId();
                $service->topUpBudget($cycleId, $_POST['top_up_amount'] ?? null, $actorId);
                self::redirectWithFlash('success', 'Cycle budget topped up and audited.', 'tab=cycles&cycle_view=' . $cycleId);
            }

            throw new InvalidArgumentException('Choose a valid compensation action.');
        } catch (InvalidArgumentException | DomainException $exception) {
            if ($settingsContext && $action === 'save_structure') {
                $_SESSION['compensation_structure_input'] = self::postedStructureFormValues();
            }
            self::redirectWithFlash('error', $exception->getMessage(), 'tab=' . $actionTab);
        } catch (PDOException $exception) {
            error_log('Compensation save database error: ' . $exception->getMessage());
            if ($settingsContext && $action === 'save_structure') {
                $_SESSION['compensation_structure_input'] = self::postedStructureFormValues();
            }
            self::redirectWithFlash('error', 'A database error prevented this compensation change from being saved. The error was logged; please contact an administrator if it continues.', 'tab=' . $actionTab);
        }
    }

    private static function tabForAction(string $action): string
    {
        return match ($action) {
            'save_employee_compensation' => 'employees',
            'save_structure' => 'structure',
            'save_matrix' => 'matrix',
            'save_cycle', 'delete_cycle', 'generate_recommendations', 'open_cycle',
            'review_recommendation', 'finalize_cycle_review', 'approve_cycle',
            'lock_cycle', 'publish_cycle', 'top_up_budget' => 'cycles',
            default => 'structure',
        };
    }

    private static function postedCycleId(): int
    {
        $cycleId = filter_var($_POST['cycle_id'] ?? null, FILTER_VALIDATE_INT);
        if ($cycleId === false || $cycleId === null || $cycleId < 1) {
            throw new InvalidArgumentException('Choose a valid compensation cycle.');
        }

        return $cycleId;
    }

    private static function postedGrades(): array
    {
        $codes = $_POST['grade_code'] ?? [];
        $tiers = $_POST['grade_job_tier'] ?? [];
        $minimums = $_POST['grade_minimum'] ?? [];
        $midpoints = $_POST['grade_midpoint'] ?? [];
        $maximums = $_POST['grade_maximum'] ?? [];
        if (!is_array($codes) || !is_array($tiers) || !is_array($minimums) || !is_array($midpoints) || !is_array($maximums)) {
            throw new InvalidArgumentException('Salary grade entries are invalid.');
        }

        $grades = [];
        $indexes = array_unique(array_merge(
            array_keys($codes),
            array_keys($tiers),
            array_keys($minimums),
            array_keys($midpoints),
            array_keys($maximums)
        ));
        foreach ($indexes as $index) {
            $fields = [
                $codes[$index] ?? '',
                $tiers[$index] ?? '',
                $minimums[$index] ?? '',
                $midpoints[$index] ?? '',
                $maximums[$index] ?? '',
            ];
            foreach ($fields as $field) {
                if (!is_string($field)) {
                    throw new InvalidArgumentException('Salary grade entries are invalid.');
                }
            }
            if (count(array_filter($fields, static fn (string $field): bool => trim($field) !== '')) === 0) {
                continue;
            }
            if (count(array_filter($fields, static fn (string $field): bool => trim($field) === '')) > 0) {
                $rowNumber = is_int($index) ? ' ' . ($index + 1) : '';
                throw new InvalidArgumentException('Grade row' . $rowNumber . ' is incomplete. Fill in the grade, job tier, minimum, midpoint, and maximum, or clear that row. Other blank rows are allowed.');
            }
            $grades[] = [
                'grade_code' => $fields[0],
                'target_job_tier' => $fields[1],
                'minimum_salary' => $fields[2],
                'midpoint_salary' => $fields[3],
                'maximum_salary' => $fields[4],
            ];
        }

        return $grades;
    }

    private static function postedStructureFormValues(): array
    {
        $values = [
            'effective_from' => is_string($_POST['effective_from'] ?? null)
                ? mb_substr($_POST['effective_from'], 0, 32)
                : '',
        ];
        foreach ([
            'grade_code',
            'grade_job_tier',
            'grade_minimum',
            'grade_midpoint',
            'grade_maximum',
        ] as $field) {
            $postedValues = $_POST[$field] ?? [];
            $values[$field] = [];
            for ($row = 0; $row < 5; $row++) {
                $value = is_array($postedValues) ? ($postedValues[$row] ?? '') : '';
                $values[$field][$row] = is_string($value) ? mb_substr($value, 0, 64) : '';
            }
        }

        return $values;
    }

    private static function postedMatrix(): array
    {
        $rawBands = $_POST['matrix'] ?? null;
        if (!is_array($rawBands)) {
            throw new InvalidArgumentException('Merit matrix entries are invalid.');
        }

        return $rawBands;
    }

    private static function postedString(string $key): string
    {
        $value = $_POST[$key] ?? null;
        if (!is_string($value)) {
            throw new InvalidArgumentException('A required compensation value is missing.');
        }

        return trim($value);
    }

    private static function redirectWithFlash(string $type, string $message, string $query = ''): never
    {
        $_SESSION['compensation_flash'] = ['type' => $type, 'message' => $message];
        if (($_POST['return_to'] ?? null) === 'settings') {
            $tab = match ($_POST['action'] ?? null) {
                'save_matrix' => 'matrix',
                'save_employee_compensation' => 'employees',
                default => 'structure',
            };
            $returnPage = $_POST['settings_return_to'] ?? 'dashboard';
            if (!is_string($returnPage) || !in_array($returnPage, ['dashboard', 'payroll', 'compensation', 'claims', 'benefits', 'analytics'], true)) {
                $returnPage = 'dashboard';
            }
            header('Location: index.php?page=settings&section=compensation&tab=' . $tab . '&return_to=' . rawurlencode($returnPage));
            exit;
        }
        header('Location: index.php?page=compensation' . ($query === '' ? '' : '&' . $query));
        exit;
    }

    private static function emptyPageData(?array $flash, bool $migrationRequired): array
    {
        return [
            'salaryStructures' => [],
            'meritMatrices' => [],
            'cycles' => [],
            'departments' => [],
            'activeStructures' => [],
            'activeMatrices' => [],
            'activeMatrixBands' => [],
            'editCycle' => null,
            'structureFormValues' => [],
            'departmentBudgets' => [],
            'employeeData' => [],
            'activeGrades' => [],
            'selectedCycle' => null,
            'recommendations' => [],
            'exclusions' => [],
            'recommendationCounts' => [],
            'activeTab' => 'structure',
            'managerCycles' => [],
            'managerCycle' => null,
            'managerRecommendations' => [],
            'managerDepartmentBudgets' => [],
            'managerEmployeeId' => null,
            'flash' => $flash,
            'canManage' => false,
            'isManager' => false,
            'migrationRequired' => $migrationRequired,
            'workflowMigrationRequired' => false,
        ];
    }
}
