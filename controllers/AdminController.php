<?php
require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Order.php';
require_once __DIR__ . '/../models/PartPurchaseRequest.php';

class AdminController extends BaseController
{
    public function getEmployees(): array
    {
        $check = $this->requireRole([User::ROLE_ADMIN]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);
        try {
            return $this->ok(['employees' => $this->users()->getEmployees()]);
        } catch (Exception $e) {
            $this->logger()->logError('DB_ERROR', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $this->currentUserId());
            return $this->fail('Ошибка получения сотрудников', 500);
        }
    }

    public function getClients(): array
    {
        $check = $this->requireRole([User::ROLE_ADMIN]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);
        try {
            return $this->ok(['clients' => $this->users()->getClients()]);
        } catch (Exception $e) {
            $this->logger()->logError('DB_ERROR', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $this->currentUserId());
            return $this->fail('Ошибка получения клиентов', 500);
        }
    }

    public function createEmployee(string $fullName, string $phone, string $email, string $password, string $role): array
    {
        $check = $this->requireRole([User::ROLE_ADMIN]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);

        if (!in_array($role, [User::ROLE_MECHANIC, User::ROLE_MASTER, User::ROLE_ADMIN], true)) return $this->fail('Некорректная роль сотрудника');
        if ($this->users()->emailExists($email)) return $this->fail('Пользователь с таким email уже существует');

        try {
            $id = $this->users()->create($fullName, $phone, $email, $role, password_hash($password, PASSWORD_BCRYPT));
            if (!$id) throw new Exception('Не удалось добавить сотрудника');
            $this->logger()->logAction($this->currentUserId(), User::ROLE_ADMIN, 'create_employee', 'user', $id, "Роль $role, email $email");
            return $this->ok(['employee_id' => $id, 'message' => 'Сотрудник добавлен']);
        } catch (Exception $e) {
            $this->logger()->logError('EMPLOYEE_CREATE', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $this->currentUserId());
            return $this->fail('Не удалось добавить сотрудника', 500);
        }
    }

    public function updateEmployee(int $employeeId, string $fullName, string $phone, string $email, string $role, ?string $newPassword = null): array
    {
        $check = $this->requireRole([User::ROLE_ADMIN]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);

        if (!in_array($role, [User::ROLE_MECHANIC, User::ROLE_MASTER, User::ROLE_ADMIN], true)) {
            return $this->fail('Некорректная роль сотрудника');
        }
        if ($this->users()->emailExists($email, $employeeId)) {
            return $this->fail('Пользователь с таким email уже существует');
        }

        try {
            if (!$this->users()->update($employeeId, $fullName, $phone, $email, $role)) {
                throw new Exception('Ошибка обновления данных');
            }
            $this->logger()->logAction($this->currentUserId(), User::ROLE_ADMIN, 'update_employee', 'user', $employeeId, "Роль $role, email $email");
            return $this->ok(['message' => 'Данные сотрудника обновлены']);
        } catch (Exception $e) {
            $this->logger()->logError('EMPLOYEE_UPDATE', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $this->currentUserId());
            return $this->fail('Не удалось обновить данные сотрудника', 500);
        }
    }

    public function setEmployeeActive(int $employeeId, bool $isActive): array
    {
        $check = $this->requireRole([User::ROLE_ADMIN]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);
        try {
            if (!$this->users()->setActive($employeeId, $isActive)) throw new Exception('Ошибка изменения статуса');
            $this->logger()->logAction($this->currentUserId(), User::ROLE_ADMIN, 'set_employee_active', 'user', $employeeId, $isActive ? 'активирован' : 'деактивирован');
            return $this->ok(['message' => 'Статус сотрудника обновлён']);
        } catch (Exception $e) {
            $this->logger()->logError('ACTIVE_CHANGE', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $this->currentUserId());
            return $this->fail('Не удалось изменить статус сотрудника', 500);
        }
    }

    public function getAllPurchaseRequests(): array
    {
        $check = $this->requireRole([User::ROLE_ADMIN]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);
        try {
            return $this->ok(['requests' => $this->purchases()->getAll()]);
        } catch (Exception $e) {
            $this->logger()->logError('DB_ERROR', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $this->currentUserId());
            return $this->fail('Ошибка получения запросов', 500);
        }
    }

