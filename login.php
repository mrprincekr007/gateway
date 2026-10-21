<?php
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';

$redirect = get('redirect');
$mode = get('mode');

$params = [];
if ($redirect !== '') {
    $params['redirect'] = $redirect;
}
if ($mode !== '') {
    $params['mode'] = $mode;
}

$target = 'auth.php' . (!empty($params) ? '?' . http_build_query($params) : '');
redirect($target);