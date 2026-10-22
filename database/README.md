# Database setup

1. Start MySQL in the XAMPP Control Panel.
2. In phpMyAdmin, create a database named `payroll_db`.
3. Select that database and import `schema.sql`.
4. The project reads database settings from the root `.env` file. It is initialized with the local defaults `127.0.0.1:3307`, database `payroll_db`, user `root`, and an empty password. Edit `.env` if your local MySQL credentials differ. Keep `.env` private; `.env.example` is safe to share.
5. From the project root, create the first sign-in account:

   ```powershell
   php database/create_user.php "admin@example.com" "Payroll Admin"
   ```

   Enter a password of at least 12 characters when prompted. The password input is visible in the console; use this script only in a trusted local terminal.

The script hashes the password before storing it. There is no public registration route. To disable an account, set its `is_active` value to `0` in the `users` table.

If a password was entered directly as plain text into `users.password_hash`, the next successful sign-in will replace it with a PHP password hash automatically. New accounts should be created with the CLI script above, not by inserting plain-text passwords in SQL.

To replace an existing user's password, run:

```powershell
php database/reset_password.php "admin@example.com"
```

Enter a new password of at least 12 characters when prompted. The password input is visible in the console; use this script only in a trusted local terminal.

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
