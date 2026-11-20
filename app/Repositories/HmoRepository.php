<?php

class HmoRepository
{
    public function __construct(private PDO $connection)
    {
    }

    public function listPlans(): array
    {
        return $this->connection->query(
            'SELECT id, provider_name, plan_tier_name, target_job_tier, max_coverage_limit,
                    room_and_board, annual_premium_cost, dependent_monthly_premium,
                    parent_dependents_allowed, is_active
             FROM hmo_providers
             ORDER BY is_active DESC, target_job_tier, provider_name, plan_tier_name'
        )->fetchAll();
    }

    public function searchEligibleEmployees(string $searchTerm): array
    {
        $statement = $this->connection->prepare(
            'SELECT e.id, e.employee_code, e.first_name, e.last_name,
                    COALESCE(d.department_name, e.department) AS department, e.job_tier
             FROM employees e
             LEFT JOIN departments d ON d.id = e.department_id
             WHERE e.employment_type = \'Full-time\'
               AND e.employment_status = \'Active\'
               AND NOT EXISTS (
                   SELECT 1 FROM employee_hmo_enrollment h
                   WHERE h.employee_id = e.id AND h.enrollment_status = \'Active\'
               )
               AND (
                   e.employee_code LIKE :search_code
                   OR e.first_name LIKE :search_first_name
                   OR e.last_name LIKE :search_last_name
                   OR CONCAT(e.first_name, \' \', e.last_name) LIKE :search_full_name
                   OR COALESCE(d.department_name, e.department) LIKE :search_department
               )
             ORDER BY e.last_name, e.first_name
             LIMIT 20'
        );
        $search = '%' . addcslashes($searchTerm, '\\%_') . '%';
        $statement->execute([
            'search_code' => $search,
            'search_first_name' => $search,
            'search_last_name' => $search,
            'search_full_name' => $search,
            'search_department' => $search,
        ]);

        return $statement->fetchAll();
    }

    public function listEnrollments(): array
    {
        return $this->connection->query(
            'SELECT h.id, h.employee_id, h.hmo_provider_id, h.enrollment_status,
                    h.effectivity_date, h.terminated_at, h.dependent_cost_share_percent,
                    e.employee_code, e.first_name, e.last_name,
                    COALESCE(dp.department_name, e.department) AS department, e.job_tier,
                    p.provider_name, p.plan_tier_name, h.annual_premium_cost,
                    SUM(CASE WHEN d.is_active = 1 THEN 1 ELSE 0 END) AS dependent_count,
                    COALESCE(SUM(CASE WHEN d.is_active = 1 THEN d.monthly_premium_amount ELSE 0 END), 0) AS dependent_monthly_premium,
                    COALESCE(SUM(CASE WHEN d.is_active = 1 THEN d.monthly_deduction_amount ELSE 0 END), 0) AS dependent_monthly_deduction
             FROM employee_hmo_enrollment h
             INNER JOIN employees e ON e.id = h.employee_id
             LEFT JOIN departments dp ON dp.id = e.department_id
             INNER JOIN hmo_providers p ON p.id = h.hmo_provider_id
             LEFT JOIN hmo_dependents d ON d.employee_hmo_id = h.id
             GROUP BY h.id, h.employee_id, h.hmo_provider_id, h.enrollment_status,
                      h.effectivity_date, h.terminated_at, h.dependent_cost_share_percent,
                      e.employee_code, e.first_name, e.last_name, dp.department_name,
                      e.department, e.job_tier,
                      p.provider_name, p.plan_tier_name, h.annual_premium_cost
             ORDER BY h.enrollment_status = \'Active\' DESC, h.effectivity_date DESC, h.id DESC'
        )->fetchAll();
    }

    public function listDependents(): array
    {
        return $this->connection->query(
            'SELECT id, employee_hmo_id, dependent_name, dependent_last_name,
                    dependent_first_name, dependent_middle_initial, dependent_name_extension,
                    dependent_age, relationship, sex, civil_status, birth_certificate_path,
                    monthly_premium_amount, monthly_deduction_amount, is_active
             FROM hmo_dependents
             ORDER BY employee_hmo_id, id'
        )->fetchAll();
    }

    public function findBirthCertificate(int $dependentId): ?string
    {
        $statement = $this->connection->prepare(
            'SELECT birth_certificate_path FROM hmo_dependents WHERE id = :id'
        );
        $statement->execute(['id' => $dependentId]);
        $path = $statement->fetchColumn();

        return $path === false || $path === null ? null : (string) $path;
    }

    public function listDeductions(string $month): array
    {
        $statement = $this->connection->prepare(
            'SELECT q.id, q.dependent_id, q.payroll_month, q.amount, q.status, q.created_at,
                    e.employee_code, e.first_name, e.last_name, d.dependent_name, d.relationship
             FROM hmo_payroll_deductions q
             INNER JOIN hmo_dependents d ON d.id = q.dependent_id
             INNER JOIN employee_hmo_enrollment h ON h.id = q.employee_hmo_id
             INNER JOIN employees e ON e.id = h.employee_id
             WHERE q.payroll_month = :payroll_month
             ORDER BY e.last_name, e.first_name, d.dependent_name'
        );
        $statement->execute(['payroll_month' => $month . '-01']);

        return $statement->fetchAll();
    }

    public function getDeductionCounts(string $month): array
    {
        $counts = ['Queued' => 0, 'Exported' => 0, 'Processed' => 0, 'Voided' => 0];
        $statement = $this->connection->prepare(
            'SELECT status, COUNT(*) AS total, COALESCE(SUM(amount), 0) AS amount
             FROM hmo_payroll_deductions
             WHERE payroll_month = :payroll_month
             GROUP BY status'
        );
        $statement->execute(['payroll_month' => $month . '-01']);
        $amounts = $counts;
        foreach ($statement->fetchAll() as $row) {
            if (array_key_exists($row['status'], $counts)) {
                $counts[$row['status']] = (int) $row['total'];
                $amounts[$row['status']] = (float) $row['amount'];
            }
        }

        return ['counts' => $counts, 'amounts' => $amounts];
    }

    public function listAuditLogs(): array
    {
        return $this->connection->query(
            'SELECT actor_name, action, entity_type, entity_id, details, created_at
             FROM hmo_audit_logs
             ORDER BY id DESC
             LIMIT 50'
        )->fetchAll();
    }

    public function listAnnualEnrollments(int $year): array
    {
        $statement = $this->connection->prepare(
            'SELECT h.id, h.enrollment_status, h.effectivity_date, h.terminated_at,
                    h.annual_premium_cost,
                    e.employee_code, e.first_name, e.last_name, p.provider_name, p.plan_tier_name
             FROM employee_hmo_enrollment h
             INNER JOIN hmo_providers p ON p.id = h.hmo_provider_id
             INNER JOIN employees e ON e.id = h.employee_id
             WHERE h.effectivity_date <= :year_end
               AND (h.terminated_at IS NULL OR h.terminated_at >= :year_start)'
        );
        $statement->execute([
            'year_start' => $year . '-01-01',
            'year_end' => $year . '-12-31',
        ]);

        return $statement->fetchAll();
    }

    public function createPlan(array $plan): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO hmo_providers (
                provider_name, plan_tier_name, target_job_tier, max_coverage_limit,
                room_and_board, annual_premium_cost, dependent_monthly_premium,
                parent_dependents_allowed, is_active
             ) VALUES (
                :provider_name, :plan_tier_name, :target_job_tier, :max_coverage_limit,
                :room_and_board, :annual_premium_cost, :dependent_monthly_premium,
                :parent_dependents_allowed, :is_active
             )'
        );
        $statement->execute($plan);

