<?php
require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Order.php';

class ClientController extends BaseController
{
    public function createOrder(int $clientId, int $carId, string $description, array $services = []): array
    {
        $check = $this->requireRole([User::ROLE_CLIENT, User::ROLE_ADMIN]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);

        if ($this->orders()->carHasActiveOrder($carId)) {
            $this->logger()->logError('VALIDATION', "Попытка создания заявки на автомобиль $carId с активной заявкой", null, null, null, $clientId);
            return $this->fail('Этот автомобиль уже указан в активной заявке. Дождитесь её завершения или отмены.');
        }

        $master   = $this->users()->getLeastBusyMaster();
        $masterId = $master ? (int) $master['id'] : null;

        mysqli_begin_transaction($this->db);
        try {
            $orderId = $this->orders()->create($clientId, $carId, $description, $masterId);
            if (!$orderId) throw new Exception('Не удалось создать заявку');

            foreach ($services as $svc) {
                $svcId    = (int) ($svc['service_id'] ?? 0);
                $quantity = max(1, (int) ($svc['quantity'] ?? 1));
                if ($svcId <= 0) continue;
                if (!$this->orders()->addService($orderId, $svcId, $quantity, (string) ($svc['comment'] ?? ''))) {
                    throw new Exception('Не удалось добавить услуги к заявке');
                }
            }

            if (!empty($services)) $this->orders()->recalculateTotal($orderId);
            mysqli_commit($this->db);

            $this->history()->log($orderId, null, Order::STATUS_NEW, $clientId);
            $this->logger()->logAction($clientId, User::ROLE_CLIENT, 'create_order', 'order', $orderId, "Авто ID $carId, мастер ID " . ($masterId ?? 'null') . ", услуг: " . count($services));
            return $this->ok([
                'order_id'    => $orderId,
                'master_id'   => $masterId,
                'master_name' => $master['full_name'] ?? null,
                'message'     => 'Заявка создана' . ($masterId ? ' и назначена мастеру' : ''),
            ]);
        } catch (Exception $e) {
            mysqli_rollback($this->db);
            $this->logger()->logError('ORDER_CREATE', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $clientId);
            return $this->fail($e->getMessage(), 500);
        }
    }

    public function getClientOrders(int $clientId): array
    {
        $check = $this->requireRole([User::ROLE_CLIENT, User::ROLE_ADMIN, User::ROLE_MASTER]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);
        try {
            return $this->ok(['orders' => $this->orders()->getOrdersByClient($clientId)]);
        } catch (Exception $e) {
            $this->logger()->logError('DB_ERROR', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $clientId);
            return $this->fail('Ошибка получения заявок', 500);
        }
    }

    public function updateClientOrderDescription(int $clientId, int $orderId, string $description): array
    {
        $check = $this->requireRole([User::ROLE_CLIENT]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);
        $description = trim($description);
        if ($description === '') return $this->fail('Укажите описание заявки.');
        try {
            $affected = $this->orders()->updateDescription($orderId, $clientId, $description);
            if ($affected < 1) return $this->fail('Изменить описание можно только у новой заявки, которую мастер ещё не принял.');
            $this->logger()->logAction($clientId, User::ROLE_CLIENT, 'update_order_description', 'order', $orderId, "Новое описание: $description");
            return $this->ok(['message' => 'Описание заявки обновлено.']);
        } catch (Exception $e) {
            $this->logger()->logError('UPDATE_ERROR', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $clientId);
            return $this->fail('Ошибка обновления описания', 500);
        }
    }

    public function getClientCars(int $clientId): array
    {
        $check = $this->requireRole([User::ROLE_CLIENT, User::ROLE_ADMIN, User::ROLE_MASTER]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);
        try {
            return $this->ok(['cars' => $this->cars()->getByClient($clientId)]);
        } catch (Exception $e) {
            $this->logger()->logError('DB_ERROR', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $clientId);
            return $this->fail('Ошибка получения автомобилей', 500);
        }
    }

    public function addClientCar(int $clientId, string $vin, string $brand, string $model, int $year, string $gosnumber): array
    {
        $check = $this->requireRole([User::ROLE_CLIENT, User::ROLE_ADMIN]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);
        if ($this->cars()->vinExists($vin)) return $this->fail('Автомобиль с таким VIN уже зарегистрирован');
        try {
            $carId = $this->cars()->create($clientId, $vin, $brand, $model, $year, $gosnumber);
            if (!$carId) throw new Exception('Не удалось добавить автомобиль');
            $this->logger()->logAction($clientId, User::ROLE_CLIENT, 'add_car', 'car', $carId, "VIN: $vin, $brand $model");
            return $this->ok(['car_id' => $carId, 'message' => 'Автомобиль добавлен']);
        } catch (Exception $e) {
            $this->logger()->logError('CAR_ADD', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $clientId);
            return $this->fail('Не удалось добавить автомобиль', 500);
        }
    }

    public function updateClientCar(int $clientId, int $carId, string $vin, string $brand, string $model, int $year, string $gosnumber): array
    {
        $check = $this->requireRole([User::ROLE_CLIENT]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);
        if (!$this->cars()->belongsToClient($carId, $clientId)) return $this->fail('Автомобиль не найден.');
        if ($this->orders()->carHasActiveOrder($carId)) return $this->fail('Нельзя редактировать автомобиль, пока он участвует в активной заявке.');
        if ($this->cars()->vinExists($vin, $carId)) return $this->fail('Автомобиль с таким VIN уже зарегистрирован.');
        try {
            if (!$this->cars()->update($carId, $vin, $brand, $model, $year, $gosnumber)) throw new Exception('Ошибка обновления');
            $this->logger()->logAction($clientId, User::ROLE_CLIENT, 'update_car', 'car', $carId, "VIN: $vin, $brand $model");
            return $this->ok(['message' => 'Данные автомобиля сохранены.']);
        } catch (Exception $e) {
            $this->logger()->logError('CAR_UPDATE', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $clientId);
            return $this->fail('Не удалось сохранить данные автомобиля', 500);
        }
    }

