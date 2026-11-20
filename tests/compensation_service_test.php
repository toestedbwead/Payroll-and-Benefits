<?php

require_once __DIR__ . '/../app/Services/CompensationService.php';

function ensure(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectInvalid(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (InvalidArgumentException) {
        return;
    }

    throw new RuntimeException($message);
}

function expectDomainError(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (DomainException) {
        return;
    }

    throw new RuntimeException($message);
}

$connection = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$connection->exec(
    'CREATE TABLE compensation_salary_structures (
        id INTEGER PRIMARY KEY AUTOINCREMENT, version INTEGER, effective_from TEXT,
        status TEXT DEFAULT \'Active\', created_by INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE compensation_salary_grades (
        id INTEGER PRIMARY KEY AUTOINCREMENT, structure_id INTEGER, grade_code TEXT,
        target_job_tier TEXT, minimum_salary NUMERIC, midpoint_salary NUMERIC, maximum_salary NUMERIC
    );
    CREATE TABLE compensation_merit_matrices (
        id INTEGER PRIMARY KEY AUTOINCREMENT, version INTEGER, effective_from TEXT,
        status TEXT DEFAULT \'Active\', created_by INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE compensation_merit_bands (
        id INTEGER PRIMARY KEY AUTOINCREMENT, matrix_id INTEGER, performance_rating INTEGER,
        compa_ratio_from NUMERIC, compa_ratio_to NUMERIC, increase_percentage NUMERIC
    );
    CREATE TABLE compensation_cycles (
        id INTEGER PRIMARY KEY AUTOINCREMENT, cycle_name TEXT, cycle_type TEXT,
        effective_date TEXT, eligibility_cutoff_date TEXT, minimum_performance_rating INTEGER,
        budget_type TEXT, budget_value NUMERIC, total_budget NUMERIC,
        salary_structure_id INTEGER, merit_matrix_id INTEGER, status TEXT DEFAULT \'Draft\',
        created_by INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE compensation_department_budgets (
        id INTEGER PRIMARY KEY AUTOINCREMENT, cycle_id INTEGER, department_id INTEGER,
        department TEXT, allocated_amount NUMERIC
    );
    CREATE TABLE compensation_recommendations (
        id INTEGER PRIMARY KEY AUTOINCREMENT, cycle_id INTEGER, employee_id INTEGER, manager_id INTEGER,
        department_id INTEGER, department TEXT, grade_code TEXT, current_salary NUMERIC, grade_minimum NUMERIC,
        grade_midpoint NUMERIC, grade_maximum NUMERIC, compa_ratio NUMERIC, performance_rating INTEGER,
        matrix_increase_percentage NUMERIC, suggested_new_salary NUMERIC, flag TEXT,
        final_increase_percentage NUMERIC, final_new_salary NUMERIC, manager_comment TEXT,
        override_flag INTEGER DEFAULT 0, status TEXT DEFAULT \'Pending\', submitted_by INTEGER,
        submitted_at TEXT, reviewed_by INTEGER, reviewed_at TEXT, review_note TEXT
    );
    CREATE TABLE compensation_exclusions (
        id INTEGER PRIMARY KEY AUTOINCREMENT, cycle_id INTEGER, employee_id INTEGER,
        exclusion_reason TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE compensation_audit_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT, cycle_id INTEGER, actor_id INTEGER,
        action TEXT, details TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE users (
        id INTEGER PRIMARY KEY, employee_id INTEGER, role TEXT, is_active INTEGER
    );
    CREATE TABLE employees (
        id INTEGER PRIMARY KEY, department_id INTEGER, department TEXT, employment_type TEXT,
        job_tier TEXT, salary_grade_code TEXT,
        current_salary NUMERIC, employment_status TEXT, regularization_status TEXT,
        manager_id INTEGER, performance_rating INTEGER, date_hired TEXT
    );
    CREATE TABLE departments (
        id INTEGER PRIMARY KEY, department_name TEXT, status TEXT DEFAULT \'Active\'
    );'
);

$connection->exec('INSERT INTO departments (id, department_name) VALUES (1, \'Engineering\')');
$service = new CompensationService($connection);
$actorId = 1;
$grade = [
    'grade_code' => 'G1',
    'target_job_tier' => 'Entry-Level',
    'minimum_salary' => '30000.00',
    'midpoint_salary' => '40000.00',
    'maximum_salary' => '50000.00',
];
$structureId = $service->createSalaryStructure('2026-01-01', [$grade], $actorId);
$nextStructureId = $service->createSalaryStructure('2027-01-01', [$grade], $actorId);
ensure($structureId !== $nextStructureId, 'A saved structure change must create a new version.');
ensure(
    $connection->query('SELECT status FROM compensation_salary_structures WHERE id = ' . $structureId)->fetchColumn() === 'Archived',
    'Saving a new structure must archive the prior active version.'
);
expectInvalid(
    fn () => $service->createSalaryStructure('2027-01-01', [[
        'grade_code' => 'BAD',
        'target_job_tier' => 'Entry-Level',
        'minimum_salary' => '40000',
        'midpoint_salary' => '40000',
        'maximum_salary' => '50000',
    ]], $actorId),
    'Invalid salary ranges must be rejected.'
);

$bandsByRating = [];
foreach (range(1, 5) as $rating) {
    $bandsByRating[$rating] = [
        ['compa_ratio_from' => '0.00', 'compa_ratio_to' => '0.90', 'increase_percentage' => '1.00'],
        ['compa_ratio_from' => '0.90', 'compa_ratio_to' => '1.11', 'increase_percentage' => '2.00'],
        ['compa_ratio_from' => '1.11', 'compa_ratio_to' => '', 'increase_percentage' => '3.00'],
    ];
}
$matrixId = $service->createMeritMatrix('2026-01-01', $bandsByRating, $actorId);
ensure((int) $connection->query('SELECT COUNT(*) FROM compensation_merit_bands')->fetchColumn() === 15, 'The matrix should save all five ratings and their bands.');
$invalidBands = $bandsByRating;
$invalidBands[3][1]['compa_ratio_from'] = '0.91';
expectInvalid(
    fn () => $service->createMeritMatrix('2027-01-01', $invalidBands, $actorId),
    'A gap in matrix coverage must be rejected.'
);

$connection->exec(
    'INSERT INTO employees
        (id, department_id, department, employment_type, job_tier, salary_grade_code, current_salary, employment_status,
         regularization_status, manager_id, performance_rating, date_hired)
     VALUES
        (1, 1, \'Engineering\', \'Full-time\', \'Entry-Level\', \'G1\', 40000,
         \'Active\', \'Regularized\', 2, 4, \'2020-01-01\')'
);
$cycleInput = [
    'cycle_name' => 'Test Merit Cycle',
    'cycle_type' => 'Annual Merit',
    'effective_date' => date('Y-m-d', strtotime('+1 month')),
    'eligibility_cutoff_date' => date('Y-m-d'),
    'minimum_performance_rating' => '2',
    'budget_type' => 'Percent of Payroll',
    'budget_value' => '5.00',
    'salary_structure_id' => (string) $nextStructureId,
    'merit_matrix_id' => (string) $matrixId,
];
$emptyAllocationCycleId = $service->saveDraftCycle(null, $cycleInput, [1 => ''], $actorId);
$service->deleteDraftCycle($emptyAllocationCycleId, $actorId);
expectInvalid(
    fn () => $service->saveDraftCycle(null, $cycleInput, [999 => '10.00'], $actorId),
    'Allocations to unknown departments must be rejected.'
);
$cycleId = $service->saveDraftCycle(null, $cycleInput, [1 => '2000.00'], $actorId);
ensure(
    (float) $connection->query('SELECT total_budget FROM compensation_cycles WHERE id = ' . $cycleId)->fetchColumn() === 2000.0,
    'A payroll percentage budget should be calculated from the eligible monthly payroll.'
);
ensure(
    (int) $connection->query('SELECT department_id FROM compensation_department_budgets WHERE cycle_id = ' . $cycleId)->fetchColumn() === 1,
    'Department budget allocations should persist the normalized department ID.'
);
$lateHire = date('Y-m-d', strtotime('+1 day'));
$connection->prepare(
    'INSERT INTO employees
        (id, department_id, department, employment_type, job_tier, salary_grade_code, current_salary,
         employment_status, regularization_status, manager_id, performance_rating, date_hired)
     VALUES
        (3, 1, \'Engineering\', \'Full-time\', \'Entry-Level\', \'G1\', 41000,
         \'Active\', \'Regularized\', 2, 4, :date_hired)'
)->execute(['date_hired' => $lateHire]);
$connection->exec(
    'INSERT INTO employees (id, department_id, department, employment_type, job_tier, employment_status)
     VALUES (2, 1, \'Engineering\', \'Full-time\', \'Supervisory\', \'Active\')'
);
$service->updateEmployeeCompensationData(1, [
    'current_salary' => '40000.00',
    'salary_grade_code' => 'G1',
    'regularization_status' => 'Regularized',
    'manager_id' => '2',
    'performance_rating' => '4',
], $actorId);
expectDomainError(
    fn () => $service->saveDraftCycle(null, $cycleInput, [1 => '2000.01'], $actorId),
    'Department allocations exceeding the budget must be rejected.'
);
$generated = $service->generateRecommendations($cycleId, $actorId);
ensure($generated === ['eligible' => 1, 'excluded' => 2], 'The cycle should generate one recommendation and explicitly exclude every unqualified employee.');
$exclusionReason = $connection->query(
    'SELECT exclusion_reason FROM compensation_exclusions WHERE cycle_id = ' . $cycleId . ' AND employee_id = 3'
)->fetchColumn();
ensure($exclusionReason === 'Hired after eligibility cutoff', 'The exclusion list should show why the employee was omitted.');
$recommendation = $connection->query(
    'SELECT compa_ratio, matrix_increase_percentage, suggested_new_salary, flag
     FROM compensation_recommendations WHERE cycle_id = ' . $cycleId
)->fetch();
ensure((float) $recommendation['compa_ratio'] === 1.0, 'Compa-ratio should be rounded to two decimals.');
ensure((float) $recommendation['matrix_increase_percentage'] === 2.0, 'The matching rating and ratio band should determine the matrix percentage.');
ensure((float) $recommendation['suggested_new_salary'] === 40800.0, 'The suggested salary should use the matrix percentage.');
ensure($recommendation['flag'] === 'None', 'An in-range suggestion should not be flagged.');
$connection->exec('UPDATE employees SET current_salary = 49500 WHERE id = 1');
$service->generateRecommendations($cycleId, $actorId);
ensure(
    $connection->query('SELECT flag FROM compensation_recommendations WHERE cycle_id = ' . $cycleId)->fetchColumn() === 'Exceeds Max',
    'A suggested salary above the grade maximum should be flagged.'
);
$connection->exec('UPDATE employees SET current_salary = 29000 WHERE id = 1');
$service->generateRecommendations($cycleId, $actorId);
ensure(
    $connection->query('SELECT flag FROM compensation_recommendations WHERE cycle_id = ' . $cycleId)->fetchColumn() === 'Below Min',
    'A current salary below the grade minimum should be flagged.'
);
$connection->exec('UPDATE employees SET current_salary = 40000 WHERE id = 1');
$service->generateRecommendations($cycleId, $actorId);

$connection->exec('INSERT INTO users (id, employee_id, role, is_active) VALUES (2, 2, \'manager\', 1)');
$service->openCycle($cycleId, $actorId);
$service->topUpBudget($cycleId, '1000.00', $actorId);
$recommendationId = (int) $connection->query(
    'SELECT id FROM compensation_recommendations WHERE cycle_id = ' . $cycleId
)->fetchColumn();
$service->saveManagerRecommendation($cycleId, $recommendationId, 2, '30.00', 'Large increase', 2);
expectDomainError(
    fn () => $service->submitManagerTeam($cycleId, 2, 2),
    'A final salary above grade maximum must block team submission.'
);
$service->saveManagerRecommendation($cycleId, $recommendationId, 2, '3.00', '', 2);
expectDomainError(
    fn () => $service->submitManagerTeam($cycleId, 2, 2),
    'An override without a comment must block team submission.'
);
$service->saveManagerRecommendation($cycleId, $recommendationId, 2, '2.00', '', 2);
$service->submitManagerTeam($cycleId, 2, 2);
$service->reviewRecommendation($cycleId, $recommendationId, 'return', 'Please recalibrate.', $actorId);
$service->saveManagerRecommendation($cycleId, $recommendationId, 2, '1.00', '', 2);
expectDomainError(
    fn () => $service->submitManagerTeam($cycleId, 2, 2),
    'A resubmitted override without a comment must be blocked.'
);
$service->saveManagerRecommendation($cycleId, $recommendationId, 2, '2.00', '', 2);
$service->submitManagerTeam($cycleId, 2, 2);
$service->reviewRecommendation($cycleId, $recommendationId, 'approve', '', $actorId);
$service->finalizeCycleReview($cycleId, $actorId);
$service->advanceCycle($cycleId, 'approve_cycle', $actorId);
$service->advanceCycle($cycleId, 'lock_cycle', $actorId);
$service->advanceCycle($cycleId, 'publish_cycle', $actorId);
ensure(
    $connection->query('SELECT status FROM compensation_cycles WHERE id = ' . $cycleId)->fetchColumn() === 'Published',
    'The compensation cycle should reach Published through allowed forward transitions.'
);

fwrite(STDOUT, "Compensation service tests passed.\n");
