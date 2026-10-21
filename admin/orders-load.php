<?php
declare(strict_types=1);

/* Transactions monitor — paginated rows endpoint for infinite scroll (JSON). */

require __DIR__ . '/../config/config.php';
require_once APP_ROOT . '/includes/icons.php';
require_once __DIR__ . '/includes/admin.php';

$user = current_user();
if (!$user || ($user['role'] ?? '') !== 'admin') {
    json_response(['ok' => false, 'error' => 'unauthorized'], 401);
}

$status   = get('status');
$merchant = (int) get('merchant');
$perPage  = max(1, min(100, (int) get('per_page', '25')));
$page     = max(1, (int) get('page', '1'));
$search   = trim(get('q'));

$where  = '1=1';
$params = [];
if (in_array($status, ['success', 'pending', 'failed', 'expired'], true)) {
    $where .= ' AND o.status = :status';
    $params[':status'] = $status;
}
if ($merchant > 0) {
    $where .= ' AND o.user_id = :uid';
    $params[':uid'] = $merchant;
}
if ($search !== '') {
    $where .= ' AND (o.order_id LIKE :sq1 OR o.customer_name LIKE :sq2 OR o.customer_email LIKE :sq3 OR o.utr LIKE :sq4 OR u.email LIKE :sq5 OR u.name LIKE :sq6)';
    $like = "%{$search}%";
    for ($i = 1; $i <= 6; $i++) { $params[":sq{$i}"] = $like; }
}

$countStmt = db()->prepare("SELECT COUNT(*) FROM orders o JOIN users u ON u.id = o.user_id WHERE {$where}");
$countStmt->execute($params);
$totalRecords = (int) $countStmt->fetchColumn();
$totalPages   = max(1, (int) ceil($totalRecords / $perPage));

if ($page > $totalPages) {
    json_response([
        'ok'            => true,
        'html'          => '',
        'page'          => $page,
        'per_page'      => $perPage,
        'total_pages'   => $totalPages,
        'total_records' => $totalRecords,
        'has_more'      => false,
    ]);
}

$offset = ($page - 1) * $perPage;

$rowsStmt = db()->prepare("
    SELECT o.*, u.name AS merchant, u.email AS merchant_email, u.merchant_id
    FROM orders o JOIN users u ON u.id = o.user_id
    WHERE {$where}
    ORDER BY o.created_at DESC
    LIMIT :limit OFFSET :offset");
$rowsStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$rowsStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
foreach ($params as $k => $v) { $rowsStmt->bindValue($k, $v); }
$rowsStmt->execute();
$rows = $rowsStmt->fetchAll();

ob_start();
foreach ($rows as $o) {
    include __DIR__ . '/includes/order-row.php';
}
$html = ob_get_clean();

json_response([
    'ok'            => true,
    'html'          => $html,
    'page'          => $page,
    'per_page'      => $perPage,
    'total_pages'   => $totalPages,
    'total_records' => $totalRecords,
    'has_more'      => $page < $totalPages,
]);
