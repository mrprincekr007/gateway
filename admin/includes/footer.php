<?php
/* Admin console shell footer. Set $extraJs = [] before including. */
$extraJs = $extraJs ?? [];
?>
        </div>
    </main>
</div>
<!-- Core Runtime & Shared Component Scripts -->
<script src="<?= asset('admin/assets/js/components/core.js') ?>"></script>
<script src="<?= asset('admin/assets/js/components/header.js') ?>"></script>
<script src="<?= asset('admin/assets/js/components/sidebar.js') ?>"></script>
<script src="<?= asset('admin/assets/js/components/navigate.js') ?>"></script>

<!-- Dedicated Modular Page Script -->
<?php
$pageKey = $pageKey ?? ($activeNav ?? ($active ?? ''));
$pageJsMap = [
    'overview'      => 'index.js',
    'merchants'     => 'users.js',
    'user'          => 'user.js',
    'orders'        => 'orders.js',
    'subscriptions' => 'subscriptions.js',
    'system'        => 'system.js',
    'admins'        => 'admins.js',
];
$currentPageJs = $pageJsMap[$pageKey] ?? null;
if ($currentPageJs):
    $pageJsDisk = __DIR__ . '/../assets/js/pages/' . $currentPageJs;
    if (file_exists($pageJsDisk)):
?>
<script id="adminPageScript" src="<?= asset('admin/assets/js/pages/' . $currentPageJs) ?>"></script>
<?php
    endif;
endif;
?>
<?php foreach ($extraJs as $js): ?>
<script src="<?= asset($js) ?>"></script>
<?php endforeach; ?>
</body>
</html>
