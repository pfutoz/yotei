<?php
require_once __DIR__ . '/session_tab.php';

$users = [
    'user_a' => ['name' => 'ユーザーA', 'role' => '管理者'],
    'user_b' => ['name' => 'ユーザーB', 'role' => '一般'],
];

if (isset($_POST['login_user']) && isset($users[$_POST['login_user']])) {
    tab_login([
        'user_id'   => $_POST['login_user'],
        'user_name' => $users[$_POST['login_user']]['name'],
        'role'      => $users[$_POST['login_user']]['role']
    ]);
    header('Location: ' . url('b.php'));
    exit;
}

$currentUser = current_tab_user();
?>
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><title>ログイン</title></head>
<body>
    <p><strong>タブID:</strong> <?= htmlspecialchars($tab_id) ?></p>
    
    <?php if ($currentUser): ?>
        <p style="color:green;">ログイン中: <?= htmlspecialchars($currentUser['user_name']) ?> (<?= htmlspecialchars($currentUser['role']) ?>)</p>
    <?php else: ?>
        <p style="color:gray;">未ログイン</p>
    <?php endif; ?>

    <form method="POST" action="<?= url('a.php') ?>">
        <button type="submit" name="login_user" value="user_a">ユーザーAでログイン</button>
        <button type="submit" name="login_user" value="user_b">ユーザーBでログイン</button>
    </form>
    
    <p><a href="<?= url('b.php') ?>">確認画面（b.php）へ</a></p>
</body>
</html>