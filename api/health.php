<?php
declare(strict_types=1);
/**
 * Public deployment health for the landing page, monitoring probes and the
 * container healthcheck. Exposes component states only — no merchant data.
 */
require_once __DIR__.'/../includes/db.php';
require_method('GET');
rate_limit('health', 120, 60);

$result = [
    'api_gateway' => 'operational',
    'database' => 'operational',
    'receipt_parser' => 'configuration_required',
    'configuration' => 'ok',
    'worker_last_run' => null,
    'worker_healthy' => false,
    'checked_at' => gmdate('c'),
];

$configProblems = tg_config_problems();
if ($configProblems) {
    $result['configuration'] = 'needs_attention';
    // Messages are static operator guidance, not secrets.
    $result['configuration_problems'] = $configProblems;
}

try {
    $pdo = db();
    $pdo->query('SELECT 1');

    $state = $pdo->prepare('SELECT state_value FROM system_state WHERE state_key=?');
    $state->execute(['worker_last_run']);
    $lastRun = $state->fetchColumn();
    if (is_string($lastRun) && $lastRun !== '') {
        $decoded = json_decode($lastRun, true);
        $result['worker_last_run'] = is_array($decoded) ? ($decoded['time'] ?? null) : $lastRun;
        $timestamp = is_array($decoded) && isset($decoded['time']) ? strtotime((string)$decoded['time']) : false;
        $result['worker_healthy'] = $timestamp !== false && $timestamp >= time() - 120;
    }

    $accounts = (int)$pdo->query('SELECT COUNT(*) FROM merchant_accounts WHERE is_active=1')->fetchColumn();
    if (!function_exists('imap_open')) {
        $result['receipt_parser'] = 'imap_extension_missing';
    } elseif ($accounts === 0) {
        $result['receipt_parser'] = 'no_active_account';
    } elseif ($result['worker_healthy']) {
        $result['receipt_parser'] = 'operational';
    } else {
        $result['receipt_parser'] = 'worker_stale';
    }
} catch (Throwable $e) {
    // Log details; never leak connection strings or credentials.
    error_log('Health check failed: '.$e->getMessage());
    $result['api_gateway'] = 'degraded';
    $result['database'] = 'unavailable';
    json_response($result, 503);
}

json_response($result);
