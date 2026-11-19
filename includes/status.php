<?php
declare(strict_types=1);
/**
 * Uptime bookkeeping for the public status page.
 *
 * A status page that only says "operational" proves nothing. Each probe records
 * a daily bucket, so the page can show a real 30-day history instead of a badge
 * that is always green. Writes are one UPSERT per component per day.
 */
require_once __DIR__.'/db.php';

/** Components tracked on the status page, with the label shown to visitors. */
function tg_status_components(): array {
    return [
        'api' => 'API Gateway',
        'database' => 'Database',
        'checkout' => 'Hosted Checkout',
        'webhook' => 'Webhook Delivery',
        'parser' => 'Receipt Parser',
    ];
}

/**
 * Probe every component once and fold the result into today's bucket.
 *
 * @return array<string,array{state:string,detail:string}>
 */
function tg_status_probe(): array {
    $result = [];
    $ok = true;

    // API: reaching this code means the PHP application served a request.
    $result['api'] = ['state' => 'operational', 'detail' => 'Serving requests'];

    try {
        $pdo = db();
        $pdo->query('SELECT 1');
        $result['database'] = ['state' => 'operational', 'detail' => 'Connected'];
    } catch (Throwable $e) {
        $result['database'] = ['state' => 'down', 'detail' => 'No database connection'];
        $ok = false;
        $pdo = null;
    }

    // Checkout depends on the database and on at least one receiving account.
    if (!$pdo) {
        $result['checkout'] = ['state' => 'down', 'detail' => 'Blocked by database'];
    } else {
        try {
            $accounts = (int)$pdo->query('SELECT COUNT(*) FROM merchant_accounts WHERE is_active=1')->fetchColumn();
            $result['checkout'] = $accounts > 0
                ? ['state' => 'operational', 'detail' => 'Ready for payments']
                : ['state' => 'degraded', 'detail' => 'No receiving account configured'];
        } catch (Throwable) {
            $result['checkout'] = ['state' => 'degraded', 'detail' => 'Cannot read accounts'];
        }
    }

    // Webhook delivery: recent failures vs successes in webhook_logs.
    if (!$pdo) {
        $result['webhook'] = ['state' => 'degraded', 'detail' => 'Unknown'];
    } else {
        try {
            $since = date('Y-m-d H:i:s', time() - 86400);
            $q = $pdo->prepare('SELECT COUNT(*) total, COALESCE(SUM(CASE WHEN delivered_at IS NOT NULL THEN 1 ELSE 0 END),0) ok FROM webhook_logs WHERE created_at>=?');
            $q->execute([$since]);
            $row = $q->fetch() ?: ['total' => 0, 'ok' => 0];
            $total = (int)$row['total'];
            $delivered = (int)$row['ok'];
            if ($total === 0) {
                $result['webhook'] = ['state' => 'operational', 'detail' => 'No deliveries in 24h'];
            } else {
                $rate = $delivered / $total;
                $result['webhook'] = [
                    'state' => $rate >= 0.95 ? 'operational' : ($rate >= 0.7 ? 'degraded' : 'down'),
                    'detail' => $delivered.'/'.$total.' delivered in 24h',
                ];
            }
        } catch (Throwable) {
            $result['webhook'] = ['state' => 'operational', 'detail' => 'No delivery history'];
        }
    }

    // Receipt parser: needs a worker that ran recently.
    if (!$pdo) {
        $result['parser'] = ['state' => 'degraded', 'detail' => 'Unknown'];
    } else {
        try {
            $q = $pdo->prepare('SELECT state_value FROM system_state WHERE state_key=?');
            $q->execute(['worker_last_run']);
            $raw = (string)($q->fetchColumn() ?: '');
            $decoded = json_decode($raw, true);
            $time = is_array($decoded) ? ($decoded['time'] ?? null) : ($raw !== '' ? $raw : null);
            $ts = $time ? strtotime((string)$time) : false;
            if ($ts !== false && $ts >= time() - 300) {
                $result['parser'] = ['state' => 'operational', 'detail' => 'Worker ran '.tg_status_ago($ts)];
            } elseif ($ts !== false) {
                $result['parser'] = ['state' => 'degraded', 'detail' => 'Worker last ran '.tg_status_ago($ts)];
            } else {
                $result['parser'] = ['state' => 'degraded', 'detail' => 'Worker has not run yet'];
            }
        } catch (Throwable) {
            $result['parser'] = ['state' => 'degraded', 'detail' => 'Unknown'];
        }
    }

    tg_status_record($pdo, $result, $ok);
    return $result;
}

/** Compact relative time for status details. */
function tg_status_ago(int $timestamp): string {
    $delta = max(0, time() - $timestamp);
    if ($delta < 60) return $delta.'s ago';
    if ($delta < 3600) return floor($delta / 60).'m ago';
    if ($delta < 86400) return floor($delta / 3600).'h ago';
    return floor($delta / 86400).'d ago';
}

/**
 * Fold a probe into today's bucket. Counts roll forward so the percentage is
 * "checks passed / checks run", not a best-of.
 */
function tg_status_record(?PDO $pdo, array $result, bool $allOk): void {
    if (!$pdo || !tg_table_exists('status_days')) return;
    $today = date('Y-m-d');
    try {
        $insert = $pdo->prepare('INSERT INTO status_days(day,component,checks,ok) VALUES(?,?,?,?)');
        $update = $pdo->prepare('UPDATE status_days SET checks=checks+1, ok=ok+? WHERE day=? AND component=?');
        foreach ($result as $component => $info) {
            $passed = ($info['state'] ?? '') === 'operational' ? 1 : 0;
            $update->execute([$passed, $today, $component]);
            if ($update->rowCount() === 0) {
                try {
                    $insert->execute([$today, $component, 1, $passed]);
                } catch (PDOException) {
                    // Concurrent insert: fall back to the update.
                    $update->execute([$passed, $today, $component]);
                }
            }
        }
    } catch (Throwable $e) {
        error_log('status: '.$e->getMessage());
    }
}

/**
 * Daily uptime for the last $days days, oldest first.
 *
 * @return array<string,list<array{day:string,uptime:?float}>>
 */
function tg_status_history(int $days = 30): array {
    $history = [];
    $rows = [];
    if (tg_table_exists('status_days')) {
        try {
            $since = date('Y-m-d', strtotime('-'.($days - 1).' days'));
            $q = db()->prepare('SELECT day, component, checks, ok FROM status_days WHERE day>=?');
            $q->execute([$since]);
            foreach ($q->fetchAll() as $row) $rows[$row['component']][$row['day']] = $row;
        } catch (Throwable) {
            $rows = [];
        }
    }
    foreach (array_keys(tg_status_components()) as $component) {
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-$i days"));
            $row = $rows[$component][$day] ?? null;
            $history[$component][] = [
                'day' => $day,
                'uptime' => $row ? round(100 * ((int)$row['ok'] / max(1, (int)$row['checks'])), 2) : null,
            ];
        }
    }
    return $history;
}

/** Overall uptime percentage across a history slice, ignoring days with no data. */
function tg_status_uptime(array $days): ?float {
    $values = array_values(array_filter(array_column($days, 'uptime'), static fn ($v) => $v !== null));
    if (!$values) return null;
    return round(array_sum($values) / count($values), 2);
}
