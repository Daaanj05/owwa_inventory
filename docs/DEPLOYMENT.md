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
   php artisan serve
   ```

   For production, use PHP-FPM with Nginx or Apache and point the Cloudflare Tunnel (or your web server) to that backend.

3. **Ollama** (optional, for RAG/procurement recommendations): Run Ollama locally and pull the required models (e.g. `nomic-embed-text`, `deepseek-r1`). Set `OLLAMA_URL`, `OLLAMA_EMBED_MODEL`, and `OLLAMA_CHAT_MODEL` in `.env`.

4. **Queue** (optional): If using queues, run `php artisan queue:work` (or a process manager like Supervisor).

## Default login

After seeding, you can log in to the admin panel:

- **Supply Custodian**: `custodian@owwa.gov.ph` / `password`
- **Employee**: `test@example.com` / `password`

Change these credentials in production.
