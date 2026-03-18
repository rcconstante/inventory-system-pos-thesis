<?php
$file = 'C:\xampp\htdocs\inventory\php-inventory\pages\cashier_dashboard.php';
$content = file_get_contents($file);

// Replace widgets
$old_widgets = <<<'EOD'
<div class="mb-8 grid grid-cols-1 gap-6 md:grid-cols-2">
    <div class="flex items-center gap-6 rounded-lg bg-gradient-to-r from-[#0AC074] to-[#00D4B4] p-8">
        <div class="w-fit rounded bg-white bg-opacity-30 p-4">
            <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-white"><circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/></svg>
        </div>
        <div class="text-white">
            <p class="text-lg font-normal">MY DAILY SALES</p>
            <p class="text-3xl font-bold">P<?php echo h(money_format_php((float) $summary['daily_sales'])); ?></p>
        </div>
    </div>

    <div class="flex items-center gap-6 rounded-lg bg-gradient-to-r from-[#0065FF] to-[#4B9FFF] p-8">
        <div class="w-fit rounded bg-white bg-opacity-30 p-4">
            <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-white"><circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/></svg>
        </div>
        <div class="text-white">
            <p class="text-lg font-normal">MY WEEKLY SALES</p>
            <p class="text-3xl font-bold">P<?php echo h(money_format_php((float) $summary['weekly_sales'])); ?></p>
        </div>
    </div>
</div>
EOD;

$new_widgets = <<<'EOD'
<div class="mb-8 grid grid-cols-1 gap-6 md:grid-cols-2">
    <div class="flex flex-col justify-center rounded-lg bg-gradient-to-r from-[#0AC074] to-[#00D4B4] p-8 text-white h-32">
        <p class="text-lg font-normal mb-1">Daily Transaction (Today)</p>
        <p class="text-4xl font-bold"><?php echo h((string) $totalTransactions); ?></p>
    </div>

    <div class="flex flex-col justify-center rounded-lg bg-gradient-to-r from-[#0065FF] to-[#4B9FFF] p-8 text-white h-32">
        <p class="text-lg font-normal mb-1">Daily Sales (Today)</p>
        <p class="text-4xl font-bold">P<?php echo h(money_format_php((float) $summary['daily_sales'])); ?></p>
    </div>
</div>
EOD;

$content = str_replace($old_widgets, $new_widgets, $content);
file_put_contents($file, $content);
echo "OK\n";
