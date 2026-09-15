<?php
require_once __DIR__ . '/_init.php';

$nav_admin_section = 'cabinet';
$nav_active = 'cabinet';

$flash_error = null;
$flash_success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'update_profile') {
        $full_name = trim($_POST['full_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $new_password = $_POST['new_password'] ?? '';
        $new_password_confirm = $_POST['new_password_confirm'] ?? '';

        if ($full_name === '') {
            $flash_error = 'Укажите ФИО.';
        } elseif ($phone === '') {
            $flash_error = 'Укажите телефон.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $flash_error = 'Укажите корректный email.';
        } elseif (trim($new_password) !== '' && $new_password !== $new_password_confirm) {
            $flash_error = 'Пароли не совпадают.';
        } else {
            $r = $admin_controller->updateProfile($admin_id, $full_name, $phone, $email, $new_password);
            if (!($r['success'] ?? false)) {
                $flash_error = $r['message'] ?? 'Не удалось сохранить профиль.';
            } else {
                $flash_success = 'Данные сохранены.';
                $admin_user = $_SESSION['user'];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Личный кабинет администратора</title>
    <?php include __DIR__ . '/../../includes/layout_styles.php'; ?>
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

        <h1 class="sans">Личный кабинет администратора</h1>
        <p class="lead">Здесь вы можете изменить свои личные данные.</p>

        <section class="card">
            <h2>Личные данные</h2>
            <form method="post" action="">
                <input type="hidden" name="action" value="update_profile">
                <div class="row2">
                    <div class="field">
                        <label for="full_name">ФИО</label>
                        <input id="full_name" name="full_name" type="text" required maxlength="150" value="<?php echo htmlspecialchars($admin_user['full_name'] ?? ''); ?>">
                    </div>
                    <div class="field">
                        <label for="phone">Телефон</label>
                        <input id="phone" name="phone" type="text" required maxlength="30" value="<?php echo htmlspecialchars($admin_user['phone'] ?? ''); ?>">
                    </div>
                </div>
                <div class="field">
                    <label for="email">Email</label>
                    <input id="email" name="email" type="email" required maxlength="150" value="<?php echo htmlspecialchars($admin_user['email'] ?? ''); ?>">
                </div>
                <div class="row2">
                    <div class="field">
                        <label for="new_password">Новый пароль</label>
                        <input id="new_password" name="new_password" type="password" autocomplete="new-password" maxlength="128" placeholder="Оставьте пустым, если не меняете">
                        <p class="hint">Не менее 6 символов, если заполняете.</p>
                    </div>
                    <div class="field">
                        <label for="new_password_confirm">Подтверждение пароля</label>
                        <input id="new_password_confirm" name="new_password_confirm" type="password" autocomplete="new-password" maxlength="128" placeholder="Повторите новый пароль">
                    </div>
                </div>
                <button type="submit" class="btn-submit">Сохранить изменения</button>
            </form>
        </section>
    </div>
</body>
</html>