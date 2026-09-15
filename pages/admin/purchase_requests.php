<?php
require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/../../models/PartPurchaseRequest.php';

$nav_admin_section = 'purchases';

$flash_error = null;
$flash_success = null;

$STATUS_LABELS = [
    PartPurchaseRequest::STATUS_PENDING  => 'Ожидает',
    PartPurchaseRequest::STATUS_APPROVED => 'Одобрен',
    PartPurchaseRequest::STATUS_REJECTED => 'Отклонён',
    'ordered'  => 'Заказан',
    'received' => 'Получен',
];

$STATUS_BADGE = [
    PartPurchaseRequest::STATUS_PENDING  => 'badge badge-warn',
    PartPurchaseRequest::STATUS_APPROVED => 'badge badge-done',
    PartPurchaseRequest::STATUS_REJECTED => 'badge',
    'ordered'  => 'badge badge-warn',
    'received' => 'badge badge-done',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'decide') {
    $request_id = (int) ($_POST['request_id'] ?? 0);
    $decision   = (string) ($_POST['decision'] ?? '');
    $comment    = trim($_POST['comment'] ?? '');
    $approve    = ($decision === 'approve');

    if ($request_id <= 0) {
        $flash_error = 'Некорректный запрос.';
    } elseif (!in_array($decision, ['approve', 'reject'], true)) {
        $flash_error = 'Некорректное решение.';
    } else {
        $comment_param = $comment === '' ? null : $comment;
        $r = $admin_controller->decidePurchaseRequest($request_id, $admin_id, $approve, $comment_param);
        if (!($r['success'] ?? false)) {
            $flash_error = $r['message'] ?? 'Не удалось обработать запрос.';
        } else {
            $flash_success = $approve ? 'Запрос одобрен.' : 'Запрос отклонён.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_purchase_request') {
    $order_id = (int) ($_POST['order_id'] ?? 0);
    $part_id  = (int) ($_POST['part_id'] ?? 0);
    $quantity = (int) ($_POST['quantity'] ?? 1);
    $comment  = trim($_POST['comment'] ?? '');

    if ($order_id <= 0 || $part_id <= 0 || $quantity < 1) {
        $flash_error = 'Выберите заявку, запчасть и количество.';
    } else {
        $r = $admin_controller->createPurchaseRequest($order_id, $part_id, $quantity, $admin_id, $comment);
        if (!($r['success'] ?? false)) {
            $flash_error = $r['message'] ?? 'Не удалось создать закупку.';
        } else {
            $flash_success = $r['data']['message'] ?? 'Закупка создана и одобрена.';
        }
    }
}

$requests = [];
$r = $admin_controller->getAllPurchaseRequests();
if ($r['success'] ?? false) {
    $requests = $r['data']['requests'] ?? [];
}

$pending = array_filter($requests, fn($req) => ($req['status'] ?? '') === PartPurchaseRequest::STATUS_PENDING);
$resolved = array_filter($requests, fn($req) => ($req['status'] ?? '') !== PartPurchaseRequest::STATUS_PENDING);

$orders_for_form = [];
$ord_r = $admin_controller->getOrdersForPurchaseRequest();
if ($ord_r['success'] ?? false) {
    $orders_for_form = $ord_r['data']['orders'] ?? [];
}
$parts = [];
$parts_r = $admin_controller->getParts();
if ($parts_r['success'] ?? false) {
    $parts = $parts_r['data']['parts'] ?? [];
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Запросы на закупку — АвтоПлюс</title>
    <?php include __DIR__ . '/../../includes/layout_styles.php'; ?>
    <style>
        .decide-form { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .btn-sm {
            padding: 5px 12px;
            border: 1px solid var(--border);
            border-radius: 4px;
            background: #fff;
            font-size: 0.82rem;
            font-weight: 600;
            cursor: pointer;
            font-family: inherit;
        }
        .btn-approve { color: var(--ok-text); border-color: var(--ok-border); }
        .btn-approve:hover { background: var(--ok-bg); }
        .btn-reject  { color: var(--danger-text); border-color: var(--danger-border); }
        .btn-reject:hover { background: var(--danger-bg); }
        .comment-inline { width: 180px; padding: 5px 8px; font-size: 0.82rem; }
        .inline-form { display: inline; }
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

        <h1 class="sans">Запросы на закупку запчастей</h1>
        <p class="lead">Управление закупками: создавайте новые закупки, одобряйте или отклоняйте запросы мастеров и механиков.</p>

        <section class="card">
            <h2>Новая закупка</h2>
            <?php if (empty($orders_for_form)): ?>
                <p class="empty">Нет подходящих заявок (нужен назначенный механик). Сначала назначьте механика на заявку.</p>
            <?php elseif (empty($parts)): ?>
                <p class="empty">В справочнике нет запчастей. Добавьте запчасти в разделе «Запчасти».</p>
            <?php else: ?>
                <form method="post" action="">
                    <input type="hidden" name="action" value="create_purchase_request">
                    <div class="field">
                        <label for="order_id">Заявка</label>
                        <select id="order_id" name="order_id" required>
                            <option value="">Выберите</option>
                            <?php foreach ($orders_for_form as $o): ?>
                                <?php
                                $st = $o['status'] ?? '';
                                $st_lbl = '';
                                if ($st === 'assigned') $st_lbl = 'Назначен механик';
                                elseif ($st === 'in_progress') $st_lbl = 'В работе';
                                elseif ($st === 'waiting_parts') $st_lbl = 'Ожидание запчастей';
                                $label = '#' . (int)($o['id'] ?? 0) . ' — ' . ($o['client_name'] ?? '') . ' — '
                                    . trim(($o['brand'] ?? '') . ' ' . ($o['model'] ?? '')) . ' (' . $st_lbl . ')';
                                ?>
                                <option value="<?php echo (int)($o['id'] ?? 0); ?>"><?php echo htmlspecialchars($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="part_search">Запчасть</label>
                        <input type="text" id="part_search" autocomplete="off"
                               placeholder="Поиск по названию или артикулу…"
                               style="margin-bottom:4px;">
                        <select id="part_id" name="part_id" required size="6"
                                style="width:100%; height:auto; border-radius:4px;">
                            <option value="">— начните ввод или выберите —</option>
                            <?php foreach ($parts as $p): ?>
                                <option value="<?php echo (int) $p['id']; ?>">
                                    <?php echo htmlspecialchars(
                                        ($p['name'] ?? '') . ' · арт. ' . ($p['article'] ?? '')
                                        . ' · ' . number_format((float)($p['price'] ?? 0), 0, '.', ' ') . ' ₽'
                                        . '  (склад: ' . (int)($p['quantity'] ?? 0) . ' шт.)'
                                    ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span id="part_search_hint" style="font-size:0.78rem; color:#6b7280; margin-top:2px; display:block;"></span>
                    </div>
                    <div class="row2">
                        <div class="field">
                            <label for="quantity">Количество</label>
                            <input id="quantity" name="quantity" type="number" required min="1" max="9999" value="1">
                        </div>
                        <div class="field" style="align-self:flex-end;">
                            <button type="submit" class="btn-submit" style="width:100%; margin-bottom:0;">Создать закупку</button>
                        </div>
                        <div class="field">
                            <label for="comment">Комментарий</label>
                            <input id="comment" name="comment" type="text" maxlength="500" placeholder="Необязательно">
                        </div>
                    </div>
                    <p class="hint">Закупка создаётся сразу со статусом «Одобрен» и не требует дополнительного решения.</p>
                </form>
            <?php endif; ?>
        </section>

        <section class="card">
            <h2>Ожидают вашего решения <?php if (!empty($pending)): ?><span class="badge badge-warn"><?php echo count($pending); ?></span><?php endif; ?></h2>
            <?php if (empty($pending)): ?>
                <p class="empty">Запросов, ожидающих решения, нет.</p>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="sans">
                        <thead>
                            <tr>
                                <th>№</th>
                                <th>Заявка</th>
                                <th>Запчасть</th>
                                <th>Цена</th>
                                <th>Запросил</th>
                                <th>Комментарий</th>
                                <th>Создан</th>
                                <th>Решение</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pending as $req): ?>
                                <?php
                                $rid = (int) ($req['id'] ?? 0);
                                $qty = (int) ($req['quantity'] ?? 0);
                                $price = isset($req['part_price'])
                                    ? number_format((float) $req['part_price'], 0, '.', ' ') . ' ₽'
                                    : '—';
                                $total = isset($req['part_price'])
                                    ? number_format((float) $req['part_price'] * $qty, 0, '.', ' ') . ' ₽'
                                    : '—';
                                ?>
                                <tr>
                                    <td><?php echo $rid; ?></td>
                                    <td>#<?php echo (int) ($req['order_id'] ?? 0); ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($req['part_name'] ?? ''); ?></strong><br>
                                        <span class="hint"><?php echo htmlspecialchars($req['article'] ?? ''); ?></span>
                                    </td>
                                    <td><?php echo $price; ?></td>
                                    <td><?php echo $qty; ?> шт.<br><span class="hint">= <?php echo $total; ?></span></td>
                                    <td>
                                        <?php echo htmlspecialchars($req['requester_name'] ?? ''); ?>
                                        <?php if (($req['requester_role'] ?? '') === 'mechanic'): ?>
                                            <span class="hint"> (механик)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="hint"><?php echo htmlspecialchars((string) ($req['comment'] ?? '—')); ?></span></td>
                                    <td><span class="hint"><?php echo htmlspecialchars((string) ($req['created_at'] ?? '')); ?></span></td>
                                    <td>
                                        <form method="post" action="" class="decide-form">
                                            <input type="hidden" name="action" value="decide">
                                            <input type="hidden" name="request_id" value="<?php echo $rid; ?>">
                                            <input type="text" name="comment" class="comment-inline" placeholder="Комментарий (необяз.)">
                                            <button type="submit" name="decision" value="approve" class="btn-sm btn-approve">Одобрить</button>
                                            <button type="submit" name="decision" value="reject" class="btn-sm btn-reject"
                                                onclick="return confirm('Отклонить запрос?')">Отклонить</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="card">
            <h2>История обработанных запросов</h2>
            <?php if (empty($resolved)): ?>
                <p class="empty">Обработанных запросов пока нет.</p>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="sans">
                        <thead>
                            <tr>
                                <th>№</th>
                                <th>Заявка</th>
                                <th>Запчасть</th>
                                <th>Статус</th>
                                <th>Запросил</th>
                                <th>Решил</th>
                                <th>Решено</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($resolved as $req): ?>
                                <?php
                                $st = (string) ($req['status'] ?? '');
                                $badge = $STATUS_BADGE[$st] ?? 'badge';
                                ?>
                                <tr>
                                    <td><?php echo (int) ($req['id'] ?? 0); ?></td>
                                    <td>#<?php echo (int) ($req['order_id'] ?? 0); ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($req['part_name'] ?? ''); ?><br>
                                        <span class="hint"><?php echo htmlspecialchars($req['article'] ?? ''); ?></span>
                                    </td>
                                    <td><?php echo (int) ($req['quantity'] ?? 0); ?></td>
                                    <td><span class="<?php echo $badge; ?>"><?php echo htmlspecialchars($STATUS_LABELS[$st] ?? $st); ?></span></td>
                                    <td>
                                        <?php echo htmlspecialchars($req['requester_name'] ?? ''); ?>
                                        <?php if (($req['requester_role'] ?? '') === 'mechanic'): ?>
                                            <span class="hint">Вы</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars((string) ($req['approved_by_admin'] ?? '—')); ?></td>
                                    <td><span class="hint"><?php echo htmlspecialchars((string) ($req['resolved_at'] ?? '—')); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    <table>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <script>
    (function () {
        var searchInput = document.getElementById('part_search');
        var partSelect  = document.getElementById('part_id');
        var hint        = document.getElementById('part_search_hint');
        if (!searchInput || !partSelect) return;

        var allOptions = Array.from(partSelect.options).map(function (o) {
            return { value: o.value, text: o.text, searchText: o.text.toLowerCase() };
        });
        var total = allOptions.filter(function (o) { return o.value !== ''; }).length;

        function rebuild(query) {
            var q = query.trim().toLowerCase();
            var kept = allOptions.filter(function (o) {
                return o.value === '' || q === '' || o.searchText.indexOf(q) !== -1;
            });
            var currentVal = partSelect.value;
            partSelect.innerHTML = '';
            kept.forEach(function (o) {
                var opt = document.createElement('option');
                opt.value = o.value;
                opt.textContent = o.text;
                if (o.value && o.value === currentVal) opt.selected = true;
                partSelect.appendChild(opt);
            });
            var found = kept.filter(function (o) { return o.value !== ''; }).length;
            if (q !== '') {
                hint.textContent = found > 0 ? 'Найдено: ' + found + ' из ' + total : 'Ничего не найдено';
                hint.style.color = found > 0 ? '#6b7280' : '#b91c1c';
                if (found === 1) {
                    partSelect.value = kept.filter(function (o) { return o.value !== ''; })[0].value;
                }
            } else {
                hint.textContent = '';
            }
        }

        searchInput.addEventListener('input', function () {
            rebuild(this.value);
        });
    })();
    </script>
</body>
</html>