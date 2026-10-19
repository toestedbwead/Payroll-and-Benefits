# Database setup

1. Start MySQL in the XAMPP Control Panel.
2. In phpMyAdmin, create a database named `payroll_db`.
3. Select that database and import `schema.sql`.
4. The default connection uses `127.0.0.1:3307`, database `payroll_db`, user `root`, and an empty password. Override these with the `PAYROLL_DB_HOST`, `PAYROLL_DB_PORT`, `PAYROLL_DB_NAME`, `PAYROLL_DB_USER`, and `PAYROLL_DB_PASSWORD` environment variables when needed.
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
