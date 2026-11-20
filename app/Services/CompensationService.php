<?php

class CompensationService
{
    private const JOB_TIERS = ['Entry-Level', 'Supervisory', 'Executive'];
    private const CYCLE_TYPES = ['Annual Merit', 'Mid-Year', 'Special Adjustment'];

    public function __construct(private PDO $connection)
    {
    }

    public function createSalaryStructure(string $effectiveFrom, array $grades, int $actorId): int
    {
        $this->validateDate($effectiveFrom, 'Structure effective date');
        if ($grades === []) {
            throw new InvalidArgumentException('Add at least one salary grade.');
        }

        $normalizedGrades = [];
        $gradeCodes = [];
        foreach ($grades as $grade) {
            if (!is_array($grade)) {
                throw new InvalidArgumentException('A salary grade entry is invalid.');
            }

            $rawCode = $grade['grade_code'] ?? null;
            if (!is_string($rawCode)) {
                throw new InvalidArgumentException('Every salary grade needs a valid grade code.');
            }
            $code = strtoupper(trim($rawCode));
            $jobTier = $grade['target_job_tier'] ?? '';
            if ($code === '' || mb_strlen($code) > 10 || !preg_match('/^[A-Z0-9_-]+$/', $code)) {
                throw new InvalidArgumentException('Grade codes must use up to 10 letters, numbers, hyphens, or underscores.');
            }
            if (!is_string($jobTier) || !in_array($jobTier, self::JOB_TIERS, true)) {
                throw new InvalidArgumentException('Choose a valid job tier for every salary grade.');
            }
            if (isset($gradeCodes[$code])) {
                throw new InvalidArgumentException('Grade codes must be unique within a structure.');
            }

            $minimum = $this->moneyToCents($grade['minimum_salary'] ?? null, 'Minimum salary');
            $midpoint = $this->moneyToCents($grade['midpoint_salary'] ?? null, 'Midpoint salary');
            $maximum = $this->moneyToCents($grade['maximum_salary'] ?? null, 'Maximum salary');
            if ($minimum >= $midpoint || $midpoint >= $maximum) {
                throw new InvalidArgumentException('Each grade must satisfy minimum < midpoint < maximum.');
            }

            $gradeCodes[$code] = true;
            $normalizedGrades[] = [
                'grade_code' => $code,
                'target_job_tier' => $jobTier,
                'minimum_salary' => $this->centsToMoney($minimum),
                'midpoint_salary' => $this->centsToMoney($midpoint),
                'maximum_salary' => $this->centsToMoney($maximum),
            ];
        }

        return $this->transaction(function () use ($effectiveFrom, $normalizedGrades, $actorId): int {
            $this->connection->exec(
                'UPDATE compensation_salary_structures SET status = \'Archived\' WHERE status = \'Active\''
            );
            $version = (int) $this->connection->query(
                'SELECT COALESCE(MAX(version), 0) + 1 FROM compensation_salary_structures'
            )->fetchColumn();
            $statement = $this->connection->prepare(
                'INSERT INTO compensation_salary_structures (version, effective_from, created_by)
                 VALUES (:version, :effective_from, :created_by)'
            );
            $statement->execute([
                'version' => $version,
                'effective_from' => $effectiveFrom,
                'created_by' => $actorId,
            ]);
            $structureId = (int) $this->connection->lastInsertId();

            $insertGrade = $this->connection->prepare(
                'INSERT INTO compensation_salary_grades
                    (structure_id, grade_code, target_job_tier, minimum_salary, midpoint_salary, maximum_salary)
                 VALUES (:structure_id, :grade_code, :target_job_tier, :minimum_salary, :midpoint_salary, :maximum_salary)'
            );
            foreach ($normalizedGrades as $grade) {
                $insertGrade->execute(['structure_id' => $structureId] + $grade);
            }

            $this->audit(null, $actorId, 'Salary structure created', 'Version ' . $version);
            return $structureId;
        });
    }

    public function createMeritMatrix(string $effectiveFrom, array $bandsByRating, int $actorId): int
    {
        $this->validateDate($effectiveFrom, 'Merit matrix effective date');
        $normalizedBands = [];
        foreach (range(1, 5) as $rating) {
            $bands = $bandsByRating[$rating] ?? null;
            if (!is_array($bands) || $bands === []) {
                throw new InvalidArgumentException('Every performance rating from 1 to 5 needs complete compa-ratio coverage.');
            }

            $expectedFrom = 0;
            foreach (array_values($bands) as $bandIndex => $band) {
                if (!is_array($band)) {
                    throw new InvalidArgumentException('A merit matrix band is invalid.');
                }
                $isLastBand = $bandIndex === count($bands) - 1;
                $from = $this->ratioToHundredths($band['compa_ratio_from'] ?? null, 'Compa-ratio band start');
                $rawTo = $band['compa_ratio_to'] ?? '';
                if (!is_string($rawTo) && !is_int($rawTo) && !is_float($rawTo)) {
                    throw new InvalidArgumentException('Compa-ratio band end must be numeric or left blank for the last band.');
                }
                $toInput = trim((string) $rawTo);
                $to = $toInput === '' ? null : $this->ratioToHundredths($toInput, 'Compa-ratio band end');
                $percentage = $this->percentageToHundredths($band['increase_percentage'] ?? null);

                if ($from !== $expectedFrom) {
                    throw new InvalidArgumentException('Compa-ratio bands must start at 0.00 and have no gaps or overlaps for every rating.');
                }
                if ($to !== null && $to <= $from) {
                    throw new InvalidArgumentException('Each compa-ratio band end must be greater than its start.');
                }
                if ($to === null && !$isLastBand) {
                    throw new InvalidArgumentException('Only the final compa-ratio band can have an open-ended range.');
                }

                $normalizedBands[$rating][] = [
                    'performance_rating' => $rating,
                    'compa_ratio_from' => $this->hundredthsToRatio($from),
                    'compa_ratio_to' => $to === null ? null : $this->hundredthsToRatio($to),
                    'increase_percentage' => $this->hundredthsToRatio($percentage),
                ];
                if ($to === null) {
                    if (!$isLastBand) {
                        throw new InvalidArgumentException('Only the final compa-ratio band can have an open-ended range.');
                    }
                    $expectedFrom = null;
                } else {
                    $expectedFrom = $to;
                }
            }
            if ($expectedFrom !== null) {
                throw new InvalidArgumentException('The final compa-ratio band must be open-ended to cover all higher ratios.');
            }
        }

        return $this->transaction(function () use ($effectiveFrom, $normalizedBands, $actorId): int {
            $this->connection->exec(
                'UPDATE compensation_merit_matrices SET status = \'Archived\' WHERE status = \'Active\''
            );
            $version = (int) $this->connection->query(
                'SELECT COALESCE(MAX(version), 0) + 1 FROM compensation_merit_matrices'
            )->fetchColumn();
            $statement = $this->connection->prepare(
                'INSERT INTO compensation_merit_matrices (version, effective_from, created_by)
                 VALUES (:version, :effective_from, :created_by)'
            );
            $statement->execute([
                'version' => $version,
                'effective_from' => $effectiveFrom,
                'created_by' => $actorId,
            ]);
            $matrixId = (int) $this->connection->lastInsertId();

            $insertBand = $this->connection->prepare(
                'INSERT INTO compensation_merit_bands
                    (matrix_id, performance_rating, compa_ratio_from, compa_ratio_to, increase_percentage)
                 VALUES (:matrix_id, :performance_rating, :compa_ratio_from, :compa_ratio_to, :increase_percentage)'
            );
            foreach ($normalizedBands as $ratingBands) {
                foreach ($ratingBands as $band) {
                    $insertBand->execute(['matrix_id' => $matrixId] + $band);
                }
            }

            $this->audit(null, $actorId, 'Merit matrix created', 'Version ' . $version);
            return $matrixId;
        });
    }

