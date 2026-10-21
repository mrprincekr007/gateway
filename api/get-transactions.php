<?php
declare(strict_types=1);

/* =========================================================
   GET /api/get-transactions?page=1&limit=20&status=success
   Paginated list of the account's orders.
   ========================================================= */

require_once __DIR__ . '/../includes/api.php';

[$key, $user] = api_auth();

$limit  = (int) api_param('limit', '20');
$limit  = max(1, min(100, $limit));
$page   = max(1, (int) api_param('page', '1'));
$offset = ($page - 1) * $limit;
$status = api_param('status');

$allowed = ['pending', 'success', 'failed', 'expired'];
$where = 'user_id = ?';
$args  = [$user['id']];
if ($status !== '' && in_array($status, $allowed, true)) {
    $where .= ' AND status = ?';
    $args[] = $status;
}

$countStmt = db()->prepare('SELECT COUNT(*) FROM orders WHERE ' . $where);
$countStmt->execute($args);
$total = (int) $countStmt->fetchColumn();

$listStmt = db()->prepare(
    'SELECT * FROM orders WHERE ' . $where . ' ORDER BY created_at DESC LIMIT ' . $limit . ' OFFSET ' . $offset
);
$listStmt->execute($args);
$rows = $listStmt->fetchAll();

$transactions = [];
foreach ($rows as $o) {
    $transactions[] = [
        'order_id'       => $o['order_id'],
        'status'         => $o['status'],
        'amount'         => (float) $o['amount'],
        'payable_amount' => (float) $o['payable_amount'],
        'utr'            => $o['utr'],
        'customer_name'  => $o['customer_name'],
        'paid_at'        => $o['paid_at'] ? date('c', strtotime((string) $o['paid_at'])) : null,
        'created_at'     => date('c', strtotime((string) $o['created_at'])),
    ];
}

api_ok([
    'transactions' => $transactions,
    'meta' => [
        'page'  => $page,
        'limit' => $limit,
        'total' => $total,
        'pages' => (int) ceil($total / $limit),
    ],
]);
