# PSX Backend (Laravel 10)

REST API for the PSX analysis platform.

## Stack

- **PHP** 8.1+ / **Laravel** 10
- **Database**: PostgreSQL only (single database with two user roles: `postgres` for DDL, `psx_user` for runtime)
- **Auth**: Laravel Sanctum personal access tokens (bearer only; the only cookie is the short-lived Google sign-in state); Google sign-in via Socialite (stateless). The `/admin` panel alone uses a session guard (`admin`)
- **Admin UI**: Filament 3.3 (Livewire 3)
- **HTTP Client**: Guzzle 7
- **Dev tools**: Laravel Pint (linter), Ignition (error pages), Sail (Docker), PHPUnit 10 + Mockery

## Common Commands

```bash
php artisan serve                        # dev server → :8000
php artisan tinker                       # REPL
php artisan pint                         # lint / auto-fix code style
./vendor/bin/phpunit                     # run tests
php artisan test                         # alias for phpunit
php artisan route:list                   # view all routes
php artisan cache:clear                  # clear app cache
```

⚠️ **DO NOT use migrations or seeders.** Database schema is managed externally. See schema.sql in project root.

## Directory Structure

```
app/
├── Http/Controllers/API/   # All API controllers (one per domain)
├── Models/                 # Eloquent models
├── Services/               # Business logic services (PaddleService, etc.)
├── Traits/                 # Reusable model traits (HasUuid, HasMarketData, etc.)
├── Providers/              # Service providers (AppServiceProvider registers the UUID PersonalAccessToken model)
└── Console/Commands/       # Artisan commands
database/
├── sql/                    # SQL utilities (DDL not managed here)
routes/
└── api.php                 # All routes (public on top, auth:sanctum web group at bottom)
```

## Controllers

All controllers extend `App\Http\Controllers\API\BaseController`. Pattern:

```php
class FooController extends BaseController
{
    public function getData(Request $request)
    {
        // validate, query, return
        return $this->sendResponse($data, 'Success message');
    }
}
```

## Models

Most domain models extend `PgsqlModel` (which forces the `pgsql` connection). Core models:

| Model | Table | Notes |
|-------|-------|-------|
| `Stock` | `stocks` | UUID PK; links to exchange, sector, asset |
| `StockPrice` | `stock_prices` | bigint PK; UNIQUE(stock_id, date); OHLC + volume |
| `Index` | `indices` | Market indices (KSE100 etc.) |
| `IndexPrice` | `index_prices` | Daily index OHLC + volume |
| `IndexStock` | `index_stocks` | M2M with weightage |
| `Sector` | `sectors` | name + total_stocks count |
| `BoardMeeting` | `board_meetings` | bigint PK; UNIQUE(stock_id, date, time) |
| `FinancialData` | `financial_data` | Flat row/col table: keyed by (symbol, statement, type, table_name, identifier, header) |
| `FinancialRatio` | `financial_ratios` | UUID PK; sector_id nullable (null = universal ratio); metadata JSONB |
| `UinSettlementData` | `uin_settlement_data` | UNIQUE(stock_id, settlement_date) |
| `SectorDataPoint` | `sector_data_points` | dimensions JSONB; UNIQUE(sector_id, dimensions, year) |

### Financial results (newer, JSONB-based)
`financial_results` (owner: `psx_user`) — stores structured results as JSONB per stock+period. Separate from `financial_data` (flat key/value). UNIQUE(stock_id, period_type, period_name).

### Technical indicators
`stock_indicators` (owner: `psx_user`) — JSONB `data` keyed by (stock_id, timeframe, date). Timeframe is a string (e.g. `"1D"`, `"1W"`).

### FIPI/LIPI tables
- `fipi_lipi_trading_data` — sector-level flows; currency defaults to **USD** (note: inconsistency vs market table)
- `fipi_lipi_market_data` — market-type flows; currency defaults to **PKR**
- 6 pre-built views: `v_sector_view`, `v_investor_type_view`, `v_market_view`, `v_daily_sector_summary`, `v_daily_investor_summary`, `v_fipi_activity`, `v_top_sectors_by_value`

### Infrastructure / operational tables
| Table | Purpose |
|-------|---------|
| `jobs_history` | ETL/scraping job runs — status: running/success/failed/skipped |
| `stock_rescrape_queue` | Queue for re-fetching stale stock data (owned by `psx_user`) |
| `stock_share_changes_audit` | Audit trail for shares-outstanding changes |
| `scoring_criteria` | Weighted rules for performance scoring (metric_name UNIQUE) |
| `result_logs` | EPS snapshot log by date + symbol |
| `splits` | Stock splits — keyed by **symbol** string, not stock_id |
| `payout_history` | Raw payout records (dividend/bonus/rights) separate from corporate_actions |

