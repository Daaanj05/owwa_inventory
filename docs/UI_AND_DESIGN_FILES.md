# Files Responsible for UI / Design (Where to Change the Interface)

Use this as a map when you want to change how the admin panel looks or behaves. All paths are relative to the project root (`c:\CapstoneProject`).

---

## 1. Panel-wide theme and layout

| What you want to change | File to edit |
|-------------------------|--------------|
| **Primary color**, **branding**, **sidebar behavior**, **default theme (light/dark)** | `app/Providers/Filament/AdminPanelProvider.php` |
| Example: change `Color::Blue` to `Color::Green` in `->colors(['primary' => ...])`; change `ThemeMode::Light` in `->defaultThemeMode()`. |
| **OWWA blue-to-red gradient theme** | `public/css/filament/admin/owwa-theme.css` — gradient on sidebar, topbar, primary buttons; loaded via `renderHook(PanelsRenderHook::STYLES_AFTER)` in the panel provider. |
| **"OWWA Inventory" brand text color** | In `public/css/filament/admin/owwa-theme.css`, search for "Brand name" — the rules under that comment set the logo/brand font color (sidebar: `.fi-sidebar-header-logo-ctn` and `.fi-logo`; topbar: `.fi-topbar .fi-logo`). Change `#fff` or `rgba(255,255,255,0.95)` to any color you want. |
| **Custom CSS** for the whole admin | Edit `public/css/filament/admin/owwa-theme.css` or create a [Filament theme](https://filamentphp.com/docs/panels/themes) and register it in the panel. |

---

## 2. List and form screens (tables and forms)

Each resource (Offices, Items, Acquisitions, etc.) has **three places** that control its UI:

| Purpose | Folder / files |
|--------|-----------------|
| **Table** (columns, filters, row actions) | `app/Filament/Resources/<Resource>/Tables/<Resource>Table.php` |
| **Form** (fields when creating/editing) | `app/Filament/Resources/<Resource>/Schemas/<Resource>Form.php` |
| **Resource** (model, icon, navigation group) | `app/Filament/Resources/<Resource>/<Resource>Resource.php` |

Examples:

- **Offices** list and form: `app/Filament/Resources/Offices/Tables/OfficesTable.php`, `app/Filament/Resources/Offices/Schemas/OfficeForm.php`
- **Items**: `app/Filament/Resources/Items/Tables/ItemsTable.php`, `app/Filament/Resources/Items/Schemas/ItemForm.php`
- **Requisitions** (including Approve/Reject buttons): `app/Filament/Resources/Requisitions/Tables/RequisitionsTable.php`, `app/Filament/Resources/Requisitions/Schemas/RequisitionForm.php`
- **Requisition line items**: `app/Filament/Resources/Requisitions/RelationManagers/ItemsRelationManager.php`

To **add/remove/reorder columns** or **change form fields**, edit the corresponding Table and Form files above.

---

## 3. Custom pages (full-page UI)

| Page | What controls the UI |
|------|----------------------|
| **Stock levels** (items + offices + current stock) | `app/Filament/Pages/StockLevels.php` and `resources/views/filament/pages/stock-levels.blade.php` |
| **Procurement recommendations** | `app/Filament/Pages/ProcurementRecommendations.php` (header button) and `resources/views/filament/pages/procurement-recommendations.blade.php` (page content and layout) |
| **COA reports** | `app/Filament/Pages/CoaReports.php` (header buttons) and `resources/views/filament/pages/coa-reports.blade.php` (page content) |

Edit the **Blade** file to change the HTML and layout; edit the **Page** class to change buttons and behavior.

---

## 4. Dashboard widgets

| Widget | File |
|--------|------|
| **Low stock alerts** | `app/Filament/Widgets/LowStockWidget.php` |
| **Issuance trends chart** | `app/Filament/Widgets/IssuanceTrendsChart.php` |

You can change labels, colors, and what data is shown in these files.

---

## 5. Report layouts (COA PDFs)

| Report | File |
|--------|------|
| **Stock level report (PDF)** | `resources/views/reports/coa-stock-level.blade.php` |
| **Issuance report (PDF)** | `resources/views/reports/coa-issuance.blade.php` |

Edit the HTML and inline styles in these Blade files to change how the PDFs look.

---

## 6. Login and layout (vendor override)

The login page and base layout come from the **Filament** package. To change them you typically:

- **Publish** Filament views: `php artisan vendor:publish --tag=filament-views` (or the tag your Filament version uses), then edit the published views under `resources/views/vendor/filament/`.
- Or use panel configuration (e.g. custom login page class) if your Filament version supports it.

---

## Quick reference

- **Colors / panel config** → `app/Providers/Filament/AdminPanelProvider.php`
- **Tables** → `app/Filament/Resources/*/Tables/*.php`
- **Forms** → `app/Filament/Resources/*/Schemas/*Form.php`
- **Custom page content** → `resources/views/filament/pages/*.blade.php`
- **Widgets** → `app/Filament/Widgets/*.php`
- **PDF report layout** → `resources/views/reports/*.blade.php`
