# Problems

- Global blocker: PHP sqlite drivers (`pdo_sqlite`, `sqlite3`) missing. This prevents migration-dependent verification and causes `php artisan optimize:clear` cache stage to fail when cache store hits database.
