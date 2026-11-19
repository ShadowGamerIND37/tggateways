<?php
declare(strict_types=1);
require_once __DIR__.'/includes/coming-soon.php';
tg_coming_soon([
    'title' => 'My Services',
    'lead' => 'Active gateway integrations, addons and recurring passes.',
    'done' => ['UPI gateway with unlimited transactions', 'Multi-account failover (up to 5 wallets)'],
    'next' => ['Service list with validity and status', 'Addon subscriptions and renewals', 'Per-service usage counters'],
]);
