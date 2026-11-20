# Deployment and Git workflow

## Local XAMPP deployment

1. Start Apache and MySQL in the XAMPP Control Panel.
2. Import `database/allo_wallet.sql` in phpMyAdmin. The script is additive and keeps the original wallet data.
3. Open `http://localhost/ALLOWALLET/`. The public student portal is the entry page.
4. Open `http://localhost/ALLOWALLET/login.php` for the administrator setup or sign-in.
5. From the admin dashboard, create employee accounts. Students can register from the public portal.

## Hosting checklist

This project is written for PHP 8.2 or later and MySQL/MariaDB with PDO MySQL enabled.

1. Create a MySQL database and a database user with access only to that database.
2. Configure `ALLO_DB_HOST`, `ALLO_DB_PORT`, `ALLO_DB_NAME`, `ALLO_DB_USER`, and `ALLO_DB_PASSWORD` in the hosting control panel. Do not commit database passwords.
3. Upload the PHP files and `assets/` directory. Import `database/allo_wallet.sql` once.
4. Enable HTTPS before allowing users to sign in. The session cookie is marked secure automatically when PHP receives an HTTPS request.
5. Visit `/login.php` to create the one administrator account. Then create employees from the admin dashboard.
6. Check `/portal.php`, the three role dashboards, and each API method after deployment. Keep a database backup before future schema changes.

The legacy account recovery setup is restricted to localhost because this project has no email delivery or email verification. On hosted instances, the administrator must use an already configured recovery answer or the documented database reset procedure.

## Git and GitHub

From the project directory, initialize a repository, review the staged files, and commit the application:

```sh
git init
git add .
git status
git commit -m "Build role-based allowance portal"
```

Create an empty private or public repository in your GitHub account, then connect and push it:

```sh
git branch -M main
git remote add origin https://github.com/YOUR-ACCOUNT/YOUR-REPOSITORY.git
git push -u origin main
```

Keep student data, credentials, production database exports, and `.env` files out of Git. The provided `.gitignore` excludes common local secrets and generated files.
