<?php
declare(strict_types=1);
require_once __DIR__.'/includes/coming-soon.php';
tg_coming_soon([
    'title' => 'Profile Settings',
    'lead' => 'Manage your account details and custom brand QR avatar.',
    'done' => ['Name and email on file', 'Merchant ID shown in the sidebar', 'Password change through registration flow'],
    'next' => ['General / Branding / Security / Notifications tabs', 'Avatar upload embedded in generated QR codes', 'Email change with OTP verification'],
]);
