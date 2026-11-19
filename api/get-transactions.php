<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/db.php';
require_method('GET');
$m = merchant_by_api_key();
rate_limit('transactions:'.$m['id'], 60, 60);

$page = max(1, min(10000, (int)($_GET['page'] ?? 1)));
$limit = max(1, min(100, (int)($_GET['limit'] ?? 25)));
$status = (string)($_GET['status'] ?? '');
$search = substr(trim((string)($_GET['search'] ?? '')), 0, 80);

$where = 'merchant_id=?';
$params = [$m['id']];
if (in_array($status, ['pending','success','expired'], true)) { $where .= ' AND status=?'; $params[] = $status; }
if ($search !== '') {
    $where .= ' AND (order_id LIKE ? OR utr LIKE ?)';
    $params[] = '%'.$search.'%';
    $params[] = '%'.$search.'%';
}
$countStmt = db()->prepare('SELECT COUNT(*) FROM orders WHERE '.$where);
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$q = db()->prepare('SELECT order_id,amount,payable_amount,status,utr,created_at,paid_at FROM orders WHERE '.$where.' ORDER BY created_at DESC LIMIT '.$limit.' OFFSET '.(($page-1)*$limit));
$q->execute($params);
json_response(['page'=>$page,'limit'=>$limit,'total'=>$total,'pages'=>(int)ceil($total/$limit),'transactions'=>$q->fetchAll()]);