    public function saveDraftCycle(?int $cycleId, array $input, array $departmentBudgets, int $actorId): int
    {
        $name = $this->inputString($input, 'cycle_name', 'Cycle name');
        if ($name === '' || mb_strlen($name) > 100) {
            throw new InvalidArgumentException('Enter a cycle name of 1 to 100 characters.');
        }
        $cycleType = $input['cycle_type'] ?? '';
        if (!is_string($cycleType) || !in_array($cycleType, self::CYCLE_TYPES, true)) {
            throw new InvalidArgumentException('Choose a valid compensation cycle type.');
        }
        $effectiveDate = $this->inputString($input, 'effective_date', 'Effective date');
        $cutoffDate = $this->inputString($input, 'eligibility_cutoff_date', 'Eligibility cutoff date');
        $this->validateDate($effectiveDate, 'Effective date');
        $this->validateDate($cutoffDate, 'Eligibility cutoff date');
        if ($effectiveDate <= date('Y-m-d')) {
            throw new InvalidArgumentException('The effective date must be later than today.');
        }
        if ($cutoffDate > $effectiveDate) {
            throw new InvalidArgumentException('The eligibility cutoff date cannot be later than the effective date.');
        }

        $minimumRating = filter_var($input['minimum_performance_rating'] ?? null, FILTER_VALIDATE_INT);
        if ($minimumRating === false || $minimumRating < 1 || $minimumRating > 5) {
            throw new InvalidArgumentException('Minimum performance rating must be from 1 to 5.');
        }
        $budgetType = $input['budget_type'] ?? '';
        if (!is_string($budgetType) || !in_array($budgetType, ['Percent of Payroll', 'Fixed Amount'], true)) {
            throw new InvalidArgumentException('Choose a valid budget type.');
        }
        $budgetCents = $this->moneyToCents($input['budget_value'] ?? null, 'Budget value');
        if ($budgetType === 'Percent of Payroll' && $budgetCents > 10000) {
            throw new InvalidArgumentException('The payroll budget percentage must not exceed 100%.');
        }
        $structureId = filter_var($input['salary_structure_id'] ?? null, FILTER_VALIDATE_INT);
        $matrixId = filter_var($input['merit_matrix_id'] ?? null, FILTER_VALIDATE_INT);
        if ($structureId === false || $structureId < 1 || $matrixId === false || $matrixId < 1) {
            throw new InvalidArgumentException('Select an active salary structure and merit matrix version.');
        }

        $normalizedBudgets = [];
        $allocatedCents = 0;
        $departmentLookup = $departmentBudgets === [] ? null : $this->connection->prepare(
                'SELECT department_name
                 FROM departments
                 WHERE id = :id AND status = \'Active\''
            );
        foreach ($departmentBudgets as $departmentId => $amount) {
            if (is_string($amount) && trim($amount) === '') {
                continue;
            }
            $departmentId = filter_var((string) $departmentId, FILTER_VALIDATE_INT);
            if ($departmentId === false || $departmentId < 1) {
                throw new InvalidArgumentException('Choose a valid department for each budget allocation.');
            }
            $departmentLookup->execute(['id' => $departmentId]);
            $departmentName = $departmentLookup->fetchColumn();
            if ($departmentName === false) {
                throw new InvalidArgumentException('Choose an active department for each budget allocation.');
            }
            $cents = $this->moneyToCents($amount, 'Department allocation');
            if ($cents > 0) {
                $normalizedBudgets[(int) $departmentId] = [
                    'department_name' => (string) $departmentName,
                    'amount_cents' => $cents,
                ];
                $allocatedCents += $cents;
            }
        }

        return $this->transaction(function () use (
            $cycleId,
            $name,
            $cycleType,
            $effectiveDate,
            $cutoffDate,
            $minimumRating,
            $budgetType,
            $budgetCents,
            $structureId,
            $matrixId,
            $normalizedBudgets,
            $allocatedCents,
            $actorId
        ): int {
            if (!$this->isActiveVersion('compensation_salary_structures', $structureId)) {
                throw new DomainException('The selected salary structure version is not active.');
            }
            if (!$this->isActiveVersion('compensation_merit_matrices', $matrixId)) {
                throw new DomainException('The selected merit matrix version is not active.');
            }

            $totalBudgetCents = $budgetType === 'Fixed Amount'
                ? $budgetCents
                : $this->eligiblePayrollCents($cutoffDate, $minimumRating, $structureId) * $budgetCents / 10000;
            $totalBudgetCents = (int) round($totalBudgetCents);
            if ($allocatedCents > $totalBudgetCents) {
                throw new DomainException('Department allocations cannot exceed the cycle budget.');
            }

            if ($cycleId !== null) {
                $cycle = $this->findCycleForUpdate($cycleId);
                if ($cycle === null) {
                    throw new DomainException('That compensation cycle could not be found.');
                }
                if ($cycle['status'] !== 'Draft') {
                    throw new DomainException('Only Draft compensation cycles can be edited.');
                }
                $statement = $this->connection->prepare(
                    'UPDATE compensation_cycles
                     SET cycle_name = :cycle_name, cycle_type = :cycle_type,
                         effective_date = :effective_date, eligibility_cutoff_date = :cutoff_date,
                         minimum_performance_rating = :minimum_rating, budget_type = :budget_type,
                         budget_value = :budget_value, total_budget = :total_budget,
                         salary_structure_id = :structure_id, merit_matrix_id = :matrix_id
                     WHERE id = :id'
                );
                $statement->execute([
                    'cycle_name' => $name,
                    'cycle_type' => $cycleType,
                    'effective_date' => $effectiveDate,
                    'cutoff_date' => $cutoffDate,
                    'minimum_rating' => $minimumRating,
                    'budget_type' => $budgetType,
                    'budget_value' => $this->centsToMoney($budgetCents),
                    'total_budget' => $this->centsToMoney($totalBudgetCents),
                    'structure_id' => $structureId,
                    'matrix_id' => $matrixId,
                    'id' => $cycleId,
                ]);
                $this->connection->prepare(
                    'DELETE FROM compensation_department_budgets WHERE cycle_id = :cycle_id'
                )->execute(['cycle_id' => $cycleId]);
                $this->audit($cycleId, $actorId, 'Draft cycle updated', $name);
                $savedCycleId = $cycleId;
            } else {
                $statement = $this->connection->prepare(
                    'INSERT INTO compensation_cycles
                        (cycle_name, cycle_type, effective_date, eligibility_cutoff_date,
                         minimum_performance_rating, budget_type, budget_value, total_budget,
                         salary_structure_id, merit_matrix_id, created_by)
                     VALUES
                        (:cycle_name, :cycle_type, :effective_date, :cutoff_date,
                         :minimum_rating, :budget_type, :budget_value, :total_budget,
                         :structure_id, :matrix_id, :created_by)'
                );
                $statement->execute([
                    'cycle_name' => $name,
                    'cycle_type' => $cycleType,
                    'effective_date' => $effectiveDate,
                    'cutoff_date' => $cutoffDate,
                    'minimum_rating' => $minimumRating,
                    'budget_type' => $budgetType,
                    'budget_value' => $this->centsToMoney($budgetCents),
                    'total_budget' => $this->centsToMoney($totalBudgetCents),
                    'structure_id' => $structureId,
                    'matrix_id' => $matrixId,
                    'created_by' => $actorId,
                ]);
                $savedCycleId = (int) $this->connection->lastInsertId();
                $this->audit($savedCycleId, $actorId, 'Draft cycle created', $name);
            }

            $insertBudget = $this->connection->prepare(
                'INSERT INTO compensation_department_budgets
                    (cycle_id, department_id, department, allocated_amount)
                 VALUES (:cycle_id, :department_id, :department, :allocated_amount)'
            );
            foreach ($normalizedBudgets as $departmentId => $allocation) {
                $insertBudget->execute([
                    'cycle_id' => $savedCycleId,
                    'department_id' => $departmentId,
                    'department' => $allocation['department_name'],
                    'allocated_amount' => $this->centsToMoney($allocation['amount_cents']),
                ]);
            }

            return $savedCycleId;
        });
    }