        return (int) $this->connection->lastInsertId();
    }

    public function updatePlan(int $planId, array $plan): void
    {
        $plan['id'] = $planId;
        $statement = $this->connection->prepare(
            'UPDATE hmo_providers
             SET provider_name = :provider_name, plan_tier_name = :plan_tier_name,
                 target_job_tier = :target_job_tier, max_coverage_limit = :max_coverage_limit,
                 room_and_board = :room_and_board, annual_premium_cost = :annual_premium_cost,
                 dependent_monthly_premium = :dependent_monthly_premium,
                 parent_dependents_allowed = :parent_dependents_allowed, is_active = :is_active
             WHERE id = :id'
        );
        $statement->execute($plan);
        if ($statement->rowCount() === 0 && !$this->planExists($planId)) {
            throw new DomainException('That HMO plan could not be found.');
        }
    }

    public function planExists(int $planId): bool
    {
        $statement = $this->connection->prepare('SELECT 1 FROM hmo_providers WHERE id = :id');
        $statement->execute(['id' => $planId]);

        return $statement->fetchColumn() !== false;
    }

    public function findEmployeeForUpdate(int $employeeId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, employment_type, employment_status, job_tier, date_hired
             FROM employees WHERE id = :id FOR UPDATE'
        );
        $statement->execute(['id' => $employeeId]);
        $employee = $statement->fetch();

        return $employee === false ? null : $employee;
    }

    public function findPlanForUpdate(int $planId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, target_job_tier, parent_dependents_allowed, is_active, annual_premium_cost,
                    dependent_monthly_premium
             FROM hmo_providers WHERE id = :id FOR UPDATE'
        );
        $statement->execute(['id' => $planId]);
        $plan = $statement->fetch();

        return $plan === false ? null : $plan;
    }

    public function hasActiveEnrollment(int $employeeId): bool
    {
        $statement = $this->connection->prepare(
            'SELECT 1 FROM employee_hmo_enrollment
             WHERE employee_id = :employee_id AND enrollment_status = \'Active\'
             LIMIT 1'
             . ' FOR UPDATE'
        );
        $statement->execute(['employee_id' => $employeeId]);

        return $statement->fetchColumn() !== false;
    }

    public function createEnrollment(
        int $employeeId,
        int $planId,
        string $effectivityDate,
        int $sharePercent,
        string $annualPremium
    ): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO employee_hmo_enrollment (
                employee_id, hmo_provider_id, enrollment_status, effectivity_date,
                dependent_cost_share_percent, annual_premium_cost
             ) VALUES (
                :employee_id, :plan_id, \'Active\', :effectivity_date, :share_percent, :annual_premium
             )'
        );
        $statement->execute([
            'employee_id' => $employeeId,
            'plan_id' => $planId,
            'effectivity_date' => $effectivityDate,
            'share_percent' => $sharePercent,
            'annual_premium' => $annualPremium,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    public function createDependent(int $enrollmentId, array $dependent): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO hmo_dependents (
                employee_hmo_id, dependent_name, dependent_last_name, dependent_first_name,
                dependent_middle_initial, dependent_name_extension, dependent_age, relationship,
                sex, civil_status, date_of_birth, birth_certificate_path,
                monthly_premium_amount, monthly_deduction_amount
             ) VALUES (
                :enrollment_id, :dependent_name, :last_name, :first_name,
                :middle_initial, :name_extension, :age, :relationship,
                :sex, :civil_status, NULL, :birth_certificate_path,
                :monthly_premium, :monthly_deduction
             )'
        );
        $statement->execute([
            'enrollment_id' => $enrollmentId,
            'dependent_name' => $dependent['name'],
            'last_name' => $dependent['last_name'],
            'first_name' => $dependent['first_name'],
            'middle_initial' => $dependent['middle_initial'],
            'name_extension' => $dependent['name_extension'],
            'age' => $dependent['age'],
            'relationship' => $dependent['relationship'],
            'sex' => $dependent['sex'],
            'civil_status' => $dependent['civil_status'],
            'birth_certificate_path' => $dependent['birth_certificate_path'],
            'monthly_premium' => number_format($dependent['monthly_premium'], 2, '.', ''),
            'monthly_deduction' => number_format($dependent['monthly_premium'], 2, '.', ''),
        ]);

        return (int) $this->connection->lastInsertId();
    }

    public function findEnrollmentForUpdate(int $enrollmentId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, employee_id, hmo_provider_id, enrollment_status, effectivity_date,
                    terminated_at, annual_premium_cost
             FROM employee_hmo_enrollment WHERE id = :id FOR UPDATE'
        );
        $statement->execute(['id' => $enrollmentId]);
        $enrollment = $statement->fetch();

        return $enrollment === false ? null : $enrollment;
    }

    public function findEnrollmentDependentsForUpdate(int $enrollmentId): array
    {
        $statement = $this->connection->prepare(
            'SELECT id, dependent_name, dependent_last_name, dependent_first_name,
                    dependent_middle_initial, dependent_name_extension, dependent_age,
                    relationship, sex, civil_status, birth_certificate_path,
                    monthly_premium_amount, monthly_deduction_amount
             FROM hmo_dependents
             WHERE employee_hmo_id = :enrollment_id AND is_active = 1
             ORDER BY id
             FOR UPDATE'
        );
        $statement->execute(['enrollment_id' => $enrollmentId]);

        return $statement->fetchAll();
    }

    public function updateEnrollment(int $enrollmentId, int $planId, string $effectivityDate, string $annualPremium): void
    {
        $statement = $this->connection->prepare(
            'UPDATE employee_hmo_enrollment
             SET hmo_provider_id = :plan_id, effectivity_date = :effectivity_date,
                 annual_premium_cost = :annual_premium
             WHERE id = :id AND enrollment_status = \'Active\''
        );
        $statement->execute([
            'plan_id' => $planId,
            'effectivity_date' => $effectivityDate,
            'annual_premium' => $annualPremium,
            'id' => $enrollmentId,
        ]);
        if ($statement->rowCount() === 0 && !$this->activeEnrollmentExists($enrollmentId)) {
            throw new DomainException('This active enrollment could not be updated.');
        }
    }

    public function updateDependent(int $dependentId, int $enrollmentId, array $dependent): void
    {
        $statement = $this->connection->prepare(
            'UPDATE hmo_dependents
             SET dependent_name = :dependent_name, dependent_last_name = :last_name,
                 dependent_first_name = :first_name, dependent_middle_initial = :middle_initial,
                 dependent_name_extension = :name_extension, dependent_age = :age,
                 relationship = :relationship, sex = :sex, civil_status = :civil_status,
                 birth_certificate_path = COALESCE(:birth_certificate_path, birth_certificate_path),
                 monthly_premium_amount = :monthly_premium,
                 monthly_deduction_amount = :monthly_deduction
             WHERE id = :id AND employee_hmo_id = :enrollment_id AND is_active = 1'
        );
        $statement->execute([
            'dependent_name' => $dependent['name'],
            'last_name' => $dependent['last_name'],
            'first_name' => $dependent['first_name'],
            'middle_initial' => $dependent['middle_initial'],
            'name_extension' => $dependent['name_extension'],
            'age' => $dependent['age'],
            'relationship' => $dependent['relationship'],
            'sex' => $dependent['sex'],
            'civil_status' => $dependent['civil_status'],
            'birth_certificate_path' => $dependent['birth_certificate_path'],
            'monthly_premium' => number_format($dependent['monthly_premium'], 2, '.', ''),
            'monthly_deduction' => number_format($dependent['monthly_premium'], 2, '.', ''),
            'id' => $dependentId,
            'enrollment_id' => $enrollmentId,
        ]);
        if ($statement->rowCount() === 0 && !$this->activeDependentExists($dependentId, $enrollmentId)) {
            throw new DomainException('A dependent selected for update could not be found.');
        }
    }

    public function deactivateDependent(int $dependentId, int $enrollmentId): void
    {
        $statement = $this->connection->prepare(
            'UPDATE hmo_dependents
             SET is_active = 0, monthly_deduction_amount = 0
             WHERE id = :id AND employee_hmo_id = :enrollment_id AND is_active = 1'
        );
        $statement->execute(['id' => $dependentId, 'enrollment_id' => $enrollmentId]);
        if ($statement->rowCount() !== 1) {
            throw new DomainException('A dependent selected for removal could not be found.');
        }
        $this->voidQueuedDependentDeductions($dependentId);
    }

    public function voidQueuedDependentDeductions(int $dependentId): int
    {
        $statement = $this->connection->prepare(
            'UPDATE hmo_payroll_deductions
             SET status = \'Voided\'
             WHERE dependent_id = :dependent_id
               AND payroll_month >= DATE_FORMAT(CURDATE(), \'%Y-%m-01\')
               AND status = \'Queued\''
        );
        $statement->execute(['dependent_id' => $dependentId]);

        return $statement->rowCount();
    }

    private function activeEnrollmentExists(int $enrollmentId): bool
    {
        $statement = $this->connection->prepare(
            'SELECT 1 FROM employee_hmo_enrollment
             WHERE id = :id AND enrollment_status = \'Active\''
        );
        $statement->execute(['id' => $enrollmentId]);

        return $statement->fetchColumn() !== false;
    }

    private function activeDependentExists(int $dependentId, int $enrollmentId): bool
    {
        $statement = $this->connection->prepare(
            'SELECT 1 FROM hmo_dependents
             WHERE id = :id AND employee_hmo_id = :enrollment_id AND is_active = 1'
        );
        $statement->execute(['id' => $dependentId, 'enrollment_id' => $enrollmentId]);

        return $statement->fetchColumn() !== false;
    }

    public function terminateEnrollment(int $enrollmentId, string $terminationDate): void
    {
        $statement = $this->connection->prepare(
            'UPDATE employee_hmo_enrollment
             SET enrollment_status = \'Terminated\', terminated_at = :terminated_at
             WHERE id = :id'
        );
        $statement->execute([
            'terminated_at' => $terminationDate,
            'id' => $enrollmentId,
        ]);
    }

    public function voidFutureDeductions(int $enrollmentId, string $terminationDate): int
    {
        $statement = $this->connection->prepare(
            'UPDATE hmo_payroll_deductions
             SET status = \'Voided\'
             WHERE employee_hmo_id = :enrollment_id
               AND payroll_month > DATE_FORMAT(:termination_date, \'%Y-%m-01\')
               AND status = \'Queued\''
        );
        $statement->execute([
            'enrollment_id' => $enrollmentId,
            'termination_date' => $terminationDate,
        ]);

        return $statement->rowCount();
    }

    public function findDependentsForMonth(string $month): array
    {
        $start = $month . '-01';
        $end = (new DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d');
        $statement = $this->connection->prepare(
            'SELECT d.id AS dependent_id, d.employee_hmo_id, d.monthly_deduction_amount
             FROM hmo_dependents d
             INNER JOIN employee_hmo_enrollment h ON h.id = d.employee_hmo_id
             WHERE d.is_active = 1
               AND d.monthly_deduction_amount > 0
               AND h.enrollment_status = \'Active\'
               AND h.effectivity_date <= :month_end
               AND (h.terminated_at IS NULL OR h.terminated_at >= :month_start)'
        );
        $statement->execute(['month_start' => $start, 'month_end' => $end]);

        return $statement->fetchAll();
    }

    public function queueDeduction(string $month, array $deduction): bool
    {
        $statement = $this->connection->prepare(
            'INSERT INTO hmo_payroll_deductions (
                employee_hmo_id, dependent_id, payroll_month, amount, status
             ) VALUES (:enrollment_id, :dependent_id, :payroll_month, :amount, \'Queued\')'
             . ' ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
        );
        $statement->execute([
            'enrollment_id' => $deduction['employee_hmo_id'],
            'dependent_id' => $deduction['dependent_id'],
            'payroll_month' => $month . '-01',
            'amount' => $deduction['monthly_deduction_amount'],
        ]);

        return $statement->rowCount() === 1;
    }

    public function updateDeductionStatuses(string $month, string $from, string $to): int
    {
        $statement = $this->connection->prepare(
            'UPDATE hmo_payroll_deductions
             SET status = :new_status
             WHERE payroll_month = :payroll_month AND status = :old_status'
        );
        $statement->execute([
            'new_status' => $to,
            'payroll_month' => $month . '-01',
            'old_status' => $from,
        ]);

        return $statement->rowCount();
    }

    public function listDeductionsForExport(string $month): array
    {
        $statement = $this->connection->prepare(
            'SELECT q.payroll_month, e.employee_code, e.first_name, e.last_name,
                    d.dependent_name, d.relationship, q.amount, q.status
             FROM hmo_payroll_deductions q
             INNER JOIN hmo_dependents d ON d.id = q.dependent_id
             INNER JOIN employee_hmo_enrollment h ON h.id = q.employee_hmo_id
             INNER JOIN employees e ON e.id = h.employee_id
             WHERE q.payroll_month = :payroll_month
             ORDER BY e.employee_code, d.dependent_name'
        );
        $statement->execute(['payroll_month' => $month . '-01']);

        return $statement->fetchAll();
    }

    public function recordAudit(string $actor, string $action, string $entity, ?int $entityId, array $details): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO hmo_audit_logs (actor_name, action, entity_type, entity_id, details)
             VALUES (:actor_name, :action, :entity_type, :entity_id, :details)'
        );
        $statement->execute([
            'actor_name' => $actor,
            'action' => $action,
            'entity_type' => $entity,
            'entity_id' => $entityId,
            'details' => json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
    }
}
