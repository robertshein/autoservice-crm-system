<?php
session_start();
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Доступ запрещён']);
    exit;
}
require_once __DIR__ . '/../../config/connect_database.php';
require_once __DIR__ . '/../../controllers/AdminController.php';
$admin = new AdminController($mysql_connection);
$result = $admin->getDashboardStats();
header('Content-Type: application/json');
echo json_encode($result);