<?php
declare(strict_types=1);

require_once 'includes/app.php';

if (is_logged_in()) {
    redirect_to(dashboard_path_for_role((int) current_role_id()));
}

// No more role selection page — redirect directly to login
redirect_to('login.php');
