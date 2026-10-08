<?php
/**
 * ============================================================
 * ファイル名: change_password.php
 * システム名: 医師予定表管理システム（yotei）
 * バージョン: v1.2
 * 生成日時: 2026-08-24 17:30
 * 最終更新: 2026-08-24 17:30
 * ============================================================
 *
 * 【概要】
 * ログインユーザー自身のパスワードを変更する画面
 * window.name ベースのタブID管理に対応
 *
 * 【設計意図】
 * - X-Tab-ID ヘッダーからタブIDを取得
 * - 現在のタブのユーザー情報を取得
 * - パスワードは空（""）にも設定可能
 *
 * 【変更履歴】
 * 2026-08-24 17:30 v1.2 window.name ベースのタブID管理に対応（X-Tab-ID ヘッダー使用）（事務長）
 * 2026-08-24 12:15 v1.1 auth_db 接続を LIB/db_auth.php に共通化（事務長）
 * 2026-08-24 11:45 v1.0 新規作成（事務長）
 * ============================================================
 */

// セッション開始
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ★ タブIDを取得（X-Tab-ID ヘッダー → POST → GET → フォールバック）
$tab_id = $_SERVER['HTTP_X_TAB_ID'] ?? $_POST['tab_id'] ?? $_GET['tab_id'] ?? null;

// ★ 認証チェック（このタブのログイン状態を確認）
if (empty($tab_id) || !isset($_SESSION['instances'][$tab_id]['staff_id'])) {
    header('Location: login.php');
    exit;
}

$userId = $_SESSION['instances'][$tab_id]['staff_id'];

// ============================================================
// 1. auth_db に接続
// ============================================================
require_once __DIR__ . '/LIB/db_auth.php';

try {
    $pdo = getAuthDbConnection();
} catch (PDOException $e) {
    die('データベース接続エラー: ' . $e->getMessage());
}

// ============================================================
// 2. 変数初期化
// ============================================================
$error = '';
$success = false;