    public function deleteDraftCycle(int $cycleId, int $actorId): void
    {
        $this->connection->beginTransaction();
        try {
            $cycle = $this->findCycleForUpdate($cycleId);
            if ($cycle === null) {
                throw new DomainException('That compensation cycle could not be found.');
            }
            if ($cycle['status'] !== 'Draft') {
                throw new DomainException('Only Draft compensation cycles can be deleted.');
            }
            $this->audit($cycleId, $actorId, 'Draft cycle deleted', 'Cycle ID ' . $cycleId);
            $statement = $this->connection->prepare(
                'DELETE FROM compensation_cycles WHERE id = :id AND status = \'Draft\''
            );
            $statement->execute(['id' => $cycleId]);
            $this->connection->commit();
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }
    }

    public function updateEmployeeCompensationData(int $employeeId, array $input, int $actorId): void
    {
        $salaryInput = $this->inputString($input, 'current_salary', 'Current salary');
        $salary = $salaryInput === '' ? null : $this->centsToMoney(
            $this->moneyToCents($salaryInput, 'Current salary')
        );
        $gradeCode = strtoupper($this->inputString($input, 'salary_grade_code', 'Salary grade'));
        if ($gradeCode !== '' && (mb_strlen($gradeCode) > 10 || !preg_match('/^[A-Z0-9_-]+$/', $gradeCode))) {
            throw new InvalidArgumentException('Choose a valid salary grade code.');
        }

        $regularizationStatus = $this->inputString($input, 'regularization_status', 'Regularization status');
        if ($regularizationStatus !== '' && !in_array($regularizationStatus, ['Regularized', 'Probationary'], true)) {
            throw new InvalidArgumentException('Choose a valid regularization status.');
        }
        $managerInput = $this->inputString($input, 'manager_id', 'Manager');
        $managerId = null;
        if ($managerInput !== '') {
            $managerId = filter_var($managerInput, FILTER_VALIDATE_INT);
            if ($managerId === false || $managerId < 1 || $managerId === $employeeId) {
                throw new InvalidArgumentException('Choose a valid manager other than the employee.');
            }
        }
        $ratingInput = $this->inputString($input, 'performance_rating', 'Performance rating');
        $rating = null;
        if ($ratingInput !== '') {
            $rating = filter_var($ratingInput, FILTER_VALIDATE_INT);
            if ($rating === false || $rating < 1 || $rating > 5) {
                throw new InvalidArgumentException('Performance rating must be from 1 to 5 or left blank.');
            }
        }

        $this->connection->beginTransaction();
        try {
            $employeeStatement = $this->connection->prepare(
                'SELECT id, job_tier FROM employees WHERE id = :id' . $this->lockClause()
            );
            $employeeStatement->execute(['id' => $employeeId]);
            $employee = $employeeStatement->fetch();
            if ($employee === false) {
                throw new DomainException('That employee could not be found.');
            }

            if ($gradeCode !== '') {
                $gradeStatement = $this->connection->prepare(
                    'SELECT 1
                     FROM compensation_salary_grades g
                     INNER JOIN compensation_salary_structures s ON s.id = g.structure_id
                     WHERE s.status = \'Active\' AND g.grade_code = :grade_code
                       AND g.target_job_tier = :job_tier
                     LIMIT 1'
                );
                $gradeStatement->execute([
                    'grade_code' => $gradeCode,
                    'job_tier' => $employee['job_tier'],
                ]);
                if ($gradeStatement->fetchColumn() === false) {
                    throw new DomainException('The selected grade is not in the active structure for this employee job tier.');
                }
            }
            if ($managerId !== null) {
                $managerStatement = $this->connection->prepare(
                    'SELECT 1 FROM employees WHERE id = :manager_id AND employment_status = \'Active\''
                );
                $managerStatement->execute(['manager_id' => $managerId]);
                if ($managerStatement->fetchColumn() === false) {
                    throw new DomainException('Choose an active employee as the manager.');
                }
            }

            $update = $this->connection->prepare(
                'UPDATE employees
                 SET current_salary = :salary, salary_grade_code = :grade_code,
                     regularization_status = :regularization_status, manager_id = :manager_id,
                     performance_rating = :rating
                 WHERE id = :id'
            );
            $update->execute([
                'salary' => $salary,
                'grade_code' => $gradeCode === '' ? null : $gradeCode,
                'regularization_status' => $regularizationStatus === '' ? null : $regularizationStatus,
                'manager_id' => $managerId,
                'rating' => $rating,
                'id' => $employeeId,
            ]);
            $this->audit(null, $actorId, 'Employee compensation inputs updated', 'Employee ID ' . $employeeId);
            $this->connection->commit();
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }
    }