    public function decidePurchaseRequest(int $requestId, int $adminId, bool $approve, ?string $comment = null): array
    {
        $check = $this->requireRole([User::ROLE_ADMIN]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);
        try {
            $status = $approve ? PartPurchaseRequest::STATUS_APPROVED : PartPurchaseRequest::STATUS_REJECTED;
            if (!$this->purchases()->decide($requestId, $adminId, $status, $comment)) throw new Exception('Ошибка обработки');
            $this->logger()->logAction($adminId, User::ROLE_ADMIN, 'decide_purchase_request', 'purchase_request', $requestId, $approve ? 'одобрен' : 'отклонён' . ($comment ? ", коммент: $comment" : ''));
            return $this->ok(['message' => 'Запрос на закупку обработан']);
        } catch (Exception $e) {
            $this->logger()->logError('DECIDE_PURCHASE', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $adminId);
            return $this->fail('Не удалось обработать запрос на закупку', 500);
        }
    }

    public function getAllOrders(): array
    {
        $check = $this->requireRole([User::ROLE_ADMIN]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);
        try {
            return $this->ok(['orders' => $this->orders()->getAllOrders()]);
        } catch (Exception $e) {
            $this->logger()->logError('DB_ERROR', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $this->currentUserId());
            return $this->fail('Ошибка получения заявок', 500);
        }
    }

    public function forceOrderStatus(int $orderId, string $newStatus): array
    {
        $check = $this->requireRole([User::ROLE_ADMIN]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);
        if (!Order::isValidStatus($newStatus)) return $this->fail('Недопустимый статус');
        try {
            $order = $this->orders()->findById($orderId);
            if (!$order) return $this->fail('Заявка не найдена', 404);
            if ($order['status'] === $newStatus) return $this->fail('Заявка уже имеет этот статус');
            $this->orders()->updateStatus($orderId, $newStatus);
            $this->history()->log($orderId, $order['status'], $newStatus, $this->currentUserId());
            $this->logger()->logAction($this->currentUserId(), User::ROLE_ADMIN, 'force_order_status', 'order', $orderId, "{$order['status']} → $newStatus");
            return $this->ok(['message' => 'Статус заявки изменён']);
        } catch (Exception $e) {
            $this->logger()->logError('FORCE_STATUS', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $this->currentUserId());
            return $this->fail('Ошибка изменения статуса', 500);
        }
    }

    public function getServices(): array
    {
        $check = $this->requireRole([User::ROLE_ADMIN]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);
        try {
            return $this->ok(['services' => $this->services()->getAll()]);
        } catch (Exception $e) {
            $this->logger()->logError('DB_ERROR', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $this->currentUserId());
            return $this->fail('Ошибка получения услуг', 500);
        }
    }

    public function createService(string $name, float $price): array
    {
        $check = $this->requireRole([User::ROLE_ADMIN]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);
        if ($name === '') return $this->fail('Укажите название услуги');
        if ($price < 0) return $this->fail('Цена не может быть отрицательной');
        if ($this->services()->nameExists($name)) return $this->fail('Услуга с таким названием уже существует');
        try {
            $id = $this->services()->create($name, $price);
            if (!$id) throw new Exception('Ошибка создания');
            $this->logger()->logAction($this->currentUserId(), User::ROLE_ADMIN, 'create_service', 'service', $id, "$name, цена $price");
            return $this->ok(['service_id' => $id, 'message' => 'Услуга добавлена']);
        } catch (Exception $e) {
            $this->logger()->logError('SERVICE_CREATE', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $this->currentUserId());
            return $this->fail('Не удалось создать услугу', 500);
        }
    }

    public function updateService(int $id, string $name, float $price): array
    {
        $check = $this->requireRole([User::ROLE_ADMIN]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);
        if ($name === '') return $this->fail('Укажите название услуги');
        if ($price < 0) return $this->fail('Цена не может быть отрицательной');
        if (!$this->services()->exists($id)) return $this->fail('Услуга не найдена', 404);
        if ($this->services()->nameExists($name, $id)) return $this->fail('Услуга с таким названием уже существует');
        try {
            if (!$this->services()->update($id, $name, $price)) throw new Exception('Ошибка обновления');
            $this->logger()->logAction($this->currentUserId(), User::ROLE_ADMIN, 'update_service', 'service', $id, "$name, цена $price");
            return $this->ok(['message' => 'Услуга обновлена']);
        } catch (Exception $e) {
            $this->logger()->logError('SERVICE_UPDATE', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $this->currentUserId());
            return $this->fail('Не удалось обновить услугу', 500);
        }
    }

    public function deleteService(int $id): array
    {
        $check = $this->requireRole([User::ROLE_ADMIN]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);
        if (!$this->services()->exists($id)) return $this->fail('Услуга не найдена', 404);
        if ($this->services()->isUsedInOrders($id)) return $this->fail('Нельзя удалить услугу: она используется в заявках');
        try {
            if (!$this->services()->delete($id)) throw new Exception('Ошибка удаления');
            $this->logger()->logAction($this->currentUserId(), User::ROLE_ADMIN, 'delete_service', 'service', $id, 'Услуга удалена');
            return $this->ok(['message' => 'Услуга удалена']);
        } catch (Exception $e) {
            $this->logger()->logError('SERVICE_DELETE', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $this->currentUserId());
            return $this->fail('Не удалось удалить услугу', 500);
        }
    }

