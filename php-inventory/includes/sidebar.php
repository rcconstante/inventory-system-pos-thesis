<?php
$currentPage = basename($_SERVER['PHP_SELF'] ?? '');
$currentRoleId = current_role_id();

$iconDashboard = '<svg xmlns="http://www.w3.org/2000/svg" width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>';
$iconCategory  = '<svg xmlns="http://www.w3.org/2000/svg" width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2H2v10l9.29 9.29c.94.94 2.48.94 3.42 0l6.58-6.58c.94-.94.94-2.48 0-3.42L12 2Z"/><path d="M7 7h.01"/></svg>';
$iconProducts  = '<svg xmlns="http://www.w3.org/2000/svg" width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7.5 4.27 9 5.15"/><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg>';
$iconUsers     = '<svg xmlns="http://www.w3.org/2000/svg" width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>';
$iconRecords   = '<svg xmlns="http://www.w3.org/2000/svg" width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><line x1="10" y1="9" x2="8" y2="9"/></svg>';
$iconPos       = '<svg xmlns="http://www.w3.org/2000/svg" width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/></svg>';

$menuItems = match ($currentRoleId) {
    APP_ROLE_ADMIN => [
        ['label' => 'DASHBOARD', 'href' => 'pages/admin_dashboard.php', 'icon' => $iconDashboard],
        ['label' => 'CATEGORY',  'href' => 'pages/category.php',        'icon' => $iconCategory],
        ['label' => 'PRODUCTS',  'href' => 'pages/products.php',        'icon' => $iconProducts],
        ['label' => 'USERS',     'href' => 'pages/users.php',           'icon' => $iconUsers],
        ['label' => 'RECORDS',   'href' => 'pages/records.php',         'icon' => $iconRecords],
    ],
    APP_ROLE_CASHIER => [
        ['label' => 'DASHBOARD', 'href' => 'pages/cashier_dashboard.php', 'icon' => $iconDashboard],
        ['label' => 'CATEGORY',  'href' => 'pages/category.php',          'icon' => $iconCategory],
        ['label' => 'PRODUCTS',  'href' => 'pages/products.php',          'icon' => $iconProducts],
        ['label' => 'POS',       'href' => 'pages/pos.php',               'icon' => $iconPos],
        ['label' => 'RECORDS',   'href' => 'pages/records.php',           'icon' => $iconRecords],
    ],
    APP_ROLE_STAFF => [
        ['label' => 'DASHBOARD', 'href' => 'pages/staff_dashboard.php', 'icon' => $iconDashboard],
        ['label' => 'CATEGORY',  'href' => 'pages/category.php',        'icon' => $iconCategory],
        ['label' => 'PRODUCTS',  'href' => 'pages/products.php',        'icon' => $iconProducts],
        ['label' => 'RECORDS',   'href' => 'pages/records.php',         'icon' => $iconRecords],
    ],
    default => [],
};
?>
<div class="w-[220px] h-screen custom-dark-bg text-white flex flex-col p-4 flex-shrink-0">
    <!-- Logo -->
    <div class="mb-8 flex flex-col items-center">
        <svg width="32" height="32" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path fill-rule="evenodd" clip-rule="evenodd" d="M0 24.5348C0 28.6303 3.32014 31.9505 7.41573 31.9505H32L0 1.79497V24.5348Z" fill="url(#logo_gradient_1)"/>
            <path opacity="0.983161" fill-rule="evenodd" clip-rule="evenodd" d="M0 7.40946C0 3.31387 3.32014 0 7.41573 0H32C32 0 3.73034 25.7671 1.2263 28.2342C0 29.9414 1.43276 31.8648 1.43276 31.8648C1.43276 31.8648 0 31.1225 0 29.5198C0 28.7041 0 15.8841 0 7.40946Z" fill="url(#logo_gradient_2)"/>
            <defs>
                <linearGradient id="logo_gradient_1" x1="7.98937" y1="10.3822" x2="37.8245" y2="18.6459" gradientUnits="userSpaceOnUse">
                    <stop stop-color="#0043FF" />
                    <stop offset="1" stop-color="#A370F1" />
                </linearGradient>
                <linearGradient id="logo_gradient_2" x1="22.0813" y1="-15.8615" x2="-5.37804" y2="2.33241" gradientUnits="userSpaceOnUse">
                    <stop stop-color="#4BF2E6" />
                    <stop offset="1" stop-color="#0065FF" />
                </linearGradient>
            </defs>
        </svg>
        <div class="text-xs font-light mt-2 uppercase tracking-wider text-center">
            Five Brothers<br>Trading
        </div>
    </div>

    <!-- User Profile -->
    <div class="mb-8 border-b border-gray-600 pb-6 flex flex-col items-center">
        <div class="w-12 h-12 bg-[#1F263E] rounded-full mx-auto mb-2 flex items-center justify-center">
            <span class="text-xl font-bold text-white uppercase">
                <?php echo h(isset($_SESSION['full_name']) ? substr($_SESSION['full_name'], 0, 1) : 'U'); ?>
            </span>
        </div>
        <div class="text-center text-sm font-medium uppercase">
            <?php echo isset($_SESSION['full_name']) ? htmlspecialchars($_SESSION['full_name']) : 'USER'; ?>
        </div>
        <div class="text-center text-xs text-gray-300 mt-1 uppercase">
            <?php echo h(role_name((int) ($_SESSION['role_id'] ?? 0))); ?>
        </div>
    </div>

    <!-- Menu Items -->
    <nav class="flex-1 space-y-1">
        <?php foreach ($menuItems as $item): ?>
            <?php $isActive = $currentPage === basename($item['href']); ?>
            <a href="<?php echo h(app_url($item['href'])); ?>" class="flex items-center gap-3 rounded px-3 py-2 text-base font-medium transition-colors <?php echo $isActive ? 'bg-gray-700 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?>">
                <?php echo $item['icon']; ?>
                <span><?php echo h($item['label']); ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <!-- Logout -->
    <div class="border-t border-gray-600 pt-4">
        <a href="<?php echo h(app_url('logout.php')); ?>" class="w-full flex items-center justify-center gap-2 px-4 py-2 text-base font-medium text-gray-300 hover:bg-gray-700 hover:text-white rounded transition-colors">
            <svg xmlns="http://www.w3.org/2000/svg" width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/></svg>
            <span>LOGOUT</span>
        </a>
    </div>
</div>
