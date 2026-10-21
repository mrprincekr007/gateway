<?php
declare(strict_types=1);

/* Merchant directory — paginated rows endpoint for infinite scroll (JSON). */

require __DIR__ . '/../config/config.php';
require_once APP_ROOT . '/includes/icons.php';
require_once __DIR__ . '/includes/admin.php';

$user = current_user();
if (!$user || ($user['role'] ?? '') !== 'admin') {
    json_response(['ok' => false, 'error' => 'unauthorized'], 401);
}

$q       = trim(get('q'));
$status  = get('status');
$role    = get('role');
$perPage = max(1, min(100, (int) get('per_page', '25')));
$page    = max(1, (int) get('page', '1'));

$where  = '1=1';
$params = [];
if ($q !== '') {
    $where .= ' AND (u.name LIKE :sq1 OR u.email LIKE :sq2 OR u.merchant_id LIKE :sq3 OR u.upi_id LIKE :sq4 OR u.phone LIKE :sq5 OR u.business_name LIKE :sq6)';
    $like = "%{$q}%";
    for ($i = 1; $i <= 6; $i++) { $params[":sq{$i}"] = $like; }
}
if (in_array($status, ['active', 'suspended'], true)) {
    $where .= ' AND u.status = :status';
    $params[':status'] = $status;
}
if (in_array($role, ['user', 'admin'], true)) {
    $where .= ' AND u.role = :role';
    $params[':role'] = $role;
}

$countStmt = db()->prepare("SELECT COUNT(*) FROM users u WHERE {$where}");
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
    SELECT u.*, 
           COUNT(o.id) AS total_orders,
           COALESCE(SUM(CASE WHEN o.status = 'success' THEN 1 ELSE 0 END), 0) AS success_orders,
           COALESCE(SUM(CASE WHEN o.status = 'success' THEN o.payable_amount END), 0) AS collected
    FROM users u 
    LEFT JOIN orders o ON o.user_id = u.id
    WHERE {$where}
    GROUP BY u.id
    ORDER BY u.created_at DESC
    LIMIT :limit OFFSET :offset");
$rowsStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$rowsStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
foreach ($params as $k => $v) { $rowsStmt->bindValue($k, $v); }
$rowsStmt->execute();
$rows = $rowsStmt->fetchAll();

$return = http_build_query(array_filter(
    ['q' => $q, 'status' => $status, 'role' => $role],
    fn ($v) => $v !== '' && $v !== null
));

ob_start();
foreach ($rows as $u) {
    include __DIR__ . '/includes/merchant-row.php';
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
