<?php
require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../models/User.php';

class AuthController extends BaseController
{
    public function register(string $fullName, string $phone, string $email, string $password, string $role = User::ROLE_CLIENT): array
    {
        try {
            if (!User::isValidRole($role)) return $this->fail('Некорректная роль');
            if ($role !== User::ROLE_CLIENT) return $this->fail('Саморегистрация доступна только для клиентов');
            if ($this->users()->emailExists($email)) return $this->fail('Пользователь с таким email уже существует');

            $id = $this->users()->create($fullName, $phone, $email, $role, password_hash($password, PASSWORD_BCRYPT));
            if (!$id) {
                $this->logger()->logError('REG_ERROR', 'Не удалось создать пользователя', __FILE__, __LINE__, null, null);
                return $this->fail('Не удалось зарегистрировать пользователя', 500);
            }
            $this->logger()->logAction($id, $role, 'register', 'user', $id, "Email: $email, ФИО: $fullName");
            return $this->ok(['message' => 'Регистрация выполнена']);
        } catch (Exception $e) {
            $this->logger()->logError('EXCEPTION', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), null);
            return $this->fail('Внутренняя ошибка сервера', 500);
        }
    }

    public function login(string $email, string $password): array
    {
        try {
            $user = $this->users()->findByEmailWithPassword($email);
            if (!$user || !password_verify($password, $user['password'])) {
                $this->logger()->logError('WARNING', "Неудачная попытка входа для email $email", null, null, null, null);
                return $this->fail('Неверный email или пароль', 401);
            }
            if ((int) $user['is_active'] !== 1) {
                $this->logger()->logError('WARNING', "Попытка входа деактивированного пользователя $email", null, null, null, null);
                return $this->fail('Пользователь деактивирован', 403);
            }

            if (session_status() !== PHP_SESSION_ACTIVE) session_start();
            unset($user['password']);
            $_SESSION['user'] = $user;
            $this->logger()->logAction($user['id'], $user['role'], 'login', null, null, "Вход выполнен, email: $email");
            return $this->ok(['user' => $user]);
        } catch (Exception $e) {
            $this->logger()->logError('EXCEPTION', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), null);
            return $this->fail('Внутренняя ошибка сервера', 500);
        }
    }

    public function logout(): array
    {
        try {
            $userId = $_SESSION['user']['id'] ?? 0;
            $role = $_SESSION['user']['role'] ?? '';
            $this->logger()->logAction($userId, $role, 'logout', null, null, 'Выход из системы');
            if (session_status() !== PHP_SESSION_ACTIVE) session_start();
            session_destroy();
            return $this->ok(['message' => 'Выход выполнен']);
        } catch (Exception $e) {
            $this->logger()->logError('EXCEPTION', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), null);
            return $this->fail('Ошибка при выходе', 500);
        }
    }
}