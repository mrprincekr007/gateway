<?php
/* Transactions table row. Expects $o (order row with merchant join). */
?>
<tr>
    <td data-label="Order ID">
        <a class="mono font-semibold" href="<?= url('admin/order.php?id=' . (int) $o['id']) ?>" style="color:var(--color-primary);font-size:13px;">
            <?= e($o['order_id']) ?>
        </a>
    </td>
    <td data-label="Merchant">
        <div style="font-weight:600;font-size:13px;">
            <a href="<?= url('admin/user.php?id=' . (int) $o['user_id']) ?>" style="color:inherit;text-decoration:none;">
                <?= e($o['merchant']) ?>
            </a>
        </div>
        <div class="text-mute" style="font-size:11.5px;"><?= e($o['merchant_email']) ?></div>
    </td>
    <td data-label="Customer">
        <div style="font-size:13px;"><?= e($o['customer_name'] ?: 'Guest') ?></div>
        <div class="text-mute" style="font-size:11.5px;"><?= e($o['customer_email'] ?: '—') ?></div>
    </td>
    <td data-label="Amount">
        <div class="tnum" style="font-weight:700;font-size:14px;color:<?= $o['status'] === 'success' ? 'var(--color-success)' : 'var(--color-ink-base)' ?>;">
            <?= money((float) $o['payable_amount']) ?>
        </div>
    </td>
    <td data-label="Status">
        <?= status_badge((string) $o['status']) ?>
    </td>
    <td data-label="Bank UTR" class="mono" style="font-size:12px;">
        <?= e($o['utr'] ?: '—') ?>
    </td>
    <td data-label="Created" class="text-mute" style="font-size:12px;">
        <?= e(nice_date($o['created_at'])) ?>
    </td>
    <td data-label="Actions" style="text-align:right;">
        <div class="flex items-center justify-end gap-1">
            <a class="btn btn-secondary btn-sm" href="<?= url('admin/order.php?id=' . (int) $o['id']) ?>" title="Inspect transaction details">
                <?= icon('eye', 12) ?> Inspect
            </a>

            <?php if ($o['status'] === 'pending'): ?>
                <button class="btn btn-primary btn-sm flex items-center gap-1" onclick="openReconcileModal(<?= (int) $o['id'] ?>, '<?= e($o['order_id']) ?>', '<?= money((float) $o['payable_amount']) ?>')" style="padding:4px 10px;font-size:12px;">
                    <?= icon('check-circle', 12) ?> Reconcile
                </button>
            <?php endif; ?>
        </div>
    </td>
</tr>
