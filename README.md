## Gated Document Signing (PHP/MySQL)

Simple web app you can deploy inside a subfolder of an existing PHP website.

### What it does
- Admin creates **projects** (each project has a secret link).
- Admin uploads an **NDA PDF** and **project files**.
- Visitors open the project link, enter their **email**, sign once, and then can return and access files without re-signing.

### Requirements
- PHP 8.1+ (PDO enabled)
- MySQL 5.7+ / MariaDB 10.2+
- Apache recommended (for `.htaccess` rewrite). Nginx is fine too (you’d add an equivalent rewrite rule).

### Setup (local or hosting)
1. Create a MySQL database and user.
2. Copy `config.example.php` to `config.php` and fill in DB credentials and an app secret.
3. Import `schema.sql` into your database.
4. Point your web server to the `public/` directory as the document root **or** browse to `public/index.php`.
5. Visit `admin/install.php` to create the first admin account, then go to `admin/index.php`.

### File storage
Uploads go under `storage/` (kept outside `public/`). Downloads are served through `public/download.php` only after permission checks.

