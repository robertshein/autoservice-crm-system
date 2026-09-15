<?php
require_once __DIR__ . '/_init.php';
$nav_admin_section = 'dashboard';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Панель администратора — АвтоПлюс</title>
    <?php include __DIR__ . '/../../includes/layout_styles.php'; ?>
</head>
<body>
    <?php include __DIR__ . '/../../includes/site_nav.php'; ?>
    <div class="page">
        <h1 class="sans">Панель администратора</h1>
        <p class="lead">Здравствуйте, <?php echo htmlspecialchars($admin_user['full_name'] ?? ''); ?>. Управляйте сотрудниками и закупками.</p>

        <div class="stats sans" id="stats-container">
            <div class="stat"><div class="num" id="stat-total-orders">—</div><div class="lbl">Всего заявок</div></div>
            <div class="stat"><div class="num" id="stat-new-orders">—</div><div class="lbl">Новых заявок</div></div>
            <div class="stat"><div class="num" id="stat-pending-purchases">—</div><div class="lbl">Закупки (ожидают)</div></div>
            <div class="stat"><div class="num" id="stat-active-employees">—</div><div class="lbl">Активных сотрудников</div></div>
            <div class="stat"><div class="num" id="stat-total-clients">—</div><div class="lbl">Клиентов</div></div>
        </div>

        <section class="card">
            <h2>Быстрые действия</h2>
            <div style="display: flex; flex-wrap: wrap; gap: 10px; margin-top: 4px;">
                <a class="btn-submit" href="orders.php" style="display:inline-block; text-decoration:none; background:#fff; color:var(--focus);">
                    Все заявки <span id="badge-total-orders" style="background:var(--focus);color:#fff;border-radius:10px;padding:1px 7px;font-size:0.75rem;margin-left:4px;">—</span>
                </a>
                <a class="btn-submit" href="employees.php" style="display:inline-block; text-decoration:none;">Сотрудники</a>
                <a class="btn-submit" href="purchase_requests.php" style="display:inline-block; text-decoration:none; background:#fff; color:var(--focus);">
                    Запросы на закупку <span id="badge-pending-purchases" style="background:var(--focus);color:#fff;border-radius:10px;padding:1px 7px;font-size:0.75rem;margin-left:4px;">—</span>
                </a>
                <a class="btn-submit" href="services.php" style="display:inline-block; text-decoration:none; background:#fff; color:var(--focus);">Услуги</a>
                <a class="btn-submit" href="parts.php" style="display:inline-block; text-decoration:none; background:#fff; color:var(--focus);">Запчасти</a>
            </div>
        </section>

        <section class="card">
            <h2>Отчётность</h2>
            <p class="hint" style="margin-top:0; margin-bottom:14px;">Системный отчёт содержит 4 листа: сводная статистика, все заявки, список сотрудников и история закупок.</p>
            <a href="report_download.php" class="btn-submit" style="display:inline-flex; align-items:center; gap:8px; text-decoration:none;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/>
                    <polyline points="7 10 12 15 17 10"/>
                    <line x1="12" y1="15" x2="12" y2="3"/>
                </svg>
                Скачать отчёт Excel (.xlsx)
            </a>
            <p class="hint" style="margin-top:10px;">Данные актуальны на момент скачивания. Формат совместим с Microsoft Excel, LibreOffice Calc и Google Sheets.</p>
        </section>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        fetch('stats_ajax.php', {
            credentials: 'include',
            headers: { 'Accept': 'application/json' }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.data && data.data.stats) {
                const s = data.data.stats;
                document.getElementById('stat-total-orders').innerText = s.total_orders ?? 0;
                document.getElementById('stat-new-orders').innerText = s.new_orders ?? 0;
                document.getElementById('stat-pending-purchases').innerText = s.pending_purchases ?? 0;
                document.getElementById('stat-active-employees').innerText = s.active_employees ?? 0;
                document.getElementById('stat-total-clients').innerText = s.total_clients ?? 0;
                document.getElementById('badge-total-orders').innerText = s.total_orders ?? 0;
                document.getElementById('badge-pending-purchases').innerText = s.pending_purchases ?? 0;
            }
        })
        .catch(err => console.error('Ошибка загрузки статистики:', err));
    });
    </script>
</body>
</html>