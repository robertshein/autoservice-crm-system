<?php
require_once __DIR__ . '/_init.php';

$nav_admin_section = 'logs';

$type = $_GET['type'] ?? 'actions';

$actions = [];
$errors = [];

$result = mysqli_query($mysql_connection, "SELECT * FROM action_logs ORDER BY created_at DESC LIMIT 50");
if ($result) {
    $actions = $result->fetch_all(MYSQLI_ASSOC);
}

$result2 = mysqli_query($mysql_connection, "SELECT * FROM error_logs ORDER BY created_at DESC LIMIT 50");
if ($result2) {
    $errors = $result2->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Логи системы</title>
    <?php include __DIR__ . '/../../includes/layout_styles.php'; ?>
    <style>
        .log-table { font-size: 0.8rem; font-family: monospace; width: 100%; }
        .log-table td, .log-table th { padding: 6px 8px; border-bottom: 1px solid #ddd; vertical-align: top; }
        .badge-err { background: #fee2e2; color: #b91c1c; padding: 2px 8px; border-radius: 12px; }
        .badge-warn { background: #fef3c7; color: #b45309; }
        .tab { display: inline-block; padding: 6px 16px; margin-right: 8px; background: #eee; text-decoration: none; color: #333; border-radius: 6px; }
        .tab.active { background: var(--focus); color: white; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../../includes/site_nav.php'; ?>
    <div class="page">
        <h1>Логи системы</h1>
        <div style="margin-bottom: 20px;">
            <a href="?type=actions" class="tab <?php echo $type === 'actions' ? 'active' : ''; ?>">Действия</a>
            <a href="?type=errors" class="tab <?php echo $type === 'errors' ? 'active' : ''; ?>">Ошибки</a>
        </div>

        <?php if ($type === 'actions'): ?>
            <div class="card">
                <h2>Последние действия (50)</h2>
                <?php if (empty($actions)): ?>
                    <p>Нет записей.</p>
                <?php else: ?>
                    <div style="overflow-x: auto;">
                        <table class="log-table">
                            <thead>
                                <tr><th>Время</th><th>Пользователь</th><th>Роль</th><th>Действие</th><th>Детали</th><th>IP</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($actions as $row): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($row['created_at']); ?></td>
                                        <td><?php echo (int)$row['user_id']; ?></td>
                                        <td><?php echo htmlspecialchars($row['user_role']); ?></td>
                                        <td><?php echo htmlspecialchars($row['action']); ?></td>
                                        <td><?php echo htmlspecialchars($row['details'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($row['ip_address'] ?? ''); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="card">
                <h2>Последние ошибки (50)</h2>
                <?php if (empty($errors)): ?>
                    <p>Нет записей.</p>
                <?php else: ?>
                    <div style="overflow-x: auto;">
                        <table class="log-table">
                            <thead>
                                <tr><th>Время</th><th>Уровень</th><th>Сообщение</th><th>Файл:строка</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($errors as $err): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($err['created_at']); ?></td>
                                        <td><span class="badge-err"><?php echo htmlspecialchars($err['error_level']); ?></span></td>
                                        <td><?php echo htmlspecialchars($err['message']); ?></td>
                                        <td><?php echo basename($err['file'] ?? '') . ':' . (int)$err['line']; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>