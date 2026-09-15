<?php
require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Order.php';
require_once __DIR__ . '/../models/PartPurchaseRequest.php';

class MasterController extends BaseController
{
    public function getNewOrders(): array
    {
        $check = $this->requireRole([User::ROLE_MASTER, User::ROLE_ADMIN]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);
        try {
            return $this->ok(['orders' => $this->orders()->getNewOrdersForMaster($this->currentUserId())]);
        } catch (Exception $e) {
            $this->logger()->logError('DB_ERROR', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $this->currentUserId());
            return $this->fail('Ошибка получения новых заявок', 500);
        }
    }

    public function getAllOrders(): array
    {
        $check = $this->requireRole([User::ROLE_MASTER, User::ROLE_ADMIN]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);
        try {
            return $this->ok(['orders' => $this->orders()->getAllOrders()]);
        } catch (Exception $e) {
            $this->logger()->logError('DB_ERROR', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $this->currentUserId());
            return $this->fail('Ошибка получения заявок', 500);
        }
    }

    public function getMechanics(): array
    {
        $check = $this->requireRole([User::ROLE_MASTER, User::ROLE_ADMIN]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);
        try {
            return $this->ok(['mechanics' => $this->users()->getMechanics()]);
        } catch (Exception $e) {
            $this->logger()->logError('DB_ERROR', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $this->currentUserId());
            return $this->fail('Ошибка получения списка механиков', 500);
        }
    }

    public function getMechanicsWithLoad(): array
    {
        $check = $this->requireRole([User::ROLE_MASTER, User::ROLE_ADMIN]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);

        $cacheDir = __DIR__ . '/../cache/';
        if (!is_dir($cacheDir)) mkdir($cacheDir, 0777, true);
        $cacheFile = $cacheDir . 'mechanics_with_load.json';
        $cacheTTL = 300;

        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTTL) {
            $mechanics = json_decode(file_get_contents($cacheFile), true);
            if ($mechanics) {
                return $this->ok(['mechanics' => $mechanics]);
            }
        }

