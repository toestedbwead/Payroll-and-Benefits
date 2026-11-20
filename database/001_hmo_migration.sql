-- Apply once to an existing payroll_db created from the original payroll_db.sql.
-- HMO provider, enrollment, and dependent tables must already exist.

ALTER TABLE hmo_providers
    ADD COLUMN parent_dependents_allowed TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1,
    ADD KEY is_active_target_job_tier (is_active, target_job_tier);

ALTER TABLE employee_hmo_enrollment
    ADD COLUMN dependent_cost_share_percent TINYINT UNSIGNED NOT NULL DEFAULT 100,
    ADD COLUMN annual_premium_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    ADD COLUMN terminated_at DATE DEFAULT NULL,
    ADD KEY terminated_at (terminated_at);

UPDATE employee_hmo_enrollment h
INNER JOIN hmo_providers p ON p.id = h.hmo_provider_id
SET h.annual_premium_cost = p.annual_premium_cost;

ALTER TABLE hmo_dependents
    ADD COLUMN monthly_premium_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER date_of_birth;

UPDATE hmo_dependents
SET monthly_premium_amount = monthly_deduction_amount
WHERE monthly_premium_amount = 0 AND monthly_deduction_amount > 0;

CREATE TABLE hmo_payroll_deductions (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    employee_hmo_id INT NOT NULL,
    dependent_id INT NOT NULL,
    payroll_month DATE NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    status ENUM('Queued','Exported','Processed','Voided') NOT NULL DEFAULT 'Queued',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY dependent_month (dependent_id, payroll_month),
    KEY enrollment_month (employee_hmo_id, payroll_month),
    KEY payroll_month_status (payroll_month, status),
    CONSTRAINT hmo_payroll_deductions_enrollment_fk
        FOREIGN KEY (employee_hmo_id) REFERENCES employee_hmo_enrollment (id) ON DELETE RESTRICT,
    CONSTRAINT hmo_payroll_deductions_dependent_fk
        FOREIGN KEY (dependent_id) REFERENCES hmo_dependents (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE hmo_audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    actor_name VARCHAR(120) NOT NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id INT DEFAULT NULL,
    details TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