    public function getParts(): array
    {
        $check = $this->requireRole([User::ROLE_ADMIN]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);
        try {
            return $this->ok(['parts' => $this->parts()->getAll()]);
        } catch (Exception $e) {
            $this->logger()->logError('DB_ERROR', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $this->currentUserId());
            return $this->fail('Ошибка получения запчастей', 500);
        }
    }

    public function createPart(string $name, string $article, float $price): array
    {
        $check = $this->requireRole([User::ROLE_ADMIN]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);
        if ($name === '') return $this->fail('Укажите название запчасти');
        if ($article === '') return $this->fail('Укажите артикул');
        if ($price < 0) return $this->fail('Цена не может быть отрицательной');
        if ($this->parts()->articleExists($article)) return $this->fail('Запчасть с таким артикулом уже существует');
        try {
            $id = $this->parts()->create($name, $article, $price);
            if (!$id) throw new Exception('Ошибка создания');
            $this->logger()->logAction($this->currentUserId(), User::ROLE_ADMIN, 'create_part', 'part', $id, "$name, арт. $article, цена $price");
            return $this->ok(['part_id' => $id, 'message' => 'Запчасть добавлена']);
        } catch (Exception $e) {
            $this->logger()->logError('PART_CREATE', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $this->currentUserId());
            return $this->fail('Не удалось создать запчасть', 500);
        }
    }

    public function updatePart(int $id, string $name, string $article, float $price): array
    {
        $check = $this->requireRole([User::ROLE_ADMIN]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);
        if ($name === '') return $this->fail('Укажите название запчасти');
        if ($article === '') return $this->fail('Укажите артикул');
        if ($price < 0) return $this->fail('Цена не может быть отрицательной');
        if (!$this->parts()->exists($id)) return $this->fail('Запчасть не найдена', 404);
        if ($this->parts()->articleExists($article, $id)) return $this->fail('Запчасть с таким артикулом уже существует');
        try {
            if (!$this->parts()->update($id, $name, $article, $price)) throw new Exception('Ошибка обновления');
            $this->logger()->logAction($this->currentUserId(), User::ROLE_ADMIN, 'update_part', 'part', $id, "$name, арт. $article, цена $price");
            return $this->ok(['message' => 'Запчасть обновлена']);
        } catch (Exception $e) {
            $this->logger()->logError('PART_UPDATE', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $this->currentUserId());
            return $this->fail('Не удалось обновить запчасть', 500);
        }
    }

    public function deletePart(int $id): array
    {
        $check = $this->requireRole([User::ROLE_ADMIN]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);
        if (!$this->parts()->exists($id)) return $this->fail('Запчасть не найдена', 404);
        if ($this->parts()->isUsedInRequests($id)) return $this->fail('Нельзя удалить запчасть: она фигурирует в запросах на закупку');
        try {
            if (!$this->parts()->delete($id)) throw new Exception('Ошибка удаления');
            $this->logger()->logAction($this->currentUserId(), User::ROLE_ADMIN, 'delete_part', 'part', $id, 'Запчасть удалена');
            return $this->ok(['message' => 'Запчасть удалена']);
        } catch (Exception $e) {
            $this->logger()->logError('PART_DELETE', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $this->currentUserId());
            return $this->fail('Не удалось удалить запчасть', 500);
        }
    }

    public function getDashboardStats(): array
    {
        $check = $this->requireRole([User::ROLE_ADMIN]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);

        $cacheDir = __DIR__ . '/../cache/';
        if (!is_dir($cacheDir)) mkdir($cacheDir, 0777, true);
        $cacheFile = $cacheDir . 'dashboard_stats.json';
        $cacheTTL = 300; // 5 минут

        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTTL) {
            $stats = json_decode(file_get_contents($cacheFile), true);
            if ($stats) {
                return $this->ok(['stats' => $stats]);
            }
        }

