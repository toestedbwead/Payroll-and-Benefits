-- Apply once after 002_hmo_dependents_details_migration.sql.
-- Existing provider rates are left at zero until HR enters the contracted rate per plan.

ALTER TABLE hmo_providers
    ADD COLUMN dependent_monthly_premium DECIMAL(10,2) NOT NULL DEFAULT 0.00
    AFTER annual_premium_cost;
