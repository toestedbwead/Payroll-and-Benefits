-- One-time migration after 008_compensation_workflow_migration.sql.
-- Do not run this again after it completes. Legacy department-name fields are retained.

CREATE TABLE departments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    department_code VARCHAR(50) DEFAULT NULL,
    department_name VARCHAR(100) NOT NULL,
    status ENUM('Active','Archived') NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY departments_code (department_code),
    UNIQUE KEY departments_name (department_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO departments (department_name)
SELECT DISTINCT TRIM(department)
FROM employees
WHERE TRIM(department) <> '';

INSERT IGNORE INTO departments (department_name)
SELECT DISTINCT TRIM(department)
FROM compensation_department_budgets
WHERE TRIM(department) <> '';

INSERT IGNORE INTO departments (department_name)
SELECT DISTINCT TRIM(department)
FROM compensation_recommendations
WHERE TRIM(department) <> '';

ALTER TABLE employees
    ADD COLUMN department_id INT UNSIGNED DEFAULT NULL AFTER department;

UPDATE employees e
INNER JOIN departments d
    ON d.department_name COLLATE utf8mb4_general_ci = TRIM(e.department) COLLATE utf8mb4_general_ci
SET e.department_id = d.id
WHERE TRIM(e.department) <> '';

ALTER TABLE employees
    ADD KEY employees_department_id (department_id),
    ADD CONSTRAINT employees_department_fk
        FOREIGN KEY (department_id) REFERENCES departments (id)
        ON DELETE RESTRICT;

ALTER TABLE compensation_department_budgets
    ADD COLUMN department_id INT UNSIGNED DEFAULT NULL AFTER department;

UPDATE compensation_department_budgets b
INNER JOIN departments d
    ON d.department_name COLLATE utf8mb4_general_ci = TRIM(b.department) COLLATE utf8mb4_general_ci
SET b.department_id = d.id
WHERE TRIM(b.department) <> '';

ALTER TABLE compensation_department_budgets
    ADD UNIQUE KEY compensation_department_budget_id (cycle_id, department_id),
    ADD CONSTRAINT compensation_department_budget_department_fk
        FOREIGN KEY (department_id) REFERENCES departments (id)
        ON DELETE RESTRICT;

ALTER TABLE compensation_recommendations
    ADD COLUMN department_id INT UNSIGNED DEFAULT NULL AFTER department;

UPDATE compensation_recommendations r
INNER JOIN departments d
    ON d.department_name COLLATE utf8mb4_general_ci = TRIM(r.department) COLLATE utf8mb4_general_ci
SET r.department_id = d.id
WHERE TRIM(r.department) <> '';

ALTER TABLE compensation_recommendations
    ADD KEY compensation_recommendation_department (cycle_id, department_id),
    ADD CONSTRAINT compensation_recommendation_department_fk
        FOREIGN KEY (department_id) REFERENCES departments (id)
        ON DELETE RESTRICT;
