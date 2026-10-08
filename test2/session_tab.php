<?php
// ============================================================
// ファイル名: session_tab.php
// 概要: タブID管理・セッション階層化・共通関数
// 変更履歴: 2026-08-24 v1.0 pfutoさんの記事から移植
// ============================================================

// 1. 共通ヘルパー関数
/**
 * 現在のタブのユーザー情報を取得
 */
function current_tab_user() {
    global $tab_id;
    return $_SESSION['instances'][$tab_id] ?? null;
}

/**
 * タブにログインユーザー情報を保存
 */
function tab_login(array $userData) {
    global $tab_id;
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    $_SESSION['instances'][$tab_id] = $userData;
}

/**
 * タブからログインユーザー情報を削除
 */
function tab_logout() {
    global $tab_id;
    if (isset($_SESSION['instances'][$tab_id])) {
        unset($_SESSION['instances'][$tab_id]);
    }
}

/**
 * 現在のタブIDを付与したURLを生成
 */
function url($path) {
    global $tab_id;
    $separator = (strpos($path, '?') !== false) ? '&' : '?';
    return htmlspecialchars($path . $separator . 'tab_id=' . $tab_id, ENT_QUOTES, 'UTF-8');
}

// 2. セッション開始
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 3. GET/POSTからタブIDを取得（初期値用）
$tab_id = $_GET['tab_id'] ?? $_POST['tab_id'] ?? null;

// 4. タブIDが不正な場合は新規発行（バリデーション）
if (empty($tab_id) || !preg_match('/^tab_[a-zA-Z0-9]+$/', $tab_id)) {
    $tab_id = 'tab_' . bin2hex(random_bytes(4));
}
?>
<!-- 5. タブ複製・新規タブ対策スクリプト（sessionStorage） -->
<script>
(function() {
    let localTabId = sessionStorage.getItem('app_tab_id');
    const urlParams = new URLSearchParams(window.location.search);
    const urlTabId = urlParams.get('tab_id');

    // sessionStorage にタブIDがなければ新規発行
    if (!localTabId) {
        localTabId = 'tab_' + Math.random().toString(36).substring(2, 10);
        sessionStorage.setItem('app_tab_id', localTabId);
    }

    // URLの tab_id と sessionStorage のタブIDが不一致なら、URLを自動補正
    if (urlTabId !== localTabId) {
        urlParams.set('tab_id', localTabId);
        window.location.search = urlParams.toString();
    }
})();
</script>
<?php
// 6. 最終的なタブIDをグローバル変数に再セット（JSがリダイレクトした場合は新しい値になる）
$tab_id = $_GET['tab_id'] ?? $_POST['tab_id'] ?? $tab_id;

// 7. タブIDを元に、セッション内の該当インスタンスを取得（他の共通関数からも使えるように）
// この時点で $tab_id は URL の値（JSで補正済み）で確定
?>