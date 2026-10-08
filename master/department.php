<?php
// ============================================================
// ファイル名: master/department.php
// システム名: 医師予定表管理システム（yotei）
// バージョン: v1.0
// 生成日時: 2026-08-07
// ============================================================
//
// 【概要】
// 部門マスタの管理（一覧・追加・編集・削除）
// ============================================================

require_once __DIR__ . '/../LIB/db.php';
require_once __DIR__ . '/../LIB/master_auth.php';

// 管理者パスワード認証チェック
requireMasterAuth('index.php');

$pdo = getDbConnection();
$message = '';
$errors = [];

// 追加処理
if (isset($_POST['add'])) {
    $name = trim($_POST['name'] ?? '');
    $displayName = trim($_POST['display_name'] ?? '');
    $colorCode = trim($_POST['color_code'] ?? '#888888');
    $sortOrder = (int)($_POST['sort_order'] ?? 0);

    if (empty($name)) $errors[] = '部門コードを入力してください';
    if (empty($displayName)) $errors[] = '表示名を入力してください';

    if (empty($errors)) {
        $stmt = $pdo->prepare("INSERT INTO departments (name, display_name, color_code, sort_order) VALUES (:name, :display_name, :color_code, :sort_order)");
        $stmt->execute(['name' => $name, 'display_name' => $displayName, 'color_code' => $colorCode, 'sort_order' => $sortOrder]);
        $message = '✅ 部門を追加しました';
    }
}

// 編集処理
if (isset($_POST['edit'])) {
    $id = (int)$_POST['id'];
    $displayName = trim($_POST['display_name'] ?? '');
    $colorCode = trim($_POST['color_code'] ?? '#888888');
    $sortOrder = (int)($_POST['sort_order'] ?? 0);

    if (empty($displayName)) $errors[] = '表示名を入力してください';

    if (empty($errors)) {
        $stmt = $pdo->prepare("UPDATE departments SET display_name = :display_name, color_code = :color_code, sort_order = :sort_order WHERE id = :id");
        $stmt->execute(['display_name' => $displayName, 'color_code' => $colorCode, 'sort_order' => $sortOrder, 'id' => $id]);
        $message = '✅ 部門を更新しました';
    }
}

// 削除処理（論理削除）
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $pdo->prepare("UPDATE departments SET is_active = false WHERE id = :id");
    $stmt->execute(['id' => $id]);
    $message = '✅ 部門を無効にしました';
    header('Location: department.php');
    exit;
}

