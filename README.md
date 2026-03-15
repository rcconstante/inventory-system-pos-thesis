# Five Brothers Trading - Point of Sale & Inventory Management System

A PHP-based Point of Sale (POS) and Inventory Management System built for Five Brothers Trading, Mabuhay, Carmona, Cavite. Features role-based access control, product catalog management, real-time stock tracking, automated reorder alerts, and a feature-based product recommendation algorithm.

## Requirements

- PHP 8.1 or higher
- MySQL 5.7+ or MariaDB 10.3+
- Apache (XAMPP recommended)

## Installation

1. Clone or copy this project to your XAMPP `htdocs` directory:
   ```
   C:\xampp\htdocs\inventory\
   ```

2. Start Apache and MySQL from the XAMPP Control Panel.

3. Import the database schema by running the SQL file:
   ```
   http://localhost/phpmyadmin
   ```
   - Create the database by importing `php-inventory/database.sql`, or run it from the MySQL CLI:
     ```bash
     mysql -u root < php-inventory/database.sql
     ```

4. Open the application:
   ```
   http://localhost/inventory/php-inventory/
   ```

## Default Accounts

| Role    | Username  | Password     |
|---------|-----------|--------------|
| Admin   | admin     | admin123     |
| Cashier | cashier   | cashier123   |
| Staff   | staff     | staff123     |

> Change these passwords immediately after first login via the Settings modal.

## Database Configuration

Database credentials are configured via environment variables with sensible defaults for local development:

| Variable              | Default          |
|-----------------------|------------------|
| `INVENTORY_DB_HOST`   | `localhost`      |
| `INVENTORY_DB_USER`   | `root`           |
| `INVENTORY_DB_PASS`   | *(empty)*        |
| `INVENTORY_DB_NAME`   | `inventory_pos_db` |

No `.env` file is used. Set these as system or Apache environment variables for production.

## Project Structure

```
php-inventory/
├── index.php                  # Role selection page (entry point)
├── login.php                  # Login form (role-specific)
├── logout.php                 # Session teardown
├── database.sql               # Full database schema + seed data
├── includes/
│   ├── app.php                # Session setup, helpers, CSRF, auth, preferences
│   ├── config.php             # PDO database connection
│   ├── domain.php             # Business logic (reorder alerts, similarity algorithm)
│   ├── header.php             # Page header + flash messages
│   ├── sidebar.php            # Role-aware sidebar navigation
│   ├── footer.php             # Page footer + settings modal JS
│   └── settings_modal.php     # Profile, password, and preferences modal
├── pages/
│   ├── admin_dashboard.php    # Admin: daily/weekly sales, today's transactions
│   ├── cashier_dashboard.php  # Cashier: personal sales summary
│   ├── staff_dashboard.php    # Staff: inventory stats, low stock watchlist
│   ├── category.php           # Category CRUD (admin, staff)
│   ├── products.php           # Product + inventory CRUD with recommendations
│   ├── users.php              # User management (admin only)
│   ├── records.php            # Reports: top selling, sold items, critical stocks, cancelled
│   ├── pos.php                # Point of Sale with cart and checkout
│   ├── settings.php           # POST handler for settings modal actions
│   ├── product_form_fields.php # Reusable product form partial
│   └── user_form_fields.php   # Reusable user form partial
├── css/                       # (Reserved for custom styles)
└── js/                        # (Reserved for custom scripts)
```

## Roles & Permissions

| Feature          | Admin | Cashier | Staff |
|------------------|:-----:|:-------:|:-----:|
| Dashboard        |   Y   |    Y    |   Y   |
| Category CRUD    |   Y   |    -    |   Y   |
| Product CRUD     |   Y   |  View   |   Y   |
| User Management  |   Y   |    -    |   -   |
| POS / Checkout   |   Y   |    Y    |   -   |
| Records / Reports|   Y   |    Y*   |   Y   |
| Settings / Profile|  Y   |    Y    |   Y   |

*Cashier records are scoped to their own transactions only.

## Feature-Based Match Algorithm

The system includes a product recommendation engine that suggests alternatives based on product similarity. The algorithm computes a weighted similarity score:

| Attribute              | Weight |
|------------------------|--------|
| Same category          | 0.30   |
| Same product type      | 0.25   |
| Same compatibility     | 0.20   |
| Same brand             | 0.10   |
| Specification/description keyword overlap | up to 0.15 |

- Minimum score threshold: **0.35**
- Top **3** alternatives are stored per product
- Matches are recomputed automatically when products are added, edited, or deleted
- Can be toggled on/off per user in Settings > Preferences

## Security Features

- CSRF token validation on all POST forms
- Passwords hashed with `password_hash()` using `PASSWORD_DEFAULT` (bcrypt)
- Session ID regeneration on login
- Secure session cookies (`HttpOnly`, `SameSite=Lax`, `Secure` when HTTPS)
- Prepared statements (PDO) with emulated prepares disabled
- All output escaped with `htmlspecialchars()`
- Role-based route guards on every page
- `SELECT ... FOR UPDATE` row locking during checkout to prevent race conditions

## License

This project is proprietary software developed for Five Brothers Trading.
