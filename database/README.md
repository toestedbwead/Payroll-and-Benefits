# Database setup

1. Start MySQL in the XAMPP Control Panel.
2. In phpMyAdmin, create a database named `payroll_db`.
3. Import the root `payroll_db.sql` dump to create the application tables and sample data. `schema.sql` contains only the sign-in `users` table for installations that provision the application tables separately.
4. Apply applicable migrations in numeric order. The prefixes show the dependency order; this project does not automatically track which files have been imported, so mark the files you apply for each database and never replay an applied migration:

   - [ ] `001_hmo_migration.sql` — existing databases with the HMO tables from the earlier dump.
   - [ ] `002_hmo_dependents_details_migration.sql` — after 001; dependent enrollment details and payroll costs.
   - [ ] `003_hmo_dependent_plan_rate_migration.sql` — after 002; dependent premium field.
   - [ ] `004_hmo_dependent_tier_rates_migration.sql` — after 003; fixed rates by employee tier.
   - [ ] `005_hmo_enrollment_edit_migration.sql` — after 004; dependent removal tracking.
   - [ ] `006_settings_migration.sql` — persisted organization, payroll, claim-threshold, and work-location settings.
   - [ ] `007_compensation_migration.sql` — for the root dump; salary structures, merit matrices, cycles, budgets, audit logs, and employee compensation fields. Populate employee compensation values only from an authoritative source.
   - [ ] `008_compensation_workflow_migration.sql` — after 007; employee-to-grade and user-to-employee links, recommendation snapshots, and exclusions.
   - [ ] `009_department_migration.sql` — after 008; creates and backfills normalized departments and adds department IDs while retaining department-name snapshots.
   - [ ] `010_password_reset_codes_migration.sql` — adds storage for expiring, single-use password reset codes.

5. The project reads database settings from the root `.env` file. It is initialized with the local defaults `127.0.0.1:3307`, database `payroll_db`, user `root`, and an empty password. Edit `.env` if your local MySQL credentials differ. Keep `.env` private; `.env.example` is safe to share.
   Password reset email uses SMTP with STARTTLS. Configure `EMAIL_SMTP_HOST`, `EMAIL_SMTP_PORT`, `EMAIL_SMTP_USER`, `EMAIL_SMTP_APP_PASSWORD`, and `EMAIL_FROM_ADDRESS`; set `EMAIL_OTP_HASH_SECRET` to a private random value of at least 32 characters. Generate one with `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`.
6. From the project root, create the first sign-in account:

   ```powershell
   php database/create_user.php "admin@example.com" "Payroll Admin"
   ```

   Enter a password of at least 12 characters when prompted. The password input is visible in the console; use this script only in a trusted local terminal.

The script hashes the password before storing it. There is no public registration route. To disable an account, set its `is_active` value to `0` in the `users` table.

After applying `008_compensation_workflow_migration.sql`, create a manager login linked to an active employee with:

```powershell
php database/create_user.php "manager@example.com" "Team Manager" manager 12
```

Replace `12` with that manager's employee ID. The account is created with the `manager` role and can review only recommendations assigned to that employee.

`compensation_employee_seed.sql` creates a new active salary-structure and merit-matrix version, then assigns compensation inputs for the six employees in the root SQL dump. It uses the project starter amounts in the file, creates grades G1/G2/G3 for the three job tiers, and uses manager employee IDs from the dump. Re-importing creates new versions and archives the prior active structure and matrix; existing cycles retain their version references. Review the values in the SQL preview before import.

If a password was entered directly as plain text into `users.password_hash`, the next successful sign-in will replace it with a PHP password hash automatically. New accounts should be created with the CLI script above, not by inserting plain-text passwords in SQL.

To replace an existing user's password, run:

```powershell
php database/reset_password.php "admin@example.com"
```

Enter a new password of at least 12 characters when prompted. The password input is visible in the console; use this script only in a trusted local terminal.

## Compensation planning workflow

The Compensation Planning page is available to signed-in `admin`, `superadmin`, and `hr_manager` accounts. It uses the project employee table; it does not invent salary, grade, manager, regularization, or performance values. HR enters those inputs from the authoritative records on the page. Employees with missing or ineligible data are explicitly listed with an exclusion reason when recommendations are generated.

