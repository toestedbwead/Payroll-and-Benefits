-- Apply once after 004_hmo_dependent_tier_rates_migration.sql.
-- Keeps dependent rows referenced by payroll history while allowing removal from active coverage.

ALTER TABLE hmo_dependents
    ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER monthly_deduction_amount;
