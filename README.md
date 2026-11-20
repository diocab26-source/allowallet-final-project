# Allo-Wallet

**Make every peso count.**

A beginner-friendly student budgeting app with HTML, CSS, JavaScript, **PHP**, and **MySQL**. It runs in XAMPP without installing any packages.

Phase 7 adds an account menu with Settings, password changes, Light / Dark / System themes, and a loading skeleton for the totals. Theme preference is kept in this browser. Password changes check the current password, use the sign-in cooldown after repeated failures, and refresh the session token after success.

## Run the app

1. Open the XAMPP Control Panel.
2. Start **Apache** and **MySQL**.
3. Open **http://localhost/ALLOWALLET/** for the public student and employee portal. Students can register; employees sign in with accounts created by an administrator.
4. Open **http://localhost/ALLOWALLET/login.php** to create or sign in to the administrator account. The admin dashboard can create employee accounts and manage student and employee access.

The database has been initialized in this workspace. When setting up a new copy:

1. Open **http://localhost/phpmyadmin/**.
2. Choose **Import**, select **database/allo_wallet.sql**, and click **Go**.
3. Open the app. The SQL file creates the database and its tables without deleting existing records.

Phase 5 added the nullable `wallet_settings.next_allowance_date` column and the `category_budgets` table. Import the updated SQL file again in phpMyAdmin to apply these additive changes without removing wallet data.

Phase 6 adds the `goals` table, a nullable `transactions.goal_id`, and the `withdrawal` transaction type. Import the latest SQL file again. On the first wallet load afterward, the app copies the existing settings goal into goal 1 and links older savings entries to it.

### Upgrade an existing database

Import **database/allo_wallet.sql** again in phpMyAdmin after replacing the app files. Its `CREATE TABLE IF NOT EXISTS` statements and idempotent `ALTER TABLE` statements add recovery fields, allowance settings, goals, budgets, and the role-based student portal tables without deleting wallet owners, settings, or transactions. Existing owner accounts keep working. The import is safe to repeat.

If your database uses a different username, password, or port, edit **config.php**. The defaults are host **127.0.0.1**, port **3306**, database **allo_wallet**, username **root**, and an empty password.

Open the app through localhost. Opening the HTML file directly cannot run PHP.

## Login and account setup

The app has three roles. The original `wallet_owner` account is the administrator and retains the personal wallet. Students can register through the public portal and manage their own allowance ledger. Administrators create employee accounts; employees can issue allowances and manage their own allowance entries. There is no default email or password. The first administrator account is created from **login.php**.

- **login.php** sets up and authenticates the administrator. **portal.php** is the public student and employee sign-in page; student registration creates student-role accounts only.
- **admin.php**, **employee.php**, and **student.php** each require their matching role before showing a dashboard.
- Passwords use **password_hash()** and **password_verify()**. Employee and student passwords can be reset by an administrator.
- **session.php** starts the PHP session. A successful login creates a fresh session ID.
- Sign-in and password recovery share an IP-based cooldown after five failed attempts in ten minutes. It clears after a successful sign-in or password reset.
- Open the owner name menu to change the password. It asks for the current password and uses the same IP cooldown; five incorrect current-password attempts pause further tries for ten minutes. A successful change refreshes the session ID and request token.
- The menu also links to Settings, offers Light / Dark / System, and contains Log out. Theme preference is stored in this browser's local storage; choosing System follows the device theme.
- **Forgot password?** lets the administrator reset with the saved recovery answer. An older admin account can set its recovery question during a one-time reset from localhost. Students and employees contact the administrator for a password reset.
- **index.php** checks the session before displaying the wallet.
- **api.php** also checks the session, so opening the API directly does not bypass login.
- **logout.php** clears the session and its cookie.

Passwords and recovery answers are not stored as plain text or saved in browser storage. This project does not send recovery emails. Older administrator accounts without a recovery question can set one during password recovery from the XAMPP computer at localhost. For remote access, or if the recovery fields are missing from the database, use the manual reset steps below.

### Manually reset an older account

