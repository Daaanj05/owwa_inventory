# Laravel Boost in Cursor

Laravel Boost gives Cursor’s AI Laravel-specific context (docs, schema, Tinker, etc.). Follow these steps to install and enable it.

## 1. Install the package (run in your terminal)

Open **Command Prompt** or **PowerShell** in your project folder and run:

```bash
cd c:\CapstoneProject
composer require laravel/boost --dev
```

## 2. Install Boost’s MCP server and guidelines

Still in the project folder:

```bash
php artisan boost:install
```

When prompted:

- Choose **Cursor** as your agent/IDE.
- Enable the options you want (MCP, guidelines, skills).

This creates the MCP config and guideline/skill files.

## 3. Enable Laravel Boost in Cursor

1. In Cursor, open the **Command Palette**: `Ctrl+Shift+P` (Windows) or `Cmd+Shift+P` (Mac).
2. Type **`/open MCP Settings`** and run it.
3. Find **`laravel-boost`** and **turn the toggle ON**.

If `laravel-boost` does not appear, Cursor may be using a different MCP config location. Add the server manually:

- In Cursor go to **Settings → MCP** (or the place where MCP servers are configured).
- Add a server with:
  - **Name:** `laravel-boost`
  - **Command:** `php`
  - **Args:** `artisan`, `boost:mcp`
  - **Working directory:** your project root (`c:\CapstoneProject`).

On Windows, if `php` is not in your PATH, use the full path to `php.exe` (e.g. `C:\php\php.exe`) as the command.

## 4. Use it

- Keep your project open in Cursor.
- In the AI chat, you can ask Laravel-specific questions; the agent can use Boost’s tools (Search Docs, Tinker, schema, routes, etc.) when the `laravel-boost` MCP server is on.

## Optional: Update Boost resources later

After updating Laravel or other ecosystem packages:

```bash
php artisan boost:update
```

## Project `.mcp.json`

This project includes a `.mcp.json` that defines the `laravel-boost` MCP server. If Cursor picks up MCP config from the project, it may use this file. If Cursor uses a global config, copy the `laravel-boost` entry from `.mcp.json` into that config.
