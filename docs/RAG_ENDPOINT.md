# RAG (Retrieval-Augmented Generation) Endpoint

The RAG endpoint returns structured inventory data so an AI (e.g. Ollama + DeepSeek) can answer questions using **real numbers** from your database—no training required.

## Endpoint

```
GET /api/rag/context
```

### Optional query parameters

| Parameter    | Description                                      | Default |
|-------------|---------------------------------------------------|---------|
| `from`      | Start date (Y-m-d)                                | Earliest data in DB |
| `to`        | End date (Y-m-d)                                  | Latest data in DB |
| `max_months`| Cap history to this many months (e.g. 60 = 5 years) | 60   |

### Example

```bash
# All available data (up to 5 years)
curl http://localhost:8000/api/rag/context

# Last 12 months
curl "http://localhost:8000/api/rag/context?max_months=12"

# Specific range
curl "http://localhost:8000/api/rag/context?from=2024-01-01&to=2024-12-31"
```

## Response shape

```json
{
  "data_range": {
    "from": "2024-01-01",
    "to": "2025-02-21",
    "months": 14,
    "years": 1.2,
    "label": "1.2 years"
  },
  "summary": {
    "total_consumption_units": 1250,
    "top_department": "HR",
    "top_department_quantity": 400,
    "avg_consumption_per_period": 89.3
  },
  "consumption_by_department": [
    { "department": "HR", "quantity": 400 },
    { "department": "IT", "quantity": 350 }
  ],
  "low_stock_summary": {
    "item_office_pairs_at_or_below_reorder": 3
  },
  "generated_at": "2025-02-21T12:00:00.000000Z"
}
```

## Using with Ollama / DeepSeek

1. **Get context** from this API (from your Laravel app or any HTTP client).
2. **Build a prompt** that includes the context, e.g.:

   ```
   Based on the following inventory data, answer the user's question. Use only the numbers given.

   Data period: {{ data_range.label }} ({{ data_range.from }} to {{ data_range.to }}).
   Total consumption: {{ summary.total_consumption_units }} units.
   Top department: {{ summary.top_department }} ({{ summary.top_department_quantity }} units).
   Consumption by department: {{ consumption_by_department }}.
   Low stock alerts (item–office pairs): {{ low_stock_summary.item_office_pairs_at_or_below_reorder }}.

   User question: ...
   ```

3. **Call Ollama** with that prompt (e.g. `POST http://localhost:11434/api/generate` with `model: deepseek-r1`, `prompt: ...`).

The AI will then answer using the retrieved data instead of guessing—high accuracy, real-time, no fine-tuning.

## Security

For production or exposed deployments, protect this route (e.g. API token, Sanctum, or restrict by IP). For local/capstone demos it can remain open if the app is not public.
