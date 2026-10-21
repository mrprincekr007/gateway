<?php
declare(strict_types=1);

/* Dashboard — streams a single lazily loaded box as HTML. */

require __DIR__ . '/../config/config.php';
require_once APP_ROOT . '/includes/icons.php';
require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/includes/dash-boxes.php';

$user = current_user();
if (!$user || ($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Forbidden');
}

$box = get('box');
header('Content-Type: text/html; charset=utf-8');

switch ($box) {
    case 'transactions':
        dash_render_transactions();
        break;
    case 'leaderboard':
        dash_render_leaderboard();
        break;
    case 'audit':
        dash_render_audit();
        break;
    case 'webhooks':
        dash_render_webhooks();
        break;
    default:
        http_response_code(404);
        exit('Unknown box');
}
