<?php
declare(strict_types=1);
require_once __DIR__.'/includes/coming-soon.php';
tg_coming_soon([
    'title' => 'Settings',
    'lead' => 'Account preferences, payment defaults and notification routing.',
    'done' => ['Brand colour and payment timeout', 'Webhook and redirect URL defaults', 'Notification email routing'],
    'next' => ['General tab (timezone, currency format)', 'Security tab (sessions, 2FA enrolment)', 'Notification preferences per event'],
]);
