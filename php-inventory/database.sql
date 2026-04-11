CREATE DATABASE IF NOT EXISTS inventory_pos_db;
USE inventory_pos_db;

-- 1. Role
CREATE TABLE IF NOT EXISTS Role (
    role_id INT AUTO_INCREMENT PRIMARY KEY,
    role_type VARCHAR(50) NOT NULL
);

-- 2. User
CREATE TABLE IF NOT EXISTS User (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(100),
    role_id INT,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    display_name VARCHAR(100) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES Role(role_id) ON DELETE SET NULL
);

-- 3. Category
CREATE TABLE IF NOT EXISTS Category (
    category_id INT AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(100) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 4. Products
CREATE TABLE IF NOT EXISTS Products (
    product_id INT AUTO_INCREMENT PRIMARY KEY,
    product_name VARCHAR(150) NOT NULL,
    brand VARCHAR(100),
    description TEXT,
    price DECIMAL(10, 2) NOT NULL,
    retail_price DECIMAL(10, 2) DEFAULT NULL,
    acquisition_cost DECIMAL(10, 2) DEFAULT NULL,
    manufacturing_date DATE DEFAULT NULL,
    expiration_date DATE DEFAULT NULL,
    category_id INT,
    product_type VARCHAR(50),
    specification TEXT,
    compatibility TEXT,
    FOREIGN KEY (category_id) REFERENCES Category(category_id) ON DELETE SET NULL
);

-- 5. Inventory
CREATE TABLE IF NOT EXISTS Inventory (
    inventory_id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT,
    current_stock INT DEFAULT 0,
    min_stock_level INT DEFAULT 0,
    expiry_date DATE DEFAULT NULL,
    date_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES Products(product_id) ON DELETE CASCADE
);

-- 6. Sale
CREATE TABLE IF NOT EXISTS Sale (
    sale_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    total_amount DECIMAL(10, 2) NOT NULL,
    payment_method VARCHAR(50),
    status VARCHAR(50),
    cancel_reason VARCHAR(255) DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES User(user_id) ON DELETE SET NULL
);

-- 7. Sale_Item
CREATE TABLE IF NOT EXISTS Sale_Item (
    sale_item_id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT,
    product_id INT,
    quantity INT NOT NULL,
    selling_price DECIMAL(10, 2) NOT NULL,
    subtotal DECIMAL(10, 2) NOT NULL,
    FOREIGN KEY (sale_id) REFERENCES Sale(sale_id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES Products(product_id) ON DELETE SET NULL
);

-- 8. Reorder_Alert
CREATE TABLE IF NOT EXISTS Reorder_Alert (
    reorder_id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT,
    current_stock INT,
    min_stock_level INT,
    alert_status VARCHAR(50),
    date_created TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES Products(product_id) ON DELETE CASCADE
);

-- 9. Feature_Based_Match
CREATE TABLE IF NOT EXISTS Feature_Based_Match (
    fbm_id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT,
    alternative_product_id INT,
    similarity_score DECIMAL(5, 2),
    matched_attribute VARCHAR(100),
    FOREIGN KEY (product_id) REFERENCES Products(product_id) ON DELETE CASCADE,
    FOREIGN KEY (alternative_product_id) REFERENCES Products(product_id) ON DELETE CASCADE
);

-- 10. Stock_Batch (FIFO batch tracking)
CREATE TABLE IF NOT EXISTS Stock_Batch (
    batch_id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    batch_number VARCHAR(50) NOT NULL,
    acquisition_cost DECIMAL(10, 2) DEFAULT NULL,
    manufacturing_date DATE DEFAULT NULL,
    expiration_date DATE DEFAULT NULL,
    quantity_received INT NOT NULL DEFAULT 0,
    quantity_remaining INT NOT NULL DEFAULT 0,
    date_received TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_depleted TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('ACTIVE','EXPIRED','DISPOSED') NOT NULL DEFAULT 'ACTIVE',
    FOREIGN KEY (product_id) REFERENCES Products(product_id) ON DELETE CASCADE,
    INDEX idx_fifo_lookup (product_id, is_depleted, date_received)
);

-- 11. Sale_Item_Batch (tracks which batches were used per sale item)
CREATE TABLE IF NOT EXISTS Sale_Item_Batch (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_item_id INT NOT NULL,
    batch_id INT NOT NULL,
    quantity_from_batch INT NOT NULL,
    FOREIGN KEY (sale_item_id) REFERENCES Sale_Item(sale_item_id) ON DELETE CASCADE,
    FOREIGN KEY (batch_id) REFERENCES Stock_Batch(batch_id) ON DELETE CASCADE
);

-- =============================================
-- MIGRATION SECTION (for existing databases ONLY)
-- Skip this section if importing fresh.
-- If you get "Duplicate column" errors, that is OK — it means the column already exists.
-- Run each ALTER statement one at a time in phpMyAdmin if needed.
-- =============================================

-- ALTER TABLE User ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1;
-- ALTER TABLE User ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP;
-- ALTER TABLE User ADD COLUMN display_name VARCHAR(100) DEFAULT NULL;
-- ALTER TABLE Category ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1;
-- ALTER TABLE Sale ADD COLUMN cancel_reason VARCHAR(255) DEFAULT NULL;
-- ALTER TABLE Products ADD COLUMN retail_price DECIMAL(10, 2) DEFAULT NULL;
-- ALTER TABLE Products ADD COLUMN acquisition_cost DECIMAL(10, 2) DEFAULT NULL;
-- ALTER TABLE Products ADD COLUMN manufacturing_date DATE DEFAULT NULL;
-- ALTER TABLE Products ADD COLUMN expiration_date DATE DEFAULT NULL;
-- ALTER TABLE Stock_Batch ADD COLUMN status ENUM('ACTIVE','EXPIRED','DISPOSED') NOT NULL DEFAULT 'ACTIVE';
-- UPDATE Products SET retail_price = price WHERE retail_price IS NULL;

-- Insert Default Roles
INSERT IGNORE INTO Role (role_id, role_type) VALUES 
(1, 'admin'), 
(2, 'cashier'), 
(3, 'staff');

-- Insert Default Admin User (password: admin123)
INSERT INTO User (user_id, full_name, username, password, email, role_id, is_active) VALUES
(1, 'System Administrator', 'admin', '$2y$10$.BEPHTxulTT8ynhKMbTb6uRYtuNTqNMLNfZMTsOTnczsfNe/Uc4mS', 'admin@example.com', 1, 1)
ON DUPLICATE KEY UPDATE password = VALUES(password), is_active = VALUES(is_active);

-- Insert Default Cashier User (password: cashier123)
INSERT INTO User (user_id, full_name, username, password, email, role_id, is_active) VALUES
(2, 'Main Cashier', 'cashier', '$2y$10$hmy1oHzHl40XpRLFZ4oBs.sQRhqmrlVDxn4TcBONzyiODNN4q8Caq', 'cashier@example.com', 2, 1)
ON DUPLICATE KEY UPDATE password = VALUES(password), is_active = VALUES(is_active);

-- Insert Default Staff User (password: staff123)
INSERT INTO User (user_id, full_name, username, password, email, role_id, is_active) VALUES
(3, 'Inventory Staff', 'staff', '$2y$10$CPlPN1JU7SK2lX3T.rwE9eVTlkkl.l1ft.SiTTC335fdzyH9qr4au', 'staff@example.com', 3, 1)
ON DUPLICATE KEY UPDATE password = VALUES(password), is_active = VALUES(is_active);