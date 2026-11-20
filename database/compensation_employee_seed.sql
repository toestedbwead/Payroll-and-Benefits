-- Initial compensation setup for the employee records in payroll_db.sql.
-- Creates a new active salary structure and merit matrix version, then assigns
-- starter compensation inputs by matching both employee ID and employee code.
-- Re-importing creates new versions; existing cycle snapshots keep their versions.

START TRANSACTION;

SET @compensation_actor_id = (SELECT MIN(id) FROM users WHERE is_active = 1);

UPDATE compensation_salary_structures
SET status = 'Archived'
WHERE status = 'Active';

INSERT INTO compensation_salary_structures (version, effective_from, status, created_by)
SELECT COALESCE(MAX(version), 0) + 1, CURRENT_DATE(), 'Active', @compensation_actor_id
FROM compensation_salary_structures;

SET @compensation_structure_id = LAST_INSERT_ID();

INSERT INTO compensation_salary_grades
    (structure_id, grade_code, target_job_tier, minimum_salary, midpoint_salary, maximum_salary)
VALUES
    (@compensation_structure_id, 'G1', 'Entry-Level', 30000.00, 40000.00, 50000.00),
    (@compensation_structure_id, 'G2', 'Supervisory', 50000.00, 65000.00, 80000.00),
    (@compensation_structure_id, 'G3', 'Executive', 80000.00, 100000.00, 120000.00);

UPDATE compensation_merit_matrices
SET status = 'Archived'
WHERE status = 'Active';

INSERT INTO compensation_merit_matrices (version, effective_from, status, created_by)
SELECT COALESCE(MAX(version), 0) + 1, CURRENT_DATE(), 'Active', @compensation_actor_id
FROM compensation_merit_matrices;

SET @compensation_matrix_id = LAST_INSERT_ID();

INSERT INTO compensation_merit_bands
    (matrix_id, performance_rating, compa_ratio_from, compa_ratio_to, increase_percentage)
VALUES
    (@compensation_matrix_id, 5, 0.00, 0.90, 10.00),
    (@compensation_matrix_id, 5, 0.90, 1.11, 8.00),
    (@compensation_matrix_id, 5, 1.11, NULL, 5.00),
    (@compensation_matrix_id, 4, 0.00, 0.90, 7.00),
    (@compensation_matrix_id, 4, 0.90, 1.11, 5.00),
    (@compensation_matrix_id, 4, 1.11, NULL, 3.00),
    (@compensation_matrix_id, 3, 0.00, 0.90, 4.00),
    (@compensation_matrix_id, 3, 0.90, 1.11, 3.00),
    (@compensation_matrix_id, 3, 1.11, NULL, 2.00),
    (@compensation_matrix_id, 2, 0.00, 0.90, 1.00),
    (@compensation_matrix_id, 2, 0.90, 1.11, 0.00),
    (@compensation_matrix_id, 2, 1.11, NULL, 0.00),
    (@compensation_matrix_id, 1, 0.00, 0.90, 0.00),
    (@compensation_matrix_id, 1, 0.90, 1.11, 0.00),
    (@compensation_matrix_id, 1, 1.11, NULL, 0.00);

CREATE TEMPORARY TABLE compensation_employee_seed (
    employee_id INT NOT NULL PRIMARY KEY,
    employee_code VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
    current_salary DECIMAL(12,2) NOT NULL,
    salary_grade_code VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    regularization_status ENUM('Regularized','Probationary') NOT NULL,
    manager_id INT DEFAULT NULL,
    performance_rating TINYINT UNSIGNED NOT NULL
) ENGINE=InnoDB;

INSERT INTO compensation_employee_seed
    (employee_id, employee_code, current_salary, salary_grade_code, regularization_status, manager_id, performance_rating)
VALUES
    (1, 'EMP-001', 42000.00, 'G1', 'Regularized', 6, 4), -- Juan Dela Cruz
    (2, 'EMP-002', 46500.00, 'G1', 'Regularized', 6, 3), -- Maria Santos
    (3, 'EMP-003', 29000.00, 'G1', 'Probationary', 6, 3), -- Carlos Reyes
    (6, 'EMP-004', 68000.00, 'G2', 'Regularized', 7, 4), -- Zest O Wilkins
    (7, 'EMP-005', 112000.00, 'G3', 'Regularized', NULL, 5), -- Nature Spring, executive lead
    (8, 'EMP-006', 38000.00, 'G1', 'Regularized', 6, 4); -- Kenneth Taylan

-- Preview the values before they are applied.
SELECT
    e.id AS employee_id,
    e.employee_code,
    CONCAT(e.first_name, ' ', e.last_name) AS employee_name,
    e.job_tier,
    s.current_salary AS seed_salary,
    s.salary_grade_code AS seed_grade,
    s.regularization_status AS seed_regularization_status,
    s.manager_id AS seed_manager_id,
    s.performance_rating AS seed_performance_rating
FROM compensation_employee_seed s
INNER JOIN employees e
    ON e.id = s.employee_id
   AND e.employee_code = s.employee_code
ORDER BY e.id;

-- The grade must match the employee's job tier in this newly created structure.
-- A mismatched employee ID/code pair or grade/job-tier pair is not updated.
UPDATE employees e
INNER JOIN compensation_employee_seed s
    ON e.id = s.employee_id
   AND e.employee_code = s.employee_code
INNER JOIN compensation_salary_grades g
    ON g.structure_id = @compensation_structure_id
   AND g.grade_code = s.salary_grade_code
   AND g.target_job_tier COLLATE utf8mb4_general_ci = e.job_tier
SET
    e.current_salary = s.current_salary,
    e.salary_grade_code = s.salary_grade_code,
    e.regularization_status = s.regularization_status,
    e.manager_id = s.manager_id,
    e.performance_rating = s.performance_rating;

COMMIT;

DROP TEMPORARY TABLE compensation_employee_seed;
