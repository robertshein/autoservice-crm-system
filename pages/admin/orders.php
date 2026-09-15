<?php
require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/../../models/Order.php';

$nav_admin_section = 'orders';

$flash_error   = null;
$flash_success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'force_status') {
    $order_id   = (int) ($_POST['order_id']   ?? 0);
    $new_status = (string) ($_POST['new_status'] ?? '');
    if ($order_id <= 0 || $new_status === '') {
        $flash_error = 'Некорректные данные.';
    } else {
        $r = $admin_controller->forceOrderStatus($order_id, $new_status);
        $flash_error   = ($r['success'] ?? false) ? null : ($r['message'] ?? 'Ошибка.');
        $flash_success = ($r['success'] ?? false) ? 'Статус заявки #' . $order_id . ' изменён.' : null;
    }
}

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$total = 0;
$totalPages = 1;

$countR = $admin_controller->getOrdersCount();
if ($countR['success'] ?? false) {
    $total = $countR['data']['total'] ?? 0;
    $totalPages = ceil($total / $limit);
}

$orders = [];
$r = $admin_controller->getAllOrdersPaginated($limit, $offset);
if ($r['success'] ?? false) {
    $orders = $r['data']['orders'] ?? [];
}

$STATUS_LABELS = [
    Order::STATUS_NEW           => 'Новая',
    Order::STATUS_ASSIGNED      => 'Назначен механик',
    Order::STATUS_IN_PROGRESS   => 'В работе',
    Order::STATUS_WAITING_PARTS => 'Ожидание запчастей',
    Order::STATUS_COMPLETED     => 'Завершена',
    Order::STATUS_CANCELLED     => 'Отменена',
];

$STATUS_BADGE = [
    Order::STATUS_NEW           => 'badge badge-warn',
    Order::STATUS_ASSIGNED      => 'badge badge-warn',
    Order::STATUS_IN_PROGRESS   => 'badge badge-done',
    Order::STATUS_WAITING_PARTS => 'badge badge-warn',
    Order::STATUS_COMPLETED     => 'badge',
    Order::STATUS_CANCELLED     => 'badge',
];

$terminal = [Order::STATUS_COMPLETED, Order::STATUS_CANCELLED];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Заявки — АвтоПлюс</title>
    <?php include __DIR__ . '/../../includes/layout_styles.php'; ?>
    <style>
        .pagination {
            margin-top: 20px;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            justify-content: center;
        }
        .pagination-link {
            padding: 6px 12px;
            border: 1px solid var(--border);
            border-radius: 4px;
            background: #fff;
            color: var(--focus);
            text-decoration: none;
            font-family: "Segoe UI", system-ui, sans-serif;
            font-size: 0.85rem;
        }
        .pagination-link.active {
            background: var(--focus);
            color: #fff;
            border-color: var(--focus);
            pointer-events: none;
        }
        .pagination-link:hover:not(.active) {
            background: #f0f4fb;
        }
        .btn-sm-danger1 {
            margin-top: 6px;
            padding: 10px 18px;
            border: 1px solid #f29089;
            border-radius: 4px;
            background: #f29089;
            color: #fff;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            font-family: inherit;
        }
        .btn-sm-danger1:hover{
            background: #ff766d;
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../../includes/site_nav.php'; ?>

    <div class="page">
        <?php if ($flash_error): ?>
            <div class="flash flash-err"><?php echo htmlspecialchars($flash_error); ?></div>
        <?php endif; ?>
        <?php if ($flash_success): ?>
            <div class="flash flash-ok"><?php echo htmlspecialchars($flash_success); ?></div>
        <?php endif; ?>

        <h1 class="sans">Все заявки</h1>
        <p class="lead">Полный список заявок системы. Можно принудительно изменить статус любой незавершённой заявки.</p>

        <section class="card">
            <h2>Заявки (<?php echo $total; ?>)</h2>

            <?php if (empty($orders)): ?>
                <p class="empty">Заявок не найдено.</p>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="sans">
                        <thead>
                            <tr>
                                <th style="width:50px;">#</th>
                                <th>Клиент</th>
                                <th>Автомобиль</th>
                                <th>Мастер</th>
                                <th>Механик</th>
                                <th>Статус</th>
                                <th>Услуги</th>
                                <th>Описание</th>
                                <th>Сумма, ₽</th>
                                <th>Создана</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orders as $order): ?>
                                <?php
                                $oid    = (int) ($order['id'] ?? 0);
                                $st     = (string) ($order['status'] ?? '');
                                $badge  = $STATUS_BADGE[$st] ?? 'badge';
                                $is_terminal = in_array($st, $terminal, true);
                                $car    = trim(($order['brand'] ?? '') . ' ' . ($order['model'] ?? ''));
                                $total_price = (float) ($order['total_price'] ?? 0);
                                $created = substr((string) ($order['created_at'] ?? ''), 0, 10);
                                ?>
                                <tr>
                                    <td><strong><?php echo $oid; ?></strong></td>
                                    <td><?php echo htmlspecialchars($order['client_name'] ?? ''); ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($car); ?><br>
                                        <span class="hint"><?php echo htmlspecialchars($order['gosnumber'] ?? ''); ?></span>
                                    </td>
                                    <td><?php echo htmlspecialchars((string) ($order['master_name'] ?? '—')); ?></td>
                                    <td><?php echo htmlspecialchars((string) ($order['mechanic_name'] ?? '—')); ?></td>
                                    <td><span class="<?php echo $badge; ?>"><?php echo $STATUS_LABELS[$st] ?? $st; ?></span></td>
                                    <td>
                                        <?php if (!empty($order['services_summary'])): ?>
                                            <span class="hint" style="font-size:0.8rem;"><?php echo htmlspecialchars($order['services_summary']); ?></span>
                                        <?php else: ?>
                                            <span class="hint">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="order-desc" title="<?php echo htmlspecialchars($order['description'] ?? ''); ?>">
                                        <?php 
                                        $desc = $order['description'] ?? '';
                                        $short_desc = mb_strlen($desc) > 50 ? mb_substr($desc, 0, 50) . '…' : $desc;
                                        echo htmlspecialchars($short_desc);
                                        ?>
                                    </td>
                                    <td><?php echo $total_price > 0 ? number_format($total_price, 0, '.', ' ') : '—'; ?></td>
                                    <td><span class="hint"><?php echo $created; ?></span></td>
                                    <td>
                                        <?php if (!$is_terminal): ?>
                                            <form method="post" action="" class="inline-form">
                                                <input type="hidden" name="action"     value="force_status">
                                                <input type="hidden" name="order_id"   value="<?php echo $oid; ?>">
                                                <input type="hidden" name="new_status" value="<?php echo Order::STATUS_CANCELLED; ?>">
                                                <button type="submit" class="btn-sm-danger" style=" border: 1px solid #f29089; border-radius: 4px;background: #f29089; color: #fff; font-weight: 600; font-size: 0.9rem; cursor: pointer; font-family: inherit;"
                                                    onclick="return confirm('Принудительно отменить заявку #<?php echo $oid; ?>?')">
                                                    Отмена
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span class="hint">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                            <a class="pagination-link <?php echo $i == $page ? 'active' : ''; ?>" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </div>
</body>
</html>