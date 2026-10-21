<?php
declare(strict_types=1);

/* =========================================================
   POST /api/manage-links
   action=list | create | disable
   ========================================================= */

require_once __DIR__ . '/../includes/api.php';

[$key, $user] = api_auth();

$action = api_param('action', 'list');

if ($action === 'create') {
    if (strtoupper($_SERVER['REQUEST_METHOD']) !== 'POST') {
        api_fail('Use POST to create a payment link.', 405);
    }
    require_once APP_ROOT . '/includes/subscriptions.php';
    $can = sub_can((int) $user['id'], 'links');
    if (!$can['ok']) {
        api_fail(sub_reason_message($can['reason']) . ' (Links used: ' . $can['used'] . '/' . ($can['limit'] > 0 ? $can['limit'] : 'unlimited') . ')', 403, [
            'reason' => $can['reason'],
            'used'   => $can['used'],
            'limit'  => $can['limit'],
        ]);
    }
    $title = api_param('title');
    if ($title === '') {
        api_fail('"title" is required.', 400);
    }
    $amount      = api_param('amount');
    $description = api_param('description');
    $expiry      = (int) api_param('expires_in', '0'); // minutes
    $linkId      = generate_link_id();
    $expiresAt   = $expiry > 0 ? date('Y-m-d H:i:s', strtotime('+' . $expiry . ' minutes')) : null;

    db()->prepare(
        'INSERT INTO payment_links (user_id, link_id, title, amount, description, status, expires_at)
         VALUES (?, ?, ?, ?, ?, "active", ?)'
    )->execute([
        $user['id'],
        $linkId,
        $title,
        $amount !== '' ? (float) $amount : null,
        $description !== '' ? $description : null,
        $expiresAt,
    ]);

    api_ok([
        'link_id'    => $linkId,
        'title'      => $title,
        'amount'     => $amount !== '' ? (float) $amount : null,
        'status'     => 'active',
        'url'        => url('pay/?link=' . urlencode($linkId)),
        'expires_at' => $expiresAt ? date('c', strtotime($expiresAt)) : null,
    ]);
}

if ($action === 'disable') {
    $linkId = api_param('link_id');
    if ($linkId === '') {
        api_fail('"link_id" is required.', 400);
    }
    $stmt = db()->prepare('UPDATE payment_links SET status = "disabled" WHERE link_id = ? AND user_id = ?');
    $stmt->execute([$linkId, $user['id']]);
    if ($stmt->rowCount() === 0) {
        api_fail('Payment link not found.', 404);
    }
    api_ok(['link_id' => $linkId, 'status' => 'disabled']);
}

/* default: list */
$stmt = db()->prepare('SELECT * FROM payment_links WHERE user_id = ? ORDER BY created_at DESC');
$stmt->execute([$user['id']]);
$links = [];
foreach ($stmt->fetchAll() as $l) {
    $links[] = [
        'link_id'    => $l['link_id'],
        'title'      => $l['title'],
        'amount'     => $l['amount'] !== null ? (float) $l['amount'] : null,
        'status'     => $l['status'],
        'url'        => url('pay/?link=' . urlencode((string) $l['link_id'])),
        'expires_at' => $l['expires_at'] ? date('c', strtotime((string) $l['expires_at'])) : null,
        'created_at' => date('c', strtotime((string) $l['created_at'])),
    ];
}
api_ok(['links' => $links]);
