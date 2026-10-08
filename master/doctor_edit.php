<?php
// ============================================================
// ファイル名: master/doctor_edit.php
// システム名: 医師予定表管理システム（yotei）
// バージョン: v1.0
// 生成日時: 2026-08-07
// ============================================================
//
// 【概要】
// 医師情報を編集する
// ============================================================

require_once __DIR__ . '/../LIB/db.php';
require_once __DIR__ . '/../LIB/master_auth.php';

// 管理者パスワード認証チェック
requireMasterAuth('index.php');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
    header('Location: index.php');
    exit;
}

$pdo = getDbConnection();

// 医師情報取得
$stmt = $pdo->prepare("SELECT * FROM doctors WHERE id = :id AND is_active = true");
$stmt->execute(['id' => $id]);
$doctor = $stmt->fetch();
if (!$doctor) {
    $_SESSION['message'] = '❌ 指定された医師が見つかりません';
    header('Location: index.php');
    exit;
}

// 部門一覧
$departments = $pdo->query("SELECT id, display_name FROM departments WHERE is_active = true ORDER BY sort_order")->fetchAll();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $lastName = trim($_POST['last_name'] ?? '');
    $firstName = trim($_POST['first_name'] ?? '');
    $shortName = trim($_POST['short_name'] ?? '');
    $title = trim($_POST['title'] ?? '');
    $departmentId = $_POST['department_id'] ?? null;
    $sortOrder = (int)($_POST['sort_order'] ?? $doctor['sort_order']);

    if (empty($lastName)) $errors[] = '姓を入力してください';
    if (empty($firstName)) $errors[] = '名を入力してください';
    if (empty($shortName)) $errors[] = '略称を入力してください';

    // 略称の重複チェック（自分以外）
    if (!empty($shortName) && $shortName !== $doctor['short_name']) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM doctors WHERE short_name = :short_name AND id != :id AND is_active = true");
        $stmt->execute(['short_name' => $shortName, 'id' => $id]);
        if ($stmt->fetchColumn() > 0) {
            $errors[] = 'この略称は既に使用されています';
        }
    }

    if (empty($errors)) {
        $stmt = $pdo->prepare("
            UPDATE doctors SET
                last_name = :last_name,
                first_name = :first_name,
                short_name = :short_name,
                title = :title,
                department_id = :department_id,
                sort_order = :sort_order
            WHERE id = :id
        ");
        $stmt->execute([
            'last_name' => $lastName,
            'first_name' => $firstName,
            'short_name' => $shortName,
            'title' => $title,
            'department_id' => $departmentId ? (int)$departmentId : null,
            'sort_order' => $sortOrder,
            'id' => $id,
        ]);
        $_SESSION['message'] = '✅ 医師情報を更新しました';
        header('Location: index.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>医師編集 - yotei</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Helvetica Neue', Arial, 'Hiragino Sans', 'Noto Sans JP', sans-serif; background: #f0f2f5; color: #2d3748; padding: 20px; }
        .container { max-width: 600px; margin: 0 auto; }
        .header { display: flex; align-items: center; justify-content: space-between; background: white; padding: 16px 24px; border-radius: 10px; box-shadow: 0 2px 6px rgba(0,0,0,0.06); margin-bottom: 20px; }
        .header h1 { font-size: 20px; font-weight: 700; color: #2C6E9C; }
        .header .back-link { color: #718096; text-decoration: none; font-size: 14px; }
        .card { background: white; border-radius: 10px; padding: 24px; box-shadow: 0 2px 6px rgba(0,0,0,0.06); }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-weight: 600; font-size: 14px; color: #4a5568; margin-bottom: 4px; }
        .form-group input, .form-group select { width: 100%; padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px; }
        .form-group input:focus, .form-group select:focus { outline: none; border-color: #2C6E9C; box-shadow: 0 0 0 3px rgba(44,110,156,0.15); }
        .form-group .help { font-size: 12px; color: #a0aec0; margin-top: 4px; }
        .error-msg { color: #e53e3e; font-size: 14px; margin-bottom: 12px; }
        .btn { padding: 10px 28px; border: none; border-radius: 8px; font-size: 15px; font-weight: 600; cursor: pointer; transition: all 0.2s; }
        .btn:hover { transform: translateY(-1px); }
        .btn-primary { background: #2C6E9C; color: white; }
        .btn-primary:hover { background: #1a4a6e; }
        .btn-secondary { background: #e2e8f0; color: #4a5568; }
        .btn-secondary:hover { background: #cbd5e0; }
        .form-actions { display: flex; gap: 12px; margin-top: 20px; }
        .row { display: flex; gap: 12px; }
        .row .form-group { flex: 1; }
        .id-badge { font-size: 12px; color: #a0aec0; }
    </style>
</head>
<body>
<div class="container">

    <div class="header">
        <h1>✏️ 医師編集</h1>
        <a href="index.php" class="back-link">← 一覧に戻る</a>
    </div>

    <div class="card">
        <div style="margin-bottom:16px;font-size:13px;color:#a0aec0;">ID: <?= $doctor['id'] ?></div>

        <?php if (!empty($errors)): ?>
        <div class="error-msg">
            <?php foreach ($errors as $e): ?>
            <div>⚠️ <?= htmlspecialchars($e) ?></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <form method="POST">
            <div class="row">
                <div class="form-group">
                    <label>姓（必須）</label>
                    <input type="text" name="last_name" value="<?= htmlspecialchars($_POST['last_name'] ?? $doctor['last_name']) ?>">
                </div>
                <div class="form-group">
                    <label>名（必須）</label>
                    <input type="text" name="first_name" value="<?= htmlspecialchars($_POST['first_name'] ?? $doctor['first_name']) ?>">
                </div>
            </div>

            <div class="form-group">
                <label>略称（必須・ユニーク）</label>
                <input type="text" name="short_name" value="<?= htmlspecialchars($_POST['short_name'] ?? $doctor['short_name']) ?>">
                <div class="help">表示名として使用されます（例: 予定表の「誠吾」）</div>
            </div>

            <div class="form-group">
                <label>肩書</label>
                <input type="text" name="title" value="<?= htmlspecialchars($_POST['title'] ?? $doctor['title']) ?>" placeholder="例: 院長">
            </div>

            <div class="form-group">
                <label>所属部門</label>
                <select name="department_id">
                    <option value="">未所属</option>
                    <?php foreach ($departments as $dept): ?>
                    <option value="<?= $dept['id'] ?>" <?= (($doctor['department_id'] ?? '') == $dept['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($dept['display_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>表示順</label>
                <input type="number" name="sort_order" value="<?= htmlspecialchars($_POST['sort_order'] ?? $doctor['sort_order']) ?>">
                <div class="help">数値が小さいほど上位に表示されます</div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">💾 更新</button>
                <a href="index.php" class="btn btn-secondary">キャンセル</a>
            </div>
        </form>
    </div>

</div>
</body>
</html>