<?php
$file = 'C:\xampp\htdocs\inventory\php-inventory\pages\cashier_dashboard.php';
$content = file_get_contents($file);

$modals = <<<'EOD'

<!-- Receipt Modal -->
<div id="receiptModal" class="hidden fixed inset-0 z-[110] items-center justify-center bg-black/50 p-4 backdrop-blur-sm">
    <div class="w-[500px] bg-white dark:bg-gray-800 text-black dark:text-white border border-black dark:border-gray-600 shadow-2xl flex flex-col relative">
        <button type="button" onclick="closeReceiptModal()" class="absolute top-4 right-4 text-black dark:text-white hover:text-gray-600">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        
        <div class="p-8 text-center border-b border-black dark:border-gray-600">
            <h2 class="text-xl font-medium tracking-widest uppercase mb-2">FIVE BROTHERS TRADING</h2>
            <p class="text-sm mb-1 text-gray-800 dark:text-gray-300">0961-195-6139</p>
            <p class="text-sm mb-1 text-gray-800 dark:text-gray-300">Mabuhay Carmona, Cavite</p>
            <p class="text-sm mb-1 text-gray-800 dark:text-gray-300">Opening Hours: 9:00 AM - 3:00 PM</p>
            <p class="text-sm mt-3 font-medium" id="rm-date">Date: </p>
        </div>
        
        <div class="p-8 flex-1 overflow-auto max-h-[40vh]">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left font-bold border-b border-black dark:border-gray-600">
                        <th class="pb-3 w-16">QTY</th>
                        <th class="pb-3">DESCRIPTION</th>
                        <th class="pb-3 text-right">AMOUNT</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700" id="rm-items">
                    <!-- Items injected here -->
                </tbody>
            </table>
        </div>
        
        <div class="p-8 border-t border-black dark:border-gray-600 bg-white dark:bg-gray-800">
            <div class="text-sm font-bold mb-3 uppercase">TOTAL: <span id="rm-total"></span></div>
            <div class="text-sm font-bold mb-8 uppercase">PAID BY: <span id="rm-method"></span></div>
            
            <div class="flex justify-between items-center">
                <div class="text-sm font-medium">Thank You :)</div>
                <button type="button" id="rm-return-btn" onclick="openReturnModal()" class="border border-black dark:border-gray-400 px-6 py-2 text-sm font-bold hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors uppercase tracking-widest">RETURN</button>
            </div>
        </div>
    </div>
</div>

<!-- Return Modal -->
<div id="returnModal" class="hidden fixed inset-0 z-[120] items-center justify-center bg-black/60 p-4 backdrop-blur-md">
    <div class="w-[450px] bg-white dark:bg-gray-800 text-black dark:text-white border border-black dark:border-gray-600 shadow-2xl p-8">
        <h3 class="text-lg font-bold uppercase mb-6 tracking-wide border-b border-black dark:border-gray-600 pb-4">Process Return</h3>
        <p class="mb-4 text-sm font-medium">Transaction: <span id="ret-sale-id-display" class="font-bold"></span></p>
        
        <form method="POST" action="">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="return_sale" value="1">
            <input type="hidden" name="sale_id" id="ret-sale-id" value="">
            
            <div class="mb-8">
                <label class="block text-sm font-bold mb-2 uppercase tracking-wide">Reason for Return</label>
                <select name="return_reason" required class="w-full border border-black dark:border-gray-500 bg-transparent px-4 py-3 focus:outline-none">
                    <option value="" class="text-black">Select a reason...</option>
                    <option value="Wrong Item Received" class="text-black">Wrong Item Received</option>
                    <option value="Damaged Item" class="text-black">Damaged Item</option>
                    <option value="Expired Product" class="text-black">Expired Product</option>
                    <option value="Customer Changed Mind" class="text-black">Customer Changed Mind</option>
                    <option value="Other" class="text-black">Other</option>
                </select>
            </div>
            
            <div class="flex justify-end gap-4">
                <button type="button" onclick="closeReturnModal()" class="border border-black dark:border-gray-500 px-6 py-2 text-sm font-bold uppercase hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors tracking-wide">CANCEL</button>
                <button type="submit" class="bg-red-600 text-white px-6 py-2 text-sm font-bold uppercase hover:bg-red-700 transition-colors tracking-wide">CONFIRM RETURN</button>
            </div>
        </form>
    </div>
</div>

<script>
    let currentSaleId = null;

    function openReceiptModal(saleData) {
        currentSaleId = saleData.id;
        document.getElementById('rm-date').textContent = 'Date: ' + saleData.date;
        document.getElementById('rm-total').textContent = parseFloat(saleData.total).toFixed(2);
        document.getElementById('rm-method').textContent = saleData.method;
        
        const tbody = document.getElementById('rm-items');
        tbody.innerHTML = '';
        saleData.items.forEach(item => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td class="py-4 font-medium">${item.quantity}</td>
                <td class="py-4 font-medium">${item.product_name}</td>
                <td class="py-4 text-right font-medium">${parseFloat(item.subtotal).toFixed(2)}</td>
            `;
            tbody.appendChild(tr);
        });
        
        // Hide return button if already returned
        const returnBtn = document.getElementById('rm-return-btn');
        if (saleData.status === 'RETURNED') {
            returnBtn.style.display = 'none';
        } else {
            returnBtn.style.display = 'block';
        }

        document.getElementById('receiptModal').classList.remove('hidden');
        document.getElementById('receiptModal').classList.add('flex');
    }

    function closeReceiptModal() {
        document.getElementById('receiptModal').classList.add('hidden');
        document.getElementById('receiptModal').classList.remove('flex');
    }

    function openReturnModal() {
        closeReceiptModal();
        document.getElementById('ret-sale-id').value = currentSaleId;
        document.getElementById('ret-sale-id-display').textContent = '#' + currentSaleId;
        document.getElementById('returnModal').classList.remove('hidden');
        document.getElementById('returnModal').classList.add('flex');
    }

    function closeReturnModal() {
        document.getElementById('returnModal').classList.add('hidden');
        document.getElementById('returnModal').classList.remove('flex');
    }
</script>
EOD;

$content = str_replace("<?php include '../includes/footer.php'; ?>", $modals . "\n<?php include '../includes/footer.php'; ?>", $content);

file_put_contents($file, $content);
echo "OK\n";
