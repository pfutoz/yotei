<?php
/**
 * ============================================================
 * ファイル名: logout.php
 * システム名: 医師予定表管理システム（yotei）
 * バージョン: v3.0（かわら版互換ログアウト ＆ 端末固定クリア）
 * ============================================================
 *
 * 【概要】
 * ログアウト処理
 * - セッション情報の破棄（タブセッション ＆ 通常セッション）
 * - スマホ端末自動固定（Remember Device）Cookieの削除
 * - ログイン画面へリダイレクト（?logout=1）
 */

require_once __DIR__ . '/LIB/auth_helper.php';

// 1. タブセッションのログアウト
tab_logout();

// 2. セッション配列のクリア
$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 3. 端末固定Cookieを削除
clearDeviceRememberCookie();

// 4. ログイン画面へリダイレクト
header('Location: login.php?logout=1');
exit;