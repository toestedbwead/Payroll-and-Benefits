ALTER TABLE employees
    ADD COLUMN current_salary DECIMAL(12,2) DEFAULT NULL AFTER job_tier,
    ADD COLUMN regularization_status ENUM('Regularized','Probationary') DEFAULT NULL AFTER employment_status,
    ADD COLUMN manager_id INT(11) DEFAULT NULL AFTER regularization_status,
    ADD COLUMN performance_rating TINYINT UNSIGNED DEFAULT NULL AFTER manager_id,
    ADD KEY employees_manager_id (manager_id),
    ADD CONSTRAINT employees_manager_fk
        FOREIGN KEY (manager_id) REFERENCES employees (id) ON DELETE SET NULL;

CREATE TABLE compensation_salary_structures (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    version INT UNSIGNED NOT NULL,
    effective_from DATE NOT NULL,
    status ENUM('Active','Archived') NOT NULL DEFAULT 'Active',
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY compensation_salary_structure_version (version),
    CONSTRAINT compensation_salary_structure_creator_fk
        FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE compensation_salary_grades (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    structure_id BIGINT UNSIGNED NOT NULL,
    grade_code VARCHAR(10) NOT NULL,
    target_job_tier ENUM('Entry-Level','Supervisory','Executive') NOT NULL,
    minimum_salary DECIMAL(12,2) NOT NULL,
    midpoint_salary DECIMAL(12,2) NOT NULL,
    maximum_salary DECIMAL(12,2) NOT NULL,
    UNIQUE KEY compensation_grade_code (structure_id, grade_code),
    KEY compensation_grade_job_tier (structure_id, target_job_tier),
    CONSTRAINT compensation_grade_structure_fk
        FOREIGN KEY (structure_id) REFERENCES compensation_salary_structures (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE compensation_merit_matrices (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    version INT UNSIGNED NOT NULL,
    effective_from DATE NOT NULL,
    status ENUM('Active','Archived') NOT NULL DEFAULT 'Active',
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY compensation_merit_matrix_version (version),
    CONSTRAINT compensation_merit_matrix_creator_fk
        FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE compensation_merit_bands (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    matrix_id BIGINT UNSIGNED NOT NULL,
    performance_rating TINYINT UNSIGNED NOT NULL,
    compa_ratio_from DECIMAL(4,2) NOT NULL,
    compa_ratio_to DECIMAL(4,2) DEFAULT NULL,
    increase_percentage DECIMAL(5,2) NOT NULL,
    UNIQUE KEY compensation_merit_band_start (matrix_id, performance_rating, compa_ratio_from),
    CONSTRAINT compensation_merit_band_rating_chk
        CHECK (performance_rating BETWEEN 1 AND 5),
    CONSTRAINT compensation_merit_band_percentage_chk
        CHECK (increase_percentage >= 0),
    CONSTRAINT compensation_merit_band_matrix_fk
        FOREIGN KEY (matrix_id) REFERENCES compensation_merit_matrices (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE compensation_cycles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cycle_name VARCHAR(100) NOT NULL,
    cycle_type ENUM('Annual Merit','Mid-Year','Special Adjustment') NOT NULL,
    effective_date DATE NOT NULL,
    eligibility_cutoff_date DATE NOT NULL,
    minimum_performance_rating TINYINT UNSIGNED NOT NULL,
    budget_type ENUM('Percent of Payroll','Fixed Amount') NOT NULL,
    budget_value DECIMAL(12,2) NOT NULL,
    total_budget DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    salary_structure_id BIGINT UNSIGNED NOT NULL,
    merit_matrix_id BIGINT UNSIGNED NOT NULL,
    status ENUM('Draft','Open','Submitted','Approved','Locked','Published') NOT NULL DEFAULT 'Draft',
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY compensation_cycle_status_type (status, cycle_type),
    CONSTRAINT compensation_cycle_min_rating_chk
        CHECK (minimum_performance_rating BETWEEN 1 AND 5),
    CONSTRAINT compensation_cycle_budget_chk
        CHECK (budget_value >= 0 AND total_budget >= 0),
    CONSTRAINT compensation_cycle_salary_structure_fk
        FOREIGN KEY (salary_structure_id) REFERENCES compensation_salary_structures (id),
    CONSTRAINT compensation_cycle_merit_matrix_fk
        FOREIGN KEY (merit_matrix_id) REFERENCES compensation_merit_matrices (id),
    CONSTRAINT compensation_cycle_creator_fk
        FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE compensation_department_budgets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cycle_id BIGINT UNSIGNED NOT NULL,
    department VARCHAR(100) NOT NULL,
    allocated_amount DECIMAL(12,2) NOT NULL,
    UNIQUE KEY compensation_department_budget (cycle_id, department),
    CONSTRAINT compensation_department_budget_amount_chk
        CHECK (allocated_amount >= 0),
    CONSTRAINT compensation_department_budget_cycle_fk
        FOREIGN KEY (cycle_id) REFERENCES compensation_cycles (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE compensation_audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cycle_id BIGINT UNSIGNED DEFAULT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    action VARCHAR(100) NOT NULL,
    details TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY compensation_audit_cycle_date (cycle_id, created_at),
    CONSTRAINT compensation_audit_cycle_fk
        FOREIGN KEY (cycle_id) REFERENCES compensation_cycles (id) ON DELETE SET NULL,
    CONSTRAINT compensation_audit_actor_fk
        FOREIGN KEY (actor_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