        try {
            $orderStats = $this->orders()->getStats();
            $stats = [
                'total_orders'      => (int) ($orderStats['total']    ?? 0),
                'new_orders'        => (int) ($orderStats['cnt_new']  ?? 0),
                'active_employees'  => 0,
                'total_clients'     => 0,
                'pending_purchases' => $this->purchases()->countPending(),
            ];
            $res = mysqli_query($this->db, "SELECT COUNT(*) AS c FROM users WHERE role = 'client'");
            $stats['total_clients'] = $res ? (int) $res->fetch_assoc()['c'] : 0;
            $res2 = mysqli_query($this->db, "SELECT COUNT(*) AS c FROM users WHERE role != 'client' AND is_active = 1");
            $stats['active_employees'] = $res2 ? (int) $res2->fetch_assoc()['c'] : 0;

            file_put_contents($cacheFile, json_encode($stats), LOCK_EX);
            return $this->ok(['stats' => $stats]);
        } catch (Exception $e) {
            $this->logger()->logError('STATS_ERROR', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $this->currentUserId());
            return $this->fail('Ошибка получения статистики', 500);
        }
    }

    public function createPurchaseRequest(int $orderId, int $partId, int $quantity, int $adminId, string $comment = ''): array
    {
        $check = $this->requireRole([User::ROLE_ADMIN]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);

        if ($orderId <= 0 || $partId <= 0 || $quantity < 1) {
            return $this->fail('Укажите заявку, запчасть и количество');
        }
        if (!$this->parts()->exists($partId)) {
            return $this->fail('Запчасть не найдена');
        }
        $order = $this->orders()->findById($orderId);
        if (!$order) {
            return $this->fail('Заявка не найдена', 404);
        }
        try {
            $requestId = $this->purchases()->createByAdmin($orderId, $partId, $quantity, $adminId, trim($comment));
            if (!$requestId) throw new Exception('Ошибка создания');
            $this->logger()->logAction($adminId, User::ROLE_ADMIN, 'create_purchase_request', 'purchase_request', $requestId, "Заявка $orderId, запчасть $partId, кол-во $quantity");
            return $this->ok(['request_id' => $requestId, 'message' => 'Закупка создана и сразу одобрена.']);
        } catch (Exception $e) {
            $this->logger()->logError('PURCHASE_CREATE', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $adminId);
            return $this->fail('Не удалось создать запрос на закупку', 500);
        }
    }

    public function getOrdersForPurchaseRequest(): array
    {
        $check = $this->requireRole([User::ROLE_ADMIN]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);
        try {
            return $this->ok(['orders' => $this->orders()->getOrdersAvailableForPurchase()]);
        } catch (Exception $e) {
            $this->logger()->logError('DB_ERROR', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $this->currentUserId());
            return $this->fail('Ошибка получения заявок', 500);
        }
    }

    public function updateProfile(int $adminId, string $fullName, string $phone, string $email, string $newPassword = ''): array
    {
        $check = $this->requireRole([User::ROLE_ADMIN]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);

        if ($this->users()->emailExists($email, $adminId)) {
            return $this->fail('Пользователь с таким email уже существует');
        }
        try {
            $this->users()->updateProfile($adminId, $fullName, $phone, $email);
            $newPassword = trim($newPassword);
            if ($newPassword !== '') {
                if (strlen($newPassword) < 6) return $this->fail('Новый пароль должен быть не короче 6 символов');
                $this->users()->updatePassword($adminId, password_hash($newPassword, PASSWORD_BCRYPT));
            }
            if (session_status() !== PHP_SESSION_ACTIVE) session_start();
            if (!empty($_SESSION['user'])) {
                $_SESSION['user']['full_name'] = $fullName;
                $_SESSION['user']['phone']     = $phone;
                $_SESSION['user']['email']     = $email;
            }
            $this->logger()->logAction($adminId, User::ROLE_ADMIN, 'update_profile', 'user', $adminId, "ФИО: $fullName, email: $email");
            return $this->ok(['message' => 'Данные сохранены']);
        } catch (Exception $e) {
            $this->logger()->logError('PROFILE_UPDATE', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $adminId);
            return $this->fail('Ошибка обновления профиля', 500);
        }
    }
    public function getAllOrdersPaginated(int $limit, int $offset): array
    {
        $check = $this->requireRole([User::ROLE_ADMIN]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);
        try {
            $orders = $this->orders()->getAllOrdersPaginated($limit, $offset);
            return $this->ok(['orders' => $orders]);
        } catch (Exception $e) {
            $this->logger()->logError('DB_ERROR', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $this->currentUserId());
            return $this->fail('Ошибка получения заявок', 500);
        }
    }

    public function getOrdersCount(): array
    {
        $check = $this->requireRole([User::ROLE_ADMIN]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);
        try {
            $count = $this->orders()->getOrdersCount();
            return $this->ok(['total' => $count]);
        } catch (Exception $e) {
            $this->logger()->logError('DB_ERROR', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $this->currentUserId());
            return $this->fail('Ошибка подсчёта заявок', 500);
        }
    }
}