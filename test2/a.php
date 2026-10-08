<?php
// ============================================================
// ファイル名: a.php
// 概要: ログイン画面（session_tab.php を利用）
// ============================================================
require_once __DIR__ . '/session_tab.php';

// ユーザーマスタ（固定）
$users = [
    'user_a' => ['name' => 'ユーザーA', 'role' => '管理者'],
    'user_b' => ['name' => 'ユーザーB', 'role' => '一般'],
];

// ログイン処理
if (isset($_POST['login_user']) && isset($users[$_POST['login_user']])) {
    $selectedUser = $_POST['login_user'];
    tab_login([
        'user_id'   => $selectedUser,
        'user_name' => $users[$selectedUser]['name'],
        'role'      => $users[$selectedUser]['role'],
    ]);
    header('Location: ' . url('b.php'));
    exit;
}

// 現在のタブのユーザー情報を取得
$currentUser = current_tab_user();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>検証用ログイン</title>
    <style>
        body { font-family: sans-serif; padding: 20px; background: #f5f5f5; }
        .container { max-width: 500px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { font-size: 20px; margin-bottom: 20px; }
        .user-btn { display: block; width: 100%; padding: 15px; margin-bottom: 10px; border: 2px solid #ddd; border-radius: 6px; background: white; font-size: 16px; cursor: pointer; transition: 0.2s; text-align: left; }
        .user-btn:hover { border-color: #4a90d9; background: #f0f7ff; }
        .user-btn .name { font-weight: bold; }
        .user-btn .role { font-size: 13px; color: #888; }
        .info { background: #e8f0fe; padding: 12px; border-radius: 6px; margin-bottom: 20px; font-size: 14px; }
        .info code { background: #d0d8e0; padding: 2px 8px; border-radius: 4px; }
        .logged-in { background: #d4edda; padding: 12px; border-radius: 6px; margin-bottom: 20px; border: 1px solid #c3e6cb; }
        .links { margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd; display: flex; gap: 12px; flex-wrap: wrap; }
        .links a { color: #4a90d9; text-decoration: none; font-size: 14px; }
        .links a:hover { text-decoration: underline; }
        .links .danger { color: #d9534f; }
        .tab-id { font-size: 12px; color: #999; margin-top: 10px; }
        .note { background: #fff3cd; padding: 10px 14px; border-radius: 6px; margin-top: 12px; font-size: 13px; color: #856404; border: 1px solid #ffc107; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔐 検証用ログイン</h1>

        <div class="info">
            <strong>📌 タブID:</strong> <code><?= htmlspecialchars($tab_id) ?></code><br>
            <strong>📌 セッションID:</strong> <code><?= session_id() ?></code>
        </div>

        <?php if ($currentUser): ?>
        <div class="logged-in">
            ✅ ログイン中: <strong><?= htmlspecialchars($currentUser['user_name']) ?></strong>
            (<?= htmlspecialchars($currentUser['role']) ?>)
            <br><a href="<?= url('b.php?logout=1') ?>">→ このタブをログアウト</a>
        </div>
        <?php endif; ?>

        <p style="color:#666;font-size:14px;margin-bottom:16px;">ログインするユーザーを選択してください</p>

        <form method="POST" action="<?= url('a.php') ?>">
            <?php foreach ($users as $id => $user): ?>
            <button type="submit" name="login_user" value="<?= $id ?>" class="user-btn">
                <div class="name"><?= htmlspecialchars($user['name']) ?></div>
                <div class="role"><?= htmlspecialchars($user['role']) ?></div>
            </button>
            <?php endforeach; ?>
        </form>

        <hr>

        <div class="links">
            <a href="<?= url('b.php') ?>">📊 セッション確認 (b.php)</a>
            <a href="?destroy_all=1&tab_id=<?= htmlspecialchars($tab_id) ?>" class="danger" onclick="return confirm('全セッションを完全に削除しますか？');">
                🗑️ 全セッション削除
            </a>
        </div>

        <div class="note">
            💡 <strong>別タブで開く場合:</strong> 右クリックは使わず、<br>
            <code>a.php</code> を新規タブで直接開くか、<br>
            アドレスバーに <code>a.php?tab_id=tab_新規ID</code> を入力してください。
        </div>

        <div class="tab-id">
            🔄 新しいタブIDで開く: <a href="a.php?tab_id=tab_<?= bin2hex(random_bytes(4)) ?>">新しいタブIDを発行</a>
        </div>
    </div>
</body>
</html>