    public function deleteClientCar(int $clientId, int $carId): array
    {
        $check = $this->requireRole([User::ROLE_CLIENT, User::ROLE_ADMIN]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);

        if (!$this->cars()->canDelete($carId, $clientId)) {
            $this->logger()->logError('VALIDATION', "Попытка удаления автомобиля $carId, участвующего в активной заявке", null, null, null, $clientId);
            return $this->fail('Автомобиль нельзя удалить: он не принадлежит вам или участвует в активной заявке.');
        }
        try {
            $deleted = $this->cars()->delete($carId);
            if (!$deleted) throw new Exception('Ошибка удаления');
            $this->logger()->logAction($clientId, User::ROLE_CLIENT, 'delete_car', 'car', $carId, 'Автомобиль удалён');
            return $this->ok(['message' => 'Автомобиль удалён.']);
        } catch (Exception $e) {
            $this->logger()->logError('CAR_DELETE', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $clientId);
            return $this->fail('Не удалось удалить автомобиль', 500);
        }
    }

    public function updateClientProfile(int $clientId, string $fullName, string $phone, string $email, string $newPassword = ''): array
    {
        $check = $this->requireRole([User::ROLE_CLIENT]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);
        if ($this->users()->emailExists($email, $clientId)) return $this->fail('Пользователь с таким email уже существует');
        try {
            $this->users()->updateProfile($clientId, $fullName, $phone, $email);

            $newPassword = trim($newPassword);
            if ($newPassword !== '') {
                if (strlen($newPassword) < 6) return $this->fail('Новый пароль должен быть не короче 6 символов');
                $this->users()->updatePassword($clientId, password_hash($newPassword, PASSWORD_BCRYPT));
            }

            if (session_status() !== PHP_SESSION_ACTIVE) session_start();
            if (!empty($_SESSION['user'])) {
                $_SESSION['user']['full_name'] = $fullName;
                $_SESSION['user']['phone']     = $phone;
                $_SESSION['user']['email']     = $email;
            }

            $this->logger()->logAction($clientId, User::ROLE_CLIENT, 'update_profile', 'user', $clientId, "ФИО: $fullName, email: $email");
            return $this->ok(['message' => 'Данные сохранены', 'full_name' => $fullName, 'phone' => $phone, 'email' => $email]);
        } catch (Exception $e) {
            $this->logger()->logError('PROFILE_UPDATE', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $clientId);
            return $this->fail('Ошибка обновления профиля', 500);
        }
    }

    public function cancelOrder(int $clientId, int $orderId): array
    {
        $check = $this->requireRole([User::ROLE_CLIENT]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);

        $order = $this->orders()->findById($orderId);
        if (!$order || (int) $order['client_id'] !== $clientId) {
            $this->logger()->logError('VALIDATION', "Попытка отмены чужой заявки $orderId", null, null, null, $clientId);
            return $this->fail('Заявка не найдена.', 404);
        }
        if ($order['status'] !== Order::STATUS_NEW) {
            $this->logger()->logError('VALIDATION', "Попытка отмены заявки $orderId в статусе {$order['status']}", null, null, null, $clientId);
            return $this->fail('Отменить можно только новую заявку, которую мастер ещё не принял в работу.');
        }
        try {
            $this->orders()->updateStatus($orderId, Order::STATUS_CANCELLED);
            $this->history()->log($orderId, Order::STATUS_NEW, Order::STATUS_CANCELLED, $clientId);
            $this->logger()->logAction($clientId, User::ROLE_CLIENT, 'cancel_order', 'order', $orderId, 'Заявка отменена клиентом');
            return $this->ok(['message' => 'Заявка отменена.']);
        } catch (Exception $e) {
            $this->logger()->logError('CANCEL_ORDER', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $clientId);
            return $this->fail('Ошибка отмены заявки', 500);
        }
    }

    public function getOrderComposition(int $clientId): array
    {
        $check = $this->requireRole([User::ROLE_CLIENT]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);
        try {
            return $this->ok([
                'services' => $this->orders()->getServicesForClientOrders($clientId),
                'parts'    => $this->orders()->getPartsForClientOrders($clientId),
            ]);
        } catch (Exception $e) {
            $this->logger()->logError('DB_ERROR', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $clientId);
            return $this->fail('Ошибка получения состава заявок', 500);
        }
    }

    public function getOrderHistory(int $clientId): array
    {
        $check = $this->requireRole([User::ROLE_CLIENT]);
        if (!$check[0]) return $this->fail($check[1]['message'], $check[1]['status']);
        try {
            return $this->ok(['history' => $this->history()->getForClientOrders($clientId)]);
        } catch (Exception $e) {
            $this->logger()->logError('DB_ERROR', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), $clientId);
            return $this->fail('Ошибка получения истории', 500);
        }
    }

    public function carHasActiveOrder(int $carId): bool   { return $this->orders()->carHasActiveOrder($carId); }
    public function carBelongsToClient(int $carId, int $clientId): bool { return $this->cars()->belongsToClient($carId, $clientId); }
}