# OWWA Inventory System – Deployment

## Cloudflare Tunnel (secure web access)

Use Cloudflare Tunnel to expose the Laravel app without opening firewall ports or using a public IP.

### 1. Install cloudflared

- Windows: Download from [Cloudflare Zero Trust](https://developers.cloudflare.com/cloudflare-one/connections/connect-apps/install-and-setup/installation/) or use `winget install Cloudflare.cloudflared`.
- Or use the quick install script for your OS from Cloudflare docs.

### 2. Authenticate

```bash
cloudflared tunnel login
```

This opens a browser to log in to your Cloudflare account and authorizes the machine.

### 3. Create a tunnel

```bash
cloudflared tunnel create owwa-inventory
```

Note the tunnel ID from the output.

### 4. Configure the tunnel

Create a config file (e.g. `config.yml` in the same directory or `~/.cloudflared/config.yml`):

```yaml
tunnel: <TUNNEL_ID>
credentials-file: /path/to/<TUNNEL_ID>.json

ingress:
  - hostname: inventory.yourdomain.com
    service: http://localhost:8000
  - service: http_status:404
```

Replace:

- `<TUNNEL_ID>` with the ID from step 3.
- `inventory.yourdomain.com` with your desired hostname.
- `http://localhost:8000` with your app URL (e.g. `http://127.0.0.1:8000` if using `php artisan serve`, or your local web server URL).

### 5. Route DNS (Cloudflare dashboard)

In Cloudflare Dashboard → Zero Trust → Tunnels (or DNS), add a CNAME record:

- Name: `inventory` (or your subdomain)
- Target: `<TUNNEL_ID>.cfargotunnel.com`

### 6. Run the tunnel

```bash
cloudflared tunnel run owwa-inventory
```

For production, run as a service (see [Cloudflare: Run a tunnel](https://developers.cloudflare.com/cloudflare-one/connections/connect-apps/install-and-setup/setup-guide/)). On Windows you can use NSSM or a scheduled task.

### 7. Laravel configuration

- Set `APP_URL` in `.env` to your public URL (e.g. `https://inventory.yourdomain.com`).
- Ensure `APP_ENV=production` and `APP_DEBUG=false` in production.
- If the app is behind the tunnel (or a reverse proxy), trust proxies so Laravel sees the correct scheme and host (see Laravel “Trusting Proxies” docs).

## Running the application

1. **Database**: Create the MySQL database (e.g. `owwa_inventory` or `Capstone_DB`) and run migrations:

   ```bash
   php artisan migrate --force
   php artisan db:seed --force
   ```

2. **Serve**: For local/dev, run:

   ```bash
   composer run dev
   ```

   This starts the web server, queue worker, **Reverb WebSocket server**, logs, and Vite. Real-time requisition updates require Reverb to be running.

3. **Real-time updates (Reverb)** — add to `.env`:

   ```env
   BROADCAST_CONNECTION=reverb
   REVERB_APP_ID=your-app-id
   REVERB_APP_KEY=your-app-key
   REVERB_APP_SECRET=your-app-secret
   REVERB_HOST=localhost
   REVERB_PORT=8080
   REVERB_SCHEME=http

   VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
   VITE_REVERB_HOST="${REVERB_HOST}"
   VITE_REVERB_PORT="${REVERB_PORT}"
   VITE_REVERB_SCHEME="${REVERB_SCHEME}"
   ```

   Generate keys with `php artisan reverb:install` or set values manually. In production, run `php artisan reverb:start` as a persistent service (like the queue worker).

4. **Serve (without full dev stack)**:

   ```bash
   php artisan serve
   ```

   For production, use PHP-FPM with Nginx or Apache and point the Cloudflare Tunnel (or your web server) to that backend.

5. **Ollama** (optional, for RAG/procurement recommendations): Run Ollama locally and pull the required models (e.g. `nomic-embed-text`, `deepseek-r1`). Set `OLLAMA_URL`, `OLLAMA_EMBED_MODEL`, and `OLLAMA_CHAT_MODEL` in `.env`.

6. **Queue** (optional): If using queues, run `php artisan queue:work` (or a process manager like Supervisor).

## Ollama via Cloudflare quick tunnel (laptop → PaaS)

Use this when the Laravel app is hosted online (Railway/Render) but Ollama + DeepSeek stay on your laptop.

### Prerequisites

- Ollama running locally; models pulled (`nomic-embed-text`, `deepseek-r1:7b` or your chosen chat model).
- `php artisan ollama:test` succeeds against `http://127.0.0.1:11434`.
- `cloudflared` installed (`winget install Cloudflare.cloudflared`). On Windows the binary is usually:

  `C:\Program Files (x86)\cloudflared\cloudflared.exe`

### One-time: Windows environment variables for Ollama

Ollama rejects proxied requests (HTTP 403) unless origins are allowed. Set **User variables** (System Properties → Environment Variables):

| Variable | Value |
| -------- | ----- |
| `OLLAMA_ORIGINS` | `*` |
| `OLLAMA_HOST` | `0.0.0.0:11434` |

Then **Quit Ollama** from the tray, confirm no `ollama.exe` in Task Manager, and start Ollama again so it picks up the new variables.

Verify in a new PowerShell window:

```powershell
[Environment]::GetEnvironmentVariable('OLLAMA_ORIGINS','User')
[Environment]::GetEnvironmentVariable('OLLAMA_HOST','User')
curl.exe http://127.0.0.1:11434/api/tags
```

### Start the tunnel (each demo session)

Run **only one** tunnel instance. Use `localhost` and a quoted host header (per [Ollama Cloudflare Tunnel FAQ](https://github.com/ollama/ollama/blob/main/docs/faq.md)):

```powershell
& "C:\Program Files (x86)\cloudflared\cloudflared.exe" tunnel --url http://localhost:11434 --http-host-header="localhost:11434"
```

Copy the **new** `https://xxxx.trycloudflare.com` URL from the output. The URL changes every time you restart the tunnel — do not reuse old URLs.

Keep this terminal open while demoing.

### Verify the tunnel

```powershell
curl.exe -v https://xxxx.trycloudflare.com/
# Expect: HTTP/1.1 200 OK and body "Ollama is running"

curl.exe https://xxxx.trycloudflare.com/api/tags
# Expect: JSON listing your models (deepseek-r1:7b, nomic-embed-text, etc.)
```

### Production `.env` (when app is on PaaS)

```env
OLLAMA_URL=https://xxxx.trycloudflare.com
OLLAMA_EMBED_MODEL=nomic-embed-text
OLLAMA_CHAT_MODEL=deepseek-r1:7b
```

Local development keeps `OLLAMA_URL=http://127.0.0.1:11434`.

### Demo-day checklist

1. Laptop on, sleep disabled.
2. Ollama running (`ollama list`).
3. Start cloudflared tunnel (command above).
4. Update `OLLAMA_URL` on PaaS if the trycloudflare URL changed.
5. Test Procurement Analytics AI narrative.
6. Stop tunnel (Ctrl+C) when finished — the URL is public while active.

### Troubleshooting

| Symptom | Fix |
| ------- | --- |
| `cloudflared` not found | Use full path above, or add `C:\Program Files (x86)\cloudflared` to PATH and reopen the terminal. |
| HTTP 403 on trycloudflare URL | Set `OLLAMA_ORIGINS` + `OLLAMA_HOST`, restart Ollama; use `--http-host-header="localhost:11434"`; test the **current** tunnel URL only. |
| 403 on an old URL | Restart tunnel → new URL → test the new URL. |
| `__OLLAMA_UNAVAILABLE__` in app | Laptop off, Ollama stopped, tunnel down, or PaaS still has `OLLAMA_URL=http://127.0.0.1:11434`. |

## Render (Docker + PostgreSQL)

Deploy the app on [Render](https://render.com) using the repo [`Dockerfile`](Dockerfile) at the project root. Choose **Docker** as the runtime (not Node — `package.json` is only for Vite assets).

### 1. Push code to GitHub

Connect Render to `https://github.com/Daaanj05/owwa_inventory` on branch `main` after pushing local changes.

### 2. Create PostgreSQL

1. Render Dashboard → **New** → **PostgreSQL**.
2. Region: **Singapore** (closest to the Philippines).
3. Copy **Internal** connection values (`DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`).

### 3. Create Web Service

| Setting | Value |
| ------- | ----- |
| Source | GitHub → `Daaanj05/owwa_inventory` |
| Branch | `main` |
| Runtime | **Docker** |
| Region | Singapore |
| Instance | Free (sleeps after ~15 min idle; cold start ~30s) |

No custom build or start command is required — the Dockerfile and [`docker/render-entrypoint.sh`](docker/render-entrypoint.sh) handle build, migrate, and `php artisan serve` on `$PORT`.

### 4. Environment variables

Generate `APP_KEY` locally:

```powershell
php artisan key:generate --show
```

Set on Render:

```env
APP_ENV=production
APP_DEBUG=false
APP_KEY=<paste from key:generate --show>
APP_URL=https://<your-service>.onrender.com

DB_CONNECTION=pgsql
DB_HOST=<from Render Postgres>
DB_PORT=5432
DB_DATABASE=<from Render Postgres>
DB_USERNAME=<from Render Postgres>
DB_PASSWORD=<from Render Postgres>

SESSION_DRIVER=database
QUEUE_CONNECTION=database
CACHE_STORE=database
BROADCAST_CONNECTION=log

OLLAMA_URL=https://<current-trycloudflare-url>
OLLAMA_EMBED_MODEL=nomic-embed-text
OLLAMA_CHAT_MODEL=deepseek-r1:7b
```

`bootstrap/app.php` trusts all proxies so HTTPS URLs work behind Render’s load balancer.

### 5. First deploy

1. Deploy and watch build logs — Docker runs `npm run build`, `composer install`, then starts the entrypoint.
2. Entrypoint runs `php artisan migrate --force` automatically.
3. Seed demo data (Render **Shell** tab):

   ```bash
   php artisan db:seed --force
   ```

4. Open `https://<your-service>.onrender.com/admin` and log in (see [Default login](#default-login) below).
5. Start the Ollama quick tunnel on your laptop (see [Ollama via Cloudflare quick tunnel](#ollama-via-cloudflare-quick-tunnel-laptop--paas)) and set `OLLAMA_URL` on Render when the URL changes.

### Render notes

- **Free tier:** the web service spins down when idle; wake it with a browser request before a demo.
- **Database:** use Render Postgres only — do not point production at a database on your laptop.
- **Secrets:** never commit `.env`; set all production values in the Render dashboard.

## Default login

After seeding, you can log in to the admin panel:

- **Supply Custodian**: `custodian@owwa.gov.ph` / `password`
- **Employee**: `test@example.com` / `password`

Change these credentials in production.
