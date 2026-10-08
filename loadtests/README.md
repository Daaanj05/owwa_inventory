# Load tests (k6)

Scripted concurrent-user checks for the OWWA Inventory app. These complement the System Admin **System health** page (online counts ≠ proven capacity).

## Assumptions

- Target **local or UAT** only — do not run against production with real users.
- `MAIL_MAILER=log` is expected. External mail delivery is not required for a successful run.
- If `QUEUE_CONNECTION=database`, run `php artisan queue:work` during the test, or treat pending `jobs` growth as a capacity signal.
- Filament login uses CSRF + Livewire; `lib/filament-login.js` may need updates after Filament upgrades.

## Prerequisites

1. Install [k6](https://k6.io/docs/get-started/installation/).
2. App reachable at `BASE_URL` (example: `http://127.0.0.1:8000`).
3. Prepare users + credentials file:

```bash
php artisan loadtest:prepare --count=20
```

Creates verified operations users (`loadtest.user01@example.test` …) and writes gitignored `loadtests/.credentials.json`.

Optional UAT outside local/testing:

```bash
LOAD_TEST_ENABLED=true php artisan loadtest:prepare --force --count=20
```

For a steep login ramp, temporarily raise limits in `.env`:

```env
LOGIN_MAX_ATTEMPTS=50
LOGIN_DECAY_SECONDS=60
```

Defaults remain 5 attempts / 60 seconds.

## Smoke: `/up`

```bash
k6 run -e BASE_URL=http://127.0.0.1:8000 loadtests/smoke-up.js
```

## Concurrent browse (20 VUs)

```bash
k6 run -e BASE_URL=http://127.0.0.1:8000 -e VUS=20 -e DURATION=3m loadtests/concurrent-browse.js
```

Each VU logs into the operations portal (`/login`), then GETs `/` and `/requisitions`.

## Success criteria (v1)

- About **20 VUs** for ~3 minutes
- Near-zero HTTP failures (`http_req_failed` under 5%) and checks passing (login + browse)
- Default **p95** duration threshold is **20s** (local-friendly). For a stricter host, pass `-e DURATION_P95_MS=2000`
- No spike in `failed_jobs` (check System health or the database)

Attach the k6 summary output to demo/defense materials when claiming concurrent capacity. Online counts on System health ≠ k6 proof; use the k6 TOTAL RESULTS.

### Example: stricter p95 on a faster UAT host

```bash
k6 run -e BASE_URL=http://capstoneproject.test:8080 -e VUS=20 -e DURATION=3m -e DURATION_P95_MS=2000 loadtests/concurrent-browse.js
```

## Performance notes (OPcache ≠ nginx)

OPcache is a **PHP** setting (keeps compiled PHP in memory). It is not a web server and does **not** require switching to nginx. Keep your current stack; enable OPcache in the active `php.ini`.

On this machine the CLI PHP config is typically `C:\php\php.ini`. Confirm:

```powershell
php -m | findstr /i opcache
php -i | findstr /i "opcache.enable"
```

Expect `Zend OPcache` and `opcache.enable => On`. After changing `php.ini`, **restart** the web server / PHP process that serves the site (not only CLI).

Helpful alongside OPcache (leave `APP_DEBUG` as you prefer for local work):

- Prefer `SESSION_DRIVER=database` and `CACHE_STORE=database` under concurrent users (already the project defaults in `.env.example`)
- If `QUEUE_CONNECTION=database`, run `php artisan queue:work` during load tests so jobs are not stuck on the HTTP request
- On a stable deploy only: `php artisan config:cache`, `route:cache`, `view:cache` (use `optimize:clear` when actively developing)

## Troubleshooting

| Symptom | Likely fix |
|---------|------------|
| Login parse errors | Filament/Livewire HTML changed — update `lib/filament-login.js` |
| Livewire call 0% / 404 on update | Livewire v4 uses `/livewire-{hash}/update`; script reads `data-update-uri` from the login page |
| Rate limited | Raise `LOGIN_MAX_ATTEMPTS` for UAT, or ensure 20 distinct emails from `loadtest:prepare` |
| Redirect loops / 403 | Confirm users are verified and `must_change_password=false`; use operations roles only |
| Queue depth grows | Normal with queued notifications; run a worker or accept it as measured load |
