<?php
declare(strict_types=1);
/**
 * CSV export of the merchant's transactions.
 *
 * Uses the same filters as the transactions page so what you see is what you
 * get. Streams row by row instead of building the file in memory, and guards
 * against CSV injection: a customer name beginning with =, +, - or @ would be
 * executed as a formula when opened in Excel, so those cells are prefixed.
 */
require_once __DIR__.'/includes/merchant.php';
require_once __DIR__.'/includes/auth.php';
$merchant = require_login();
$id = (int)$merchant['id'];
$pdo = db();

$status = strtolower((string)($_GET['status'] ?? 'all'));
if (!in_array($status, ['all', 'pending', 'success', 'expired'], true)) $status = 'all';
$range = strtolower((string)($_GET['range'] ?? 'all'));
if (!in_array($range, ['all', 'today', 'yesterday', '7d', '30d', 'this_month', 'last_month'], true)) $range = 'all';
$search = mb_substr(trim((string)($_GET['search'] ?? '')), 0, 80);

/** Start/end bounds for the range filter (mirrors transactions.php). */
$startOfToday = strtotime('today');
[$from, $to] = match ($range) {
    'today' => [$startOfToday, null],
    'yesterday' => [strtotime('-1 day', $startOfToday), $startOfToday],
    '7d' => [strtotime('-6 days', $startOfToday), null],
    '30d' => [strtotime('-29 days', $startOfToday), null],
    'this_month' => [strtotime('first day of this month'), null],
    'last_month' => [strtotime('first day of last month'), strtotime('first day of this month')],
    default => [null, null],
};

$where = 'merchant_id=?';
$params = [$id];
if ($status !== 'all') { $where .= ' AND status=?'; $params[] = $status; }
if ($from !== null) { $where .= ' AND created_at>=?'; $params[] = date('Y-m-d H:i:s', $from); }
if ($to !== null) { $where .= ' AND created_at<?'; $params[] = date('Y-m-d H:i:s', $to); }
if ($search !== '') {
    $where .= ' AND (order_id LIKE ? OR utr LIKE ? OR customer_name LIKE ? OR customer_phone LIKE ?)';
    array_push($params, '%'.$search.'%', '%'.$search.'%', '%'.$search.'%', '%'.$search.'%');
}

// Hard ceiling: a deployment with a year of traffic should not try to stream
// hundreds of thousands of rows through a PHP request.
$limit = max(1, min(50000, (int)envv('EXPORT_MAX_ROWS', '10000')));

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE $where");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$q = $pdo->prepare("SELECT order_id,utr,status,amount,payable_amount,customer_name,customer_email,customer_phone,source,created_at,paid_at
                      FROM orders WHERE $where ORDER BY created_at DESC, id DESC LIMIT $limit");
$q->execute($params);

/** Neutralise spreadsheet formula injection without mangling normal text. */
function tg_csv_cell(?string $value): string {
    $value = (string)$value;
    if ($value === '') return '';
    if (in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) return "'".$value;
    return $value;
}

$filename = 'transactions-'.($status === 'all' ? 'all' : $status).'-'.date('Y-m-d').'.csv';

tg_header('Content-Type: text/csv; charset=utf-8');
tg_header('Content-Disposition: attachment; filename="'.$filename.'"');
tg_header('Cache-Control: no-store');
tg_header('X-Content-Type-Options: nosniff');
if ($total > $limit) {
    tg_header('X-Export-Truncated: '.$total.' rows matched, '.$limit.' exported');
}

$out = fopen('php://output', 'w');
// BOM so Excel opens UTF-8 (₹, names with diacritics) correctly.
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['Order ID', 'Bank RRN / UTR', 'Status', 'Amount', 'Payable amount',
    'Customer name', 'Customer email', 'Customer phone', 'Source', 'Created at', 'Paid at']);

while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($out, [
        tg_csv_cell((string)$row['order_id']),
        tg_csv_cell((string)($row['utr'] ?? '')),
        ucfirst((string)$row['status']),
        number_format((float)$row['amount'], 2, '.', ''),
        number_format((float)$row['payable_amount'], 2, '.', ''),
        tg_csv_cell((string)($row['customer_name'] ?? '')),
        tg_csv_cell((string)($row['customer_email'] ?? '')),
        tg_csv_cell((string)($row['customer_phone'] ?? '')),
        tg_csv_cell((string)($row['source'] ?? '')),
        (string)$row['created_at'],
        (string)($row['paid_at'] ?? ''),
    ]);
}
fclose($out);
tg_audit($id, 'transactions.exported', $status.'/'.$range.'/'.min($total, $limit).' rows');
