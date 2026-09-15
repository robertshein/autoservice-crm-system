<?php
require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Order.php';

class MechanicController extends BaseController
{
    public function getMyOrders(int $mechanicId): array
    {
        $check = $this->requireRole([User::ROLE_MECHANIC, User::ROLE_MASTER, User::ROLE_ADMIN]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);
        try {
            return $this->ok(['orders' => $this->orders()->getOrdersByMechanic($mechanicId, false)]);
        } catch (Exception $e) {
            $this->logger()->logError('DB_ERROR', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $mechanicId);
            return $this->fail('Ошибка получения заявок', 500);
        }
    }

    public function getMyArchivedOrders(int $mechanicId): array
    {
        $check = $this->requireRole([User::ROLE_MECHANIC, User::ROLE_MASTER, User::ROLE_ADMIN]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);
        try {
            return $this->ok(['orders' => $this->orders()->getOrdersByMechanic($mechanicId, true)]);
        } catch (Exception $e) {
            $this->logger()->logError('DB_ERROR', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $mechanicId);
            return $this->fail('Ошибка получения архива', 500);
        }
    }

    public function updateOrderStatus(int $orderId, int $mechanicId, string $newStatus, string $partsComment = ''): array
    {
        $check = $this->requireRole([User::ROLE_MECHANIC, User::ROLE_MASTER, User::ROLE_ADMIN]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);

        $allowed = [Order::STATUS_IN_PROGRESS, Order::STATUS_WAITING_PARTS, Order::STATUS_COMPLETED, Order::STATUS_CANCELLED];
        if (!in_array($newStatus, $allowed, true)) return $this->fail('Механик не может установить этот статус');
        if ($orderId <= 0 || $mechanicId <= 0) return $this->fail('Некорректные параметры');

        $order = $this->orders()->findById($orderId);
        if (!$order || (int) $order['mechanic_id'] !== $mechanicId) return $this->fail('Заявка не найдена или не назначена вам', 404);

        $current = $order['status'];
        if (in_array($current, [Order::STATUS_COMPLETED, Order::STATUS_CANCELLED], true)) {
            return $this->fail('Статус завершённой или отменённой заявки менять нельзя');
        }

        $transitions = [
            Order::STATUS_ASSIGNED      => [Order::STATUS_IN_PROGRESS],
            Order::STATUS_IN_PROGRESS   => [Order::STATUS_WAITING_PARTS, Order::STATUS_COMPLETED, Order::STATUS_CANCELLED],
            Order::STATUS_WAITING_PARTS => [Order::STATUS_IN_PROGRESS,   Order::STATUS_COMPLETED, Order::STATUS_CANCELLED],
        ];
        if (!in_array($newStatus, $transitions[$current] ?? [], true)) {
            return $this->fail("Переход из «{$current}» в «{$newStatus}» недопустим");
        }

        try {
            if ($this->orders()->updateStatus($orderId, $newStatus, $mechanicId) < 1) throw new Exception('Статус не изменён');
            if ($newStatus === Order::STATUS_WAITING_PARTS) {
                $this->orders()->updatePartsComment($orderId, trim($partsComment));
            }
            $this->history()->log($orderId, $current, $newStatus, $mechanicId);
            $this->logger()->logAction($mechanicId, User::ROLE_MECHANIC, 'change_order_status', 'order', $orderId, "$current → $newStatus" . ($partsComment ? ", коммент: $partsComment" : ''));
            return $this->ok(['message' => 'Статус заявки обновлён']);
        } catch (Exception $e) {
            $this->logger()->logError('STATUS_UPDATE', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $mechanicId);
            return $this->fail('Статус не изменён', 500);
        }
    }

    public function getServices(): array
    {
        $check = $this->requireRole([User::ROLE_MECHANIC, User::ROLE_MASTER, User::ROLE_ADMIN]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);
        try {
            return $this->ok(['services' => $this->services()->getAll()]);
        } catch (Exception $e) {
            $this->logger()->logError('DB_ERROR', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $this->currentUserId());
            return $this->fail('Ошибка получения услуг', 500);
        }
    }

