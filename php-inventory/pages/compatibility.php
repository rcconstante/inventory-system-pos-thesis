<?php
declare(strict_types=1);
require_once '../includes/app.php';
require_login([APP_ROLE_ADMIN, APP_ROLE_STAFF]);
redirect_to('pages/product_options.php');
