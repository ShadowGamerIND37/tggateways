<?php
declare(strict_types=1);
/**
 * Worker: expire stale orders, prune rate-limit rows, parse receipt mail, retry
 * webhooks. Run every 5-15 seconds from cron/supervisor or `docker compose up`
 * (the bundled worker service loops this script).
 *
 * CLI only. Never expose this file through the web server.
 */
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/settlement.php';
require_once __DIR__.'/../includes/webhook.php';
require_once __DIR__.'/../includes/imap.php';

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$started = microtime(true);
$pdo = db();

$result = ['time' => gmdate('c'), 'housekeeping' => null, 'imap' => null, 'webhooks_retried' => 0];

try {
    $result['housekeeping'] = housekeeping($pdo);
} catch (Throwable $e) {
    error_log('Worker housekeeping failed: '.$e->getMessage());
}

$result['imap'] = imap_ingest_all($pdo);

try {
    $result['webhooks_retried'] = retry_webhooks($pdo, (int)envv('WEBHOOK_RETRY_BATCH', '25'));
} catch (Throwable $e) {
    error_log('Webhook retry batch failed: '.$e->getMessage());
}

$pdo->prepare('INSERT INTO system_state(state_key,state_value,updated_at) VALUES(?,?,?)'.(
    db_driver() === 'sqlite'
        ? ' ON CONFLICT(state_key) DO UPDATE SET state_value=excluded.state_value, updated_at=excluded.updated_at'
        : ' ON DUPLICATE KEY UPDATE state_value=VALUES(state_value), updated_at=VALUES(updated_at)'
))->execute(['worker_last_run', (string)json_encode($result), db_now()]);

$result['duration_ms'] = (int)round((microtime(true) - $started) * 1000);
echo json_encode($result, JSON_UNESCAPED_SLASHES).PHP_EOL;

if (isset($result['imap']['error'])) exit(2);