    public function getParts(): array
    {
        $check = $this->requireRole([User::ROLE_MECHANIC, User::ROLE_MASTER, User::ROLE_ADMIN]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);
        try {
            return $this->ok(['parts' => $this->parts()->getAll()]);
        } catch (Exception $e) {
            $this->logger()->logError('DB_ERROR', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $this->currentUserId());
            return $this->fail('Ошибка получения запчастей', 500);
        }
    }

    public function getOrderParts(int $orderId, int $mechanicId): array
    {
        $check = $this->requireRole([User::ROLE_MECHANIC, User::ROLE_MASTER, User::ROLE_ADMIN]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);
        $order = $this->orders()->findById($orderId);
        if (!$order || (int) $order['mechanic_id'] !== $mechanicId) return $this->fail('Заявка не найдена или не назначена вам', 404);
        try {
            return $this->ok(['parts' => $this->orders()->getPartsForOrder($orderId)]);
        } catch (Exception $e) {
            $this->logger()->logError('DB_ERROR', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $mechanicId);
            return $this->fail('Ошибка получения запчастей заявки', 500);
        }
    }

    public function addPartToOrder(int $orderId, int $mechanicId, int $partId, int $quantity, string $note = ''): array
    {
        $check = $this->requireRole([User::ROLE_MECHANIC, User::ROLE_MASTER, User::ROLE_ADMIN]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);

        if ($orderId <= 0 || $mechanicId <= 0 || $partId <= 0) return $this->fail('Некорректные параметры');
        $quantity = max(1, $quantity);
        $order = $this->orders()->findById($orderId);
        if (!$order || (int) $order['mechanic_id'] !== $mechanicId) return $this->fail('Заявка не найдена или не назначена вам');
        if (in_array($order['status'], [Order::STATUS_COMPLETED, Order::STATUS_CANCELLED], true)) {
            return $this->fail('Нельзя добавлять запчасти в завершённую или отменённую заявку');
        }
        if (!$this->parts()->exists($partId)) return $this->fail('Запчасть не найдена');

        try {
            $rowId = $this->orders()->addPart($orderId, $partId, $quantity, trim($note));
            if (!$rowId) throw new Exception('Ошибка добавления');
            $this->logger()->logAction($mechanicId, User::ROLE_MECHANIC, 'add_part', 'order_part', $rowId, "Заявка $orderId, запчасть $partId, кол-во $quantity");
            return $this->ok(['message' => 'Запчасть добавлена в заявку', 'row_id' => $rowId]);
        } catch (Exception $e) {
            $this->logger()->logError('PART_ADD', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $mechanicId);
            return $this->fail('Не удалось добавить запчасть в заявку', 500);
        }
    }

    public function getMyPurchaseRequests(int $mechanicId): array
    {
        $check = $this->requireRole([User::ROLE_MECHANIC, User::ROLE_MASTER, User::ROLE_ADMIN]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);
        try {
            return $this->ok(['requests' => $this->purchases()->getAllByMechanic($mechanicId)]);
        } catch (Exception $e) {
            $this->logger()->logError('DB_ERROR', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $mechanicId);
            return $this->fail('Ошибка получения запросов', 500);
        }
    }

    public function createPartPurchaseRequest(int $orderId, int $mechanicId, int $partId, int $quantity, string $comment = ''): array
    {
        $check = $this->requireRole([User::ROLE_MECHANIC, User::ROLE_MASTER, User::ROLE_ADMIN]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);

        if ($orderId <= 0 || $partId <= 0 || $quantity < 1) {
            return $this->fail('Укажите заявку, запчасть и количество');
        }

        $order = $this->orders()->findById($orderId);
        if (!$order || (int) $order['mechanic_id'] !== $mechanicId) {
            return $this->fail('Заявка не найдена или не назначена вам');
        }

        $allowed = [Order::STATUS_ASSIGNED, Order::STATUS_IN_PROGRESS, Order::STATUS_WAITING_PARTS];
        if (!in_array($order['status'], $allowed, true)) {
            return $this->fail('Запрос на закупку можно создать только для активной заявки с назначенным механиком');
        }

        if (!$this->parts()->exists($partId)) {
            return $this->fail('Запчасть не найдена');
        }

        try {
            $requestId = $this->purchases()->createByMechanic($orderId, $partId, $quantity, $mechanicId, trim($comment));
            if (!$requestId) throw new Exception('Ошибка создания');

            if ($order['status'] !== Order::STATUS_WAITING_PARTS) {
                $this->orders()->setWaitingParts($orderId);
                $this->history()->log($orderId, $order['status'], Order::STATUS_WAITING_PARTS, $mechanicId, 'Создан запрос на закупку запчастей');
            } else {
                $this->orders()->updatePartsComment($orderId, trim($comment));
            }

            $this->logger()->logAction($mechanicId, User::ROLE_MECHANIC, 'create_purchase_request', 'purchase_request', $requestId, "Заявка $orderId, запчасть $partId, кол-во $quantity");
            return $this->ok(['request_id' => $requestId, 'message' => 'Запрос на закупку отправлен администратору. Заявка переведена в статус "Ожидание запчастей".']);
        } catch (Exception $e) {
            $this->logger()->logError('PURCHASE_CREATE', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $mechanicId);
            return $this->fail('Не удалось создать запрос на закупку', 500);
        }
    }

