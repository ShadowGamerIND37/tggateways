<?php
declare(strict_types=1);
require_once __DIR__.'/includes/coming-soon.php';
tg_coming_soon([
    'title' => 'System Status',
    'lead' => 'Real-time uptime monitoring for the API and receipt-processing services.',
    'done' => ['/api/health.php machine-readable checks', 'Config warnings for insecure URLs'],
    'next' => ['Public status page with 90-day uptime', 'Per-service incident history', 'Subscribe to incident email alerts'],
]);
