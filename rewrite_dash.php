<?php
$file = 'C:\xampp\htdocs\inventory\php-inventory\pages\cashier_dashboard.php';
$content = file_get_contents($file);

// Add PHP POST block at the top
$post_logic = <<<'EOD'
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['return_sale'])) {
        try {
            validate_csrf_or_fail('pages/cashier_dashboard.php');
            
            // Example of role check (if we wanted to restrict returns)
            // if (current_role_id() !== APP_ROLE_ADMIN) {
            //     throw new RuntimeException("Only Admins can process returns.");
            // }

            $saleId = (int)($_POST['sale_id'] ?? 0);
            $reason = trim((string)($_POST['return_reason'] ?? ''));

            if ($saleId <= 0) {
                throw new RuntimeException("Invalid sale ID.");
            }
            if ($reason === '') {
                throw new RuntimeException("Return reason is required.");
            }

            $pdo->beginTransaction();
            
            // Check if it's already returned
            $checkSale = $pdo->prepare("SELECT status FROM Sale WHERE sale_id = ? FOR UPDATE");
            $checkSale->execute([$saleId]);
            $saleStatus = $checkSale->fetchColumn();
            
            if (!$saleStatus) {
                throw new RuntimeException("Sale not found.");
            }
            if (strtoupper($saleStatus) === 'RETURNED') {
                throw new RuntimeException("This transaction has already been returned.");
            }
            
            // Mark sale as returned
            $updateSale = $pdo->prepare("UPDATE Sale SET status = 'RETURNED' WHERE sale_id = ?");
            $updateSale->execute([$saleId]);
            
            // Add items back to inventory
            $itemsStmt = $pdo->prepare("SELECT product_id, quantity FROM Sale_Item WHERE sale_id = ?");
            $itemsStmt->execute([$saleId]);
            $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $updateInv = $pdo->prepare("UPDATE Inventory SET current_stock = current_stock + ? WHERE product_id = ?");
            foreach ($items as $item) {
                $updateInv->execute([$item['quantity'], $item['product_id']]);
            }
            
            // If we had a returns table we would log the reason here.
            
            $pdo->commit();
            set_flash('success', 'Transaction #' . $saleId . ' successfully returned.');
            
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            set_flash('error', $e->getMessage());
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            set_flash('error', 'An error occurred processing the return.');
        }
        
        redirect_to('pages/cashier_dashboard.php');
    }
}
EOD;

$content = str_replace("require_login([APP_ROLE_CASHIER]);\n", "require_login([APP_ROLE_CASHIER]);\n\n" . $post_logic . "\n", $content);

file_put_contents($file, $content);
echo "OK\n";
