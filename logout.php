<?php
declare(strict_types=1);

require __DIR__ . '/config/config.php';

logout_user();
redirect('login.php');