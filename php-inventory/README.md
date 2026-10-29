# Inventory Management with Point of Sale System
### Using Feature-Based Recommendation Algorithm
**Five Brothers Trading — Mabuhay, Carmona, Cavite**

---

## About

A web-based Inventory Management and Point of Sale (POS) system built for Five Brothers Trading, a motorcycle parts shop in Carmona, Cavite. The system features role-based access control, real-time stock monitoring, transaction processing, and a **Feature-Based Recommendation Algorithm** that suggests alternative products based on shared characteristics.

---

## Technology Stack

| Layer | Technology |
|---|---|
| Backend | PHP 8+ (strict types, vanilla architecture) |
| Database | MySQL via PDO (prepared statements, utf8mb4) |
| Frontend | Tailwind CSS (CDN), Lucide Icons (SVG) |
| Server | XAMPP (Apache + MySQL) |
| Version Control | Git / GitHub |

---

## Prerequisites

Download and install the following:

1. **XAMPP** — [apachefriends.org](https://www.apachefriends.org/)
2. **VS Code** — [code.visualstudio.com](https://code.visualstudio.com/)
3. **Git** — [git-scm.com](https://git-scm.com/)

---

## Installation & Setup

### 1. Clone the Repository

```bash
cd C:\xampp\htdocs
git clone https://github.com/rcconstante/inventory-system-pos-thesis inventory
```

> You can also download as ZIP from GitHub and extract to `C:\xampp\htdocs\inventory`.
> Using `git clone` is recommended — run `git pull` anytime to get the latest updates.

### 2. Start XAMPP

1. Open **XAMPP Control Panel**
2. Click **Start** next to **Apache**
3. Click **Start** next to **MySQL**
4. Both should show green (server is running)

### 3. Set Up the Database

1. Open **http://localhost/phpmyadmin** in your browser
2. Click **"New"** on the left sidebar
3. Enter database name: `inventory_pos_db`
4. Click **Create**
5. Click the **"Import"** tab
6. Click **"Choose File"** → select `C:\xampp\htdocs\inventory\php-inventory\database.sql`
7. Click **"Go"** — this creates all tables and inserts default data

### 4. Access the Application

```
http://localhost/inventory/php-inventory/
```

---

## Default Login Credentials

| Role | Username | Password |
|---|---|---|
| Admin | `admin` | `admin123` |
| Cashier | `cashier` | `cashier123` |
| Staff | `staff` | `staff123` |

---

## Features

### Role-Based Access

| Feature | Admin | Cashier | Staff |
|---|:---:|:---:|:---:|
| Dashboard | ✅ Analytics | ✅ Own Transactions | ❌ |
| Category Management | ✅ | View Only | ✅ |
| Product Management | ✅ | Search Only | ✅ |
| Point of Sale (POS) | ❌ | ✅ | ❌ |
| User Management | ✅ | ❌ | ❌ |
| Reports / Records | ✅ All Data | ✅ Own Data | ❌ |
| Delete Products/Categories | ✅ | ❌ | ✅ |

### Core Modules

- **Admin Dashboard** — Daily sales summary, fast/slow moving product analytics
- **Cashier Dashboard** — Personal transaction history, daily/weekly metrics, receipt viewing, transaction returns
- **Category Management** — CRUD operations with product count tracking
- **Product Management** — Full CRUD with inventory tracking, expiry dates, stock levels, and recommendation display
- **Point of Sale** — Product search, cart management, multi-select, checkout with Cash/GCash/Card, receipt generation
- **User Management** — Create/edit/deactivate users, role assignment (Admin only)
- **Reports** — Top Selling, Sold Items, Critical Stocks, Cancelled Orders with date filtering
- **Notifications** — Automatic alerts for critical stock, low stock, expiring, and expired products
- **Settings** — Dark mode toggle, recommendation toggle, password change
- **Feature-Based Recommendations** — AI-driven product suggestions in POS and product pages

---

## Feature-Based Recommendation Algorithm

The system compares products using five weighted features to suggest alternatives:

| Feature | Weight | Description |
|---|---|---|
| Category | 0.30 | Same product category |
| Product Type | 0.25 | Same type (e.g., both "Engine Oil") |
| Compatibility | 0.20 | Same vehicle compatibility |
| Brand | 0.10 | Same manufacturer |
| Specification | 0.15 | Overlapping keywords in specs (capped) |

- **Eligibility:** Same non-empty category and product type; alternatives must be active and have remaining stock in active, non-depleted, non-expired batches, matching the checkout stock rules. Product type comparisons ignore case and repeated whitespace.
- **Compatibility:** Select compatible model names in Inventory. Each source model must be covered by the alternative, unless it is Universal. Universal is selected alone. Names ignore case, spacing and punctuation. Inventory → Manage Product Options opens a modal with Compatibility and Product Type side by side, with add/edit/delete. Saves reopen the modal with inline feedback, refresh product selectors, and preserve the Inventory search, category and page. Old manager links redirect to the Inventory modal. Editing a name updates linked products and recommendations. Deletion is blocked whenever any product uses the option, including archived products. Previously archived compatibility entries can still be restored; they remain valid on existing assignments but cannot be newly assigned until restored.
- **Essential specifications:** Use the labeled Specifications fields for tire size, position, voltage and oil viscosity. Complete only requirements that replacements must match, using consistent units. Every source requirement must match the candidate; extra candidate attributes and descriptive wording are allowed. A Front and rear candidate can cover a Front-only or Rear-only source. For tire/tyre product types, a single unambiguous size is required, either explicitly entered or parsed from Specification (`80/90-14`, `80/90R14`, `2.75-17`). Unknown or ambiguous sizes without an explicit size are excluded. Different construction markers such as `R` and `-` remain distinct. Essential values ignore case and repeated spaces. Descriptive specification text does not require an exact match. Unambiguous voltage and viscosity values can also be read from legacy descriptions; equivalent units/spacing such as 12 Volts / 12V and 10 w 40 / 10W-40 normalize to the same value. Batteries without known voltage and engine/gear oils without known viscosity are excluded. Enter other required fitment explicitly. Essential fitment is also shown in product details and POS specification dialogs.
- **Minimum threshold:** 0.20, retained with the existing weights. Same-category eligibility already contributes 0.30, so this cutoff is redundant as an eligibility filter; the remaining features differentiate ranking.
- **Ranking:** Highest similarity first, then lower selling price, then case-insensitive alphabetical product name. Product ID breaks any remaining tie. All eligible alternatives are returned; there is no top-three limit.
- **No match:** POS displays `No suitable alternative available` when no eligible in-stock replacement is found.
- **POS search:** The recommendation panel opens only for an exact active product name or product code (for example P0001) whose sellable batch stock is zero. Generic brand, category, compatibility, partial-name and unknown searches keep normal results without a recommendation panel. An available Plus variant does not suppress an exact out-of-stock product. Codes distinguish same-name variants. Suitable alternatives may also appear in the normal results.
- **Customer motorcycle model:** Select an active model in POS to check alternatives for that customer's motorcycle. Both the original product and replacement must support that exact canonical model (or explicitly support Universal); matching the entire compatibility list or sharing two models is unnecessary. Category, product type, essential fitment and available active, nonexpired stock batches still apply. Verified selected-model compatibility earns the existing 0.20 compatibility weight; score, lower price, alphabetical name and ID determine ranking. Without a model selection, the existing full-coverage rule applies. Invalid or inactive model selections show feedback and return no alternatives. Model-specific results are calculated from current products and batches rather than the global full-coverage cache. The selection survives search/category changes and cart actions. This feature requires no database reset or migration.
- **Auto-sync:** Recalculates when products are created, updated, or deleted
- **Existing matches:** Eligibility is checked again when recommendations are read. Product creation, edits (including fitment and vehicle selections), and archival rebuild matches in the same transaction. Stock and price changes are reflected when read. Deleting a category clears obsolete matches.

### Upgrade an existing installation

Database upgrades now run **automatically once when an active administrator signs in and opens the updated system**. Back up the database, pull the code, then open the dashboard or Inventory as admin. No terminal migration command is needed. The database account needs permission to create/alter the required tables as well as read/write application data.

The app records completion in `Schema_Migration`, checks the authenticated account's current admin role, and uses a database advisory lock to prevent simultaneous upgrades. Staff and cashiers see a pending-update message until an admin completes the upgrade. Login remains available throughout. Failed imports roll back their data changes and leave the upgrade pending for retry; schema changes already committed by MySQL remain safe to reuse. HTTP errors show a concise message and detailed failures go to server logs. API requests receive JSON errors.

For deployment scripts or troubleshooting, the same versioned upgrade is still available as an optional CLI command from `php-inventory`, using the app's `INVENTORY_DB_HOST`, optional `INVENTORY_DB_PORT` (default 3306), `INVENTORY_DB_NAME`, `INVENTORY_DB_USER` and `INVENTORY_DB_PASS` configuration:

```sh
php migrations/recommendation-fitment.php
```

The shared upgrade adds `Products.essential_fitment`, creates `Vehicle_Compatibility`, `Vehicle_Alias`, `Product_Vehicle`, `Product_Type`, `Recommendation_State` and `Schema_Migration`, imports existing compatibility values and product types, consolidates spacing/punctuation variants, and rebuilds recommendations. Completed upgrades skip data import on subsequent page loads and CLI runs. The recommendation state record also prevents empty match caches from being recalculated on every read. The schema-only step preserves existing data; the separate one-time fresh-data reset below intentionally clears the previous catalog and sales. Fresh installations import the updated `database.sql`; the first admin visit initializes the catalogs and completion marker.

After upgrading, correct model names in Manage Product Options and complete product specifications where needed. Existing tire descriptions with a recognizable size work immediately. Do not re-import `database.sql` over an existing installation.

### Faster product entry

Add and Edit Product provide a **Product Type (select one)** dropdown with search and **Add New Product Type**, plus a **Compatibility (select multiple)** dropdown with search, inline **Add New Motorcycle Model**, selected count, **Cancel** and **Apply**. Product type selection applies immediately. Compatibility checkboxes update a draft; Apply commits it to the form, while Cancel, Escape or clicking outside discards unapplied selections. Opening another picker also cancels the previous draft. New types/models are saved for future products even when a selection is canceled. Existing names reuse the canonical record; archived entries require restoration in Manage Product Options. Inline creation enforces catalog permissions, CSRF validation, bounded input and request limits. Unknown product types submitted outside the selector are rejected server-side.


The Product Type dropdown uses the persistent `Product_Type` catalog. The automatic upgrade imports existing types, including installations that previously ran the manual migration. The latest upgrade also removes retired rating/mounting requirements and alternate-name entries, then adds GCash reference storage. The legacy Vehicle_Alias table now holds canonical name keys only for uniqueness; there is no alias search, alias editor or merge feature. No products or stock history need to be re-entered.

Run unit checks with `php tests/recommendations.php`. For database integration checks, enable PDO MySQL and set `INVENTORY_TEST_DB_DSN` (e.g. `mysql:host=127.0.0.1;port=3306;charset=utf8mb4`, without `dbname`), `INVENTORY_TEST_DB_USER`, and `INVENTORY_TEST_DB_PASS` for a disposable local MySQL/MariaDB server. Run `php tests/recommendations.php` and `php tests/recommendation_http.php`. Each creates a uniquely named database and drops only that database afterward. The HTTP suite starts and stops an isolated PHP server and exercises migration reruns, login, POS, fitment edits, compatibility edits, product archival, CSRF and cashier access. Use PHP 8.1+.

Run `php tests/database_upgrades.php` to verify upgrade locking, rollback, retry and one-time execution. Set `INVENTORY_TEST_AUTOMATIC_UPGRADE=1` when running `tests/recommendation_http.php` to test the automatic admin path without invoking the CLI migration, including non-admin blocking, failed-upgrade responses and retry.

### Example

```
Product A: Motul Engine Oil 10W-40 (Engine Parts, Universal)
Product B: Castrol Engine Oil 10W-40 (Engine Parts, Universal)

  Category match:      +0.30
  Product type match:  +0.25
  Compatibility match: +0.20
  Brand (different):   +0.00
  Spec overlap (3):    +0.15
                       ─────
  Score:                0.90 ✓ Match
```

---

## Project Structure

```
php-inventory/
├── index.php                  Landing page
├── login.php                  Authentication
├── logout.php                 Session termination
├── database.sql               Database schema + seed data
├── db_migrate.php             Schema migrations
│
├── includes/
│   ├── app.php                Core: session, auth, CSRF, roles, helpers
│   ├── config.php             Database connection (PDO)
│   ├── domain.php             Business logic: recommendations, alerts
│   ├── header.php             Page header, notifications
│   ├── footer.php             Page footer, modal scripts
│   ├── sidebar.php            Role-based navigation
│   ├── settings_modal.php     Settings panel
│   └── notifications_modal.php Stock/expiry alerts
│
└── pages/
    ├── admin_dashboard.php    Admin analytics
    ├── cashier_dashboard.php  Cashier transactions & returns
    ├── category.php           Category CRUD
    ├── products.php           Product CRUD + inventory
    ├── pos.php                Point of Sale interface
    ├── records.php            Reports (4 tabs)
    ├── settings.php           Settings handler
    ├── users.php              User management
    └── users_create.php       User creation
```

---

## Database Schema

| Table | Purpose |
|---|---|
| `Role` | User roles (Admin, Cashier, Staff) |
| `User` | User accounts with bcrypt passwords |
| `Category` | Product categories |
| `Products` | Product catalog with features |
| `Inventory` | Stock levels and expiry dates |
| `Sale` | Transaction records |
| `Sale_Item` | Individual items per transaction |
| `Reorder_Alert` | Auto-generated low stock alerts |
| `Feature_Based_Match` | Precomputed product recommendations |

---

## Security

- **SQL Injection** — PDO prepared statements, `EMULATE_PREPARES = false`
- **XSS** — All output escaped via `htmlspecialchars()` with `ENT_QUOTES`
- **CSRF** — Token per session using `random_bytes(32)`, validated with `hash_equals()`
- **Passwords** — bcrypt hashing (`PASSWORD_DEFAULT`)
- **Sessions** — HttpOnly, SameSite=Lax, session regeneration on login

---

## Updating

```bash
cd C:\xampp\htdocs\inventory
git pull origin main
```

---

*Capstone Project — Cavite State University*

### Archived products and GCash receipts

Open Inventory → Archived Products to see products that can still be restored, their archived dates and restore deadlines. Authorized catalog managers can Restore before the 30-day deadline. Restoring preserves batch history and recalculates sellable stock and recommendations. Expired archives are no longer restorable; linked sales history is retained.

GCash checkout requires a reference number of 1–64 letters, numbers, spaces or hyphens. It is validated before stock is consumed, stored on Sale.reference_number, and displayed on the sale owner’s receipt and printed receipt. Cash payments store no reference. Older receipts without a saved reference remain readable.

## Final product list and batches

The supplied workbooks are kept at the repository root. The validated application payload is `catalog/final-products.php` (220 products, 425 batches, 1,506 units). Same-name products remain separate rows; workbook product numbers link batches to the correct specification variant. The 17 zero-stock products have no invented batches. Source SHA-256 hashes are included in the payload.

After pulling this update, the first authenticated admin visit performs the approved fresh-data reset: previous sales, sale items, stock batches, products, categories, compatibility entries and product types are physically deleted, then replaced with the final list, including its batches, prices and essential specifications. The database completion marker `2026_10_fresh_catalog_sales_v2` is written in the same transaction as the replacement. Every later visit skips replacement, so sales, stock adjustments, product edits and newly added products persist. No button, new database or separate migration command is needed. Cashiers/staff see a pending-update notice until an admin completes the first visit. On failure the catalog changes roll back and the next admin visit can retry; concurrent updates use the database upgrade lock.

CLI alternative: `php scripts/import-final-catalog.php` validates the payload; append `--apply` to run the same one-time update on the configured database. Repeating it skips an already-completed update. Regenerate the payload from the workbooks with `python scripts/prepare-final-catalog.py` (requires openpyxl for reading). Product dates use the source ISO dates; batch dates are parsed as DD/MM/YYYY. Batch quantities and costs are preserved; received timestamps are the actual import time because the source provides no receipt date. FIFO uses source batch order when timestamps tie.

GCash references persist in the Sale record and appear on POS receipts and reopened admin/cashier dashboard receipts, including print views. Cashier history is scoped to that cashier. Historical sales that never stored a reference display no invented value.

Run `php tests/catalog_import.php` with the disposable database environment described above to verify stock reconciliation, repeat-import protection, essential matching and FIFO across imported batches.

This reset intentionally deletes previous sales and their GCash references, rather than preserving them as hidden records. User accounts, passwords, roles and account settings remain unchanged. Old session carts are cleared once per user; carts created afterward remain. The completion marker prevents another reset on later opens. A failed data import rolls back both deletion and insertion, leaving the previous business data intact.

Run `php tests/catalog_update.php` to verify replacement, transaction rollback/retry, concurrent access, account preservation, deletion of prior sales/categories and that later stock/product changes survive repeated opens.

Inventory opens by stable product code: P001 through P220 for the source list, then P221 for the next added product. Product display codes are stored separately from database IDs, are unique, and are allocated inside the save transaction. Archiving/restoring does not renumber other products. Inventory search, POS search, cart/checkout and reports use the same displayed code. No business data is reset when an already-completed product-code correction is checked.


### October 29 revisions

- **Inventory / Stock-in:** Product forms no longer set quantities. New products start with zero stock; product edits preserve stock. Use Inventory → Stock-in to receive a validated batch into an active product. Existing batch management remains available.
- **Batch audit dates:** Product creation / edit timestamps and batch received / updated timestamps appear in Batch Management. Historical product creation dates that were never recorded display “Not recorded”.
- **Returns:** Admins and cashiers can select whole sale-item lines and choose original sellable batches or Do not restock. Cashiers retain sale ownership restrictions. Reasons, time and destination are saved per line. Repeated returns are rejected. Partial-return sales remain completed for the remaining lines; dashboard revenue subtracts returned line amounts and reports exclude returned items from sold/top-selling totals. The final returned line marks the sale RETURNED. Expired, disposed or archived-product batches cannot be restocked through returns. Legacy returns without stored reasons show “Reason not recorded”.
- **Reports:** Shorter print/export header. Inventory list supports printing / Save as PDF via the print dialog and CSV download with spreadsheet formula protection.
- **Business settings:** Settings gear → Business header, logo & product properties (admin only). Name, address, phone and validated PNG/JPEG logo apply to navigation and receipts; reports use the configured branding. Logo uploads stay in the database as image data, without executable filesystem uploads. Custom property keys are stable; an existing key can have its label renamed. Per-product nonblank custom values participate in essential alternative matching. Built-in fitment rules remain protected.
- **Receipts:** Stable `SI-` invoice identifiers derived from the sale ID appear on POS and dashboard receipts.
- **Upgrade:** New tables and columns are installed once automatically under a database lock. This revision does not change the existing catalog-reset version or reset products, stock, sales or accounts.

Annotated screenshots and the walkthrough are in `../artifacts/revision-guide/index.html`. They use disposable test records, not live customer transactions.
