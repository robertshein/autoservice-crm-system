<?php
require_once __DIR__ . '/_init.php';

$nav_admin_section = 'employees';

$flash_error = null;
$flash_success = null;

$ROLE_LABELS = [
    User::ROLE_MECHANIC => 'Механик',
    User::ROLE_MASTER   => 'Мастер',
    User::ROLE_ADMIN    => 'Администратор',
];

$EDIT_ROLES = [User::ROLE_MECHANIC, User::ROLE_MASTER, User::ROLE_ADMIN];

function validateFullName(string $name): bool {
    $name = trim($name);
    if (mb_strlen($name) < 3) return false;
    if (!preg_match('/^[\p{L}\s\-\.]+$/u', $name)) return false;
    if (substr_count($name, ' ') < 1) return false;
    return true;
}

function validatePhone(string $phone): bool {
    $digits = preg_replace('/\D/', '', $phone);
    $len = strlen($digits);
    return $len >= 10 && $len <= 12;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create_employee') {
        $full_name = trim($_POST['full_name'] ?? '');
        $phone     = trim($_POST['phone'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $password  = (string) ($_POST['password'] ?? '');
        $role      = (string) ($_POST['role'] ?? '');

        if ($full_name === '' || $phone === '' || $email === '' || $password === '' || $role === '') {
            $flash_error = 'Заполните все обязательные поля.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $flash_error = 'Укажите корректный email.';
        } elseif (!validateFullName($full_name)) {
            $flash_error = 'ФИО введено некорректно.';
        } elseif (!validatePhone($phone)) {
            $flash_error = 'Номер телефона введен некорректно.';
        } elseif (strlen($password) < 6) {
            $flash_error = 'Пароль должен содержать не менее 6 символов.';
        } else {
            $r = $admin_controller->createEmployee($full_name, $phone, $email, $password, $role);
            if (!($r['success'] ?? false)) {
                $flash_error = $r['message'] ?? 'Не удалось добавить сотрудника.';
            } else {
                $flash_success = 'Сотрудник добавлен.';
            }
        }
    } elseif ($action === 'toggle_active') {
        $emp_id    = (int) ($_POST['employee_id'] ?? 0);
        $is_active = (int) ($_POST['is_active'] ?? 0);
        if ($emp_id <= 0) {
            $flash_error = 'Некорректный сотрудник.';
        } elseif ($emp_id === $admin_id) {
            $flash_error = 'Нельзя деактивировать себя.';
        } else {
            $r = $admin_controller->setEmployeeActive($emp_id, $is_active);
            if (!($r['success'] ?? false)) {
                $flash_error = $r['message'] ?? 'Не удалось изменить статус.';
            } else {
                $flash_success = $is_active ? 'Сотрудник активирован.' : 'Сотрудник деактивирован.';
            }
        }
    } elseif ($action === 'update_employee') {
        $emp_id    = (int) ($_POST['employee_id'] ?? 0);
        $full_name = trim($_POST['full_name'] ?? '');
        $phone     = trim($_POST['phone'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $role      = (string) ($_POST['role'] ?? '');

        if ($emp_id <= 0 || $full_name === '' || $phone === '' || $email === '' || $role === '') {
            $flash_error = 'Заполните все поля.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $flash_error = 'Укажите корректный email.';
        } elseif (!validateFullName($full_name)) {
            $flash_error = 'ФИО введено некорректно';
        } elseif (!validatePhone($phone)) {
            $flash_error = 'Номер телефона введен некорректно.';
        } elseif ($emp_id === $admin_id && $role !== User::ROLE_ADMIN) {
            $flash_error = 'Нельзя изменить роль самому себе.';
        } elseif (!in_array($role, $EDIT_ROLES, true)) {
            $flash_error = 'Некорректная роль сотрудника.';
        } else {
            $r = $admin_controller->updateEmployee($emp_id, $full_name, $phone, $email, $role);
            if (!($r['success'] ?? false)) {
                $flash_error = $r['message'] ?? 'Не удалось обновить данные.';
            } else {
                $flash_success = 'Данные сотрудника обновлены.';
                if ($emp_id === $admin_id) {
                    $_SESSION['user']['full_name'] = $full_name;
                    $_SESSION['user']['phone']     = $phone;
                    $_SESSION['user']['email']     = $email;
                }
            }
        }
    }
}

$employees = [];
$r = $admin_controller->getEmployees();
if ($r['success'] ?? false) {
    $employees = $r['data']['employees'] ?? [];
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Сотрудники — АвтоПлюс</title>
    <?php include __DIR__ . '/../../includes/layout_styles.php'; ?>
    <style>
        .emp-row td { vertical-align: middle; }
        .emp-inactive { opacity: 0.55; }
        .inline-form { display: inline; }
        .btn-sm {
            padding: 5px 12px;
            border: 1px solid var(--border);
            border-radius: 4px;
            background: #fff;
            color: var(--focus);
            font-size: 0.82rem;
            font-weight: 600;
            cursor: pointer;
            font-family: inherit;
        }
        .btn-sm:hover { background: #f0f4fb; }
        .btn-sm-danger { color: var(--danger-text); border-color: var(--danger-border); }
        .btn-sm-danger:hover { background: var(--danger-bg); }
        .btn-sm-ok { color: var(--ok-text); border-color: var(--ok-border); }
        .btn-sm-ok:hover { background: var(--ok-bg); }
        .edit-row { display: none; background: #f7f9fc; }
        .edit-row td { padding: 12px 16px; }
        .edit-form { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; }
        .edit-form .field { margin: 0; min-width: 140px; }
        .edit-form input, .edit-form select { padding: 6px 10px; font-size: 0.9rem; }
        .edit-form .btn-submit { margin: 0; padding: 6px 16px; }
    </style>
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

        <h1 class="sans">Сотрудники</h1>
        <p class="lead">Управление персоналом: добавление, редактирование, активация/деактивация сотрудников.</p>

        <section class="card">
            <h2>Добавить сотрудника</h2>
            <form method="post" action="">
                <input type="hidden" name="action" value="create_employee">
                <div class="row2">
                    <div class="field">
                        <label for="full_name">ФИО <span style="color:red;">*</span></label>
                        <input id="full_name" name="full_name" type="text" required maxlength="150" placeholder="Иванов Иван Иванович" pattern="^[\p{L}\s\-\.]+$" title="Только буквы, пробелы, дефисы, точки">
                    </div>
                    <div class="field">
                        <label for="phone">Телефон <span style="color:red;">*</span></label>
                        <input id="phone" name="phone" type="text" required maxlength="30" placeholder="+7 (123) 456-78-90">
                    </div>
                </div>
                <div class="row2">
                    <div class="field">
                        <label for="email">Email <span style="color:red;">*</span></label>
                        <input id="email" name="email" type="email" required maxlength="150" placeholder="employee@service.local">
                    </div>
                    <div class="field">
                        <label for="password">Пароль <span style="color:red;">*</span></label>
                        <input id="password" name="password" type="password" required minlength="6" placeholder="Не менее 6 символов">
                    </div>
                </div>
                <div class="field">
                    <label for="role">Роль <span style="color:red;">*</span></label>
                    <select id="role" name="role" required>
                        <option value="">Выберите</option>
                        <?php foreach ($ROLE_LABELS as $val => $lbl): ?>
                            <option value="<?php echo htmlspecialchars($val); ?>"><?php echo htmlspecialchars($lbl); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn-submit">Добавить сотрудника</button>
            </form>
        </section>

        <section class="card">
            <h2>Список сотрудников</h2>
            <?php if (empty($employees)): ?>
                <p class="empty">Сотрудников пока нет.</p>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="sans">
                        <thead>
                            <tr>
                                <th>ФИО</th>
                                <th>Email / Телефон</th>
                                <th>Роль</th>
                                <th>Статус</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($employees as $emp): ?>
                                <?php
                                $eid       = (int) ($emp['id'] ?? 0);
                                $is_active = (int) ($emp['is_active'] ?? 0);
                                $is_self   = ($eid === $admin_id);
                                $row_class = $is_active ? 'emp-row' : 'emp-row emp-inactive';
                                ?>
                                <tr class="<?php echo $row_class; ?>" id="row-<?php echo $eid; ?>">
                                    <td>
                                        <strong><?php echo htmlspecialchars($emp['full_name'] ?? ''); ?></strong>
                                        <?php if ($is_self): ?>
                                            <span class="badge" style="margin-left:4px;">Вы</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($emp['email'] ?? ''); ?><br>
                                        <span class="hint"><?php echo htmlspecialchars($emp['phone'] ?? ''); ?></span>
                                    </td>
                                    <td>
                                        <span class="badge"><?php echo htmlspecialchars($ROLE_LABELS[$emp['role'] ?? ''] ?? ($emp['role'] ?? '')); ?></span>
                                    </td>
                                    <td>
                                        <?php if ($is_active): ?>
                                            <span class="badge badge-ok" style="background:var(--ok-bg);border-color:var(--ok-border);color:var(--ok-text);">Активен</span>
                                        <?php else: ?>
                                            <span class="badge badge-warn">Деактивирован</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!$is_self): ?>
                                            <form method="post" action="" class="inline-form" style="margin-right: 8px;">
                                                <input type="hidden" name="action" value="toggle_active">
                                                <input type="hidden" name="employee_id" value="<?php echo $eid; ?>">
                                                <?php if ($is_active): ?>
                                                    <input type="hidden" name="is_active" value="0">
                                                    <button type="submit" class="btn-sm btn-sm-danger"
                                                        onclick="return confirm('Деактивировать сотрудника?')">Деактивировать</button>
                                                <?php else: ?>
                                                    <input type="hidden" name="is_active" value="1">
                                                    <button type="submit" class="btn-sm btn-sm-ok">Активировать</button>
                                                <?php endif; ?>
                                            </form>
                                        <?php endif; ?>
                                        <button type="button" class="btn-sm" onclick="toggleEdit(<?php echo $eid; ?>)">Изменить</button>
                                    </td>
                                </tr>

                                <tr class="edit-row" id="edit-<?php echo $eid; ?>">
                                    <td colspan="5">
                                        <form method="post" action="" class="edit-form">
                                            <input type="hidden" name="action" value="update_employee">
                                            <input type="hidden" name="employee_id" value="<?php echo $eid; ?>">

                                            <div class="field">
                                                <label for="full_name_<?php echo $eid; ?>">ФИО</label>
                                                <input type="text" name="full_name" id="full_name_<?php echo $eid; ?>" required maxlength="150"
                                                       value="<?php echo htmlspecialchars($emp['full_name'] ?? ''); ?>">
                                            </div>
                                            <div class="field">
                                                <label for="phone_<?php echo $eid; ?>">Телефон</label>
                                                <input type="text" name="phone" id="phone_<?php echo $eid; ?>" required maxlength="30"
                                                       value="<?php echo htmlspecialchars($emp['phone'] ?? ''); ?>">
                                            </div>
                                            <div class="field">
                                                <label for="email_<?php echo $eid; ?>">Email</label>
                                                <input type="email" name="email" id="email_<?php echo $eid; ?>" required maxlength="150"
                                                       value="<?php echo htmlspecialchars($emp['email'] ?? ''); ?>">
                                            </div>
                                            <div class="field">
                                                <label for="role_<?php echo $eid; ?>">Роль</label>
                                                <select name="role" id="role_<?php echo $eid; ?>" required>
                                                    <option value="">Выберите</option>
                                                    <?php foreach ($ROLE_LABELS as $val => $lbl): ?>
                                                        <option value="<?php echo htmlspecialchars($val); ?>"
                                                            <?php echo ($emp['role'] ?? '') === $val ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($lbl); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            
                                            <button type="submit" class="btn-submit">Сохранить</button>
                                            <button type="button" class="btn-sm" onclick="toggleEdit(<?php echo $eid; ?>)">Отмена</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <script>
    function toggleEdit(id) {
        var row = document.getElementById('edit-' + id);
        if (row) {
            row.style.display = (row.style.display === 'table-row') ? 'none' : 'table-row';
        }
    }
    </script>
</body>
</html>