// ============================================================
// 3. パスワード変更処理
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (empty($currentPassword)) {
        $error = '現在のパスワードを入力してください';
    } else {
        $stmt = $pdo->prepare("
            SELECT password_hash
            FROM users
            WHERE user_id = :user_id
              AND is_active = true
        ");
        $stmt->execute(['user_id' => $userId]);
        $user = $stmt->fetch();

        if (!$user) {
            $error = 'ユーザー情報が見つかりません';
        } elseif ($user['password_hash'] !== $currentPassword) {
            $error = '現在のパスワードが正しくありません';
        } elseif ($newPassword !== $confirmPassword) {
            $error = '新しいパスワードと確認用が一致しません';
        } else {
            $stmt = $pdo->prepare("
                UPDATE users
                SET password_hash = :password,
                    updated_at = CURRENT_TIMESTAMP
                WHERE user_id = :user_id
            ");
            $stmt->execute([
                'password' => $newPassword,
                'user_id' => $userId,
            ]);

            session_regenerate_id(true);
            $success = true;

            try {
                $stmt = $pdo->prepare("
                    INSERT INTO password_change_history (user_id, new_password_hash, change_type, ip_address)
                    VALUES (:user_id, :password, 'self', :ip)
                ");
                $stmt->execute([
                    'user_id' => $userId,
                    'password' => $newPassword,
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                ]);
            } catch (Exception $e) {
                error_log('パスワード変更履歴記録エラー: ' . $e->getMessage());
            }
        }
    }
}

// ============================================================
// 4. ユーザー表示名を取得
// ============================================================
$stmt = $pdo->prepare("
    SELECT display_name
    FROM users
    WHERE user_id = :user_id
");
$stmt->execute(['user_id' => $userId]);
$userInfo = $stmt->fetch();
$displayName = $userInfo['display_name'] ?? $userId;

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>パスワード変更 - yotei</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Noto+Sans+JP:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', 'Noto Sans JP', 'Hiragino Sans', 'Helvetica Neue', Arial, sans-serif;
            background: #f0f4f8;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        .container {
            max-width: 500px;
            width: 100%;
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            padding: 40px 48px;
        }
        .header {
            text-align: center;
            padding-bottom: 24px;
            border-bottom: 2px solid #e8edf3;
            margin-bottom: 28px;
        }
        .header .icon {
            font-size: 40px;
            display: block;
            margin-bottom: 8px;
        }
        .header h1 {
            font-size: 22px;
            font-weight: 700;
            color: #1a2a3a;
        }
        .header .sub {
            font-size: 13px;
            color: #8a9aa8;
            margin-top: 4px;
        }
        .header .user-name {
            font-size: 14px;
            color: #2C6E9C;
            font-weight: 600;
            margin-top: 4px;
        }

        .success-msg {
            background: #c6f6d5;
            color: #276749;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #38a169;
            font-size: 14px;
        }
        .error-msg {
            background: #fff5f5;
            color: #e53e3e;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #e53e3e;
            font-size: 14px;
        }

        .form-group {
            margin-bottom: 18px;
        }
        .form-group label {
            display: block;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 4px;
            font-size: 14px;
        }
        .form-group input {
            width: 100%;
            padding: 10px 14px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 16px;
            transition: border-color 0.3s;
        }
        .form-group input:focus {
            outline: none;
            border-color: #2C6E9C;
            box-shadow: 0 0 0 3px rgba(44,110,156,0.15);
        }
        .form-group .hint {
            font-size: 12px;
            color: #8a9aa8;
            margin-top: 4px;
        }

        .form-actions {
            display: flex;
            gap: 12px;
            margin-top: 8px;
        }
        .btn {
            padding: 10px 28px;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn:hover { transform: translateY(-1px); }
        .btn-primary {
            background: #2C6E9C;
            color: white;
        }
        .btn-primary:hover { background: #1a4a6e; }
        .btn-secondary {
            background: #e2e8f0;
            color: #4a5568;
        }
        .btn-secondary:hover { background: #cbd5e0; }
        .btn-success {
            background: #48bb78;
            color: white;
        }
        .btn-success:hover { background: #38a169; }

        .footer {
            text-align: center;
            margin-top: 20px;
            font-size: 12px;
            color: #a0aec0;
        }
        .footer a {
            color: #2C6E9C;
            text-decoration: none;
        }
        .footer a:hover { text-decoration: underline; }

        @media (max-width: 600px) {
            .container { padding: 24px; }
            .form-actions { flex-direction: column; }
            .form-actions .btn { justify-content: center; }
        }
    </style>
</head>
<body>
    <div class="container">

        <div class="header">
            <span class="icon">🔑</span>
            <h1>パスワード変更</h1>
            <div class="sub">現在のパスワードを確認後、新しいパスワードに変更します</div>
            <div class="user-name">👤 <?= htmlspecialchars($displayName) ?></div>
        </div>

        <?php if ($success): ?>
        <div class="success-msg">
            ✅ パスワードを変更しました。
            <?php if ($newPassword === ''): ?>
            （パスワードは空になりました）
            <?php endif; ?>
        </div>
        <div style="text-align:center;margin-top:16px;">
            <a href="index.php" class="btn btn-success"><i class="fa-solid fa-arrow-left"></i> 予定表へ戻る</a>
        </div>
        <?php else: ?>

        <?php if ($error): ?>
        <div class="error-msg">⚠️ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" id="changePasswordForm">
            <input type="hidden" name="tab_id" value="<?= htmlspecialchars($tab_id) ?>">
            <div class="form-group">
                <label for="current_password">🔒 現在のパスワード</label>
                <input type="password" id="current_password" name="current_password" placeholder="現在のパスワードを入力" required autofocus>
            </div>

            <div class="form-group">
                <label for="new_password">🆕 新しいパスワード</label>
                <input type="password" id="new_password" name="new_password" placeholder="新しいパスワードを入力（空でもOK）">
                <div class="hint">※ 空欄にするとパスワードなしでログイン可能になります</div>
            </div>

            <div class="form-group">
                <label for="confirm_password">✅ 確認のため再入力</label>
                <input type="password" id="confirm_password" name="confirm_password" placeholder="新しいパスワードをもう一度入力">
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> 変更する</button>
                <a href="index.php" class="btn btn-secondary"><i class="fa-solid fa-times"></i> キャンセル</a>
            </div>
        </form>

        <?php endif; ?>

    </div>

    <script>
        document.getElementById('changePasswordForm')?.addEventListener('submit', function(e) {
            const newPass = document.getElementById('new_password').value;
            const confirmPass = document.getElementById('confirm_password').value;

            if (newPass === '' && confirmPass !== '') {
                e.preventDefault();
                alert('新しいパスワードを空にする場合は、確認用も空にしてください');
                document.getElementById('confirm_password').focus();
                return;
            }
        });
    </script>

    <!-- ★ タブID管理スクリプト -->
    <script src="assets/js/tab_manager.js"></script>
</body>
</html>