    public function generateRecommendations(int $cycleId, int $actorId): array
    {
        $this->connection->beginTransaction();
        try {
            $cycle = $this->findCycleForUpdate($cycleId);
            if ($cycle === null) {
                throw new DomainException('That compensation cycle could not be found.');
            }
            if ($cycle['status'] !== 'Draft') {
                throw new DomainException('Recommendations can only be generated or replaced while the cycle is Draft.');
            }

            $this->connection->prepare(
                'DELETE FROM compensation_recommendations WHERE cycle_id = :cycle_id'
            )->execute(['cycle_id' => $cycleId]);
            $this->connection->prepare(
                'DELETE FROM compensation_exclusions WHERE cycle_id = :cycle_id'
            )->execute(['cycle_id' => $cycleId]);

            $gradeStatement = $this->connection->prepare(
                'SELECT grade_code, target_job_tier, minimum_salary, midpoint_salary, maximum_salary
                 FROM compensation_salary_grades WHERE structure_id = :structure_id'
            );
            $gradeStatement->execute(['structure_id' => $cycle['salary_structure_id']]);
            $grades = [];
            foreach ($gradeStatement->fetchAll() as $grade) {
                $grades[$grade['target_job_tier'] . '|' . $grade['grade_code']] = $grade;
            }

            $bandStatement = $this->connection->prepare(
                'SELECT performance_rating, compa_ratio_from, compa_ratio_to, increase_percentage
                 FROM compensation_merit_bands
                 WHERE matrix_id = :matrix_id
                 ORDER BY performance_rating, compa_ratio_from'
            );
            $bandStatement->execute(['matrix_id' => $cycle['merit_matrix_id']]);
            $bandsByRating = [];
            foreach ($bandStatement->fetchAll() as $band) {
                $bandsByRating[(int) $band['performance_rating']][] = $band;
            }

            $employees = $this->connection->query(
                'SELECT e.id, e.department_id, COALESCE(d.department_name, e.department) AS department,
                        e.employment_type, e.job_tier, e.salary_grade_code, e.current_salary,
                        e.employment_status, e.regularization_status, e.manager_id,
                        e.performance_rating, e.date_hired
                 FROM employees e
                 LEFT JOIN departments d ON d.id = e.department_id
                 ORDER BY e.id'
            )->fetchAll();
            $insertRecommendation = $this->connection->prepare(
                'INSERT INTO compensation_recommendations
                    (cycle_id, employee_id, manager_id, department_id, department, grade_code, current_salary,
                     grade_minimum, grade_midpoint, grade_maximum, compa_ratio, performance_rating,
                     matrix_increase_percentage, suggested_new_salary, flag)
                 VALUES
                    (:cycle_id, :employee_id, :manager_id, :department_id, :department, :grade_code, :current_salary,
                     :grade_minimum, :grade_midpoint, :grade_maximum, :compa_ratio, :performance_rating,
                     :matrix_percentage, :suggested_salary, :flag)'
            );
            $insertExclusion = $this->connection->prepare(
                'INSERT INTO compensation_exclusions (cycle_id, employee_id, exclusion_reason)
                 VALUES (:cycle_id, :employee_id, :reason)'
            );
            $eligible = 0;
            $excluded = 0;
            foreach ($employees as $employee) {
                $reason = $this->eligibilityExclusionReason($employee, $cycle);
                $grade = null;
                if ($reason === null) {
                    $gradeKey = $employee['job_tier'] . '|' . $employee['salary_grade_code'];
                    $grade = $grades[$gradeKey] ?? null;
                    if ($grade === null) {
                        $reason = 'No matching salary grade in selected structure';
                    }
                }
                if ($reason !== null) {
                    $insertExclusion->execute([
                        'cycle_id' => $cycleId,
                        'employee_id' => $employee['id'],
                        'reason' => $reason,
                    ]);
                    $excluded++;
                    continue;
                }

                $salaryCents = $this->moneyToCents((string) ($employee['current_salary'] ?? ''), 'Current salary');
                $midpointCents = $this->moneyToCents((string) $grade['midpoint_salary'], 'Grade midpoint');
                $ratioHundredths = $midpointCents === 0
                    ? 0
                    : (int) round($salaryCents * 100 / $midpointCents, 0, PHP_ROUND_HALF_UP);
                if ($reason === null && $ratioHundredths > 9999) {
                    $reason = 'Compa-ratio exceeds supported range';
                }

                $rating = (int) ($employee['performance_rating'] ?? 0);
                $matchedBand = null;
                if ($reason === null) {
                    foreach ($bandsByRating[$rating] ?? [] as $band) {
                        $from = $this->ratioToHundredths((string) $band['compa_ratio_from'], 'Matrix band start');
                        $to = $band['compa_ratio_to'] === null
                            ? null
                            : $this->ratioToHundredths((string) $band['compa_ratio_to'], 'Matrix band end');
                        if ($ratioHundredths >= $from && ($to === null || $ratioHundredths < $to)) {
                            $matchedBand = $band;
                            break;
                        }
                    }
                    if ($matchedBand === null) {
                        $reason = 'No merit matrix band matches compa-ratio';
                    }
                }

                if ($reason !== null) {
                    $insertExclusion->execute([
                        'cycle_id' => $cycleId,
                        'employee_id' => $employee['id'],
                        'reason' => $reason,
                    ]);
                    $excluded++;
                    continue;
                }

                $increaseHundredths = $this->percentageToHundredths(
                    (string) $matchedBand['increase_percentage']
                );
                $suggestedCents = (int) round(
                    $salaryCents * (10000 + $increaseHundredths) / 10000,
                    0,
                    PHP_ROUND_HALF_UP
                );
                $minimumCents = $this->moneyToCents((string) $grade['minimum_salary'], 'Grade minimum');
                $maximumCents = $this->moneyToCents((string) $grade['maximum_salary'], 'Grade maximum');
                $flag = $suggestedCents > $maximumCents
                    ? 'Exceeds Max'
                    : ($salaryCents < $minimumCents ? 'Below Min' : 'None');

                $insertRecommendation->execute([
                    'cycle_id' => $cycleId,
                    'employee_id' => $employee['id'],
                    'manager_id' => $employee['manager_id'],
                    'department_id' => $employee['department_id'],
                    'department' => $employee['department'],
                    'grade_code' => $grade['grade_code'],
                    'current_salary' => $this->centsToMoney($salaryCents),
                    'grade_minimum' => $this->centsToMoney($minimumCents),
                    'grade_midpoint' => $this->centsToMoney($midpointCents),
                    'grade_maximum' => $this->centsToMoney($maximumCents),
                    'compa_ratio' => $this->hundredthsToRatio($ratioHundredths),
                    'performance_rating' => $rating,
                    'matrix_percentage' => $this->hundredthsToRatio($increaseHundredths),
                    'suggested_salary' => $this->centsToMoney($suggestedCents),
                    'flag' => $flag,
                ]);
                $eligible++;
            }

            $this->audit(
                $cycleId,
                $actorId,
                'Recommendations generated',
                $eligible . ' eligible; ' . $excluded . ' excluded'
            );
            $this->connection->commit();

            return ['eligible' => $eligible, 'excluded' => $excluded];
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }
    }

