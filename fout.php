
<?php
/**
 * ============================================================
 * ファイル名: fout.php
 * システム名: 医師予定表管理システム（yotei）
 * バージョン: v1.1
 * 生成日時: 2026-08-24 21:00
 * 最終更新: 2026-08-24 21:00
 * ============================================================
 *
 * 【概要】
 * 強制ログアウト処理（全セッション完全クリア）
 * セッション異常時の緊急用ログアウト
 *
 * 【設計意図】
 * - 全セッション情報を完全に破棄
 * - Cookieも削除（PHPSESSID）
 * - すべてのタブ・ウィンドウでログアウト状態になる
 * - fout = force logout（強制ログアウトの意）
 *
 * 【変更履歴】
 * 2026-08-24 21:00 v1.1 デバッグ出力を追加（事務長）
 * 2026-08-24 15:00 v1.0 新規作成（事務長）
 * ============================================================
 */

// ★★★ デバッグ出力 ★★★
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo '<pre style="background:#f0f0f0;padding:15px;border:1px solid #ccc;font-family:monospace;font-size:13px;">';
echo '=== fout.php デバッグ開始 ===' . "\n";

// セッション開始
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

echo 'セッションID: ' . session_id() . "\n";
echo '$_SESSION 内容（削除前）: ' . "\n";
print_r($_SESSION);

// ★ セッション変数をすべてクリア
$_SESSION = [];
echo "\n" . 'セッション変数クリア完了' . "\n";

// ★ セッションCookieを削除
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
    echo 'Cookie 削除: ' . session_name() . "\n";
} else {
    echo 'session.use_cookies が無効のためCookie削除スキップ' . "\n";
}


// ★ セッションを破棄
session_destroy();
echo 'セッション破棄完了' . "\n";

echo 'リダイレクト先: login.php?logout=force' . "\n";
echo '</pre>';

// ★ ログイン画面へリダイレクト（強制ログアウトフラグ付き）
header('Location: login.php?logout=force');
exit;