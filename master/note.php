<?php
// ============================================================
// ファイル名: master/note.php
// システム名: 医師予定表管理システム（yotei）
// バージョン: v1.0
// 生成日時: 2026-08-07
// ============================================================
//
// 【概要】
// 備考定型文の管理（一覧・追加・編集・削除・並び替え）
// ============================================================

require_once __DIR__ . '/../LIB/db.php';
require_once __DIR__ . '/../LIB/master_auth.php';

// 管理者パスワード認証チェック
requireMasterAuth('index.php');

$pdo = getDbConnection();
$message = '';
$errors = [];

// 並び替え（↑）
if (isset($_GET['move_up']) && is_numeric($_GET['move_up'])) {
    $id = (int)$_GET['move_up'];
    $stmt = $pdo->prepare("SELECT sort_order FROM note_templates WHERE id = :id AND note_category = 'note'");
    $stmt->execute(['id' => $id]);
    $current = $stmt->fetch();
    if ($current) {
        $stmt = $pdo->prepare("SELECT id, sort_order FROM note_templates WHERE sort_order < :order AND note_category = 'note' AND is_active = true ORDER BY sort_order DESC LIMIT 1");
        $stmt->execute(['order' => $current['sort_order']]);
        $prev = $stmt->fetch();
        if ($prev) {
            $pdo->prepare("UPDATE note_templates SET sort_order = :new WHERE id = :id")->execute(['new' => $prev['sort_order'], 'id' => $id]);
            $pdo->prepare("UPDATE note_templates SET sort_order = :new WHERE id = :id")->execute(['new' => $current['sort_order'], 'id' => $prev['id']]);
        }
    }
    header('Location: note.php');
    exit;
}

// 並び替え（↓）
if (isset($_GET['move_down']) && is_numeric($_GET['move_down'])) {
    $id = (int)$_GET['move_down'];
    $stmt = $pdo->prepare("SELECT sort_order FROM note_templates WHERE id = :id AND note_category = 'note'");
    $stmt->execute(['id' => $id]);
    $current = $stmt->fetch();
    if ($current) {
        $stmt = $pdo->prepare("SELECT id, sort_order FROM note_templates WHERE sort_order > :order AND note_category = 'note' AND is_active = true ORDER BY sort_order ASC LIMIT 1");
        $stmt->execute(['order' => $current['sort_order']]);
        $next = $stmt->fetch();
        if ($next) {
            $pdo->prepare("UPDATE note_templates SET sort_order = :new WHERE id = :id")->execute(['new' => $next['sort_order'], 'id' => $id]);
            $pdo->prepare("UPDATE note_templates SET sort_order = :new WHERE id = :id")->execute(['new' => $current['sort_order'], 'id' => $next['id']]);
        }
    }
    header('Location: note.php');
    exit;
}

// 追加処理
if (isset($_POST['add'])) {
    $text = trim($_POST['note_text'] ?? '');
    if (empty($text)) {
        $errors[] = '定型文を入力してください';
    } else {
        $maxOrder = $pdo->query("SELECT COALESCE(MAX(sort_order), 0) FROM note_templates WHERE note_category = 'note'")->fetchColumn();
        $stmt = $pdo->prepare("INSERT INTO note_templates (note_category, note_text, sort_order) VALUES ('note', :text, :order)");
        $stmt->execute(['text' => $text, 'order' => $maxOrder + 1]);
        $message = '✅ 定型文を追加しました';
    }
}

// 編集処理
if (isset($_POST['edit'])) {
    $id = (int)$_POST['id'];
    $text = trim($_POST['note_text'] ?? '');
    if (empty($text)) {
        $errors[] = '定型文を入力してください';
    } else {
        $stmt = $pdo->prepare("UPDATE note_templates SET note_text = :text WHERE id = :id AND note_category = 'note'");
        $stmt->execute(['text' => $text, 'id' => $id]);
        $message = '✅ 定型文を更新しました';
    }
}

// 削除処理（論理削除）
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $pdo->prepare("UPDATE note_templates SET is_active = false WHERE id = :id AND note_category = 'note'");
    $stmt->execute(['id' => $id]);
    $message = '✅ 定型文を無効にしました';
    header('Location: note.php');
    exit;
}

// 一覧取得
$templates = $pdo->query("SELECT * FROM note_templates WHERE note_category = 'note' AND is_active = true ORDER BY sort_order")->fetchAll();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>備考定型文 - yotei</title>
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
        .form-group input, .form-group textarea { width: 100%; padding: 6px 10px; border: 1px solid #e2e8f0; border-radius: 4px; font-size: 14px; font-family: inherit; }
        .form-group input:focus, .form-group textarea:focus { outline: none; border-color: #2C6E9C; }
        .form-group textarea { resize: vertical; min-height: 60px; }
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
        .action-group { display: flex; gap: 4px; flex-wrap: wrap; }
        .row { display: flex; gap: 12px; }
        .row .form-group { flex: 1; }
        .sort-order { font-weight: 600; color: #2C6E9C; text-align: center; }
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
        <a href="department.php">🏥 部門</a>
        <a href="note.php" class="active">📝 備考定型文</a>
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
        <div class="card-title">➕ 定型文追加</div>
        <form method="POST">
            <div class="form-group">
                <label>定型文</label>
                <textarea name="note_text" placeholder="例: 受付中止（99:00以降）"><?= htmlspecialchars($_POST['note_text'] ?? '') ?></textarea>
            </div>
            <button type="submit" name="add" class="btn btn-success">➕ 追加</button>
        </form>
    </div>

    <!-- 一覧 -->
    <div class="card">
        <div class="card-title">📋 定型文一覧</div>
        <?php if (empty($templates)): ?>
        <p style="color:#a0aec0;text-align:center;padding:20px;">定型文が登録されていません</p>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th style="width:50px;">順</th>
                    <th>定型文</th>
                    <th style="width:160px;">操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($templates as $t): ?>
                <tr>
                    <td class="sort-order"><?= $t['sort_order'] ?></td>
                    <td>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="edit" value="1">
                            <input type="hidden" name="id" value="<?= $t['id'] ?>">
                            <input type="text" name="note_text" value="<?= htmlspecialchars($t['note_text']) ?>" style="width:100%;padding:4px 8px;border:1px solid #e2e8f0;border-radius:4px;font-size:13px;">
                            <button type="submit" class="btn btn-sm btn-warning">✏️</button>
                        </form>
                    </td>
                    <td>
                        <div class="action-group">
                            <a href="?move_up=<?= $t['id'] ?>" class="btn btn-sm btn-primary" title="上へ">↑</a>
                            <a href="?move_down=<?= $t['id'] ?>" class="btn btn-sm btn-primary" title="下へ">↓</a>
                            <a href="?delete=<?= $t['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('この定型文を無効にしますか？')">🗑️</a>
                        </div>
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