    public function openCycle(int $cycleId, int $actorId): void
    {
        $this->connection->beginTransaction();
        try {
            $cycle = $this->findCycleForUpdate($cycleId);
            if ($cycle === null || $cycle['status'] !== 'Draft') {
                throw new DomainException('Only a Draft cycle can be opened.');
            }
            if ((float) $cycle['total_budget'] <= 0) {
                throw new DomainException('A cycle needs a positive budget before it can be opened.');
            }
            $count = $this->connection->prepare(
                'SELECT COUNT(*) FROM compensation_recommendations WHERE cycle_id = :cycle_id'
            );
            $count->execute(['cycle_id' => $cycleId]);
            if ((int) $count->fetchColumn() < 1) {
                throw new DomainException('Generate at least one eligible recommendation before opening the cycle.');
            }

            $otherOpen = $this->connection->prepare(
                'SELECT 1 FROM compensation_cycles
                 WHERE cycle_type = :cycle_type AND status = \'Open\' AND id <> :cycle_id
                 LIMIT 1' . $this->lockClause()
            );
            $otherOpen->execute([
                'cycle_type' => $cycle['cycle_type'],
                'cycle_id' => $cycleId,
            ]);
            if ($otherOpen->fetchColumn() !== false) {
                throw new DomainException('Another cycle of this type is already Open.');
            }

            $unmappedManagers = $this->connection->prepare(
                'SELECT COUNT(DISTINCT r.manager_id)
                 FROM compensation_recommendations r
                 LEFT JOIN users u
                    ON u.employee_id = r.manager_id AND u.is_active = 1 AND u.role = \'manager\'
                 WHERE r.cycle_id = :cycle_id AND u.id IS NULL'
            );
            $unmappedManagers->execute(['cycle_id' => $cycleId]);
            if ((int) $unmappedManagers->fetchColumn() > 0) {
                throw new DomainException('Link an active manager login to every assigned manager employee before opening this cycle.');
            }

            $this->connection->prepare(
                'UPDATE compensation_cycles SET status = \'Open\' WHERE id = :id'
            )->execute(['id' => $cycleId]);
            $this->audit($cycleId, $actorId, 'Cycle opened', (string) $cycle['cycle_name']);
            $this->connection->commit();
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }
    }

