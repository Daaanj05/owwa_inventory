# Consumption trend data

The **consumption trend** on the dashboard is built from **Issuances**, not from the number of items you have.

- **Items** = what you track (e.g. "Bond paper", "Printer ink").
- **Issuances** = records of *giving out* a quantity of an item to a department on a date. Each issuance is "consumption" for that department in that period.

So if you only add 1 item and never record any **Issuances**, the consumption chart will be empty.

## How to get consumption trend data

1. **Add Issuances**  
   Go to **Inventory → Issuances → Create**, choose an item, office, department, quantity, and date. Each issuance adds one point to the consumption trend (by department and month).

2. **Run the demo seeder (once)**  
   If you have at least one item and want sample data:

   ```bash
   php artisan db:seed --class=ConsumptionDemoSeeder
   ```

   This creates one office, two departments, and several issuances over the last 3 months for your first item. Run it only when you have **no** issuances yet (it skips if you already have any).

3. **Add data through the app**  
   Create offices and departments under **Setup**, then add **Acquisitions** (to increase stock) and **Issuances** (to record consumption). The dashboard will then show trends by department and time.
