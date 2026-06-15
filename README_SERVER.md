# Dynamic PHP/MySQL Training Site

This folder is the dynamic version of the LMS DEMO site.

## Files

- `index.php` public training site
- `admin/` admin panel for modules, videos, and reflections
- `assets/styles.css` same visual design as the static version
- `assets/site.js` small interactions for video posters and reflection form
- `database/schema.sql` database tables
- `database/seed.sql` starter modules and resources
- `config.php` database and admin settings

## Install On Server

1. Upload the full `nex` folder to your hosting account.
2. In phpMyAdmin, open the database connected to this app.
3. Import `database/schema.sql`.
4. Import `database/seed.sql` once.
5. Open `config.php` and confirm the database host/name/user/password are correct.
6. Open the site in the browser:

```txt
https://your-domain.com/nex/index.php
```

7. Open admin:

```txt
https://your-domain.com/nex/admin/login.php
```

If you uploaded the contents of `nex` directly into the domain root, use:

```txt
https://your-domain.com/index.php
https://your-domain.com/admin/login.php
```

For your current domain, first open:

```txt
https://99.devmode.business/install.php
```

If something still fails, open:

```txt
https://99.devmode.business/diagnostics.php
```

After install works, delete these files from the server:

```txt
install.php
diagnostics.php
```

Default admin login:

```txt
Username: admin
Password: ChangeThisAdminPassword!
```

Change this before going live. You can set `ADMIN_PASSWORD` in `config.php`, or set `ADMIN_PASSWORD_HASH` and leave `ADMIN_PASSWORD` unused.

## Change Videos

Go to:

```txt
/nex/admin/login.php
```

Edit a module and update the `Video URL` field. You can paste a normal YouTube link like:

```txt
https://youtu.be/ACoOxcK8y6I?si=4vLtdb34ndr2evOV
```

## Add Or Delete Modules

In the admin panel:

- Use `Add Module` to create a new module.
- Use `Edit` to update title, image, video, focus bullets, timing, and comment prompt.
- Use `Delete` to remove a module and its reflections.
- Use `Sort Order` to control display order.
- Uncheck `Active` to hide a module without deleting it.

## Reflection Anti-Bot Protection

Reflection submissions include:

- CSRF token
- Hidden honeypot field
- Minimum time before submit
- Per-IP hourly rate limit
- Server-side length validation

These are basic protections. For high-traffic public sites, add a stronger CAPTCHA or server firewall rule.

## Move From Test URL To Final Domain

1. Copy all project files to the final domain or subdomain document root.
2. Export the current MySQL database from phpMyAdmin.
3. Create/import that database on the final hosting account.
4. Update the live database values in `config.php`:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_database_name');
define('DB_USER', 'your_database_user');
define('DB_PASS', 'your_database_password');
```

5. Open the final URL and test:

```txt
/login.php
/index.php
/admin/login.php
```

6. Make sure hosting email/PHP `mail()` or SMTP is configured, otherwise user approval/reset emails will not be delivered.
7. After confirming everything works, remove `install.php` from the live server.