    public function saveManagerRecommendation(
        int $cycleId,
        int $recommendationId,
        int $managerEmployeeId,
        mixed $finalPercentage,
        mixed $managerComment,
        int $actorId
    ): void {
        if (!is_string($managerComment)) {
            throw new InvalidArgumentException('Manager comment must be text.');
        }
        $managerComment = trim($managerComment);
        if (mb_strlen($managerComment) > 500) {
            throw new InvalidArgumentException('Manager comments must be 500 characters or fewer.');
        }
        $increaseHundredths = $this->percentageToHundredths($finalPercentage);

        $this->connection->beginTransaction();
        try {
            $cycle = $this->findCycleForUpdate($cycleId);
            if ($cycle === null || $cycle['status'] !== 'Open') {
                throw new DomainException('Manager recommendations can only be edited while the cycle is Open.');
            }
            $statement = $this->connection->prepare(
                'SELECT id, current_salary, grade_maximum, matrix_increase_percentage, status
                 FROM compensation_recommendations
                 WHERE id = :id AND cycle_id = :cycle_id AND manager_id = :manager_id
                 ' . $this->lockClause()
            );
            $statement->execute([
                'id' => $recommendationId,
                'cycle_id' => $cycleId,
                'manager_id' => $managerEmployeeId,
            ]);
            $recommendation = $statement->fetch();
            if ($recommendation === false) {
                throw new DomainException('That recommendation is not assigned to your team.');
            }
            if (!in_array($recommendation['status'], ['Pending', 'Returned'], true)) {
                throw new DomainException('Only Pending or Returned recommendations can be edited.');
            }

            $salaryCents = $this->moneyToCents((string) $recommendation['current_salary'], 'Current salary');
            $finalSalaryCents = (int) round(
                $salaryCents * (10000 + $increaseHundredths) / 10000,
                0,
                PHP_ROUND_HALF_UP
            );
            $matrixHundredths = $this->percentageToHundredths(
                (string) $recommendation['matrix_increase_percentage']
            );
            $update = $this->connection->prepare(
                'UPDATE compensation_recommendations
                 SET final_increase_percentage = :percentage, final_new_salary = :salary,
                     manager_comment = :comment, override_flag = :override
                 WHERE id = :id'
            );
            $update->execute([
                'percentage' => $this->hundredthsToRatio($increaseHundredths),
                'salary' => $this->centsToMoney($finalSalaryCents),
                'comment' => $managerComment === '' ? null : $managerComment,
                'override' => $increaseHundredths === $matrixHundredths ? 0 : 1,
                'id' => $recommendationId,
            ]);
            $this->audit($cycleId, $actorId, 'Manager recommendation updated', 'Recommendation ' . $recommendationId);
            $this->connection->commit();
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }
    }