// 部門一覧
$departments = $pdo->query("SELECT * FROM departments WHERE is_active = true ORDER BY sort_order")->fetchAll();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>部門マスタ - yotei</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Helvetica Neue', Arial, 'Hiragino Sans', 'Noto Sans JP', sans-serif; background: #f0f2f5; color: #2d3748; padding: 20px; }
        .container { max-width: 1000px; margin: 0 auto; }
        .header { display: flex; align-items: center; justify-content: space-between; background: white; padding: 16px 24px; border-radius: 10px; box-shadow: 0 2px 6px rgba(0,0,0,0.06); margin-bottom: 20px; }
        .header h1 { font-size: 20px; font-weight: 700; color: #2C6E9C; }
        .header .back-link { color: #718096; text-decoration: none; font-size: 14px; }
        .nav-tabs { display: flex; gap: 4px; margin-bottom: 16px; flex-wrap: wrap; }
        .nav-tabs a { padding: 8px 20px; background: white; border-radius: 8px 8px 0 0; text-decoration: none; color: #4a5568; border: 1px solid #e2e8f0; border-bottom: none; font-weight: 600; }
        .nav-tabs a.active { background: #2C6E9C; color: white; border-color: #2C6E9C; }
        .nav-tabs a:hover:not(.active) { background: #edf2f7; }
        .card { background: white; border-radius: 10px; padding: 24px; box-shadow: 0 2px 6px rgba(0,0,0,0.06); margin-bottom: 16px; }
        .card-title { font-size: 16px; font-weight: 700; margin-bottom: 16px; }
        .message { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; background: #c6f6d5; color: #22543d; border: 1px solid #9ae6b4; }
        .error { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; background: #fed7d7; color: #c53030; border: 1px solid #feb2b2; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-size: 12px; font-weight: 600; color: #a0aec0; padding: 8px 12px; border-bottom: 2px solid #e2e8f0; background: #f7fafc; }
        td { padding: 8px 12px; border-bottom: 1px solid #edf2f7; font-size: 14px; vertical-align: middle; }
        tr:hover td { background: #f7fafc; }
        .form-group { margin-bottom: 12px; }
        .form-group label { display: block; font-weight: 600; font-size: 13px; color: #4a5568; margin-bottom: 2px; }
        .form-group input { width: 100%; padding: 6px 10px; border: 1px solid #e2e8f0; border-radius: 4px; font-size: 14px; }
        .form-group input:focus { outline: none; border-color: #2C6E9C; }
        .btn { padding: 6px 14px; border: none; border-radius: 4px; font-size: 13px; font-weight: 600; cursor: pointer; transition: all 0.2s; text-decoration: none; display: inline-block; }
        .btn:hover { transform: translateY(-1px); }
        .btn-primary { background: #2C6E9C; color: white; }
        .btn-primary:hover { background: #1a4a6e; }
        .btn-success { background: #48bb78; color: white; }
        .btn-success:hover { background: #38a169; }
        .btn-warning { background: #ecc94b; color: #2d3748; }
        .btn-warning:hover { background: #d69e2e; }
        .btn-danger { background: #fc8181; color: white; }
        .btn-danger:hover { background: #f56565; }
        .btn-sm { padding: 2px 8px; font-size: 12px; }
        .row { display: flex; gap: 12px; }
        .row .form-group { flex: 1; }
        .color-preview { display: inline-block; width: 20px; height: 20px; border-radius: 4px; border: 1px solid #e2e8f0; vertical-align: middle; }
    </style>
</head>
<body>
<div class="container">

    <div class="header">
        <h1>⚙️ マスターメンテナンス</h1>
        <a href="../index.php" class="back-link">← 予定表に戻る</a>
    </div>

    <div class="nav-tabs">
        <a href="index.php">👨‍⚕️ 医師</a>
        <a href="department.php" class="active">🏥 部門</a>
        <a href="note.php">📝 備考定型文</a>
        <a href="all_meeting.php">📅 全体会</a>
    </div>

    <?php if ($message): ?>
    <div class="message"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    <?php if (!empty($errors)): ?>
    <div class="error"><?= implode('<br>', array_map('htmlspecialchars', $errors)) ?></div>
    <?php endif; ?>

    <!-- 追加フォーム -->
    <div class="card">
        <div class="card-title">➕ 部門追加</div>
        <form method="POST">
            <div class="row">
                <div class="form-group">
                    <label>コード（例: ONO）</label>
                    <input type="text" name="name" placeholder="部門コード" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>表示名（例: 肛門科）</label>
                    <input type="text" name="display_name" placeholder="表示名" value="<?= htmlspecialchars($_POST['display_name'] ?? '') ?>">
                </div>
            </div>
            <div class="row">
                <div class="form-group">
                    <label>カラーコード</label>
                    <input type="color" name="color_code" value="<?= htmlspecialchars($_POST['color_code'] ?? '#888888') ?>">
                </div>
                <div class="form-group">
                    <label>表示順</label>
                    <input type="number" name="sort_order" value="<?= htmlspecialchars($_POST['sort_order'] ?? 0) ?>">
                </div>
            </div>
            <button type="submit" name="add" class="btn btn-success">➕ 追加</button>
        </form>
    </div>

    <!-- 一覧 -->
    <div class="card">
        <div class="card-title">📋 部門一覧</div>
        <?php if (empty($departments)): ?>
        <p style="color:#a0aec0;text-align:center;padding:20px;">部門が登録されていません</p>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>順</th>
                    <th>コード</th>
                    <th>表示名</th>
                    <th>色</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($departments as $dept): ?>
                <tr>
                    <td><?= $dept['sort_order'] ?></td>
                    <td><?= htmlspecialchars($dept['name']) ?></td>
                    <td>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="edit" value="1">
                            <input type="hidden" name="id" value="<?= $dept['id'] ?>">
                            <input type="text" name="display_name" value="<?= htmlspecialchars($dept['display_name']) ?>" style="padding:4px 8px;border:1px solid #e2e8f0;border-radius:4px;font-size:13px;width:120px;">
                            <input type="color" name="color_code" value="<?= htmlspecialchars($dept['color_code'] ?? '#888888') ?>" style="width:40px;height:30px;padding:0;border:none;cursor:pointer;">
                            <input type="number" name="sort_order" value="<?= $dept['sort_order'] ?>" style="width:50px;padding:4px 4px;border:1px solid #e2e8f0;border-radius:4px;font-size:13px;">
                            <button type="submit" class="btn btn-sm btn-warning">✏️</button>
                        </form>
                    </td>
                    <td>
                        <a href="?delete=<?= $dept['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('この部門を無効にしますか？')">🗑️</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

</div>
</body>
</html>