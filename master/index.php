<?php
// ============================================================
// ファイル名: master/index.php
// システム名: 医師予定表管理システム（yotei）
// バージョン: v1.0
// 生成日時: 2026-08-07
// ============================================================
//
// 【概要】
// 医師マスタの一覧表示・表示順変更
// ============================================================

require_once __DIR__ . '/../LIB/db.php';
require_once __DIR__ . '/../LIB/master_auth.php';

// 管理者パスワード認証チェック
requireMasterAuth('../index.php');

$pdo = getDbConnection();

// 表示順変更（↑）
if (isset($_GET['move_up']) && is_numeric($_GET['move_up'])) {
    $id = (int)$_GET['move_up'];
    $stmt = $pdo->prepare("SELECT sort_order FROM doctors WHERE id = :id");
    $stmt->execute(['id' => $id]);
    $current = $stmt->fetch();
    if ($current) {
        $stmt = $pdo->prepare("SELECT id, sort_order FROM doctors WHERE sort_order < :order AND is_active = true ORDER BY sort_order DESC LIMIT 1");
        $stmt->execute(['order' => $current['sort_order']]);
        $prev = $stmt->fetch();
        if ($prev) {
            $pdo->prepare("UPDATE doctors SET sort_order = :new WHERE id = :id")->execute(['new' => $prev['sort_order'], 'id' => $id]);
            $pdo->prepare("UPDATE doctors SET sort_order = :new WHERE id = :id")->execute(['new' => $current['sort_order'], 'id' => $prev['id']]);
        }
    }
    header('Location: index.php');
    exit;
}

// 表示順変更（↓）
if (isset($_GET['move_down']) && is_numeric($_GET['move_down'])) {
    $id = (int)$_GET['move_down'];
    $stmt = $pdo->prepare("SELECT sort_order FROM doctors WHERE id = :id");
    $stmt->execute(['id' => $id]);
    $current = $stmt->fetch();
    if ($current) {
        $stmt = $pdo->prepare("SELECT id, sort_order FROM doctors WHERE sort_order > :order AND is_active = true ORDER BY sort_order ASC LIMIT 1");
        $stmt->execute(['order' => $current['sort_order']]);
        $next = $stmt->fetch();
        if ($next) {
            $pdo->prepare("UPDATE doctors SET sort_order = :new WHERE id = :id")->execute(['new' => $next['sort_order'], 'id' => $id]);
            $pdo->prepare("UPDATE doctors SET sort_order = :new WHERE id = :id")->execute(['new' => $current['sort_order'], 'id' => $next['id']]);
        }
    }
    header('Location: index.php');
    exit;
}

// 医師一覧
$stmt = $pdo->query("
    SELECT d.*, dep.name AS department_name
    FROM doctors d
    LEFT JOIN departments dep ON d.department_id = dep.id
    WHERE d.is_active = true
    ORDER BY d.sort_order
");
$doctors = $stmt->fetchAll();

$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>医師マスタ - yotei</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Helvetica Neue', Arial, 'Hiragino Sans', 'Noto Sans JP', sans-serif; background: #f0f2f5; color: #2d3748; padding: 20px; }
        .container { max-width: 1000px; margin: 0 auto; }
        .header { display: flex; align-items: center; justify-content: space-between; background: white; padding: 16px 24px; border-radius: 10px; box-shadow: 0 2px 6px rgba(0,0,0,0.06); margin-bottom: 20px; }
        .header h1 { font-size: 20px; font-weight: 700; color: #2C6E9C; }
        .header .back-link { color: #718096; text-decoration: none; font-size: 14px; }
        .header .back-link:hover { color: #2C6E9C; }
        .nav-tabs { display: flex; gap: 4px; margin-bottom: 16px; flex-wrap: wrap; }
        .nav-tabs a { padding: 8px 20px; background: white; border-radius: 8px 8px 0 0; text-decoration: none; color: #4a5568; border: 1px solid #e2e8f0; border-bottom: none; font-weight: 600; }
        .nav-tabs a.active { background: #2C6E9C; color: white; border-color: #2C6E9C; }
        .nav-tabs a:hover:not(.active) { background: #edf2f7; }
        .card { background: white; border-radius: 10px; padding: 24px; box-shadow: 0 2px 6px rgba(0,0,0,0.06); margin-bottom: 16px; }
        .card-title { font-size: 16px; font-weight: 700; margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center; }
        .message { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; background: #c6f6d5; color: #22543d; border: 1px solid #9ae6b4; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-size: 12px; font-weight: 600; color: #a0aec0; padding: 8px 12px; border-bottom: 2px solid #e2e8f0; background: #f7fafc; }
        td { padding: 8px 12px; border-bottom: 1px solid #edf2f7; font-size: 14px; vertical-align: middle; }
        tr:hover td { background: #f7fafc; }
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
        .badge-inactive { font-size: 11px; background: #fc8181; color: white; padding: 1px 8px; border-radius: 10px; }
        .text-muted { color: #a0aec0; }
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
        <a href="index.php" class="active">👨‍⚕️ 医師</a>
        <a href="department.php">🏥 部門</a>
        <a href="note.php">📝 備考定型文</a>
        <a href="all_meeting.php">📅 全体会</a>
    </div>

    <?php if ($message): ?>
    <div class="message"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-title">
            <span>👨‍⚕️ 医師一覧</span>
            <a href="doctor_add.php" class="btn btn-success">➕ 新規追加</a>
        </div>

        <?php if (empty($doctors)): ?>
        <p style="color:#a0aec0;text-align:center;padding:20px;">医師が登録されていません</p>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th style="width:50px;">順</th>
                    <th>略称</th>
                    <th>氏名</th>
                    <th>肩書</th>
                    <th>所属</th>
                    <th style="width:140px;">操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($doctors as $doc): ?>
                <tr>
                    <td class="sort-order"><?= $doc['sort_order'] ?></td>
                    <td><?= htmlspecialchars($doc['short_name']) ?></td>
                    <td><?= htmlspecialchars($doc['last_name'] . $doc['first_name']) ?></td>
                    <td><?= htmlspecialchars($doc['title'] ?? '') ?></td>
                    <td><?= htmlspecialchars($doc['department_name'] ?? '') ?></td>
                    <td>
                        <div class="action-group">
                            <a href="?move_up=<?= $doc['id'] ?>" class="btn btn-sm btn-primary" title="上へ">↑</a>
                            <a href="?move_down=<?= $doc['id'] ?>" class="btn btn-sm btn-primary" title="下へ">↓</a>
                            <a href="doctor_edit.php?id=<?= $doc['id'] ?>" class="btn btn-sm btn-warning">✏️</a>
                            <a href="doctor_delete.php?id=<?= $doc['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('この医師を無効にしますか？')">🗑️</a>
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