1. In a local terminal, create a password hash with XAMPP's PHP CLI. Replace the example with your new password:

   ```text
   C:\xampp\php\php.exe -r "echo password_hash('your-new-password', PASSWORD_DEFAULT), PHP_EOL;"
   ```

2. Copy the resulting hash. In phpMyAdmin, open **allo_wallet → wallet_owner → Browse**, edit row **id 1**, paste the hash into **password_hash**, and save. Do not put the plain password in that column.
3. Return to the app and sign in with the new password.

The reset form shows these steps when manual administrator recovery is needed.

## Form validation

Required wallet fields are marked. Dates and allowance source are optional. Errors appear beside the affected fields, and submitting an invalid form focuses the first error. Editing an invalid value updates its message. **Clear** resets the expense form and its errors.

| Field | Rule |
| --- | --- |
| Allowance, expense, savings, and goal amounts | PHP 0.01 to PHP 1,000,000.00 |
| Amount formatting | Numbers with up to two decimal places; no commas or exponents |
| Expense description | Nonblank, up to 60 characters |
| Expense category | Choose one of the provided categories |
| Allowance source | Optional; up to 40 characters. It appears as `Allowance · Source` in the description |
| Transaction date | Optional; January 1, 2000 through today in the Philippines |
| Next allowance date | Optional; today or a later date |
| Monthly category budget | Optional; positive amount up to PHP 1,000,000.00 for each expense category |
| Savings contribution | Cannot exceed the available balance; it belongs to the selected active goal |
| Savings withdrawal | Cannot exceed the selected goal's saved amount |
| Edited allowance or expense | Uses the same amount, description, category, and date checks; an edit cannot use money already set aside for savings |
| Goal name or account name | Nonblank, up to 50 characters |
| Login email | A valid email address, up to 254 characters |
| Password | 8–64 characters; bcrypt also limits UTF-8 input to 72 bytes |
| Password confirmation | Must match the password during account setup |
| Recovery question | Choose one of the five account setup questions |
| Recovery answer | Required; up to 100 characters and 72 UTF-8 bytes; case-insensitive |
| Current password when changing it | Required; up to 64 characters and 72 UTF-8 bytes |
| New password when changing it | 8 to 64 characters; bcrypt also limits UTF-8 input to 72 bytes |
| Confirmation when changing password | Must match the new password |
| New password | 8–64 characters; bcrypt also limits UTF-8 input to 72 bytes |
| New password confirmation | Must match the new password |

**assets/validation.js** contains the wallet checks. Start with **moneyError()** and **validateForm()**. **assets/auth.js** handles the login and account setup checks.

PHP repeats the checks before saving, including when JavaScript is bypassed. Invalid wallet requests return **422** with a **fields** object; JavaScript uses those names to display the errors beside the correct inputs. No invalid records are saved.

## Try it

1. Add **₱1,000** as allowance and optionally add a source such as **Parents**.
2. Add **₱85** for lunch under **Food & drinks** and optionally choose its date.
3. Add **₱100** to savings.
4. Your available balance is now **₱815**.
5. Use **Edit** beside an allowance or expense to change its details.
6. Remove an entry with **×**, then choose **Undo** in the brief message to restore it.
7. Use the goal selector to switch goals, add another goal, rename or retarget one, or archive it.
8. Add savings to the selected goal, then withdraw some of it back to your available balance.
9. Refresh the page. PHP loads your entries and goal balances from MySQL.
10. Use the activity search and filters, then choose **Show more** to reveal additional matching entries.
11. Review category spending in **Insights**, download the filtered activity as CSV, or choose **Print / Save as PDF**.
12. In **Settings**, set your next allowance date and add a monthly limit for one or more categories. The small money thought uses this wallet's recent activity and stays the same until the next page load.
13. Open the owner menu, switch between Light, Dark, and System, then open Change password and use your current password to set a new one.

Expenses can take the balance below zero, so you can record actual spending. Savings cannot exceed the available balance, and a withdrawal cannot exceed its goal's saved amount. A savings entry cannot be removed if a withdrawal has already used part of it. Removing a withdrawal returns that amount to its goal.

