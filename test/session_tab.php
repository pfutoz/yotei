<?php
// session_tab.php

function current_tab_user() {
    global $tab_id;
    return $_SESSION['instances'][$tab_id] ?? null;
}

function tab_login(array $userData) {
    global $tab_id;
    $_SESSION['instances'][$tab_id] = $userData;
}

function tab_logout() {
    global $tab_id;
    if (isset($_SESSION['instances'][$tab_id])) {
        unset($_SESSION['instances'][$tab_id]);
    }
}

function url($path) {
    global $tab_id;
    $separator = (strpos($path, '?') !== false) ? '&' : '?';
    return htmlspecialchars($path . $separator . 'tab_id=' . $tab_id, ENT_QUOTES, 'UTF-8');
}

// セッション開始
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$tab_id = $_GET['tab_id'] ?? $_POST['tab_id'] ?? null;
?>
<script>
(function() {
    // 1. このタブ固有のIDを sessionStorage から取得
    let localTabId = sessionStorage.getItem('app_tab_id');
    const urlParams = new URLSearchParams(window.location.search);
    const urlTabId = urlParams.get('tab_id');

    // 新規タブ、またはタブ複製等で sessionStorage がまだ無い場合
    if (!localTabId) {
        // 新しいタブIDを採番
        localTabId = 'tab_' + Math.random().toString(36).substring(2, 10);
        sessionStorage.setItem('app_tab_id', localTabId);
    }

    // 2. URLの tab_id と、このタブ本来の localTabId が不一致なら正しいURLへ自動補正
    if (urlTabId !== localTabId) {
        urlParams.set('tab_id', localTabId);
        window.location.search = urlParams.toString();
    }
})();
</script>
<?php
// タブIDが存在しない場合は一時的なIDをセット（上記JSで即座にリロード補正されます）
if (empty($tab_id) || !preg_match('/^tab_[a-zA-Z0-9]+$/', $tab_id)) {
    $tab_id = 'tab_' . bin2hex(random_bytes(4));
}