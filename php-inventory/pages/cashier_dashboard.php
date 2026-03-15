<?php
declare(strict_types=1);

require_once '../includes/app.php';
require_once '../includes/config.php';
require_once '../includes/domain.php';

require_login([APP_ROLE_CASHIER]);

$cashierId = (int) current_user_id();
$transactionPage = max(1, (int) ($_GET['page'] ?? 1));
$perPage = max(5, min(50, (int) ($_GET['per_page'] ?? 10)));
$offset = ($transactionPage - 1) * $perPage;

$summaryStatement = $pdo->prepare(
    "SELECT
        COALESCE(SUM(CASE WHEN DATE(`date`) = CURDATE() AND status = 'COMPLETED' THEN total_amount ELSE 0 END), 0) AS daily_sales,
        COALESCE(SUM(CASE WHEN DATE(`date`) >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND status = 'COMPLETED' THEN total_amount ELSE 0 END), 0) AS weekly_sales
     FROM Sale
     WHERE user_id = :user_id"
);
$summaryStatement->execute(['user_id' => $cashierId]);
$summary = $summaryStatement->fetch(PDO::FETCH_ASSOC) ?: ['daily_sales' => 0, 'weekly_sales' => 0];

$countStatement = $pdo->prepare('SELECT COUNT(*) FROM Sale WHERE user_id = :user_id AND DATE(`date`) = CURDATE()');
$countStatement->execute(['user_id' => $cashierId]);
$totalTransactions = (int) $countStatement->fetchColumn();
$totalPages = max(1, (int) ceil($totalTransactions / $perPage));

if ($transactionPage > $totalPages) {
    $transactionPage = $totalPages;
    $offset = ($transactionPage - 1) * $perPage;
}

$transactionsStatement = $pdo->prepare(
    "SELECT sale_id, payment_method, total_amount, status, `date`
     FROM Sale
     WHERE user_id = :user_id AND DATE(`date`) = CURDATE()
     ORDER BY `date` DESC, sale_id DESC
     LIMIT :limit OFFSET :offset"
);
$transactionsStatement->bindValue(':user_id', $cashierId, PDO::PARAM_INT);
$transactionsStatement->bindValue(':limit', $perPage, PDO::PARAM_INT);
$transactionsStatement->bindValue(':offset', $offset, PDO::PARAM_INT);
$transactionsStatement->execute();
$transactions = $transactionsStatement->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'DASHBOARD';
include '../includes/header.php';
?>

<h2 class="mb-8 text-2xl font-normal text-black">WELCOME TO FIVE BROTHERS TRADING</h2>

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

<div>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-4">
        <h3 class="text-base font-bold">TODAY'S TRANSACTION</h3>

        <form method="GET" class="flex items-center gap-2 text-sm">
            <span>Show</span>
            <select name="per_page" onchange="this.form.submit()" class="rounded border border-black px-3 py-2 text-sm">
                <?php foreach ([10, 25, 50] as $option): ?>
                    <option value="<?php echo h((string) $option); ?>" <?php echo $perPage === $option ? 'selected' : ''; ?>><?php echo h((string) $option); ?></option>
                <?php endforeach; ?>
            </select>
            <span>entries</span>
        </form>
    </div>

    <div class="overflow-hidden rounded-lg border border-black">
        <div class="border-b border-black bg-white">
            <div class="grid grid-cols-4 gap-4 p-4 text-sm font-semibold">
                <div>ORDER ID</div>
                <div>PAYMENT METHOD</div>
                <div>AMOUNT</div>
                <div>STATUS</div>
            </div>
        </div>

        <?php if ($transactions === []): ?>
            <div class="p-4 text-center text-sm text-gray-500">No transactions recorded today.</div>
        <?php else: ?>
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
        <?php endif; ?>
    </div>

    <div class="mt-6 flex justify-end gap-3">
        <?php if ($transactionPage > 1): ?>
            <a href="?page=<?php echo h((string) ($transactionPage - 1)); ?>&per_page=<?php echo h((string) $perPage); ?>" class="rounded-lg border border-black px-6 py-2 text-black hover:bg-gray-50">Previous</a>
        <?php endif; ?>
        <?php if ($transactionPage < $totalPages): ?>
            <a href="?page=<?php echo h((string) ($transactionPage + 1)); ?>&per_page=<?php echo h((string) $perPage); ?>" class="rounded-lg border border-black px-6 py-2 text-black hover:bg-gray-50">Next</a>
        <?php endif; ?>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