    public function submitManagerTeam(int $cycleId, int $managerEmployeeId, int $actorId): void
    {
        $this->connection->beginTransaction();
        try {
            $cycle = $this->findCycleForUpdate($cycleId);
            if ($cycle === null || $cycle['status'] !== 'Open') {
                throw new DomainException('A team can only be submitted while the cycle is Open.');
            }
            $statement = $this->connection->prepare(
                'SELECT * FROM compensation_recommendations
                 WHERE cycle_id = :cycle_id AND manager_id = :manager_id
                 ORDER BY id' . $this->lockClause()
            );
            $statement->execute([
                'cycle_id' => $cycleId,
                'manager_id' => $managerEmployeeId,
            ]);
            $team = $statement->fetchAll();
            if ($team === []) {
                throw new DomainException('This manager has no recommendations in the selected cycle.');
            }

            foreach ($team as $recommendation) {
                if ($recommendation['final_increase_percentage'] === null || $recommendation['final_new_salary'] === null) {
                    throw new DomainException('Enter a final increase percentage for every direct report before submitting the team.');
                }
                if (in_array($recommendation['status'], ['Pending', 'Returned'], true)) {
                    $finalSalaryCents = $this->moneyToCents((string) $recommendation['final_new_salary'], 'Final salary');
                    $maximumCents = $this->moneyToCents((string) $recommendation['grade_maximum'], 'Grade maximum');
                    if ($finalSalaryCents > $maximumCents) {
                        throw new DomainException('Reduce recommendations above the grade maximum before submitting.');
                    }
                    if (
                        (int) $recommendation['override_flag'] === 1
                        && trim((string) ($recommendation['manager_comment'] ?? '')) === ''
                    ) {
                        throw new DomainException('Add a comment for every increase that differs from the merit matrix.');
                    }
                }
            }

            $this->validateTeamBudget(
                $cycleId,
                $managerEmployeeId,
                $this->moneyToCents((string) $cycle['total_budget'], 'Cycle budget'),
                $team
            );
            $update = $this->connection->prepare(
                'UPDATE compensation_recommendations
                 SET status = \'Submitted\', submitted_by = :user_id, submitted_at = CURRENT_TIMESTAMP
                 WHERE cycle_id = :cycle_id AND manager_id = :manager_id
                   AND status IN (\'Pending\', \'Returned\')'
            );
            $update->execute([
                'user_id' => $actorId,
                'cycle_id' => $cycleId,
                'manager_id' => $managerEmployeeId,
            ]);
            if ($update->rowCount() === 0) {
                throw new DomainException('There are no Pending or Returned rows to submit.');
            }
            $this->audit($cycleId, $actorId, 'Manager team submitted', 'Manager employee ' . $managerEmployeeId);
            $this->connection->commit();
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }
    }

    public function reviewRecommendation(
        int $cycleId,
        int $recommendationId,
        string $action,
        string $note,
        int $actorId
    ): void {
        if (!in_array($action, ['approve', 'return'], true)) {
            throw new InvalidArgumentException('Choose Approve or Return for this recommendation.');
        }
        $note = trim($note);
        if (mb_strlen($note) > 500) {
            throw new InvalidArgumentException('Review notes must be 500 characters or fewer.');
        }
        if ($action === 'return' && $note === '') {
            throw new InvalidArgumentException('Add a note when returning a recommendation.');
        }

        $this->connection->beginTransaction();
        try {
            $cycle = $this->findCycleForUpdate($cycleId);
            if ($cycle === null || $cycle['status'] !== 'Open') {
                throw new DomainException('Recommendations can only be reviewed while the cycle is Open.');
            }
            $status = $action === 'approve' ? 'Approved' : 'Returned';
            $statement = $this->connection->prepare(
                'UPDATE compensation_recommendations
                 SET status = :status, reviewed_by = :user_id, reviewed_at = CURRENT_TIMESTAMP,
                     review_note = :note
                 WHERE id = :id AND cycle_id = :cycle_id AND status = \'Submitted\''
            );
            $statement->execute([
                'status' => $status,
                'user_id' => $actorId,
                'note' => $note === '' ? null : $note,
                'id' => $recommendationId,
                'cycle_id' => $cycleId,
            ]);
            if ($statement->rowCount() === 0) {
                throw new DomainException('Only a Submitted recommendation can be reviewed.');
            }
            $this->audit($cycleId, $actorId, 'Recommendation ' . strtolower($status), 'Recommendation ' . $recommendationId);
            $this->connection->commit();
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }
    }

    public function finalizeCycleReview(int $cycleId, int $actorId): void
    {
        $this->connection->beginTransaction();
        try {
            $cycle = $this->findCycleForUpdate($cycleId);
            if ($cycle === null || $cycle['status'] !== 'Open') {
                throw new DomainException('Only an Open cycle can be submitted for final approval.');
            }
            $statement = $this->connection->prepare(
                'SELECT COUNT(*) FROM compensation_recommendations
                 WHERE cycle_id = :cycle_id AND status <> \'Approved\''
            );
            $statement->execute(['cycle_id' => $cycleId]);
            if ((int) $statement->fetchColumn() !== 0) {
                throw new DomainException('Every recommendation must be approved before submitting the cycle for final approval.');
            }
            $this->connection->prepare(
                'UPDATE compensation_cycles SET status = \'Submitted\' WHERE id = :id'
            )->execute(['id' => $cycleId]);
            $this->audit($cycleId, $actorId, 'Cycle submitted for final approval', 'All recommendations approved');
            $this->connection->commit();
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }
    }

    public function advanceCycle(int $cycleId, string $action, int $actorId): void
    {
        $transitions = [
            'approve_cycle' => ['Submitted', 'Approved'],
            'lock_cycle' => ['Approved', 'Locked'],
            'publish_cycle' => ['Locked', 'Published'],
        ];
        if (!isset($transitions[$action])) {
            throw new InvalidArgumentException('Choose a valid cycle status action.');
        }
        [$from, $to] = $transitions[$action];
        $this->transitionCycle($cycleId, $from, $to, $actorId, 'Cycle status changed to ' . $to);
    }

    public function topUpBudget(int $cycleId, mixed $amount, int $actorId): void
    {
        $amountCents = $this->moneyToCents($amount, 'Budget top-up');
        if ($amountCents < 1) {
            throw new InvalidArgumentException('Enter a top-up amount greater than zero.');
        }
        $this->connection->beginTransaction();
        try {
            $cycle = $this->findCycleForUpdate($cycleId);
            if ($cycle === null || $cycle['status'] !== 'Open') {
                throw new DomainException('Only an Open cycle can receive a budget top-up.');
            }
            $newBudget = $this->moneyToCents((string) $cycle['total_budget'], 'Cycle budget') + $amountCents;
            if ($newBudget > 999999999999) {
                throw new InvalidArgumentException('The topped-up budget exceeds the supported amount.');
            }
            $this->connection->prepare(
                'UPDATE compensation_cycles SET total_budget = :budget WHERE id = :id'
            )->execute([
                'budget' => $this->centsToMoney($newBudget),
                'id' => $cycleId,
            ]);
            $this->audit($cycleId, $actorId, 'Cycle budget topped up', $this->centsToMoney($amountCents));
            $this->connection->commit();
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }
    }

    private function eligiblePayrollCents(string $cutoffDate, int $minimumRating, int $structureId): int
    {
        $isMysql = $this->connection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $jobTierComparison = $isMysql
            ? 'g.target_job_tier COLLATE utf8mb4_general_ci = e.job_tier COLLATE utf8mb4_general_ci'
            : 'g.target_job_tier = e.job_tier';
        $gradeCodeComparison = $isMysql
            ? 'g.grade_code COLLATE utf8mb4_general_ci = e.salary_grade_code COLLATE utf8mb4_general_ci'
            : 'g.grade_code = e.salary_grade_code';
        $statement = $this->connection->prepare(
            'SELECT COALESCE(SUM(e.current_salary * 100), 0)
             FROM employees e
             WHERE e.employment_status = \'Active\'
               AND e.employment_type = \'Full-time\'
               AND e.regularization_status = \'Regularized\'
               AND e.date_hired IS NOT NULL AND e.date_hired <= :cutoff_date
               AND e.performance_rating BETWEEN :minimum_rating AND 5
               AND e.current_salary IS NOT NULL
               AND e.manager_id IS NOT NULL
               AND EXISTS (
                    SELECT 1 FROM compensation_salary_grades g
                    WHERE g.structure_id = :structure_id
                      AND ' . $jobTierComparison . '
                       AND ' . $gradeCodeComparison . '
               )'
        );
        $statement->execute([
            'structure_id' => $structureId,
            'cutoff_date' => $cutoffDate,
            'minimum_rating' => $minimumRating,
        ]);

        return (int) round((float) $statement->fetchColumn());
    }

    private function eligibilityExclusionReason(array $employee, array $cycle): ?string
    {
        if ($employee['employment_status'] !== 'Active') {
            return 'Employment status is not Active';
        }
        if ($employee['employment_type'] !== 'Full-time') {
            return 'Employment type is not Full-time';
        }
        if ($employee['regularization_status'] === null) {
            return 'Regularization status is missing';
        }
        if ($employee['regularization_status'] !== 'Regularized') {
            return 'Not Regularized';
        }
        if ($employee['date_hired'] === null) {
            return 'Hire date is missing';
        }
        if ($employee['date_hired'] > $cycle['eligibility_cutoff_date']) {
            return 'Hired after eligibility cutoff';
        }
        if ($employee['performance_rating'] === null) {
            return 'No performance rating';
        }
        if ((int) $employee['performance_rating'] < 1 || (int) $employee['performance_rating'] > 5) {
            return 'Performance rating is outside 1 to 5';
        }
        if ((int) $employee['performance_rating'] < (int) $cycle['minimum_performance_rating']) {
            return 'Performance rating below cycle minimum';
        }
        if ($employee['current_salary'] === null) {
            return 'Current salary is missing';
        }
        if ($employee['salary_grade_code'] === null || trim((string) $employee['salary_grade_code']) === '') {
            return 'Salary grade is not assigned';
        }
        if ($employee['manager_id'] === null) {
            return 'Manager is not assigned';
        }

        return null;
    }

    private function validateTeamBudget(int $cycleId, int $managerId, int $totalBudgetCents, array $team): void
    {
        $previousTotals = $this->connection->prepare(
            'SELECT department_id, department,
                    SUM((final_new_salary - current_salary) * 100) AS total
             FROM compensation_recommendations
             WHERE cycle_id = :cycle_id AND manager_id <> :manager_id
               AND status IN (\'Submitted\', \'Approved\')
             GROUP BY department_id, department'
        );
        $previousTotals->execute([
            'cycle_id' => $cycleId,
            'manager_id' => $managerId,
        ]);
        $departmentTotals = [];
        $departmentNames = [];
        $overallCents = 0;
        foreach ($previousTotals->fetchAll() as $row) {
            $amount = (int) round((float) $row['total']);
            $departmentId = $row['department_id'] === null ? 0 : (int) $row['department_id'];
            $departmentTotals[$departmentId] = $amount;
            $departmentNames[$departmentId] = (string) ($row['department'] ?? 'Unassigned');
            $overallCents += $amount;
        }

        foreach ($team as $recommendation) {
            if ($recommendation['final_new_salary'] === null) {
                continue;
            }
            $raiseCents = $this->moneyToCents((string) $recommendation['final_new_salary'], 'Final salary')
                - $this->moneyToCents((string) $recommendation['current_salary'], 'Current salary');
            $departmentId = $recommendation['department_id'] === null
                ? 0
                : (int) $recommendation['department_id'];
            $departmentNames[$departmentId] = (string) ($recommendation['department'] ?? 'Unassigned');
            $departmentTotals[$departmentId] =
                ($departmentTotals[$departmentId] ?? 0) + $raiseCents;
            $overallCents += $raiseCents;
        }

        if ($overallCents > $totalBudgetCents) {
            throw new DomainException('The submitted recommendations exceed the total cycle budget.');
        }

        $allocationStatement = $this->connection->prepare(
            'SELECT b.department_id, COALESCE(d.department_name, b.department) AS department,
                    b.allocated_amount
             FROM compensation_department_budgets b
             LEFT JOIN departments d ON d.id = b.department_id
             WHERE b.cycle_id = :cycle_id'
        );
        $allocationStatement->execute(['cycle_id' => $cycleId]);
        $allocations = [];
        foreach ($allocationStatement->fetchAll() as $allocation) {
            $departmentId = $allocation['department_id'] === null ? 0 : (int) $allocation['department_id'];
            $allocations[$departmentId] =
                $this->moneyToCents((string) $allocation['allocated_amount'], 'Department allocation');
            $departmentNames[$departmentId] = (string) ($allocation['department'] ?? 'Unassigned');
        }
        foreach ($departmentTotals as $departmentId => $amount) {
            $limit = $allocations[$departmentId] ?? $totalBudgetCents;
            if ($amount > $limit) {
                $departmentName = $departmentNames[$departmentId] ?? 'Unassigned';
                throw new DomainException('The submitted recommendations exceed the budget for ' . $departmentName . '.');
            }
        }
    }

    private function transitionCycle(
        int $cycleId,
        string $expectedStatus,
        string $newStatus,
        int $actorId,
        string $details
    ): void {
        $this->connection->beginTransaction();
        try {
            $cycle = $this->findCycleForUpdate($cycleId);
            if ($cycle === null || $cycle['status'] !== $expectedStatus) {
                throw new DomainException('This cycle cannot move to ' . $newStatus . ' from its current status.');
            }
            $statement = $this->connection->prepare(
                'UPDATE compensation_cycles SET status = :status WHERE id = :id'
            );
            $statement->execute(['status' => $newStatus, 'id' => $cycleId]);
            $this->audit($cycleId, $actorId, 'Cycle status changed', $details);
            $this->connection->commit();
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }
    }

    private function isActiveVersion(string $table, int $id): bool
    {
        if (!in_array($table, ['compensation_salary_structures', 'compensation_merit_matrices'], true)) {
            throw new LogicException('Invalid compensation version table.');
        }
        $statement = $this->connection->prepare(
            'SELECT 1 FROM ' . $table . ' WHERE id = :id AND status = \'Active\''
        );
        $statement->execute(['id' => $id]);

        return $statement->fetchColumn() !== false;
    }

    private function findCycleForUpdate(int $cycleId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT * FROM compensation_cycles WHERE id = :id' . $this->lockClause()
        );
        $statement->execute(['id' => $cycleId]);
        $cycle = $statement->fetch();

        return $cycle === false ? null : $cycle;
    }

    private function lockClause(): string
    {
        return $this->connection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? ''
            : ' FOR UPDATE';
    }

    private function audit(?int $cycleId, int $actorId, string $action, string $details): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO compensation_audit_logs (cycle_id, actor_id, action, details)
             VALUES (:cycle_id, :actor_id, :action, :details)'
        );
        $statement->execute([
            'cycle_id' => $cycleId,
            'actor_id' => $actorId,
            'action' => $action,
            'details' => $details,
        ]);
    }

    private function transaction(callable $operation): int
    {
        $this->connection->beginTransaction();
        try {
            $id = $operation();
            $this->connection->commit();
            return $id;
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }
    }

    private function validateDate(string $date, string $label): void
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException($label . ' must be a valid date.');
        }
    }

    private function inputString(array $input, string $key, string $label): string
    {
        $value = $input[$key] ?? null;
        if (!is_string($value)) {
            throw new InvalidArgumentException($label . ' is required.');
        }

        return trim($value);
    }

    private function moneyToCents(mixed $value, string $label): int
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw new InvalidArgumentException($label . ' must be a non-negative amount with up to two decimals.');
        }
        $value = trim((string) $value);
        if (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $value)) {
            throw new InvalidArgumentException($label . ' must be a non-negative amount with up to two decimals.');
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '0');
        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    private function percentageToHundredths(mixed $value): int
    {
        $percentage = $this->moneyToCents($value, 'Increase percentage');
        if ($percentage > 99999) {
            throw new InvalidArgumentException('Increase percentages must not exceed 999.99%.');
        }
        return $percentage;
    }

    private function ratioToHundredths(mixed $value, string $label): int
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw new InvalidArgumentException($label . ' must be from 0.00 to 99.99.');
        }
        $value = trim((string) $value);
        if (!preg_match('/^\d{1,2}(?:\.\d{1,2})?$/', $value)) {
            throw new InvalidArgumentException($label . ' must be from 0.00 to 99.99.');
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '0');
        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    private function centsToMoney(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    private function hundredthsToRatio(int $hundredths): string
    {
        return number_format($hundredths / 100, 2, '.', '');
    }
}
