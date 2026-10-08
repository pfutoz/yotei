<?php
/**
 * ============================================================
 * ファイル名: LIB/master_auth.php
 * システム名: 医師予定表管理システム（yotei）
 * ============================================================
 *
 * 【概要】
 * マスター管理・管理者用機能の共通認証ヘルパー
 * - ソースコードへのパスワード固定書ききりを完全撤廃
 * - かわら版スタッフDBの管理者（is_admin = true）のPINコード / パスワードで照合
 * - セッション維持・二重送信防止
 */

require_once __DIR__ . '/session_tab.php';
require_once __DIR__ . '/auth_helper.php';

/**
 * 入力されたパスワード／暗証番号が管理者のものか照合
 * @param string $inputPassword
 * @return bool
 */
function verifyAdminPassword($inputPassword) {
    $inputPassword = trim((string)$inputPassword);
    if ($inputPassword === '') {
        return false;
    }

    // 1. かわら版 staff テーブルの管理者 (is_admin = true) の pin_code と照合
    try {
        $pdo = getKawaraDbConnection();
        if ($pdo) {
            $stmt = $pdo->prepare("
                SELECT pin_code FROM staff 
                WHERE (is_deleted IS NOT TRUE) 
                  AND is_admin IS TRUE 
                  AND pin_code IS NOT NULL 
                  AND pin_code != ''
            ");
            $stmt->execute();
            $adminPins = $stmt->fetchAll(PDO::FETCH_COLUMN);
            foreach ($adminPins as $pin) {
                if (trim((string)$pin) === $inputPassword) {
                    return true;
                }
            }
        }
    } catch (Exception $e) {
        error_log('【verifyAdminPassword:kawara】' . $e->getMessage());
    }

    // 2. auth_db users テーブルの管理者 (is_admin = true) の password_hash と照合
    try {
        if (file_exists(__DIR__ . '/db_auth.php')) {
            require_once __DIR__ . '/db_auth.php';
            $pdoAuth = getAuthDbConnection();
            if ($pdoAuth) {
                $stmtAuth = $pdoAuth->prepare("
                    SELECT password_hash FROM users 
                    WHERE is_active IS TRUE 
                      AND is_admin IS TRUE 
                      AND password_hash IS NOT NULL 
                      AND password_hash != ''
                ");
                $stmtAuth->execute();
                $authPasses = $stmtAuth->fetchAll(PDO::FETCH_COLUMN);
                foreach ($authPasses as $hash) {
                    if (trim((string)$hash) === $inputPassword) {
                        return true;
                    }
                }
            }
        }
    } catch (Exception $e) {
        error_log('【verifyAdminPassword:auth_db】' . $e->getMessage());
    }

    return false;
}

/**
 * マスター管理アクセス権限の検証（未認証なら認証画面を出力して終了）
 * @param string $backUrl 戻り先URL（デフォルトは ../index.php）
 */
function requireMasterAuth($backUrl = '../index.php') {
    $authError = '';

    // POST認証リクエスト
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['auth_password'])) {
        $input = $_POST['auth_password'];
        if (verifyAdminPassword($input)) {
            $_SESSION['master_authorized'] = true;
            // リロード時のPOST再送信防止のため現在URLにリダイレクト
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;
        } else {
            $authError = '⚠️ パスワード（暗証番号）が正しくありません';
        }
    }

    // すでにセッション認証済みなら通過
    if (!empty($_SESSION['master_authorized'])) {
        return;
    }

    // 未認証時の認証フォーム画面
    $loginUser = current_tab_user();
    $userName = $loginUser['display_name'] ?? $loginUser['staff_name'] ?? '';
    ?>
    <!DOCTYPE html>
    <html lang="ja">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>管理者認証 - yotei</title>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Noto+Sans+JP:wght@400;500;700&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body {
                font-family: 'Inter', 'Noto Sans JP', sans-serif;
                background: #f8fafc;
                color: #1e293b;
                display: flex;
                align-items: center;
                justify-content: center;
                min-height: 100vh;
                padding: 20px;
            }
            .auth-card {
                background: white;
                padding: 36px 32px;
                border-radius: 16px;
                box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.08), 0 8px 10px -6px rgba(0, 0, 0, 0.04);
                border: 1px solid #e2e8f0;
                max-width: 420px;
                width: 100%;
                text-align: center;
            }
            .auth-icon {
                width: 56px;
                height: 56px;
                background: #eff6ff;
                color: #2563eb;
                border-radius: 50%;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                font-size: 24px;
                margin-bottom: 16px;
            }
            h1 {
                font-size: 1.25rem;
                font-weight: 700;
                color: #0f172a;
                margin-bottom: 8px;
            }
            p.desc {
                font-size: 0.88rem;
                color: #64748b;
                margin-bottom: 24px;
                line-height: 1.5;
            }
            .user-info {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                background: #f1f5f9;
                padding: 4px 12px;
                border-radius: 20px;
                font-size: 0.8rem;
                color: #475569;
                margin-bottom: 20px;
            }
            .auth-input-group {
                margin-bottom: 18px;
                text-align: left;
            }
            .auth-input-group label {
                display: block;
                font-size: 0.82rem;
                font-weight: 600;
                color: #475569;
                margin-bottom: 6px;
            }
            input[type="password"] {
                width: 100%;
                padding: 12px 14px;
                border: 1px solid #cbd5e1;
                border-radius: 8px;
                font-size: 16px;
                transition: all 0.2s;
            }
            input[type="password"]:focus {
                outline: none;
                border-color: #2563eb;
                box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
            }
            .error-box {
                background: #fef2f2;
                border: 1px solid #fecaca;
                color: #dc2626;
                padding: 10px 14px;
                border-radius: 8px;
                font-size: 0.85rem;
                margin-bottom: 16px;
                text-align: left;
            }
            .btn-submit {
                width: 100%;
                padding: 12px;
                background: #2563eb;
                color: white;
                border: none;
                border-radius: 8px;
                font-size: 0.95rem;
                font-weight: 600;
                cursor: pointer;
                transition: background 0.2s;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
            }
            .btn-submit:hover {
                background: #1d4ed8;
            }
            .back-link {
                display: inline-block;
                margin-top: 18px;
                color: #64748b;
                text-decoration: none;
                font-size: 0.85rem;
                transition: color 0.2s;
            }
            .back-link:hover {
                color: #0f172a;
            }
        </style>
    </head>
    <body>
        <div class="auth-card">
            <div class="auth-icon">
                <i class="fa-solid fa-shield-halved"></i>
            </div>
            <h1>管理者認証</h1>
            <p class="desc">
                マスターメンテナンス機能へ進むには、<br><strong>管理者パスワード（暗証番号）</strong>を入力してください。
            </p>

            <?php if ($userName): ?>
            <div class="user-info">
                <i class="fa-regular fa-user"></i> ログイン中: <strong><?= htmlspecialchars($userName) ?></strong>
            </div>
            <?php endif; ?>

            <?php if ($authError): ?>
            <div class="error-box">
                <?= htmlspecialchars($authError) ?>
            </div>
            <?php endif; ?>

            <form method="POST">
                <div class="auth-input-group">
                    <label for="auth_password">管理者パスワード / 暗証番号</label>
                    <input type="password" id="auth_password" name="auth_password" placeholder="パスワードを入力" required autofocus autocomplete="current-password">
                </div>
                <button type="submit" class="btn-submit">
                    <i class="fa-solid fa-unlock"></i> 認証して進む
                </button>
            </form>

            <a href="<?= htmlspecialchars($backUrl) ?>" class="back-link">
                <i class="fa-solid fa-arrow-left"></i> ポータルへ戻る
            </a>
        </div>
    </body>
    </html>
    <?php
    exit;
}
