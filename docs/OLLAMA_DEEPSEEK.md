# How to Download and Run DeepSeek with Ollama

Follow these steps on your PC (Windows) where Ollama is already installed.

---

## Step 1: Open a terminal

- **Option A:** Press `Win + R`, type `cmd`, press Enter.  
- **Option B:** In VS Code or Cursor, open the integrated terminal (`Ctrl + `` ` `` or View → Terminal).

---

## Step 2: Pull the DeepSeek model

Run **one** of these (choose based on your RAM and need):

### Recommended for most laptops (7B, good balance)

```bash
ollama pull deepseek-r1
```

This downloads **DeepSeek R1** (reasoning-focused, ~7B parameters). First run may take several minutes depending on your connection.

### If you want the 7B variant explicitly (same as capstone recommendation)

```bash
ollama pull deepseek-r1:7b
```

### Smaller / faster (less accurate)

```bash
ollama pull deepseek-r1:1.5b
```

### Larger / more capable (needs more RAM)

```bash
ollama pull deepseek-v3
```

---

## Step 3: Confirm it’s installed

```bash
ollama list
```

You should see `deepseek-r1` (or the model you pulled) in the list.

---

## Step 4: Run the model (optional test)

```bash
ollama run deepseek-r1
```

- Type a question and press Enter.  
- To exit, type `/bye` or press `Ctrl+D`.

---

## Step 5: Use from your app

- **Local:** Your Laravel app can call Ollama at `http://localhost:11434` (e.g. `/api/generate` with `model: deepseek-r1`).  
- **Demo (tunnel):** If Laravel is on Railway/Render and Ollama is on your laptop, use **Cloudflare Tunnel** or **Ngrok** so the host can reach `http://localhost:11434`, then set your app’s AI URL to that tunnel address.

---

## Quick reference

| Task            | Command                |
|-----------------|------------------------|
| Download 7B     | `ollama pull deepseek-r1` |
| List models     | `ollama list`          |
| Run interactively | `ollama run deepseek-r1` |
| Stop / exit     | Type `/bye` or `Ctrl+D` |

---

**Troubleshooting:** If `ollama` is not recognized, add Ollama’s install folder to your system PATH or run the command from the folder where `ollama.exe` is installed.