### Portfolio / user data tables
`portfolios` → `trades` / `buy_lots` → `completed_trades` → `deductions` + `tax_payables` + `dividends`

### Subscription / billing tables
`subscription_plans` → `subscription_plan_features` / `subscription_plan_prices` → `user_subscriptions` → `subscription_transactions` + `subscription_feature_usage`

### DB users
Two Postgres users share the schema: `postgres` (DDL owner of most tables) and `psx_user` (owns scraping/ingestion tables: `financial_results`, `stock_indicators`, `stock_rescrape_queue`). The Laravel app connects as `psx_user` in production.

## Authentication

Authenticated web routes sit in the `['auth:sanctum', 'abilities:web', 'active']` group. Web logins issue `psx-web` tokens (ability `web`, 30-day expiry, newest 5 kept); MCP connections issue non-expiring `mcp:*` tokens (ability `mcp`) that only the `/mcp` endpoint accepts — never prune or revoke them from auth flows. Most data-read routes are public.

Email/password signups must verify their email (signed link → `GET /api/auth/email/verify/{id}/{hash}` → frontend `/auth/verify-email?status=`). Google sign-in: `GET /api/auth/google/redirect` creates the OAuth state and binds it to the browser with a 10-minute httpOnly cookie (`google_oauth_state`, path `/api/auth/google`); the callback checks it before exchanging Google's code (`invalid_state` otherwise), then hands the frontend a one-time code in the URL fragment (`/auth/callback#code=`), exchanged at `POST /api/auth/google/exchange`; tokens never appear in URLs.

## Admin Panel (`/admin`)

Filament 3 panel for back-office work (`app/Filament/`, provider `app/Providers/Filament/AdminPanelProvider.php`). Admins live in `admins` / `admin_roles` and sign in through the session guard `admin` — separate from app users, who only hold Sanctum tokens.

- **First admin**: `php artisan admin:create you@example.com` (prompts for a password, creates a "Super admin" role). Later admins and roles are managed in the panel.
- **Roles**: `admin_roles.module_access` is `*` or a comma list of `App\Support\AdminModules` keys (`market`, `quality`, `customers`, `billing`, `system`). Resources and pages opt in with the `BelongsToAdminModule` trait + `$adminModule`.
- **Data integrity** lives only here (Data quality → Daily coverage / Financial coverage), built on `app/Services/DataIntegrity/*`. There is no public API for it.
- Styling is plain CSS in `public/admin-ui/admin.css` (no theme build). Filament 3 colour variables are comma RGB triplets: write `rgba(var(--primary-500), 0.1)`, not `rgb(var(--x) / 0.1)`.
- The log viewer (`/log-viewer`) opens for a signed-in admin with System access, or HTTP Basic creds from env.
- `composer` runs `filament:upgrade` after autoload dump, which republishes `public/css/filament` and `public/js/filament`.

## Adding a New Endpoint

1. Create controller in `app/Http/Controllers/API/`
2. Add route in `routes/api.php` (public block or the `auth:sanctum` web group)
3. Register controller `use` at top of `api.php`

## Documentation Rules

**Do not create `.md` files unless explicitly asked.** This includes:
- Analysis summaries
- Fix documentation  
- Review findings
- Implementation guides

**Why**: Scattered markdown files become outdated, duplicate information from code/commit messages, and clutter the repo. CLAUDE.md is the single source of truth for project guidance.

**Where documentation belongs**:
- Code comments (for non-obvious WHY)
- Commit messages (for context on changes)
- CLAUDE.md (for project rules and guidelines)
- Code itself (self-documenting names)

## Environment Variables (key ones)

```env
APP_KEY=                    # php artisan key:generate
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=psx_db
DB_USERNAME=psx_user
DB_PASSWORD=
GOOGLE_CLIENT_ID=          # for Google OAuth login
GOOGLE_CLIENT_SECRET=
```

## Database Management

**No migrations or seeders.** The database schema is defined in `../schema.sql` and managed externally by the user. The Laravel app connects as the `psx_user` role to an existing PostgreSQL database.

Do not:
- Create migration files
- Use `php artisan migrate` or `php artisan migrate:fresh`
- Create seeder files
- Modify the database structure from the app

## Testing

```bash
php artisan test                    # all tests
php artisan test --filter FooTest   # single test class
```

Tests live in `tests/Feature/` and `tests/Unit/`. Uses Mockery for mocking dependencies.
