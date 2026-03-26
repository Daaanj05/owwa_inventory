# Designs, Ollama/DeepSeek Setup, and “Neuron AI” Explained

## 1. Where are the designs? (Files to change the UI)

This project **does not include separate design files** (e.g. Figma or mockups). The **design is the code** that builds the admin UI. When you want to **change the UI**, edit these files:

- **Panel theme / primary color:** `app/Providers/Filament/AdminPanelProvider.php` (e.g. `->colors(['primary' => Color::Amber])`)
- **Tables** (columns, filters, row actions): `app/Filament/Resources/<Name>/Tables/<Name>Table.php`
- **Forms** (create/edit fields): `app/Filament/Resources/<Name>/Schemas/<Name>Form.php`
- **Custom pages** (e.g. Procurement recommendations, COA reports): `app/Filament/Pages/*.php` and `resources/views/filament/pages/*.blade.php`
- **Dashboard widgets:** `app/Filament/Widgets/*.php`
- **COA PDF layout:** `resources/views/reports/*.blade.php`

A full map with examples is in **[docs/UI_AND_DESIGN_FILES.md](UI_AND_DESIGN_FILES.md)**.

---

## 2. Installing Ollama and DeepSeek (step-by-step)

**You do not install “DeepSeek” as a separate app.** DeepSeek is a **model** that runs **inside Ollama**. So you only install **Ollama**; then you **pull** the DeepSeek model (and the embed model) using Ollama’s CLI.

### Step 1: Download and install Ollama (Windows)

1. Go to **https://ollama.com/download** (or https://ollama.ai).
2. Download the **Windows** installer.
3. Run the installer and follow the prompts (default options are fine).
4. When finished, Ollama usually starts in the background. You can confirm by opening a browser and going to **http://localhost:11434** – you should see a simple Ollama page or “Ollama is running”.

### Step 2: Pull the models (embed + chat)

Open **PowerShell** or **Command Prompt** and run:

```bash
# Embedding model (used by RAG to understand inventory context)
ollama pull nomic-embed-text

# Chat model for generating recommendations (DeepSeek v3.2 7B)
ollama pull deepseek-v3.2:7b
```

After pulling, run `ollama list` to see the exact model name if you need it (e.g. some setups use `deepseek-v3.2` without the `:7b` tag). Set the same name in your project’s **`.env`**:

```env
OLLAMA_CHAT_MODEL=deepseek-v3.2:7b
```

If that model is unavailable or too large, you can use e.g. `ollama pull deepseek-coder` or `ollama pull llama3.2` and set `OLLAMA_CHAT_MODEL` to that name in `.env`.

### Step 3: Confirm Ollama is running

- **URL:** http://localhost:11434  
- Your app already uses this in `.env`: `OLLAMA_URL=http://localhost:11434`

No need to “download DeepSeek” separately – Ollama downloads the model the first time you run `ollama pull deepseek-r1` (or whichever model you choose).

### Step 4: Use it in the app

1. Start your Laravel app (`php artisan serve`).
2. Log in to the admin panel and go to **Procurement recommendations** (under Analytics).
3. Click **“Generate recommendation”**.  
   - If Ollama is running and the models are pulled, you’ll get AI-generated procurement suggestions.  
   - If not, you’ll see the message: *“Ollama is not available. Start Ollama and ensure the embed and chat models are installed.”*

---

## 3. Where is “Neuron AI”? Do I need to download or install it?

**You do not need to download or install anything called “Neuron AI.”**

In your plan and in the README, **“Neuron AI”** is just a **label for the AI part** of the system. In this project that means:

- **Ollama** (the app you install from ollama.com)
- **Plus the models** you pull inside Ollama (e.g. **DeepSeek v3.2 7B** for chat, **nomic-embed-text** for embeddings)

There is no separate “Neuron AI” product or installer. Once Ollama is installed and the models are pulled, the “Neuron AI” functionality is already there: it’s used on the **Procurement recommendations** page and in `app/Services/OllamaClient.php` and `app/Services/RagService.php`.

---

## Quick reference

| Term / question              | What it means in this project |
|-----------------------------|-------------------------------|
| **Designs**                 | No Figma/XD files; UI is Filament (see `app/Filament/` and `resources/views/filament/`). Add screenshots or mockups in `docs/` if needed. |
| **Ollama**                  | The app you install; it runs AI models locally. Install from https://ollama.com/download . |
| **DeepSeek**                | A model that runs inside Ollama. Use `ollama pull deepseek-r1` (or another variant) after installing Ollama. |
| **Neuron AI**               | Same as “the AI part” of the system = Ollama + models (e.g. DeepSeek v3.2 7B + nomic-embed-text). **You do not download or install “Neuron AI”**; it’s just this stack. |