    public function cancelPurchaseRequest(int $requestId, int $mechanicId): array
    {
        $check = $this->requireRole([User::ROLE_MECHANIC, User::ROLE_MASTER, User::ROLE_ADMIN]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);
        try {
            if (!$this->purchases()->deleteIfPendingByMechanic($requestId, $mechanicId)) {
                return $this->fail('Запрос не найден, не принадлежит вам или уже обработан администратором');
            }
            $this->logger()->logAction($mechanicId, User::ROLE_MECHANIC, 'cancel_purchase_request', 'purchase_request', $requestId, 'Отменён');
            return $this->ok(['message' => 'Запрос на закупку отменён.']);
        } catch (Exception $e) {
            $this->logger()->logError('PURCHASE_CANCEL', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $mechanicId);
            return $this->fail('Ошибка отмены запроса', 500);
        }
    }

    public function removePartFromOrder(int $orderId, int $mechanicId, int $rowId): array
    {
        $check = $this->requireRole([User::ROLE_MECHANIC, User::ROLE_MASTER, User::ROLE_ADMIN]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);

        $order = $this->orders()->findById($orderId);
        if (!$order || (int) $order['mechanic_id'] !== $mechanicId) return $this->fail('Заявка не найдена или не назначена вам');
        if (in_array($order['status'], [Order::STATUS_COMPLETED, Order::STATUS_CANCELLED], true)) {
            return $this->fail('Нельзя удалять запчасти из завершённой или отменённой заявки');
        }
        try {
            if (!$this->orders()->removePart($rowId, $orderId)) throw new Exception('Ошибка удаления');
            $this->logger()->logAction($mechanicId, User::ROLE_MECHANIC, 'remove_part', 'order_part', $rowId, "Из заявки $orderId");
            return $this->ok(['message' => 'Запчасть удалена из заявки']);
        } catch (Exception $e) {
            $this->logger()->logError('PART_REMOVE', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $mechanicId);
            return $this->fail('Не удалось удалить запчасть', 500);
        }
    }

    public function addServiceToOrder(int $orderId, int $mechanicId, int $serviceId, int $quantity = 1, string $comment = ''): array
    {
        $check = $this->requireRole([User::ROLE_MECHANIC, User::ROLE_MASTER, User::ROLE_ADMIN]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);

        if ($orderId <= 0 || $mechanicId <= 0 || $serviceId <= 0) return $this->fail('Некорректные параметры');
        $quantity = max(1, $quantity);
        $order = $this->orders()->findById($orderId);
        if (!$order || (int) $order['mechanic_id'] !== $mechanicId) return $this->fail('Заявка не найдена или не назначена вам');
        if (in_array($order['status'], [Order::STATUS_COMPLETED, Order::STATUS_CANCELLED], true)) {
            return $this->fail('Нельзя добавлять услуги в завершённую или отменённую заявку');
        }
        if (!$this->services()->exists($serviceId)) return $this->fail('Услуга не найдена');

        mysqli_begin_transaction($this->db);
        try {
            if (!$this->orders()->upsertService($orderId, $serviceId, $quantity, trim($comment))) throw new Exception('Не удалось добавить услугу');
            if (!$this->orders()->recalculateTotal($orderId)) throw new Exception('Не удалось пересчитать сумму');
            mysqli_commit($this->db);
            $this->logger()->logAction($mechanicId, User::ROLE_MECHANIC, 'add_service', 'order', $orderId, "Услуга $serviceId, кол-во $quantity");
            return $this->ok(['message' => 'Услуга добавлена в заявку']);
        } catch (Exception $e) {
            mysqli_rollback($this->db);
            $this->logger()->logError('SERVICE_ADD', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $mechanicId);
            return $this->fail('Не удалось добавить услугу', 500);
        }
    }

