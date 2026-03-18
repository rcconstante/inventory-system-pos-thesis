<?php
$file = 'C:\xampp\htdocs\inventory\php-inventory\pages\cashier_dashboard.php';
$content = file_get_contents($file);

$old_grid = <<<'EOD'
            <?php foreach ($transactions as $transaction): ?>
                <div class="grid grid-cols-4 gap-4 border-b border-black p-4 text-sm last:border-b-0">
                    <div>#<?php echo h((string) $transaction['sale_id']); ?></div>
                    <div><?php echo h((string) $transaction['payment_method']); ?></div>
                    <div>P<?php echo h(money_format_php((float) $transaction['total_amount'])); ?></div>
                    <div class="font-medium <?php echo strtoupper((string) $transaction['status']) === 'COMPLETED' ? 'text-green-700' : 'text-yellow-700'; ?>">
                        <?php echo h((string) $transaction['status']); ?>
                    </div>
                </div>
            <?php endforeach; ?>
EOD;

$new_grid = <<<'EOD'
            <?php foreach ($transactions as $transaction): 
                $saleId = $transaction['sale_id'];
                $items = $transactionItems[$saleId] ?? [];
                $jsonSale = json_encode([
                    'id' => $saleId,
                    'date' => date('F d, Y', strtotime($transaction['date'])),
                    'method' => $transaction['payment_method'],
                    'total' => $transaction['total_amount'],
                    'status' => strtoupper($transaction['status']),
                    'items' => $items
                ]);
            ?>
                <div onclick="openReceiptModal(<?php echo h(htmlspecialchars($jsonSale, ENT_QUOTES, 'UTF-8')); ?>)" class="grid grid-cols-4 gap-4 border-b border-black p-4 text-sm last:border-b-0 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors">
                    <div>#<?php echo h((string) $transaction['sale_id']); ?></div>
                    <div><?php echo h((string) $transaction['payment_method']); ?></div>
                    <div>P<?php echo h(money_format_php((float) $transaction['total_amount'])); ?></div>
                    <div class="font-medium <?php echo strtoupper((string) $transaction['status']) === 'COMPLETED' ? 'text-green-700' : (strtoupper((string) $transaction['status']) === 'RETURNED' ? 'text-red-600' : 'text-yellow-700'); ?>">
                        <?php echo h((string) $transaction['status']); ?>
                    </div>
                </div>
            <?php endendforeach; ?>
EOD;

// Oh wait, `endendforeach` is a typo. Let me fix that.
$new_grid = str_replace('endendforeach', 'endforeach', $new_grid);

$content = str_replace($old_grid, $new_grid, $content);
file_put_contents($file, $content);
echo "OK\n";
