<?php
declare(strict_types=1);
/**
 * Container healthcheck for the worker service.
 *
 * Exits 0 when the last worker pass finished recently, 1 otherwise. This is the
 * real proof that receipt polling is running: an extension check only proves a
 * binary is loaded, not that a mailbox is being read. Mirrors the
 * `worker_healthy` field in /api/health.php.
 *
 * Usage: php cron/healthcheck.php [max_age_seconds]
 */
require_once __DIR__.'/../includes/db.php';

/**
 * Evaluate worker freshness.
 *
 * @return array{ok:bool,message:string}
 */
function worker_freshness(PDO $pdo, int $maxAge = 120): array
{
    $statement = $pdo->prepare('SELECT state_value FROM system_state WHERE state_key=?');
    $statement->execute(['worker_last_run']);
    $value = $statement->fetchColumn();
    $decoded = is_string($value) ? json_decode($value, true) : null;
    $time = is_array($decoded) && isset($decoded['time']) ? strtotime((string)$decoded['time']) : false;

    if ($time === false) {
        return ['ok' => false, 'message' => 'worker has not completed a pass yet'];
    }
    $age = time() - $time;
    if ($age > $maxAge) {
        return ['ok' => false, 'message' => "worker pass is stale ({$age}s old)"];
    }
    return ['ok' => true, 'message' => "worker pass {$age}s ago"];
}

if (PHP_SAPI === 'cli' && realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)) {
    try {
        $result = worker_freshness(db(), (int)($argv[1] ?? 120));
        fwrite($result['ok'] ? STDOUT : STDERR, $result['message']."\n");
        exit($result['ok'] ? 0 : 1);
    } catch (Throwable $e) {
        fwrite(STDERR, 'healthcheck failed: '.$e->getMessage()."\n");
        exit(1);
    }
}
