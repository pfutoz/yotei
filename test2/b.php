<?php
// ============================================================
// ファイル名: b.php
// 概要: セッション確認画面（session_tab.php を利用）
// ============================================================
require_once __DIR__ . '/session_tab.php';

// ログアウト処理（このタブのみ）
if (isset($_GET['logout'])) {
    tab_logout();
    header('Location: ' . url('a.php'));
    exit;
}

// 全セッション強制削除（fout.php 非依存）
if (isset($_GET['destroy_all'])) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    $tab_id_for_redirect = $_GET['tab_id'] ?? 'tab_' . bin2hex(random_bytes(8));
    header('Location: a.php?tab_id=' . $tab_id_for_redirect . '&destroyed=1');
    exit;
}

// 現在のタブのユーザー情報を取得
$currentUser = current_tab_user();

// セッション破棄後のメッセージ用
$destroyed = isset($_GET['destroyed']);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>セッション確認</title>
    <style>
        body { font-family: sans-serif; padding: 20px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; }
        .card { background: white; padding: 24px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-bottom: 16px; }
        h1 { font-size: 20px; margin: 0 0 16px 0; }
        .message { padding: 10px 14px; border-radius: 6px; margin-bottom: 16px; font-size: 14px; }
        .message-success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; }
        .message-danger { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; }
        pre { background: #f8f9fa; padding: 16px; border-radius: 6px; overflow: auto; font-size: 13px; border: 1px solid #e9ecef; max-height: 300px; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        @media (max-width: 600px) { .grid { grid-template-columns: 1fr; } }
        .links { margin-top: 20px; display: flex; gap: 12px; flex-wrap: wrap; }
        .links a { color: #4a90d9; text-decoration: none; font-size: 14px; }
        .links a:hover { text-decoration: underline; }
        .links .danger { color: #d9534f; }
        .tab-id { font-size: 12px; color: #999; margin-top: 8px; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th { text-align: left; background: #f1f3f5; padding: 8px 12px; border-bottom: 2px solid #dee2e6; }
        td { padding: 8px 12px; border-bottom: 1px solid #e9ecef; }
        .current { background: #d4edda; }
        hr { margin: 16px 0; border: none; border-top: 1px solid #ddd; }
        code { background: #f1f3f5; padding: 2px 6px; border-radius: 4px; font-size: 13px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <h1>📊 セッション状態確認</h1>

            <?php if ($destroyed): ?>
            <div class="message message-danger">🗑️ 全セッションを削除しました</div>
            <?php endif; ?>

            <div style="display:flex;gap:20px;flex-wrap:wrap;margin-bottom:16px;">
                <div><strong>タブID:</strong> <code><?= htmlspecialchars($tab_id) ?></code></div>
                <div><strong>セッションID:</strong> <code><?= session_id() ?></code></div>
            </div>

            <?php if ($currentUser): ?>
                <div class="message message-success">
                    ✅ このタブは <strong><?= htmlspecialchars($currentUser['user_name']) ?></strong> でログイン中です
                    (<?= htmlspecialchars($currentUser['role']) ?>)
                    <br><a href="<?= url('b.php?logout=1') ?>" style="color:#d9534f;font-size:14px;">→ このタブをログアウト</a>
                </div>
            <?php else: ?>
                <div class="message message-danger">
                    ❌ このタブはログインしていません
                </div>
            <?php endif; ?>
        </div>

        <div class="grid">
            <div class="card">
                <h2>📋 現在のタブ情報</h2>
                <?php if ($currentUser): ?>
                <table>
                    <tr><th>項目</th><th>値</th></tr>
                    <?php foreach ($currentUser as $key => $val): ?>
                    <tr><td><?= htmlspecialchars($key) ?></td><td><?= htmlspecialchars($val ?? '(空)') ?></td></tr>
                    <?php endforeach; ?>
                </table>
                <?php else: ?>
                <p style="color:#888;">このタブのセッション情報はありません</p>
                <?php endif; ?>
            </div>

            <div class="card">
                <h2>📋 全タブのインスタンス</h2>
                <?php if (isset($_SESSION['instances']) && count($_SESSION['instances']) > 0): ?>
                <table>
                    <tr><th>タブID</th><th>ユーザー</th><th>役割</th><th>現在</th></tr>
                    <?php foreach ($_SESSION['instances'] as $tid => $data): ?>
                    <tr class="<?= ($tid === $tab_id) ? 'current' : '' ?>">
                        <td><code><?= htmlspecialchars(substr($tid, 0, 20)) ?>...</code></td>
                        <td><?= htmlspecialchars($data['user_name'] ?? '不明') ?></td>
                        <td><?= htmlspecialchars($data['role'] ?? '不明') ?></td>
                        <td><?= ($tid === $tab_id) ? '✅' : '' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
                <?php else: ?>
                <p style="color:#888;">インスタンスはありません</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <h2>🔍 $_SESSION 全体</h2>
            <pre><?php print_r($_SESSION); ?></pre>
        </div>

        <hr>

        <div class="links">
            <a href="<?= url('a.php') ?>">🔐 ログイン画面 (a.php)</a>
            <a href="<?= url('b.php?logout=1') ?>" class="danger" onclick="return confirm('このタブをログアウトしますか？');">
                🚪 このタブをログアウト
            </a>
            <a href="<?= url('b.php?destroy_all=1') ?>" class="danger" onclick="return confirm('全セッションを完全に削除しますか？');">
                🗑️ 全セッション削除
            </a>
            <a href="<?= url('b.php') ?>">🔄 更新</a>
        </div>

        <div class="tab-id">
            💡 別タブで b.php を開くには: <code>b.php?tab_id=tab_新規ID</code>
        </div>
    </div>
</body>
</html>