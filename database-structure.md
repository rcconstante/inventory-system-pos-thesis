# Database Structure

## Entity-Relationship Diagram (Tables)

### 1. Role

| Column    | Type        | Constraints |
|-----------|-------------|-------------|
| role_id   | INT (PK)    | AUTO_INCREMENT |
| role_type | VARCHAR(50) | NOT NULL (admin / cashier / staff) |

### 2. User

| Column    | Type         | Constraints |
|-----------|--------------|-------------|
| user_id   | INT (PK)     | AUTO_INCREMENT |
| full_name | VARCHAR(100) | NOT NULL |
| username  | VARCHAR(50)  | NOT NULL, UNIQUE |
| password  | VARCHAR(255) | NOT NULL |
| email     | VARCHAR(100) | |
| role_id   | INT (FK)     | REFERENCES Role(role_id) ON DELETE SET NULL |

### 3. Category

| Column        | Type         | Constraints |
|---------------|--------------|-------------|
| category_id   | INT (PK)     | AUTO_INCREMENT |
| category_name | VARCHAR(100) | NOT NULL |
| created_at    | TIMESTAMP    | DEFAULT CURRENT_TIMESTAMP |

### 4. Products

| Column        | Type          | Constraints |
|---------------|---------------|-------------|
| product_id    | INT (PK)      | AUTO_INCREMENT |
| product_name  | VARCHAR(150)  | NOT NULL |
| brand         | VARCHAR(100)  | |
| description   | TEXT          | |
| price         | DECIMAL(10,2) | NOT NULL |
| category_id   | INT (FK)      | REFERENCES Category(category_id) ON DELETE SET NULL |
| product_type  | VARCHAR(50)   | |
| specification | TEXT          | |
| compatibility | TEXT          | |

### 5. Inventory

| Column          | Type      | Constraints |
|-----------------|-----------|-------------|
| inventory_id    | INT (PK)  | AUTO_INCREMENT |
| product_id      | INT (FK)  | REFERENCES Products(product_id) ON DELETE CASCADE |
| current_stock   | INT       | DEFAULT 0 |
| min_stock_level | INT       | DEFAULT 0 |
| date_updated    | TIMESTAMP | DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP |

### 6. Sale

| Column         | Type          | Constraints |
|----------------|---------------|-------------|
| sale_id        | INT (PK)      | AUTO_INCREMENT |
| user_id        | INT (FK)      | REFERENCES User(user_id) ON DELETE SET NULL |
| date           | TIMESTAMP     | DEFAULT CURRENT_TIMESTAMP |
| total_amount   | DECIMAL(10,2) | NOT NULL |
| payment_method | VARCHAR(50)   | |
| status         | VARCHAR(50)   | COMPLETED / CANCELLED |

### 7. Sale_Item

| Column        | Type          | Constraints |
|---------------|---------------|-------------|
| sale_item_id  | INT (PK)      | AUTO_INCREMENT |
| sale_id       | INT (FK)      | REFERENCES Sale(sale_id) ON DELETE CASCADE |
| product_id    | INT (FK)      | REFERENCES Products(product_id) |
| quantity      | INT           | NOT NULL |
| selling_price | DECIMAL(10,2) | NOT NULL |
| subtotal      | DECIMAL(10,2) | NOT NULL |

### 8. Reorder_Alert

| Column          | Type      | Constraints |
|-----------------|-----------|-------------|
| reorder_id      | INT (PK)  | AUTO_INCREMENT |
| product_id      | INT (FK)  | REFERENCES Products(product_id) ON DELETE CASCADE |
| current_stock   | INT       | |
| min_stock_level | INT       | |
| alert_status    | VARCHAR(50) | ACTIVE |
| date_created    | TIMESTAMP | DEFAULT CURRENT_TIMESTAMP |

### 9. Feature_Based_Match

| Column                 | Type         | Constraints |
|------------------------|--------------|-------------|
| fbm_id                 | INT (PK)     | AUTO_INCREMENT |
| product_id             | INT (FK)     | REFERENCES Products(product_id) ON DELETE CASCADE |
| alternative_product_id | INT (FK)     | REFERENCES Products(product_id) ON DELETE CASCADE |
| similarity_score       | DECIMAL(5,2) | |
| matched_attribute      | VARCHAR(100) | |

## Relationship Summary

```
Role ──(1:N)──> User
Category ──(1:N)──> Products
Products ──(1:1)──> Inventory
User ──(1:N)──> Sale
Sale ──(1:N)──> Sale_Item
Products ──(1:N)──> Sale_Item
Products ──(1:N)──> Reorder_Alert
Products ──(self-ref)──> Feature_Based_Match  (product recommendation pairs)
```
