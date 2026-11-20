<?php

class CompensationRepository
{
    public function __construct(private PDO $connection)
    {
    }

    public function listSalaryStructures(): array
    {
        $statement = $this->connection->query(
            'SELECT s.id, s.version, s.effective_from, s.status, s.created_at,
                    COUNT(g.id) AS grade_count
             FROM compensation_salary_structures s
             LEFT JOIN compensation_salary_grades g ON g.structure_id = s.id
             GROUP BY s.id
             ORDER BY s.version DESC'
        );

        return $statement->fetchAll();
    }

    public function listMeritMatrices(): array
    {
        $statement = $this->connection->query(
            'SELECT m.id, m.version, m.effective_from, m.status, m.created_at,
                    COUNT(b.id) AS band_count
             FROM compensation_merit_matrices m
             LEFT JOIN compensation_merit_bands b ON b.matrix_id = m.id
             GROUP BY m.id
             ORDER BY m.version DESC'
        );

        return $statement->fetchAll();
    }

    public function listArchivedSalaryStructureGrades(): array
    {
        $statement = $this->connection->query(
            'SELECT g.structure_id, g.grade_code, g.target_job_tier,
                    g.minimum_salary, g.midpoint_salary, g.maximum_salary
             FROM compensation_salary_grades g
             INNER JOIN compensation_salary_structures s ON s.id = g.structure_id
             WHERE s.status = \'Archived\'
             ORDER BY s.version DESC, g.target_job_tier, g.grade_code'
        );

        return $statement->fetchAll();
    }

    public function listArchivedMeritMatrixBands(): array
    {
        $statement = $this->connection->query(
            'SELECT b.matrix_id, b.performance_rating, b.compa_ratio_from,
                    b.compa_ratio_to, b.increase_percentage
             FROM compensation_merit_bands b
             INNER JOIN compensation_merit_matrices m ON m.id = b.matrix_id
             WHERE m.status = \'Archived\'
             ORDER BY m.version DESC, b.performance_rating DESC, b.compa_ratio_from'
        );

        return $statement->fetchAll();
    }

    public function listCycles(bool $workflowSchemaReady): array
    {
        $recommendationCount = $workflowSchemaReady
            ? '(SELECT COUNT(*) FROM compensation_recommendations r WHERE r.cycle_id = c.id)'
            : '0';
        $outstandingRecommendationCount = $workflowSchemaReady
            ? '(SELECT COUNT(*) FROM compensation_recommendations r WHERE r.cycle_id = c.id AND r.status <> \'Approved\')'
            : '0';
        $statement = $this->connection->query(
            'SELECT c.id, c.cycle_name, c.cycle_type, c.effective_date,
                    c.eligibility_cutoff_date, c.minimum_performance_rating,
                    c.budget_type, c.budget_value, c.total_budget, c.status,
                    ' . $recommendationCount . ' AS recommendation_count,
                    ' . $outstandingRecommendationCount . ' AS outstanding_recommendation_count,
                    s.version AS structure_version, m.version AS matrix_version,
                    c.created_at
             FROM compensation_cycles c
             INNER JOIN compensation_salary_structures s ON s.id = c.salary_structure_id
             INNER JOIN compensation_merit_matrices m ON m.id = c.merit_matrix_id
             ORDER BY c.created_at DESC, c.id DESC'
        );

        return $statement->fetchAll();
    }

    public function listDepartments(): array
    {
        $statement = $this->connection->query(
            'SELECT id, department_name
             FROM departments
             WHERE status = \'Active\'
             ORDER BY department_name'
        );

        return $statement->fetchAll();
    }

    public function listEmployeesForCompensation(): array
    {
        $statement = $this->connection->query(
            'SELECT e.id, e.employee_code, e.first_name, e.last_name, e.department_id,
                    COALESCE(d.department_name, e.department) AS department,
                    e.employment_type, e.job_tier, e.employment_status, e.date_hired,
                    e.current_salary, e.salary_grade_code, e.regularization_status,
                    e.manager_id, e.performance_rating,
                    m.employee_code AS manager_code, m.first_name AS manager_first_name,
                    m.last_name AS manager_last_name
             FROM employees e
             LEFT JOIN departments d ON d.id = e.department_id
             LEFT JOIN employees m ON m.id = e.manager_id
             ORDER BY e.last_name, e.first_name, e.id'
        );

        return $statement->fetchAll();
    }

    public function listActiveGrades(): array
    {
        $statement = $this->connection->query(
            'SELECT g.grade_code, g.target_job_tier, g.minimum_salary,
                    g.midpoint_salary, g.maximum_salary, s.version
             FROM compensation_salary_grades g
             INNER JOIN compensation_salary_structures s ON s.id = g.structure_id
             WHERE s.status = \'Active\'
             ORDER BY g.target_job_tier, g.grade_code'
        );

        return $statement->fetchAll();
    }

