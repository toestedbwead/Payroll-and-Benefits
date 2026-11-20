-- Apply once after 003_hmo_dependent_plan_rate_migration.sql.
-- Store the fixed employee-tier dependent rates on every matching provider plan.

UPDATE hmo_providers
SET dependent_monthly_premium = CASE target_job_tier
    WHEN 'Entry-Level' THEN 1000.00
    WHEN 'Supervisory' THEN 1500.00
    WHEN 'Executive' THEN 2500.00
END;
