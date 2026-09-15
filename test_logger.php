<?php
require_once 'config/connect_database.php';
require_once 'includes/Logger.php';

$logger = new Logger($mysql_connection);

// 1. Логируем тестовое действие
$logger->logAction(1, 'admin', 'test_action', 'test', 123, 'Тестовая запись действия');
echo "1. Действие записано в action_logs<br>";

// 2. Логируем тестовую ошибку
$logger->logError('TEST', 'Тестовая ошибка из test_logger.php', __FILE__, __LINE__, null, 1);
echo "2. Ошибка записана в error_logs и в файл logs/error_*.log<br>";

// 3. Проверяем чтение логов (опционально)
$result = mysqli_query($mysql_connection, "SELECT COUNT(*) as cnt FROM action_logs");
$row = mysqli_fetch_assoc($result);
echo "3. Всего записей в action_logs: " . $row['cnt'] . "<br>";

echo "<hr>Готово. Проверьте таблицы action_logs и error_logs в БД, а также папку logs/";