# Stock App Backend

Laravel 11 API backend with Sanctum authentication, role-based authorization (Spatie Permission), betting schema, announcements, odd settings CRUD, and Thai 2D ingestion command.

## Requirements

- PHP 8.2+
- Composer 2+
- MySQL or MariaDB
- Node.js 18+ (optional, only for frontend assets)

## Quick Installation

1. Clone and install dependencies.

```bash
git clone <your-repo-url>
cd stock_app_backend
composer install
```

2. Create environment and app key.

```bash
cp .env.example .env
php artisan key:generate
```

3. Configure database in `.env`.

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=stock_app_backend
DB_USERNAME=root
DB_PASSWORD=
```

4. Run migrations and seed baseline data.

```bash
php artisan migrate --seed
```

5. Start local server.

```bash
php artisan serve
```

API base URL in local development:

```text
http://127.0.0.1:8000/api/v1
```

## OpenAPI Documentation

OpenAPI (Swagger) spec for current API routes is available at:

```text
docs/openapi.yaml
```

You can import this file into Swagger UI, Postman, Insomnia, or any OpenAPI-compatible tool.

## Authentication

- Auth uses Laravel Sanctum bearer tokens.
- Public routes:
  - `POST /api/v1/register`
  - `POST /api/v1/login`
- Register payload requires `username`, `email`, `password`, and `password_confirmation`.
- Bet create payload supports per-number stake via `bet_numbers: [{ number, amount }]`; `total_amount` is computed as the sum of all number amounts. Leading-zero numbers like `01` (2D) and `001` (3D) are accepted and normalized numerically.
- Authenticated routes require:

```http
Authorization: Bearer <token>
```

## Roles and Access

- `user`, `vip`, and `admin` roles are managed by Spatie Permission.
- Authenticated read endpoints are available to both roles.
- Write endpoints for announcements and odd settings are under `/api/v1/admin/*` and require `admin` role.
- Admin can switch customer users between `user` and `vip` via `PATCH /api/v1/admin/users/{user}/role` with payload `{ "role": "user" | "vip" }`.

## 2D Result Read APIs

Authenticated users (`user` or `admin`) can read persisted 2D results:

- `GET /api/v1/two-d-results`
  - Query params: `page`, `page_size`, `stock_date`, `open_time`, `history_id`
- `GET /api/v1/two-d-results/latest`
- `GET /api/v1/two-d-results/last-5-days`
  - Returns all records within the latest 5 distinct `stock_date` values, ordered by newest `stock_datetime`.

## Background Scheduler (Thai 2D)

Command:

```bash
php artisan twod:fetch-live
```

This command fetches live data from `https://api.thaistock2d.com/live` and upserts into `two_d_results` by `history_id`.

Scheduled in `routes/console.php` (Bangkok timezone):

- 11:00
- 12:01
- 15:00
- 16:30

To run scheduler in production, configure cron:

```cron
* * * * * cd /path/to/stock_app_backend && php artisan schedule:run >> /dev/null 2>&1
```

## Useful Commands

```bash
php artisan optimize:clear
php artisan migrate:fresh --seed --force
php artisan test
php artisan schedule:list
```

## Bet Settlement Testing Seeder

Seed deterministic full betting-flow snapshot data (2D results first, then bets):

```bash
php artisan db:seed --class=Database\\Seeders\\BetSettlementTestingSeeder
```

Seeded statuses include:

- `PENDING`
- `ACCEPTED` (with both `WON` and `LOST` outcomes based on seeded 2D results)
- `REJECTED`
- `REFUNDED` (with payout status `REFUNDED`)

Seeder is idempotent and safe to re-run.

## Test Notes

- Feature tests cover auth lifecycle, authorization, announcements, odd settings, and betting schema integrity.
- If you use a different DB engine locally, ensure your `.env` DB driver is consistent before running tests.
