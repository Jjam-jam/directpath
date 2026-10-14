# Database configuration

The application connection is created by `config/config.php` in `getDB()`.
That file selects settings in this order:

1. Environment variables (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, `DB_CHARSET`, and `APP_URL`).
2. `config/database.local.php` for local requests, or `config/database.production.php` for hosted requests.
3. XAMPP defaults only for local hostnames (`localhost`, `127.0.0.1`, or `::1`).

For InfinityFree, copy `database.production.php.example` to
`database.production.php` and fill its placeholders with the values from the
InfinityFree Control Panel. The exact database host, database name, username,
and password are account-specific; do not use `localhost` unless InfinityFree
explicitly lists it as your MySQL host. Set `APP_URL` to the deployed site's
HTTPS URL. Alternatively, provide the corresponding environment variables.

If production settings are missing or still contain placeholders, the app
returns a generic service-unavailable message and writes diagnostic details
to the PHP/server error log. It never sends a DSN, username, password, or PDO
exception text to the browser.

XAMPP keeps working without a local override and uses `localhost`, `root`, an
empty password, and `directpath_db`. If local settings differ, copy
`database.local.php.example` to `database.local.php` and edit that ignored file.

`dbh.php` remains as a backward-compatible wrapper around `getDB()`; it no
longer contains a separate credential set. The unused temporary duplicate
connection/config file was removed.
