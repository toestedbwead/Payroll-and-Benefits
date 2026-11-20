ALTER TABLE employees
    ADD COLUMN salary_grade_code VARCHAR(10) DEFAULT NULL AFTER job_tier;

ALTER TABLE users
    ADD COLUMN employee_id INT(11) DEFAULT NULL AFTER role,
    ADD UNIQUE KEY users_employee_id (employee_id),
    ADD CONSTRAINT users_employee_fk
        FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE SET NULL;

CREATE TABLE compensation_recommendations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cycle_id BIGINT UNSIGNED NOT NULL,
    employee_id INT(11) NOT NULL,
    manager_id INT(11) NOT NULL,
    department VARCHAR(100) NOT NULL,
    grade_code VARCHAR(10) NOT NULL,
    current_salary DECIMAL(12,2) NOT NULL,
    grade_minimum DECIMAL(12,2) NOT NULL,
    grade_midpoint DECIMAL(12,2) NOT NULL,
    grade_maximum DECIMAL(12,2) NOT NULL,
    compa_ratio DECIMAL(4,2) NOT NULL,
    performance_rating TINYINT UNSIGNED NOT NULL,
    matrix_increase_percentage DECIMAL(5,2) NOT NULL,
    suggested_new_salary DECIMAL(12,2) NOT NULL,
    flag ENUM('None','Exceeds Max','Below Min') NOT NULL DEFAULT 'None',
    final_increase_percentage DECIMAL(5,2) DEFAULT NULL,
    final_new_salary DECIMAL(12,2) DEFAULT NULL,
    manager_comment VARCHAR(500) DEFAULT NULL,
    override_flag TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('Pending','Submitted','Returned','Approved') NOT NULL DEFAULT 'Pending',
    submitted_by BIGINT UNSIGNED DEFAULT NULL,
    submitted_at DATETIME DEFAULT NULL,
    reviewed_by BIGINT UNSIGNED DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    review_note VARCHAR(500) DEFAULT NULL,
    UNIQUE KEY compensation_recommendation_employee (cycle_id, employee_id),
    KEY compensation_recommendation_manager (cycle_id, manager_id, status),
    CONSTRAINT compensation_recommendation_rating_chk
        CHECK (performance_rating BETWEEN 1 AND 5),
    CONSTRAINT compensation_recommendation_cycle_fk
        FOREIGN KEY (cycle_id) REFERENCES compensation_cycles (id) ON DELETE CASCADE,
    CONSTRAINT compensation_recommendation_employee_fk
        FOREIGN KEY (employee_id) REFERENCES employees (id),
    CONSTRAINT compensation_recommendation_manager_fk
        FOREIGN KEY (manager_id) REFERENCES employees (id),
    CONSTRAINT compensation_recommendation_submitted_by_fk
        FOREIGN KEY (submitted_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT compensation_recommendation_reviewed_by_fk
        FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE compensation_exclusions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cycle_id BIGINT UNSIGNED NOT NULL,
    employee_id INT(11) NOT NULL,
    exclusion_reason VARCHAR(100) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY compensation_exclusion_employee (cycle_id, employee_id),
    CONSTRAINT compensation_exclusion_cycle_fk
        FOREIGN KEY (cycle_id) REFERENCES compensation_cycles (id) ON DELETE CASCADE,
    CONSTRAINT compensation_exclusion_employee_fk
        FOREIGN KEY (employee_id) REFERENCES employees (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
