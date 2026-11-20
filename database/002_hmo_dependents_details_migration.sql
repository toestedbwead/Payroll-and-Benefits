    -- Apply once after 001_hmo_migration.sql to existing HMO databases.
    -- It adds enrollment-form details and makes dependent payroll costs employee-paid.

    ALTER TABLE employee_hmo_enrollment
        MODIFY dependent_cost_share_percent TINYINT UNSIGNED NOT NULL DEFAULT 100;

    ALTER TABLE hmo_dependents
        MODIFY date_of_birth DATE DEFAULT NULL,
        ADD COLUMN dependent_last_name VARCHAR(100) DEFAULT NULL AFTER dependent_name,
        ADD COLUMN dependent_first_name VARCHAR(100) DEFAULT NULL AFTER dependent_last_name,
        ADD COLUMN dependent_middle_initial VARCHAR(5) DEFAULT NULL AFTER dependent_first_name,
        ADD COLUMN dependent_name_extension VARCHAR(20) DEFAULT NULL AFTER dependent_middle_initial,
        ADD COLUMN dependent_age SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER dependent_name_extension,
        ADD COLUMN sex ENUM('Female','Male') DEFAULT NULL AFTER relationship,
        ADD COLUMN civil_status ENUM('Single','Married','Widowed','Separated') DEFAULT NULL AFTER sex,
        ADD COLUMN birth_certificate_path VARCHAR(255) DEFAULT NULL AFTER date_of_birth;

    UPDATE hmo_dependents
    SET dependent_age = TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE())
    WHERE date_of_birth IS NOT NULL AND dependent_age = 0;

    UPDATE employee_hmo_enrollment
    SET dependent_cost_share_percent = 100;

    UPDATE hmo_dependents
    SET monthly_deduction_amount = monthly_premium_amount;

    UPDATE hmo_payroll_deductions q
    INNER JOIN hmo_dependents d ON d.id = q.dependent_id
    SET q.amount = d.monthly_premium_amount
    WHERE q.status = 'Queued';