Allowance and expense forms accept an optional date, defaulted to today. Chosen dates are stored at 12:00 UTC so the calendar date stays the same in the app's Philippine timezone. Entries are listed by date and then ID. Delete Undo is available for about six seconds; restoring an entry gives it a new database ID and its original date.

Activity search, type, category, and date filters run in the browser. The list shows ten rows at a time. Insights group expenses by category for the selected week, month, or all-time period; the text table gives the amount and percentage alongside the hand-drawn chart. **Download CSV** exports every entry matching the current activity filters, with a UTF-8 BOM for Excel. **Print / Save as PDF** prints the wallet summary and activity table.

The summary cards can show this week, this month, or all time. Available balance always stays all-time. Comparison notes use the same part of the previous week or month when both periods have entries, and otherwise keep their short reminder. The hero gives a daily spending guide when a next allowance date is set, and a gentle warning when the balance is zero or below. Monthly budgets track expenses in the current month; bars turn amber at 80% and show an over-budget message at 100% or more. The small money thought uses wallet activity when it can and rotates through five short tips otherwise.

Savings can be split across active goals. Archiving keeps a goal and its history; you can still view it and withdraw its remaining savings. Each selected goal shows a projection based on its net savings over the last four weeks when there is enough recent activity.

## How the backend works

1. You submit a form.
2. JavaScript sends the form data to **api.php** using **fetch()**.
3. PHP checks the values and uses a prepared SQL statement to update MySQL.
4. PHP returns the updated wallet as JSON.
5. JavaScript updates the totals and activity list.

The screen updates only after the database confirms a change. If the connection fails, the form keeps your input and shows **Try again**. That button reloads the wallet; it does not repeat the previous save.

## Files to learn from

| File | What it does |
| --- | --- |
| index.php | The protected wallet page and its forms |
| index.html | Redirects old links to index.php |
| login.php | Administrator setup, sign in, and recovery |
| portal.php | Public student and employee portal and student self-registration |
| admin.php | Administrator account dashboard |
| employee.php | Employee allowance desk |
| student.php | Student allowance ledger |
| logout.php | Ends the login session |
| session.php | Shared session settings and login check |
| assets/styles.css | Colors, layout, and mobile styling |
| assets/app.js | Form events, account menu and theme, goal switching and projections, period summaries, budgets, withdrawals, money thought, activity filters, insights, CSV and print |
| assets/validation.js | Inline validation for wallet forms, password changes, transaction edits, settings, and budgets |
| assets/auth.js | Inline validation and password visibility for login and recovery |
| config.php | The MySQL connection using PHP's built-in PDO |
| api.php | Legacy administrator wallet actions |
| api/auth.php | JSON sign-in, student registration, and session information |
| api/accounts.php | Role-protected account CRUD |
| api/ledger.php | Role-protected allowance and expense CRUD |
| postman/Allo-Wallet.postman_collection.json | Postman requests for authentication and REST endpoints |
| docs/DEPLOYMENT.md | XAMPP, hosting, and GitHub setup checklist |
| database/allo_wallet.sql | Creates the database and tables |
| assets/logo.svg | The green-and-gold coin and graduation cap logo |

Start with **config.php**, then look at the **add_transaction** case in **api.php**, and finally **addTransaction()** in **assets/app.js**.

## Database tables

**wallet_owner** stores the owner's name, email, password hash, and optional recovery question and answer hash.

**login_attempts** stores recent sign-in, recovery, and password-change attempts by IP so the app can apply its cooldown.

**transactions** stores each entry's ID, type, amount, description, category, optional goal ID, and creation time. Withdrawal entries record money moved back from a goal.

**wallet_settings** stores the original goal values for the upgrade, next allowance date, and whether an earlier browser wallet has been imported.

**category_budgets** stores optional monthly limits for expense categories.

**goals** stores each savings goal's name, target, creation date, and active status.

**portal_accounts** stores student and employee accounts, their role, active status, and password hash. The administrator remains in the original **wallet_owner** table.

**allowance_entries** stores per-student allowance and expense entries plus the role and account that created each one.

