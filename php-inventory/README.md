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
- **Vehicle fitment:** Compatibility must be populated on both products. Every comma-separated vehicle entry on the source must be present on the alternative (case-insensitive, with whitespace normalized), unless the alternative explicitly says `Universal`. A universal source requires a universal alternative. Shared words such as a manufacturer name do not establish fitment.
- **Specifications:** When the source has specifications, the alternative must have the same specification text, ignoring case and repeated whitespace. The schema stores free text, so the system deliberately excludes differently worded specifications rather than guessing equivalence. If the source has no specifications, no specification constraint can be inferred; enter required sizes/ratings before relying on recommendations.
- **Minimum threshold:** 0.20, retained with the existing weights. Same-category eligibility already contributes 0.30, so this cutoff is redundant as an eligibility filter; the remaining features differentiate ranking.
- **Ranking:** Highest similarity first, then lower selling price, then case-insensitive alphabetical product name. Product ID breaks any remaining tie. All eligible alternatives are returned; there is no top-three limit.
- **No match:** POS displays `No suitable alternative available` when no eligible in-stock replacement is found.
- **Auto-sync:** Recalculates when products are created, updated, or deleted
- **Existing matches:** Eligibility is also checked when recommendations are read, so cached cross-category or incompatible pairs are excluded immediately without a database migration.

Run unit checks with `php tests/recommendations.php`. For database integration checks, enable PDO MySQL and set `INVENTORY_TEST_DB_DSN` (e.g. `mysql:host=127.0.0.1;port=3306;charset=utf8mb4`, without `dbname`), `INVENTORY_TEST_DB_USER`, and `INVENTORY_TEST_DB_PASS` for a disposable local MySQL/MariaDB server. The test creates a uniquely named database using the repository's tables and drops only that database afterward. Use PHP 8.1+.

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