- Save a salary structure or merit matrix to create a new immutable version. Matrix bands use inclusive starts and exclusive ends; the final band must have no upper limit.
- Create a Draft cycle with active structure and matrix versions, eligibility dates, minimum rating, and a fixed or eligible-payroll-percentage budget. Optional department allocations cannot exceed the total budget.
- Generate or replace recommendations only while the cycle is Draft. Opening requires a positive budget, at least one eligible recommendation, and an active manager login linked to every assigned manager employee.
- Managers sign in with the `manager` role and see only their own direct reports. They can set final percentages while the cycle is Open; the page previews the salary and running team/department totals. Submission enforces the grade maximum, override-comment, completeness, and budget rules.
- HR reviews submitted rows while the cycle is Open, approving or returning them with a note. Once every recommendation is approved, HR submits the cycle for final approval and moves it forward through Approved, Locked, and Published.
- HR may top up an Open cycle's total budget; top-ups and workflow decisions are audited. Draft cycles can be edited or deleted; other cycle states cannot be edited.

## Claims review workflow

The Claims page reads the seeded `claims` table joined to `employees`. Signed-in `admin`, `superadmin`, and `hr_manager` users can review requests:

- The Claims and Base thresholds tabs show the reimbursement queue and the financial, timeline, documentation, and employment-standing policy baselines.
- Each row's View action opens claim context and policy pre-checks; approve, return, and reject actions are available in that detail view.
- Employment eligibility is evaluated strictly as `PASS` or `FAIL`: active full-time employees pass medical, transportation, and official business claims; part-time employees pass transportation only when within its ceiling; contractual employees fail standard claims; and inactive, unverified, or unrecognized cases fail. Contractual official business also fails because project cost-center approval is not recorded in the current schema.
- The current employee schema does not distinguish regularized from probationary full-time staff, so active `Full-time` records are treated as full-time regularized for this check. Receipt contents, exact receipt-to-request amount matching, and medical monthly accumulation still require HR verification.
- `Pending Approval` and `Returned for Revision` claims can be reviewed.
- Approving changes the status to `Approved`.
- Rejecting changes the status to `Rejected` and requires a note.
- Returning for revision changes the status to `Returned for Revision` and requires a note.
- `Approved` and `Rejected` are final states and cannot be reviewed again in the UI.

Review actions are saved transactionally. The reviewer, timestamp, decision, and optional note are appended to the existing `claims.review_notes` field, preserving earlier review entries. Receipt downloads are served through the authenticated app route and expect files under `uploads/receipts/`.

## HMO benefits workflow

The HMO page is available to signed-in `admin`, `superadmin`, and `hr_manager` accounts.

- Provider plans store annual, company-paid primary premiums and coverage details. Plan tiers must match an employee's `job_tier`.
- Enrollment is limited to employees marked `Active` and `Full-time`, with no current active enrollment. The employee table does not distinguish regularized from probationary employees, so active full-time employees are treated as regularized until that source data is added.
- Up to two dependents can be attached to an enrollment. The HR enrollment form records dependent name, age, relationship, sex, civil status, and a required birth-certificate upload. It enforces spouse, children youngest-to-oldest, then parent ordering; a parent is accepted only for plans that allow parents and when age 65 or younger.
- Dependent monthly rates are fixed by employee tier: ₱1,000 for Entry-Level, ₱1,500 for Supervisory, and ₱2,500 for Executive, per dependent. The plan tier determines the rate automatically; HR cannot override it on the plan or enrollment form. Dependents are always 100% employee-paid through payroll. The primary employee premium is company-funded. Queue generation is repeatable and creates at most one deduction per dependent and payroll month.
- Enrollment rows provide a View action for a detail/edit modal. Editing preserves audit and payroll history; removing a dependent deactivates the coverage record and voids their still-queued current/future deductions rather than deleting payroll-referenced data. Enrollment termination is permanent and is only available from the detail modal.
- Payroll deductions move from `Queued` to `Exported` to `Processed`. The CSV download is a snapshot; marking a queue exported/processed is a separate audited action.
- Annual company premium costs are estimated from the primary plan premium by covered calendar months. Employee-paid dependent premiums are excluded. The database does not contain provider visits, illness events, or insurer utilization reports, so clinical utilization reporting must be supplied by the HMO provider.
- Plan edits, enrollments, terminations, and payroll queue actions are recorded in `hmo_audit_logs`. Existing queue records prevent deletion of their enrollment/dependent records so payroll history remains available. Birth certificates are stored with randomized names, access is restricted to HR roles through the authenticated route, and direct Apache access is denied by `.htaccess`.
