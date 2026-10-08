<?php
require_once __DIR__ . '/session_tab.php';

if (isset($_GET['logout'])) {
    tab_logout();
    header('Location: ' . url('a.php'));
    exit;
}

$currentUser = current_tab_user();
?>
<!DOCTYPE html>
<html>
<body>
    <h1>確認画面</h1>
    <?php if ($currentUser): ?>
        <p>ユーザー: <?= htmlspecialchars($currentUser['user_name']) ?> (<?= htmlspecialchars($currentUser['role']) ?>)</p>
    <?php else: ?>
        <p>未ログイン</p>
    <?php endif; ?>

    <a href="<?= url('b.php?logout=1') ?>">ログアウト</a> | 
    <a href="<?= url('a.php') ?>">ログイン画面</a>
</body>
</html>