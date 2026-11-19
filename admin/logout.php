<?php
declare(strict_types=1);
/** Revokes the server-side admin session row, then clears the cookie. */
require_once __DIR__.'/../includes/admin.php';

tg_admin_logout();
redirect_to('/admin/login.php');