Import the latest SQL file again after an upgrade to add `goals`, `transactions.goal_id`, the withdrawal type, and earlier recovery, allowance-date, and budget changes. Existing wallet records stay in place. The first app load creates goal 1 from `wallet_settings` and assigns old savings rows to it.

Amounts use whole centavos: **8500 means ₱85.00**. This avoids decimal rounding problems.

**Available balance = Total allowance − Total expenses − Total savings + Total withdrawals**

Each goal's saved amount is its savings entries minus withdrawals. The savings summary adds the net amounts across all goals.

Dates are saved in UTC. A date chosen in the form is stored at noon UTC; entries without a chosen date keep the current UTC time. The activity list sorts by date, then ID.

## PHP actions

| Request | Action | Purpose |
| --- | --- | --- |
| GET api.php | No action needed | Load the wallet |
| POST api.php | add_transaction | Add allowance, expense, savings, or a withdrawal; allowance and expense can include a date |
| POST api.php | delete_transaction | Remove an entry by ID |
| POST api.php | update_transaction | Edit an allowance or expense and check the resulting available balance |
| POST api.php | restore_transaction | Restore a removed entry with its original date |
| POST api.php | save_goal | Rename or retarget an active goal |
| POST api.php | create_goal | Add a new active goal |
| POST api.php | archive_goal | Archive an active goal while keeping its history |
| POST api.php | save_settings | Save the optional next allowance date |
| POST api.php | save_budgets | Save or clear optional monthly category limits |
| POST api.php | change_password | Check the current password, apply the shared IP cooldown, and change the password |
| POST api.php | import_wallet | Bring in the earlier browser wallet |

All API requests require a login session. POST requests contain JSON. JavaScript includes a session token from the first GET request so another website cannot submit changes through your browser. PHP checks amounts, descriptions, categories, optional sources and dates, monthly limits, goal IDs, the available balance, and each goal's saved amount before a withdrawal. Invalid wallet values return HTTP 422 with field names; repeated wrong current-password attempts return HTTP 429 with a wait time. A successful password change regenerates the session ID and returns a fresh request token.

### Role-based REST API

The REST endpoints use standard HTTP methods and return JSON. Sign in through **POST api/auth.php** with `action`, `email`, and `password`; Postman keeps the session cookie and the response includes the token required for mutations in the `X-Allo-Token` header.

| Endpoint | Methods | Access |
| --- | --- | --- |
| api/auth.php | GET, POST, DELETE | Sign in, inspect the current session, and sign out |
| api/accounts.php | GET | Admin: all accounts; employee: active students |
| api/accounts.php | POST, PUT, PATCH, DELETE | Admin only; delete disables an account and preserves its history |
| api/ledger.php | GET | Student: own ledger; employee/admin: a selected active student's ledger |
| api/ledger.php | POST | Student: own expense; employee/admin: allowance for a student |
| api/ledger.php | PUT, PATCH, DELETE | Student: own expenses; employee: allowances they created; admin: any ledger entry |

Ledger API amounts use whole centavos (`8500` means PHP 85.00). Import **postman/Allo-Wallet.postman_collection.json** to exercise the endpoints. To register a student, run **Open portal (get signup token)** and then **Register student**; the request saves the session token and student ID. To sign in to an existing account, set the collection's email and password and run **Sign in**. Enter an active student ID for employee/admin ledger requests.

## Keep earlier browser entries

If this browser has data from the original version, an **Import my entries** button appears while the database wallet is empty and its goal is unchanged.

Click it before adding new records. The import includes the old goal, amounts, and dates. It runs once and keeps the browser copy untouched. Open the same localhost address you used before so the browser can find that copy.

## Useful things to know

- The original wallet remains the administrator's personal wallet. The student portal has separate accounts and allowance ledgers.
- Refresh the page to see changes made from another browser or tab.
- Clearing browser storage does not delete records saved in MySQL.
- In phpMyAdmin, open **allo_wallet**, then **transactions → Browse**, to see saved entries. Use **Export** there to back up the database.
- If a database error appears, check that MySQL is running, the SQL file was imported, and the settings in **config.php** match your XAMPP setup.