    public function listDepartmentBudgets(int $cycleId): array
    {
        $statement = $this->connection->prepare(
            'SELECT b.department_id, COALESCE(d.department_name, b.department) AS department,
                    b.allocated_amount
             FROM compensation_department_budgets b
             LEFT JOIN departments d ON d.id = b.department_id
             WHERE b.cycle_id = :cycle_id
             ORDER BY department'
        );
        $statement->execute(['cycle_id' => $cycleId]);

        return $statement->fetchAll();
    }

    public function listManagerCycles(): array
    {
        $statement = $this->connection->query(
            'SELECT id, cycle_name, cycle_type, effective_date
             FROM compensation_cycles
             WHERE status = \'Open\'
             ORDER BY effective_date, id'
        );

        return $statement->fetchAll();
    }

    public function hasWorkflowSchema(): bool
    {
        $columns = $this->connection->query(
            'SELECT COUNT(*)
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND ((TABLE_NAME = \'users\' AND COLUMN_NAME = \'employee_id\')
                 OR (TABLE_NAME = \'employees\' AND COLUMN_NAME IN (\'salary_grade_code\', \'department_id\'))
                 OR (TABLE_NAME = \'compensation_department_budgets\' AND COLUMN_NAME = \'department_id\')
                 OR (TABLE_NAME = \'compensation_recommendations\' AND COLUMN_NAME = \'department_id\'))'
        );
        if ((int) $columns->fetchColumn() !== 5) {
            return false;
        }

        $tables = $this->connection->query(
            'SELECT COUNT(*)
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME IN (\'compensation_recommendations\', \'compensation_exclusions\', \'departments\')'
        );

        return (int) $tables->fetchColumn() === 3;
    }

    public function findManagerEmployeeId(int $userId): ?int
    {
        $statement = $this->connection->prepare(
            'SELECT employee_id FROM users
             WHERE id = :user_id AND is_active = 1 AND role = \'manager\''
        );
        $statement->execute(['user_id' => $userId]);
        $employeeId = $statement->fetchColumn();

        return $employeeId === false || $employeeId === null ? null : (int) $employeeId;
    }

    public function listRecommendations(int $cycleId, ?int $managerId = null): array
    {
        $sql = 'SELECT r.*, e.employee_code, e.first_name, e.last_name
                FROM compensation_recommendations r
                INNER JOIN employees e ON e.id = r.employee_id
                WHERE r.cycle_id = :cycle_id';
        $parameters = ['cycle_id' => $cycleId];
        if ($managerId !== null) {
            $sql .= ' AND r.manager_id = :manager_id';
            $parameters['manager_id'] = $managerId;
        }
        $sql .= ' ORDER BY e.last_name, e.first_name, e.id';

        $statement = $this->connection->prepare($sql);
        $statement->execute($parameters);

        return $statement->fetchAll();
    }

    public function listExclusions(int $cycleId): array
    {
        $statement = $this->connection->prepare(
            'SELECT x.employee_id, x.exclusion_reason, e.employee_code, e.first_name, e.last_name
             FROM compensation_exclusions x
             INNER JOIN employees e ON e.id = x.employee_id
             WHERE x.cycle_id = :cycle_id
             ORDER BY e.last_name, e.first_name, e.id'
        );
        $statement->execute(['cycle_id' => $cycleId]);

        return $statement->fetchAll();
    }

    public function listRecommendationCounts(int $cycleId): array
    {
        $counts = ['Pending' => 0, 'Submitted' => 0, 'Returned' => 0, 'Approved' => 0];
        $statement = $this->connection->prepare(
            'SELECT status, COUNT(*) AS total
             FROM compensation_recommendations
             WHERE cycle_id = :cycle_id
             GROUP BY status'
        );
        $statement->execute(['cycle_id' => $cycleId]);
        foreach ($statement->fetchAll() as $row) {
            if (array_key_exists($row['status'], $counts)) {
                $counts[$row['status']] = (int) $row['total'];
            }
        }

        return $counts;
    }

    public function listActiveStructures(): array
    {
        $statement = $this->connection->query(
            'SELECT id, version, effective_from
             FROM compensation_salary_structures
             WHERE status = \'Active\'
             ORDER BY version DESC'
        );

        return $statement->fetchAll();
    }

    public function listActiveMatrices(): array
    {
        $statement = $this->connection->query(
            'SELECT id, version, effective_from
             FROM compensation_merit_matrices
             WHERE status = \'Active\'
             ORDER BY version DESC'
        );

        return $statement->fetchAll();
    }

    public function listActiveMatrixBands(): array
    {
        $statement = $this->connection->query(
            'SELECT b.performance_rating, b.compa_ratio_from, b.compa_ratio_to,
                    b.increase_percentage, m.version
             FROM compensation_merit_bands b
             INNER JOIN compensation_merit_matrices m ON m.id = b.matrix_id
             WHERE m.status = \'Active\'
             ORDER BY b.performance_rating DESC, b.compa_ratio_from'
        );

        return $statement->fetchAll();
    }

    public function findCycle(int $cycleId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT * FROM compensation_cycles WHERE id = :id'
        );
        $statement->execute(['id' => $cycleId]);
        $cycle = $statement->fetch();

        return $cycle === false ? null : $cycle;
    }
}