    public function updateProfile(int $mechanicId, string $fullName, string $phone, string $email, string $newPassword = ''): array
    {
        $check = $this->requireRole([User::ROLE_MECHANIC, User::ROLE_MASTER, User::ROLE_ADMIN]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);

        if ($this->users()->emailExists($email, $mechanicId)) {
            return $this->fail('Пользователь с таким email уже существует');
        }
        try {
            $this->users()->updateProfile($mechanicId, $fullName, $phone, $email);
            $newPassword = trim($newPassword);
            if ($newPassword !== '') {
                if (strlen($newPassword) < 6) return $this->fail('Новый пароль должен быть не короче 6 символов');
                $this->users()->updatePassword($mechanicId, password_hash($newPassword, PASSWORD_BCRYPT));
            }
            if (session_status() !== PHP_SESSION_ACTIVE) session_start();
            if (!empty($_SESSION['user'])) {
                $_SESSION['user']['full_name'] = $fullName;
                $_SESSION['user']['phone']     = $phone;
                $_SESSION['user']['email']     = $email;
            }
            $this->logger()->logAction($mechanicId, User::ROLE_MECHANIC, 'update_profile', 'user', $mechanicId, "ФИО: $fullName, email: $email");
            return $this->ok(['message' => 'Данные сохранены']);
        } catch (Exception $e) {
            $this->logger()->logError('PROFILE_UPDATE', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $mechanicId);
            return $this->fail('Ошибка обновления профиля', 500);
        }
    }
    public function getOrderServices(int $orderId, int $mechanicId): array
    {
        $check = $this->requireRole([User::ROLE_MECHANIC, User::ROLE_MASTER, User::ROLE_ADMIN]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);

        $order = $this->orders()->findById($orderId);
        if (!$order || (int) $order['mechanic_id'] !== $mechanicId) {
            return $this->fail('Заявка не найдена или не назначена вам', 404);
        }
        try {
            $services = $this->orders()->getServicesForOrder($orderId);
            return $this->ok(['services' => $services]);
        } catch (Exception $e) {
            $this->logger()->logError('DB_ERROR', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $mechanicId);
            return $this->fail('Ошибка получения услуг заявки', 500);
        }
    }
    public function removeServiceFromOrder(int $orderId, int $mechanicId, int $serviceRowId): array
    {
        $check = $this->requireRole([User::ROLE_MECHANIC, User::ROLE_MASTER, User::ROLE_ADMIN]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);

        $order = $this->orders()->findById($orderId);
        if (!$order || (int) $order['mechanic_id'] !== $mechanicId) {
            return $this->fail('Заявка не найдена или не назначена вам', 404);
        }
        if (in_array($order['status'], [Order::STATUS_COMPLETED, Order::STATUS_CANCELLED], true)) {
            return $this->fail('Нельзя удалять услуги из завершённой или отменённой заявки');
        }

        mysqli_begin_transaction($this->db);
        try {
            if (!$this->orders()->removeService($serviceRowId, $orderId)) {
                throw new Exception('Ошибка удаления услуги');
            }
            $this->orders()->recalculateTotal($orderId);
            mysqli_commit($this->db);
            $this->logger()->logAction($mechanicId, User::ROLE_MECHANIC, 'remove_service', 'order_service', $serviceRowId, "Из заявки $orderId");
            return $this->ok(['message' => 'Услуга удалена']);
        } catch (Exception $e) {
            mysqli_rollback($this->db);
            $this->logger()->logError('SERVICE_REMOVE', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $mechanicId);
            return $this->fail('Не удалось удалить услугу', 500);
        }
    }
}