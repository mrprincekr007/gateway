<?php
declare(strict_types=1);

/* =========================================================
   GET /pay.php?order_id=fg_XXXXXXXX
   Hosted checkout alias (FamGateway-compatible path).
   Also accepts ?link=lnk_XXXXXXXX for reusable payment links.
   ========================================================= */

if (!isset($_GET['order']) && isset($_GET['order_id'])) {
    $_GET['order'] = $_GET['order_id'];
}

require __DIR__ . '/pay/index.php';
