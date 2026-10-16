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
- **Vehicle fitment:** Select standardized vehicle records in Inventory. Every source vehicle must be covered by the alternative, unless the alternative is explicitly `Universal`. Universal cannot be selected alongside specific models. Names ignore case, spacing and punctuation; spelling variants are recorded as aliases instead of being guessed. Inventory's Manage Compatibility screen supports add/edit, archive/restore, and merging a duplicate into the correct record. Renames and merges update linked products and rebuild matches. Archived vehicles remain valid on existing assignments but cannot be newly assigned. Admin and staff retain catalog-management permissions; cashiers cannot modify these records.
- **Essential specifications:** Use the labeled Essential Fitment fields for tire size, position, voltage, oil viscosity, mounting/thread size and required rating. Complete only requirements that replacements must match, using consistent units. Every source requirement must match the candidate; extra candidate attributes and descriptive wording are allowed. A Front and rear candidate can cover a Front-only or Rear-only source. For tire/tyre product types, a single unambiguous size is required, either explicitly entered or parsed from Specification (`80/90-14`, `80/90R14`, `2.75-17`). Unknown or ambiguous sizes without an explicit size are excluded. Different construction markers such as `R` and `-` remain distinct. Essential values ignore case and repeated spaces. Non-tire legacy products retain full-text specification matching until structured requirements are entered; other essential attributes must be entered by the catalog manager, not inferred from marketing text. Essential fitment is also shown in product details and POS specification dialogs.
- **Minimum threshold:** 0.20, retained with the existing weights. Same-category eligibility already contributes 0.30, so this cutoff is redundant as an eligibility filter; the remaining features differentiate ranking.
- **Ranking:** Highest similarity first, then lower selling price, then case-insensitive alphabetical product name. Product ID breaks any remaining tie. All eligible alternatives are returned; there is no top-three limit.
- **No match:** POS displays `No suitable alternative available` when no eligible in-stock replacement is found.
- **POS search:** An exact product-name match takes priority as the recommendation source. If that product has no sellable batches, recommendations still appear alongside available partial matches such as a Plus variant. Broad searches use matching unavailable products as sources. Main results and recommendations use checkout's batch availability rules, and already-visible alternatives are not duplicated in the recommendation panel.
- **Auto-sync:** Recalculates when products are created, updated, or deleted
- **Existing matches:** Eligibility is checked again when recommendations are read. Product creation, edits (including fitment and vehicle selections), and archival rebuild matches in the same transaction. Stock and price changes are reflected when read. Deleting a category clears obsolete matches.

### Upgrade an existing installation

This update **requires a migration** for structured fitment and standardized compatibility. Back up the database and pause writes, pull the code, then run from `php-inventory` with the same `INVENTORY_DB_HOST`, optional `INVENTORY_DB_PORT` (default 3306), `INVENTORY_DB_NAME`, `INVENTORY_DB_USER` and `INVENTORY_DB_PASS` configuration as the app:

```sh
php migrations/recommendation-fitment.php
```

The CLI-only migration adds `Products.essential_fitment`, creates `Vehicle_Compatibility`, `Vehicle_Alias`, `Product_Vehicle` and `Recommendation_State`, imports existing comma-separated compatibility values, consolidates spacing/punctuation variants, and rebuilds recommendations. The state record tracks the algorithm version so an empty valid match cache is not recalculated on every read. It is safe to rerun and does not delete products, sales or batches. MySQL schema changes commit independently; a failed data import rolls back its data transaction and can be rerun after correcting the reported value. Fresh installations import the updated `database.sql`; the migration may still be run to initialize compatibility from existing data.

After migration, use Manage Compatibility to merge misspelled duplicate models, then enter essential fitment for products that require it. Existing tire descriptions with a recognizable size work immediately. Do not re-import `database.sql` over an existing installation.

Run unit checks with `php tests/recommendations.php`. For database integration checks, enable PDO MySQL and set `INVENTORY_TEST_DB_DSN` (e.g. `mysql:host=127.0.0.1;port=3306;charset=utf8mb4`, without `dbname`), `INVENTORY_TEST_DB_USER`, and `INVENTORY_TEST_DB_PASS` for a disposable local MySQL/MariaDB server. Run `php tests/recommendations.php` and `php tests/recommendation_http.php`. Each creates a uniquely named database and drops only that database afterward. The HTTP suite starts and stops an isolated PHP server and exercises migration reruns, login, POS, fitment edits, compatibility merges, product archival, CSRF and cashier access. Use PHP 8.1+.

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
