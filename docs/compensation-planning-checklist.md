# Compensation Planning Implementation Tracker

Last reviewed: 2026-11-15

## Status summary

The compensation workflow is implemented in the application and its focused service tests pass. The configured database has the compensation tables and employee fields, but it currently has no salary structure, merit matrix, cycle, or populated employee compensation inputs. The first seed import reported a MySQL collation mismatch; the seed and payroll-budget query now explicitly align comparisons between the employee and compensation table collations. A successful re-import is still pending.

**Current phase: operational setup and live-database verification.**

Status key: `[x]` complete; `[ ]` pending.

## 0. Data and workflow assumptions

- [x] Read the root `payroll_db.sql` dump and compare it with the configured database schema.
- [x] Use the existing `employees` table and its department, job tier, employment type/status, and hire date.
- [x] Add compensation fields for salary, grade, regularization status, manager, and performance rating.
- [x] Use existing application roles for HR (`admin`, `superadmin`, `hr_manager`) and the `manager` role for team review.
- [ ] Review the starter employee compensation values in `database/compensation_employee_seed.sql` before importing.

## 1. Database design

- [x] Create versioned salary-structure, grade, merit-matrix, and matrix-band tables.
- [x] Create cycle, department-budget, recommendation, exclusion, and audit tables.
- [x] Add employee-to-grade and user-to-employee links.
- [x] Keep salary, grade, manager, regularization, and rating values nullable until populated.
- [x] Compare the configured database schema with the compensation migrations.
- [ ] Confirm the seed has been imported into the intended database.
- [ ] Confirm the root SQL dump is refreshed after any desired live-database changes.

## 2. Salary grades and merit matrix

- [x] Build HR forms to create salary-grade and merit-matrix versions.
- [x] Validate minimum < midpoint < maximum.
- [x] Validate matrix coverage for ratings 1–5 with contiguous bands from 0.00 and an open-ended last band.
- [x] Archive the previous active version instead of overwriting it.
- [x] Cover versioning and validation in service tests.
- [ ] Review the initial G1/G2/G3 ranges and sample matrix version created by the seed.
- [ ] Confirm the first active versions exist in the database after importing the seed.

## 3. Cycle and budget setup

- [x] Build Draft cycle create, edit, and delete actions.
- [x] Support fixed-amount and percentage-of-eligible-monthly-payroll budgets.
- [x] Support optional department allocations and reject allocations above the budget.
- [x] Snapshot the structure and matrix references on each cycle.
- [x] Audit cycle changes and Open-cycle budget top-ups.
- [x] Enforce Draft-only editing and forward-only lifecycle transitions.
- [ ] Create and review the first cycle in the application.

## 4. Eligibility and recommendations

- [x] Check active status, full-time type, regularization, hire cutoff, minimum rating, salary, grade, and manager assignment.
- [x] Record an explicit exclusion reason for each excluded employee.
- [x] Snapshot salary, grade ranges, rating, compa-ratio, matrix increase, and suggested salary.
- [x] Calculate two-decimal compa-ratio and suggested salary and flag grade-limit cases.
- [x] Restrict regeneration to Draft cycles.
- [x] Test eligibility, exclusions, calculations, and flags.
- [ ] Generate recommendations for a real configured cycle and review results with HR.

## 5. Manager review

- [x] Restrict manager views and actions to recommendations assigned to the signed-in manager employee.
- [x] Allow edits only for Pending/Returned rows in an Open cycle.
- [x] Preview final salaries and running team/department increases.
- [x] Enforce non-negative percentages, override comments, complete submissions, grade maximum, and budget limits.
- [x] Support HR returns and manager resubmission.
- [x] Add a CLI option for creating a manager login linked to an active employee.
- [ ] Create manager logins for employees assigned as managers in the seed.
- [ ] Test the manager workflow with an actual linked manager login.

## 6. HR approval and lifecycle

- [x] Add HR approve/return actions for submitted recommendations.
- [x] Require approval of every recommendation before final cycle submission.
- [x] Implement Submitted → Approved → Locked → Published transitions.
- [x] Audit manager updates, HR reviews, lifecycle transitions, and top-ups.
- [x] Test the full transition sequence in focused service tests.
- [ ] Complete an actual live-database cycle through Published.

## 7. Verification and delivery

- [x] PHP syntax checks pass for compensation controllers, repository, service, view, route, and manager-account utility.
- [x] Focused service tests pass, including exclusions, matrix lookups, budget limits, manager overrides, returns, and cycle transitions.
- [x] HR and manager views render against the configured database.
- [ ] Review the employee values in the seed SQL before importing.
- [ ] Import the seed SQL into the intended database.
- [ ] Verify active versions and employee setup after import.
- [ ] Create manager accounts, create a cycle, and run a complete live workflow.
- [ ] Refresh `payroll_db.sql` if the intended source-of-truth dump should include the resulting setup.

## Next actions

1. Review `database/compensation_employee_seed.sql`. It creates a new active salary structure and merit matrix and assigns the employee compensation values in one transaction. Re-importing it creates additional versions and archives the prior active versions.
2. Import the reviewed seed into the intended `payroll_db`.
3. Verify the active versions and employee assignments on the Compensation Planning page.
4. Create linked manager accounts for the assigned manager employees using the command in `database/README.md`.
5. Create a Draft cycle, generate recommendations, and test manager submission and HR approval in the live application.

Do not mark the operational items complete until they are verified in the intended database.