        try {
            $mechanics = $this->users()->getMechanicsWithLoad();
            file_put_contents($cacheFile, json_encode($mechanics), LOCK_EX);
            return $this->ok(['mechanics' => $mechanics]);
        } catch (Exception $e) {
            $this->logger()->logError('DB_ERROR', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $this->currentUserId());
            return $this->fail('Ошибка получения списка механиков', 500);
        }
    }

    public function getParts(): array
    {
        $check = $this->requireRole([User::ROLE_MASTER, User::ROLE_ADMIN]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);
        try {
            return $this->ok(['parts' => $this->parts()->getAll()]);
        } catch (Exception $e) {
            $this->logger()->logError('DB_ERROR', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $this->currentUserId());
            return $this->fail('Ошибка получения справочника запчастей', 500);
        }
    }

    public function getServices(): array
    {
        $check = $this->requireRole([User::ROLE_MASTER, User::ROLE_ADMIN]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);
        try {
            return $this->ok(['services' => $this->services()->getAll()]);
        } catch (Exception $e) {
            $this->logger()->logError('DB_ERROR', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $this->currentUserId());
            return $this->fail('Ошибка получения справочника услуг', 500);
        }
    }

    public function getOrdersForPartRequest(): array
    {
        $check = $this->requireRole([User::ROLE_MASTER, User::ROLE_ADMIN]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);
        try {
            return $this->ok(['orders' => $this->orders()->getOrdersAvailableForPurchase()]);
        } catch (Exception $e) {
            $this->logger()->logError('DB_ERROR', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $this->currentUserId());
            return $this->fail('Ошибка получения заявок для закупки', 500);
        }
    }

    public function assignMechanic(int $orderId, int $mechanicId, int $masterId, ?string $comment = null, array $orderServices = []): array
    {
        $check = $this->requireRole([User::ROLE_MASTER, User::ROLE_ADMIN]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);

        if (!$this->users()->isActiveMechanic($mechanicId)) {
            $this->logger()->logError('VALIDATION', "Попытка назначить неактивного механика $mechanicId", null, null, null, $masterId);
            return $this->fail('Механик не найден или недоступен');
        }

        $normalizedServices = $this->normalizeServices($orderServices);
        foreach ($normalizedServices as $svc) {
            if (!$this->services()->exists($svc['service_id'])) {
                $this->logger()->logError('VALIDATION', "Услуга {$svc['service_id']} не найдена", null, null, null, $masterId);
                return $this->fail('Одна из выбранных услуг не найдена в справочнике');
            }
        }

        mysqli_begin_transaction($this->db);
        try {
            $affected = $this->orders()->assignMechanicToOrder($orderId, $mechanicId, $masterId);
            if ($affected < 1) throw new Exception('Заявка не найдена или уже принята другим мастером');

            $commentStr = trim((string) ($comment ?? ''));
            if (!$this->assignments()->create($orderId, $mechanicId, $masterId, $commentStr)) {
                throw new Exception('Не удалось сохранить назначение');
            }

            foreach ($normalizedServices as $svc) {
                if (!$this->orders()->addService($orderId, $svc['service_id'], $svc['quantity'])) {
                    throw new Exception('Не удалось привязать услуги к заявке');
                }
            }

            if (!empty($normalizedServices)) $this->orders()->recalculateTotal($orderId);
            mysqli_commit($this->db);

            $this->history()->log($orderId, Order::STATUS_NEW, Order::STATUS_ASSIGNED, $masterId);
            $this->logger()->logAction($masterId, User::ROLE_MASTER, 'assign_mechanic', 'order', $orderId, "Механик ID $mechanicId, услуг: " . count($normalizedServices));
            return $this->ok(['message' => empty($normalizedServices) ? 'Механик назначен' : 'Механик назначен, услуги добавлены']);
        } catch (Exception $e) {
            mysqli_rollback($this->db);
            $this->logger()->logError('ASSIGN_ERROR', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $masterId);
            return $this->fail($e->getMessage(), 500);
        }
    }

    public function reassignMechanic(int $orderId, int $newMechanicId, int $masterId, string $comment = ''): array
    {
        $check = $this->requireRole([User::ROLE_MASTER, User::ROLE_ADMIN]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);

        $order = $this->orders()->findById($orderId);
        if (!$order) return $this->fail('Заявка не найдена', 404);
        if (in_array($order['status'], [Order::STATUS_COMPLETED, Order::STATUS_CANCELLED], true)) {
            return $this->fail('Нельзя переназначить механика на завершённую или отменённую заявку');
        }
        if (!(int) $order['mechanic_id']) {
            return $this->fail('На эту заявку ещё не назначен механик. Используйте страницу новых заявок.');
        }
        if ((int) $order['mechanic_id'] === $newMechanicId) {
            return $this->fail('Этот механик уже назначен на заявку');
        }
        if (!$this->users()->isActiveMechanic($newMechanicId)) {
            return $this->fail('Механик не найден или недоступен');
        }

        $oldMechanicId = (int) $order['mechanic_id'];

        try {
            mysqli_begin_transaction($this->db);

            $this->execute(
                "UPDATE mechanic_assignments SET status = 'reassigned' WHERE order_id = ? AND mechanic_id = ? AND status = 'assigned'",
                'ii', [$orderId, $oldMechanicId]
            );

            $affected = $this->orders()->reassignMechanic($orderId, $newMechanicId);
            if ($affected < 1) throw new Exception('Не удалось переназначить механика');

            $this->assignments()->create($orderId, $newMechanicId, $masterId, trim($comment));

            $note = "Переназначен с механика ID $oldMechanicId на механика ID $newMechanicId";
            if (!empty(trim($comment))) $note .= " (комментарий: " . trim($comment) . ")";
            $this->history()->log($orderId, $order['status'], $order['status'], $masterId, $note);

            mysqli_commit($this->db);

            $this->logger()->logAction($masterId, User::ROLE_MASTER, 'reassign_mechanic', 'order', $orderId, "Старый механик $oldMechanicId → новый $newMechanicId");
            return $this->ok(['message' => 'Механик переназначен.']);
        } catch (Exception $e) {
            mysqli_rollback($this->db);
            $this->logger()->logError('REASSIGN_ERROR', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $masterId);
            return $this->fail('Не удалось переназначить механика', 500);
        }
    }

    public function cancelOrder(int $orderId, int $masterId, string $comment): array
    {
        $check = $this->requireRole([User::ROLE_MASTER, User::ROLE_ADMIN]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);

        $order = $this->orders()->findById($orderId);
        if (!$order) return $this->fail('Заявка не найдена', 404);
        if ((int) $order['master_id'] !== $masterId) return $this->fail('Эта заявка не закреплена за вами');
        if ($order['status'] !== Order::STATUS_NEW) return $this->fail('Отменить можно только новую заявку, которой ещё не назначен механик');

        $comment = trim($comment);
        if ($comment === '') return $this->fail('Укажите причину отмены — клиент её увидит');
        try {
            $affected = $this->orders()->cancelByMaster($orderId, $masterId, $comment);
            if ($affected < 1) throw new Exception('Не удалось отменить заявку');
            $this->history()->log($orderId, Order::STATUS_NEW, Order::STATUS_CANCELLED, $masterId, $comment);
            $this->logger()->logAction($masterId, User::ROLE_MASTER, 'cancel_order', 'order', $orderId, $comment);
            return $this->ok(['message' => 'Заявка отменена.']);
        } catch (Exception $e) {
            $this->logger()->logError('CANCEL_ORDER', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $masterId);
            return $this->fail('Не удалось отменить заявку', 500);
        }
    }

    public function createPartPurchaseRequest(int $orderId, int $partId, int $quantity, int $masterId, ?string $comment = null): array
    {
        $check = $this->requireRole([User::ROLE_MASTER, User::ROLE_ADMIN]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);

        if ($orderId <= 0 || $partId <= 0 || $quantity < 1) return $this->fail('Укажите заявку, запчасть и количество');
        $order = $this->orders()->findById($orderId);
        if (!$order) return $this->fail('Заявка не найдена');
        $allowedStatuses = [Order::STATUS_ASSIGNED, Order::STATUS_IN_PROGRESS, Order::STATUS_WAITING_PARTS];
        if (!in_array($order['status'], $allowedStatuses, true)) {
            return $this->fail('Для этой заявки нельзя оформить запрос на закупку (механик должен быть назначен).');
        }
        if (!$this->parts()->exists($partId)) return $this->fail('Запчасть не найдена');

        try {
            $requestId = $this->purchases()->create($orderId, $partId, $quantity, $masterId, trim((string) ($comment ?? '')));
            if (!$requestId) throw new Exception('Не удалось создать запрос');
            if ($order['status'] !== Order::STATUS_WAITING_PARTS) {
                $this->orders()->setWaitingParts($orderId);
                $this->history()->log($orderId, $order['status'], Order::STATUS_WAITING_PARTS, $masterId);
            } else {
                $this->orders()->setWaitingParts($orderId);
            }
            $this->logger()->logAction($masterId, User::ROLE_MASTER, 'create_purchase_request', 'purchase_request', $requestId, "Заявка $orderId, запчасть $partId, кол-во $quantity");
            return $this->ok(['request_id' => $requestId, 'message' => 'Запрос на закупку создан']);
        } catch (Exception $e) {
            $this->logger()->logError('PURCHASE_CREATE', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $masterId);
            return $this->fail('Не удалось создать запрос на закупку', 500);
        }
    }

    public function getMyPurchaseRequests(int $masterId): array
    {
        $check = $this->requireRole([User::ROLE_MASTER, User::ROLE_ADMIN]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);
        try {
            return $this->ok(['requests' => $this->purchases()->getAllByMaster($masterId)]);
        } catch (Exception $e) {
            $this->logger()->logError('DB_ERROR', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $masterId);
            return $this->fail('Ошибка получения запросов', 500);
        }
    }

    public function getPendingPurchaseRequests(): array
    {
        $check = $this->requireRole([User::ROLE_MASTER, User::ROLE_ADMIN]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);
        try {
            return $this->ok(['requests' => $this->purchases()->getPending()]);
        } catch (Exception $e) {
            $this->logger()->logError('DB_ERROR', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $this->currentUserId());
            return $this->fail('Ошибка получения ожидающих запросов', 500);
        }
    }

    public function cancelPurchaseRequest(int $requestId, int $masterId): array
    {
        $check = $this->requireRole([User::ROLE_MASTER, User::ROLE_ADMIN]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);

        try {
            $orderId = $this->purchases()->deleteIfPendingByMaster($requestId, $masterId);
            if ($orderId === null) {
                return $this->fail('Запрос не найден, не принадлежит вам или уже обработан администратором');
            }
            if (!$this->purchases()->hasPendingForOrder($orderId)) {
                $this->orders()->updateStatus($orderId, Order::STATUS_IN_PROGRESS);
                $this->history()->log($orderId, Order::STATUS_WAITING_PARTS, Order::STATUS_IN_PROGRESS, $masterId);
            }
            $this->logger()->logAction($masterId, User::ROLE_MASTER, 'cancel_purchase_request', 'purchase_request', $requestId, 'Отменён');
            return $this->ok(['message' => 'Запрос на закупку отменён. Заявка возвращена в работу.']);
        } catch (Exception $e) {
            $this->logger()->logError('PURCHASE_CANCEL', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $masterId);
            return $this->fail('Ошибка отмены запроса', 500);
        }
    }

    public function updateProfile(int $masterId, string $fullName, string $phone, string $email, string $newPassword = ''): array
    {
        $check = $this->requireRole([User::ROLE_MASTER, User::ROLE_ADMIN]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);

        if ($this->users()->emailExists($email, $masterId)) {
            return $this->fail('Пользователь с таким email уже существует');
        }
        try {
            $this->users()->updateProfile($masterId, $fullName, $phone, $email);
            $newPassword = trim($newPassword);
            if ($newPassword !== '') {
                if (strlen($newPassword) < 6) return $this->fail('Новый пароль должен быть не короче 6 символов');
                $this->users()->updatePassword($masterId, password_hash($newPassword, PASSWORD_BCRYPT));
            }
            if (session_status() !== PHP_SESSION_ACTIVE) session_start();
            if (!empty($_SESSION['user'])) {
                $_SESSION['user']['full_name'] = $fullName;
                $_SESSION['user']['phone']     = $phone;
                $_SESSION['user']['email']     = $email;
            }
            $this->logger()->logAction($masterId, User::ROLE_MASTER, 'update_profile', 'user', $masterId, "ФИО: $fullName, email: $email");
            return $this->ok(['message' => 'Данные сохранены']);
        } catch (Exception $e) {
            $this->logger()->logError('PROFILE_UPDATE', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $masterId);
            return $this->fail('Ошибка обновления профиля', 500);
        }
    }

    private function normalizeServices(array $raw): array
    {
        $result = [];
        foreach ($raw as $row) {
            $sid = (int) ($row['service_id'] ?? 0);
            if ($sid > 0) $result[] = ['service_id' => $sid, 'quantity' => max(1, (int) ($row['quantity'] ?? 1))];
        }
        return $result;
    }
}