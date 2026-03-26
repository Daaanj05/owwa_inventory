# OWWA Web-Based Data-Driven Inventory System with Procurement Analysis

A centralized, web-based inventory system for OWWA Regional Office IV-A, with procurement analytics, RAG-powered recommendations, and COA-compliant reports.

## Stack

- **Laravel** 12
- **Filament** 5 (admin UI: Livewire + Tailwind)
- **MySQL** (schema manageable via MySQL Workbench; Laravel migrations included)
- **Ollama** (DeepSeek/Neuron) for RAG and procurement recommendations
- **Cloudflare Tunnel** for secure access (see [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md))

## Requirements

- PHP 8.2+ (with extensions: pdo_mysql, mbstring, xml, curl, etc.; **ext-intl** required for Filament)
- Composer
- MySQL 8 (or MariaDB)
- Node.js/npm (for Vite; optional if using pre-built assets)

## Setup

1. **Clone and install**

   ```bash
   cd CapstoneProject
   composer install
   cp .env.example .env
   php artisan key:generate
   ```

2. **Database**

   Create a MySQL database (e.g. `owwa_inventory` or `Capstone_DB`) and set in `.env`:

   ```env
   DB_CONNECTION=mysql
   DB_DATABASE=owwa_inventory
   DB_USERNAME=root
   DB_PASSWORD=your_password
   ```

   Then run:

   ```bash
   php artisan migrate
   php artisan db:seed
   ```

3. **Admin panel**

   Open `/admin` in the browser. Log in with:

   - **Supply Custodian**: `custodian@owwa.gov.ph` / `password`
   - **Employee**: `test@example.com` / `password`

   **Creating user accounts:** Only the **Supply Custodian** can create and manage users. In the admin panel, go to **Setup → Users**, then click **New user**. Fill in name, email, password, role (Employee or Supply Custodian), and optionally office and department. Passwords are required when creating a user; when editing, leave password blank to keep the existing one.

4. **Ollama (optional, for AI recommendations)**

   Install [Ollama](https://ollama.ai), then:

   ```bash
   ollama pull nomic-embed-text
   ollama pull deepseek-v3.2:7b
   ```

   Set in `.env` (defaults are already present):

   ```env
   OLLAMA_URL=http://localhost:11434
   OLLAMA_EMBED_MODEL=nomic-embed-text
   OLLAMA_CHAT_MODEL=deepseek-v3.2:7b
   ```

   (Run `ollama list` after pulling to see the exact model name if you use a different tag.)

5. **Run locally**

   ```bash
   php artisan serve
   ```

   Visit `http://localhost:8000/admin`.

## Features

- **Setup**: Offices, Departments, Item categories, Items (with reorder levels)
- **Inventory**: Acquisitions, Issuances, Transfers, Disposals (with auto reference codes)
- **Requisitions**: Create requisitions with line items; Supply Custodian can Approve/Reject
- **Dashboard**: Low-stock alerts widget, Issuance trends chart
- **Analytics**: Procurement recommendations (RAG via Ollama), COA reports (PDF)
- **Roles**: Supply Custodian (full access), Employee (request + view)

## Designs, Ollama, and “Neuron AI”

- **Where are the design / UI files?** To change the interface, edit the files listed in [docs/UI_AND_DESIGN_FILES.md](docs/UI_AND_DESIGN_FILES.md) (panel theme, tables, forms, custom pages, widgets, report layouts).
- **Ollama & DeepSeek:** Install Ollama from https://ollama.com/download , then run `ollama pull nomic-embed-text` and `ollama pull deepseek-r1`. Full steps: [docs/DESIGNS_AND_OLLAMA_SETUP.md](docs/DESIGNS_AND_OLLAMA_SETUP.md).
- **“Neuron AI”** in this project = Ollama + models (e.g. DeepSeek). There is no separate Neuron AI app; it’s the same AI stack above.

## Tests and quality

- Run tests: `php artisan test`
- ISO 25010 evaluation guide: [docs/ISO_25010_EVALUATION.md](docs/ISO_25010_EVALUATION.md)
- Deployment (including Cloudflare Tunnel): [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)

## License

MIT (or as required by your institution).
