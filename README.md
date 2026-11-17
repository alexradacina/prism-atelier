# Prism Atelier

An image generation studio built with Laravel 13, Vue 3 and Inertia 3. Pixelforge runs server-side; the browser never receives the API key.

## Run locally

Requirements: PHP 8.4+ (8.5 recommended), Composer, SQLite, and the Node version in `.nvmrc`. Versions are locked in `composer.lock` and `package-lock.json`.

```sh
cp .env.example .env
composer install
php artisan key:generate
touch database/database.sqlite
php artisan migrate

# Follow the repository's Node instruction, then select the stable project runtime:
nvm use node
nvm install
nvm use
npm ci
npm run build
```

Set `PIXELFORGE_API_KEY` in `.env`. Run:

```sh
nvm use node
nvm use
composer run dev
```

Open [http://127.0.0.1:8000](http://127.0.0.1:8000), register, and use **Credits & activity → Buy credits**. Purchases and the provider are simulated; there are no real payments. New users start at $0. The development command starts Laravel, Vite, a queue listener and the scheduler.

To run without Vite, build once and use separate terminals:

```sh
php artisan serve --host=127.0.0.1 --port=8000
php artisan queue:work --sleep=1 --timeout=30 --tries=6
php artisan schedule:work
```

Keep the worker running: submissions, webhook processing and polling are queued. After PHP changes, restart a `queue:work` worker; the `composer run dev` listener reloads PHP between jobs.

## Features

- Registration, session sign-in/out, password hashing, CSRF protection and sign-in throttling.
- Prompt, three canvas sizes, three quality levels and up to four HTTP(S) reference URLs.
- Server-calculated estimates and current-rate maximum reservations before submission.
- A private, paginated history with queued/processing/succeeded/failed status and automatic updates.
- Durable signed webhook inbox with unique event IDs, atomic processing and terminal-state protection.
- Polling and scheduled recovery when webhooks are unavailable or missed.
- Exact final billing, idempotent demo credit purchases, and balance reservations.
- Operator reports by UTC completion day, user, and day within each user, with provider usage reconciliation.

## Billing decisions

Money is stored as signed 64-bit integer **nanodollars**: one dollar = 1,000,000,000 units. API values remain decimal strings at the UI boundary. `Money` uses `brick/math`; no binary floating-point calculations affect billing or reports.

Pixelforge reports four token categories and the generation's own locked rates, in dollars per million tokens. Provider cost is the exact sum of `tokens × rate × 1,000` nanodollars. We calculate the user's charge as `ceil(provider_nanos × 5 / 4)`. The only rounding is less than one nanodollar per successful generation. Failed or definitively rejected generations cost zero.

Pricing and token rules are read from `GET /v1/pricing` and cached for 60 seconds. The estimate uses Unicode character count, expected text tokens, exact image/output tokens and the midpoint of the reasoning-token range. The reservation uses the ceiling of the upper text-token bound and maximum reasoning tokens. Quotes belong to one user and expire in five minutes; submission accepts only the quote ID, never a client-supplied price.

A database transaction locks the user before reserving credits. Existing reservations reduce available balance, so simultaneous requests cannot independently spend the same funds. The quote ID doubles as the local generation ID and provider idempotency key. A repeated submission cannot create another local record or another reservation.

On completion, one transaction locks the user and generation, records the exact provider and user costs, appends a uniquely constrained ledger entry, deducts the final charge and releases the full reservation. Retries from submission responses, webhooks and polling all use this same settlement method.

**Rate-change policy:** the displayed maximum is at quote-time rates, not a contractual price cap. Pixelforge locks prices when it accepts the request. If rates change between quoting and acceptance, the final charge still uses the provider's locked rates plus 25%, even if it exceeds the reservation. A resulting negative balance blocks new generations until topped up. The generation retains both quoted pricing and actual rates for audit.

## Webhooks and recovery

The endpoint is `POST /webhooks/pixelforge`. It is the only CSRF-exempt application endpoint. Set the URL and copy the **same API key's** signing secret from Pixelforge into `PIXELFORGE_WEBHOOK_SECRET`. An absent secret fails closed with HTTP 503.

For local development, expose port 8000 with your preferred public tunnel and configure:

```text
https://YOUR-TUNNEL/webhooks/pixelforge
```

Restart the queue worker after changing environment variables, then use Pixelforge's dashboard to send a test event or resend deliveries.

Signatures use HMAC-SHA256 over `timestamp.raw_body`, checked in constant time with a five-minute tolerance in either direction. The receiver persists the event under a unique primary key, enqueues processing after commit and returns 204. Repeated delivery preserves the original event record.

Processing locks the event and settles in the same database transaction. A retried processing event cannot undo success or failure. An early webhook can match the pre-existing local generation via its metadata even before the create response has returned. The scheduled reconciler also retries unprocessed inbox records if queuing was interrupted after commit.

Polling starts after submission and repeats every 15 seconds until terminal. The scheduler recovers stalled polling and fresh submissions whose post-commit dispatch was interrupted. Unique submit jobs prevent competing retries of the same request. Transient failures use bounded retries, backoff and `Retry-After`. All create attempts reuse the original idempotency key and immutable parameters.

If all submission attempts fail with an ambiguous connection error, the app retains the reservation rather than assuming no provider charge exists. An operator can safely retry:

```sh
php artisan prism:reconcile --retry-submissions
php artisan queue:failed
php artisan queue:retry all
```

This explicit retry is bounded again. Known 4xx rejections, or exhausted explicit 429/503 responses, release the reservation and mark the request failed. Scheduler recovery for already accepted generations remains active.

Images remain on Pixelforge; signed URLs expire after 24 hours. The UI handles expired/unavailable images without deleting their billing history.

## Operator reports

Register normally, then grant operator access on the server:

```sh
php artisan prism:admin your@email.com
# To remove access:
php artisan prism:admin your@email.com --revoke
```

Visit `/admin/reports`. Ordinary users receive HTTP 403. Registration cannot grant admin access.

Reports use the provider's **UTC completion day**, not submission day. Daily and total provider-cost columns come directly from `GET /v1/usage` for the configured API key; user attribution comes from local generation snapshots. The report displays an exact reconciliation delta instead of silently treating missing attribution as zero cost. Usage responses are cached for 30 seconds, so recent completions may temporarily show a difference.

Use a separate API key per environment. If an API key is also used outside this application, those provider costs appear in the total and reconciliation delta, without inventing a user to charge.

## Verification

```sh
php artisan test
npm run build
```

The tests isolate all provider HTTP requests and cover money precision and Unicode, mutable pricing, quote expiry and ownership, credit reservations, idempotent purchases/submissions, success/failure settlement, forged/stale signatures, repeated and out-of-order webhooks, early webhooks, polling recovery, retry headers, auth isolation and usage reconciliation.

GitHub Actions runs the suite against SQLite and PostgreSQL, builds the frontend and checks Composer metadata. Local end-to-end verification also exercised a real Pixelforge success and failure and matched provider usage to the nanodollar. Live dashboard webhook delivery needs a configured tunnel and signing secret; signed receiver behavior is covered by automated tests.

## Deploy manually on Laravel Forge

No public deployment is performed by this project; deployment is intentionally left to the operator.

1. Create a Laravel site from this repository's `main` branch. Use PHP 8.5, the Node runtime from `.nvmrc`, and a persistent PostgreSQL database (recommended) or MySQL. Set the web root to `public`.
2. Set production environment values: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://your-domain`, a persistent generated `APP_KEY`, database credentials, `SESSION_SECURE_COOKIE=true`, and a separate production `PIXELFORGE_API_KEY` / `PIXELFORGE_WEBHOOK_SECRET`. Keep database-backed sessions, queues and cache unless you intentionally configure shared Redis.
3. Adapt the site's deployment script to the commands below, retaining Forge's checkout, release-activation and PHP-FPM handling. Do not regenerate `APP_KEY` on each deployment.

```sh
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan optimize
php artisan queue:restart
```

4. Add a Forge queue worker for the database connection/default queue: `php artisan queue:work --sleep=1 --timeout=30 --tries=6`. Keep `retry_after` above the job timeout (the default 90 seconds is sufficient). Configure graceful worker restarts after deployment.
5. Enable the Laravel scheduler, running `php artisan schedule:run` every minute. The scheduled `prism:reconcile` task recovers stalled updates.
6. Configure this environment's Pixelforge webhook URL as `https://your-domain/webhooks/pixelforge` and copy that key's secret into Forge. Send a test event; check for HTTP 204 and a processed inbox record.
7. Register the operator account and run `prism:admin`. Exercise `[succeed] [duplicate]` and `[fail]` prompts, then verify the admin report delta is zero. The health endpoint is `/up`.

Never commit `.env`, SQLite databases, sessions, logs or provider credentials. They are excluded from Git.
