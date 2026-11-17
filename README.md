# Prism Atelier

An image generation studio built with Laravel 13, Vue 3 and Inertia 3. Pixelforge runs server-side; the browser never receives the API key.

## Run locally

Requirements: PHP 8.4+ (8.5 recommended) with PDO MySQL, PCNTL and POSIX, Composer, MySQL 8+, Redis 7+, and the Node version in `.nvmrc`. Versions are locked in `composer.lock` and `package-lock.json`. Redis uses the bundled Predis client, so the PhpRedis extension is optional.

On macOS, start your installed services (for example, `brew services start mysql@8.0` and `brew services start redis`). Other installations can use their normal service manager. Create a dedicated database:

```sh
mysql -u root -e 'CREATE DATABASE IF NOT EXISTS prism_atelier CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
```

If MySQL requires a password, add `-p` to that command. Configure your own database user and password in `.env`; the example defaults to a local `root` account with no password.

```sh
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate

# Follow the repository's Node instruction, then select the stable project runtime:
nvm use node
nvm install
nvm use
npm ci
npm run build
```

The example environment selects MySQL for application data and sessions, Redis for cache and queues, and Horizon for queue processing:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=prism_atelier
DB_USERNAME=root
DB_PASSWORD=
SESSION_DRIVER=database
QUEUE_CONNECTION=redis
CACHE_STORE=redis
REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_DB=0
REDIS_CACHE_DB=1
REDIS_PREFIX=prism-atelier-local-database-
HORIZON_PREFIX=prism-atelier-local-horizon:
```

Set `PIXELFORGE_API_KEY` in `.env`, check the database credentials, and run:

```sh
nvm use node
nvm use
composer run dev
```

Open [http://127.0.0.1:8000](http://127.0.0.1:8000), register, and use **Credits & activity → Buy credits**. Purchases and the provider are simulated; there are no real payments. New users start at $0. The development command starts Laravel, Vite, `horizon:listen` and the scheduler. Horizon uses up to three Redis workers and restarts them on PHP/config changes. Its file watcher uses the Chokidar development dependency, so run `npm ci` before starting development.

To run without Vite, build once and use separate terminals:

```sh
php artisan serve --host=127.0.0.1 --port=8000
php artisan horizon
php artisan schedule:work
```

Keep Horizon running: submissions, webhook processing and polling are queued on Redis. Do not run a separate `queue:work` or `queue:listen` against the same queue. After PHP/config changes, stop and restart a manually started Horizon process; `horizon:listen` handles this automatically during development.

When moving an existing SQLite installation, preserve the original database and copy accounts, generations, ledger entries and active sessions into a freshly migrated MySQL database. Verify row counts and wallet/billing totals before switching `.env`; changing `DB_CONNECTION` alone does not transfer records. Fresh checkouts start with an empty MySQL database.

## Features

- Registration, session sign-in/out, password hashing, CSRF protection and sign-in throttling.
- Prompt, three canvas sizes, three quality levels and up to four individual HTTP(S) reference URL fields.
- Three inspiration suggestions sampled from nine ideas on each page load; they stay steady during editing and live updates.
- Server-calculated estimates and current-rate maximum reservations before submission.
- A private, paginated history with queued/processing/succeeded/failed status and automatic updates.
- Durable signed webhook inbox with unique event IDs, atomic processing and terminal-state protection.
- Polling and scheduled recovery when webhooks are unavailable or missed.
- Exact final billing, idempotent demo credit purchases, and balance reservations.
- Append-only balance history with exact running balances, separate pending holds and an operator view for every user.
- Operator reports by UTC completion day, user, and day within each user, with provider usage reconciliation.
- Redis-backed cache/queues and an operator-only Horizon dashboard with job metrics.
- Account-wide request, concurrency and UTC daily quotas, shared across users and queue workers.

## Billing decisions

Money is stored as signed 64-bit integer **nanodollars**: one dollar = 1,000,000,000 units. API values remain decimal strings at the UI boundary. `Money` uses `brick/math`; no binary floating-point calculations affect billing or reports.

Pixelforge reports four token categories and the generation's own locked rates, in dollars per million tokens. Provider cost is the exact sum of `tokens × rate × 1,000` nanodollars. We calculate the user's charge as `ceil(provider_nanos × 5 / 4)`. The only rounding is less than one nanodollar per successful generation. Failed or definitively rejected generations cost zero.

Pricing and token rules are read from `GET /v1/pricing` and cached for 60 seconds. The estimate uses Unicode character count, expected text tokens, exact image/output tokens and the midpoint of the reasoning-token range. The reservation uses the ceiling of the upper text-token bound and maximum reasoning tokens. Quotes belong to one user and expire in five minutes; submission accepts only the quote ID, never a client-supplied price.

A database transaction locks the user before reserving credits. Existing reservations reduce available balance, so simultaneous requests cannot independently spend the same funds. The quote ID doubles as the local generation ID and provider idempotency key. A repeated submission cannot create another local record or another reservation.

On completion, one transaction locks the user and generation, records the exact provider and user costs, appends a uniquely constrained ledger entry with the balance after the transaction, deducts the final charge and releases the full reservation. Retries from submission responses, webhooks and polling all use this same settlement method. A unique generation ID and idempotency key in the ledger provide a second database-level guard against duplicate charges. A failed ledger insert rolls back the wallet and generation changes together.

The existing `ledger_entries` table records every credit addition and final charge; failed generations have zero-value entries. Ledger rows cannot be edited or deleted through the application model. Each row stores an integer `balance_after_nanos` snapshot, and the migration backfills existing snapshots without changing monetary entries. Reusing a purchase key with a different amount is rejected.

The credits page separates **credit balance** (added credits minus completed charges), **reserved** (pending holds) and **available** (balance minus holds). Reservations never appear as a completed spend. Its history shows additions, spending and running balances to all nine decimal places, with pending images listed separately. Amount formatting and affordability checks in the browser also use integer nanodollars with BigInt, avoiding float precision loss.

**Rate-change policy:** the displayed maximum is at quote-time rates, not a contractual price cap. Pixelforge locks prices when it accepts the request. If rates change between quoting and acceptance, the final charge still uses the provider's locked rates plus 25%, even if it exceeds the reservation. A resulting negative balance blocks new generations until topped up. The generation retains both quoted pricing and actual rates for audit.

## Webhooks and recovery

The endpoint is `POST /webhooks/pixelforge`. It is the only CSRF-exempt application endpoint. Set the URL and copy the **same API key's** signing secret from Pixelforge into `PIXELFORGE_WEBHOOK_SECRET`. An absent secret fails closed with HTTP 503.

For local development, expose port 8000 with your preferred public tunnel and configure:

```text
https://YOUR-TUNNEL/webhooks/pixelforge
```

Restart Horizon after changing environment variables, then use Pixelforge's dashboard to send a test event or resend deliveries.

Signatures use HMAC-SHA256 over `timestamp.raw_body`, checked in constant time with a five-minute tolerance in either direction. The receiver persists the event under a unique primary key, enqueues processing after commit and returns 204. Repeated delivery preserves the original event record.

Processing locks the event and settles in the same database transaction. A retried processing event cannot undo success or failure. An early webhook can match the pre-existing local generation via its metadata even before the create response has returned. The scheduled reconciler also retries unprocessed inbox records if queuing was interrupted after commit.

Polling starts after submission and repeats every 15 seconds until terminal. The scheduler recovers stalled polling and fresh submissions whose post-commit dispatch was interrupted. Unique submit jobs prevent competing retries of the same request. All create attempts reuse the original idempotency key and immutable parameters.

If all submission attempts fail with an ambiguous connection error, the app retains the reservation rather than assuming no provider charge exists. An operator can safely retry:

```sh
php artisan prism:reconcile --retry-submissions
php artisan queue:failed
php artisan horizon:retry all
```

This explicit retry resets the six-request budget only for stopped, unconfirmed submissions. Known rejections release the reservation only when **every** preceding attempt was also definitively rejected. A timeout followed by a 401, 429 or 503 cannot prove that the earlier request created nothing; those holds and provider slots remain reserved until an idempotent replay, webhook or operator investigation resolves them. A late success then follows the normal, exactly-once settlement path. Scheduler recovery for already accepted generations remains active.

Images remain on Pixelforge; signed URLs expire after 24 hours. The UI handles expired/unavailable images without deleting their billing history.

## Provider limits

Pixelforge limits the **account**, across all API keys: 60 authenticated requests per minute, three queued/processing generations, and 500 accepted generations per UTC day. Horizon's worker count is independent of those limits.

Every authenticated HTTP call passes through a database coordinator. A locked `provider_accounts` row records a rolling 60-second request window and server feedback. Submissions, status checks, usage reports and retries share that allowance. `X-RateLimit-Limit` and `X-RateLimit-Remaining` can reduce it; exhausted server allowance conservatively waits 60 seconds from observation. A 429 or 503 `Retry-After` pauses the account's other workers too. Public `GET /pricing` sends no credentials and is cached separately.

Before a create request leaves the app, the same transaction reserves a provider slot and increments a durable HTTP-attempt counter. Known rejections release that slot; accepted jobs hold it until a terminal result. Unconfirmed submissions also retain slots. Daily admission counts the provider's `created_at` UTC day, including accepted failures, plus unresolved slot reservations. Definitively rejected requests consume only request allowance. The 501st admission waits until UTC midnight; a provider `daily_limit_exceeded` response also pauses new creates until then, while existing generations can still be polled.

Actual create requests are limited to six attempts with increasing backoff (5, 15, 30, 60, 120 seconds), or longer when `Retry-After` requires it. Queue releases while waiting for quota do **not** consume this budget. Persisted submission and polling cooldowns prevent a new queue job or the scheduler from bypassing a delay. Stored request times and cooldown deadlines round up to whole seconds so timestamp precision cannot shorten a limit. Poll failures use five attempts per cycle, then leave a five-minute cooldown before scheduled recovery. Waiting messages appear on generation cards; holds remain separate from completed spending. Exhausted, ambiguous submissions appear as failed jobs in Horizon and retain their holds for explicit reconciliation.

`PIXELFORGE_ACCOUNT_SCOPE=primary` identifies the provider account, **not** the API key. Workers and deployments using keys from that same account must share the coordinator database and scope. Use a different scope for a genuinely different account; never rotate the scope to evade a quota. Requests made outside this coordinator cannot be known in advance: provider headers and 429 responses remain authoritative and are honored. For predictable admission, route all use of an account through this coordinator, or use separate provider accounts for independent environments. Keep separate environment API keys for webhook secrets and usage reporting.

## Operator reports

Register normally, then grant operator access on the server:

```sh
php artisan prism:admin your@email.com
# To remove access:
php artisan prism:admin your@email.com --revoke
```

Visit `/admin/reports`. Ordinary users receive HTTP 403. Registration cannot grant admin access.

Operators can open **User balances** at `/admin/wallets`, including users with no successful generations, then inspect any user’s running balance history and current holds. Ordinary users can only view their own wallet at `/credits`.

The same operators can open **Queue monitor** in the sidebar or visit `/horizon`. The dashboard and its API require sign-in and operator access in every environment, including `local`. Horizon records job throughput, runtime, pending work and failures; the scheduler takes a metrics snapshot every five minutes. The supervisor timeout is 30 seconds, above the generation jobs' 25-second timeout and below Redis's 90-second `retry_after`.

Use `php artisan horizon:status` to check the supervisor and `php artisan horizon:retry all` to retry failed jobs. In production, run Horizon under a process monitor; after deployments, `php artisan horizon:terminate` exits gracefully so the monitor starts the new code.

Reports use the provider's **UTC completion day**, not submission day. Daily and total provider-cost columns come directly from `GET /v1/usage` for the configured API key; user attribution comes from local generation snapshots. The report displays an exact reconciliation delta instead of silently treating missing attribution as zero cost. Usage responses are cached for 30 seconds, so recent completions may temporarily show a difference.

Use a separate API key per environment. If an API key is also used outside this application, those provider costs appear in the total and reconciliation delta, without inventing a user to charge.

## Verification

```sh
php artisan test
npm test
npm run build
```

MySQL/PostgreSQL race tests start six independent processes at the same time. They verify that distinct requests cannot reserve the same money, replayed submissions create one hold, simultaneous terminal updates create one charge, and workers cannot overbook provider slots, request allowance or daily allowance. SQLite skips these six row-lock tests; the ordinary functional suite still runs there. These tests run only against the isolated CI/test database.

The tests isolate all provider HTTP requests and cover money precision and Unicode, mutable pricing, quote expiry and ownership, credit reservations, idempotent purchases/submissions, success/failure settlement, forged/stale signatures, repeated and out-of-order webhooks, early webhooks, polling recovery, account-wide quotas, UTC midnight, bounded retries, cross-job cooldowns, lost responses followed by rejection, auth isolation and usage reconciliation.

GitHub Actions runs the suite against SQLite, MySQL and PostgreSQL with Redis available, builds the frontend and checks Composer metadata. Unit/feature tests use an array cache, synchronous queue and isolated test database, so running `php artisan test` does not modify your local MySQL app data or Redis jobs. Local end-to-end verification also exercised a real Pixelforge success and failure and matched provider usage to the nanodollar. Live dashboard webhook delivery needs a configured tunnel and signing secret; signed receiver behavior is covered by automated tests.

## Deploy manually on Laravel Forge

No public deployment is performed by this project; deployment is intentionally left to the operator.

1. Create a Laravel site from this repository's `main` branch. Use PHP 8.5, the Node runtime from `.nvmrc`, and persistent MySQL and Redis services. Set the web root to `public`.
2. Set production environment values: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://your-domain`, a persistent generated `APP_KEY`, database credentials, `SESSION_SECURE_COOKIE=true`, and a separate production `PIXELFORGE_API_KEY` / `PIXELFORGE_WEBHOOK_SECRET`. Use `DB_CONNECTION=mysql`, `SESSION_DRIVER=database`, `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis` and `REDIS_CLIENT=predis`. Set production-specific `REDIS_PREFIX` and `HORIZON_PREFIX` values instead of the example local prefixes. Configure Redis host/password/TLS as required by your host.
3. Adapt the site's deployment script to the commands below, retaining Forge's checkout, release-activation and PHP-FPM handling. Do not regenerate `APP_KEY` on each deployment.

```sh
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan optimize
php artisan horizon:terminate
```

4. Add a Forge background process or Supervisor entry running `php artisan horizon` in the site directory, with automatic restart enabled. Do not also start an ordinary queue worker. Keep Redis `retry_after` above the Horizon supervisor timeout (90 seconds vs 30 seconds by default), and allow at least 60 seconds for graceful shutdown.
5. Enable the Laravel scheduler, running `php artisan schedule:run` every minute. The scheduled `prism:reconcile` task recovers stalled updates; `horizon:snapshot` records dashboard metrics every five minutes.
6. Configure this environment's Pixelforge webhook URL as `https://your-domain/webhooks/pixelforge` and copy that key's secret into Forge. Send a test event; check for HTTP 204 and a processed inbox record.
7. Register the operator account and run `prism:admin`. Exercise `[succeed] [duplicate]` and `[fail]` prompts, then verify the admin report delta is zero. The health endpoint is `/up`.

Never commit `.env`, SQLite databases, sessions, logs or provider credentials. They are excluded from Git.
