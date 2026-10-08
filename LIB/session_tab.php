<?php
/**
 * ============================================================
 * ファイル名: session_tab.php
 * システム名: 医師予定表管理システム（yotei）
 * バージョン: v1.1
 * 生成日時: 2026-08-24 22:00
 * 最終更新: 2026-08-24 22:00
 * ============================================================
 *
 * 【概要】
 * タブ単位セッション管理共通ライブラリ
 * ブラウザのタブごとに独立したログイン状態を維持する
 *
 * 【設計意図】
 * - sessionStorage と URLパラメータを組み合わせてタブIDを管理
 * - 右クリック「新しいタブで開く」によるIDコピー問題を解決
 * - 各画面で共通して使える関数群を提供
 *
 * 【提供関数】
 * - get_tab_id(): 現在のタブIDを取得
 * - current_tab_user(): 現在のタブのユーザー情報を取得
 * - tab_login($userData): 現在のタブにユーザー情報を保存
 * - tab_logout(): 現在のタブのユーザー情報を削除
 * - is_logged_in(): 現在のタブがログイン状態か判定
 * - url($path): 現在のタブIDを付与したURLを生成（HTML用）
 * - redirect_url($path): リダイレクト用URLを生成
 * - check_tab_timeout($timeout_seconds): タブ単位のタイムアウトチェック
 *
 * 【変更履歴】
 * 2026-08-24 22:00 v1.1 JS（sessionStorage）を有効化（リダイレクトループ対策付き）（事務長）
 * 2026-08-24 21:50 v1.0 新規作成（pfuto / 事務長）
 * ============================================================
 */

// ============================================================
// 1. セッション開始
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================================
// 2. タブIDの取得と正規化
// ============================================================

/**
 * 現在のタブIDを取得する（GET/POST/Cookie から順に探す）
 * 
 * @return string タブID（例: tab_abc123）
 */
function get_tab_id() {
    // GET → POST → Cookie → 新規発行 の順で取得
    $tab_id = $_GET['tab_id'] ?? $_POST['tab_id'] ?? $_COOKIE['yotei_tab_id'] ?? null;
    
    // 不正な値の場合は新規発行
    if (empty($tab_id) || !preg_match('/^tab_[a-zA-Z0-9]+$/', $tab_id)) {
        $tab_id = 'tab_' . bin2hex(random_bytes(4));
    }
    
    return $tab_id;
}

/**
 * グローバル変数 $tab_id を設定
 * すべての関数から参照可能にする
 */
$tab_id = get_tab_id();

// ============================================================
// 3. 共通関数群
// ============================================================

/**
 * 現在のタブのユーザー情報を取得
 *
 * @return array|null ユーザー情報（未ログインなら null）
 */
function current_tab_user() {
    global $tab_id;
    if (isset($_SESSION['instances'][$tab_id])) {
        return $_SESSION['instances'][$tab_id];
    }
    // 互換性変数からの復元チェック（全タブ共通セッションがあればタブにも適用）
    if (!empty($_SESSION['staff_id']) && !empty($_SESSION['staff_name'])) {
        $userData = [
            'staff_id'         => (int)$_SESSION['staff_id'],
            'staff_name'       => $_SESSION['staff_name'],
            'role'             => $_SESSION['role'] ?? '',
            'is_admin'         => (bool)($_SESSION['is_admin'] ?? false),
            'permission_level' => !empty($_SESSION['is_admin']) ? 9 : 1,
            'login_at'         => date('Y-m-d H:i:s'),
            'last_activity'    => time(),
        ];
        $_SESSION['instances'][$tab_id] = $userData;
        return $userData;
    }
    // 端末記憶Cookieからの自動復元チェック
    if (!empty($_COOKIE['yotei_device_token'])) {
        require_once __DIR__ . '/auth_helper.php';
        $pdo = getKawaraDbConnection();
        $staff = verifyDeviceRememberCookie($pdo);
        if ($staff) {
            setupStaffSession($staff);
            return $_SESSION['instances'][$tab_id] ?? null;
        }
    }
    return null;
}

/**
 * 現在のタブがログイン状態か判定
 *
 * @return bool
 */
function is_logged_in() {
    return current_tab_user() !== null;
}

/**
 * 現在のタブにユーザー情報を保存（ログイン）
 *
 * @param array $userData ユーザー情報（staff_id, staff_name, role, is_admin など）
 */
function tab_login(array $userData) {
    global $tab_id;
    
    // セッションIDを再生成（セキュリティ対策）
    if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
        session_regenerate_id(true);
    }
    
    // タブごとにユーザー情報を保存
    $_SESSION['instances'][$tab_id] = $userData;
    
    // 互換性のため従来の変数にも保存（既存コード対応）
    $_SESSION['staff_id'] = $userData['staff_id'] ?? null;
    $_SESSION['staff_name'] = $userData['staff_name'] ?? null;
    $_SESSION['role'] = $userData['role'] ?? null;
    $_SESSION['is_admin'] = $userData['is_admin'] ?? false;
    $_SESSION['last_activity'] = time();
}

/**
 * 現在のタブをログアウト（タブ単位）
 */
function tab_logout() {
    global $tab_id;
    
    if (isset($_SESSION['instances'][$tab_id])) {
        unset($_SESSION['instances'][$tab_id]);
    }
    
    // 互換性変数もクリア（このタブのものだけ）
    // 他のタブの情報は維持するため、$tab_id に紐づく情報だけを削除
    // ただし、従来の $_SESSION['staff_id'] などは全タブ共通のため、
    // 現在のタブが最後のタブの場合のみクリアする
    if (empty($_SESSION['instances'])) {
        // 全タブのインスタンスがなくなった場合のみ従来変数をクリア
        unset($_SESSION['staff_id']);
        unset($_SESSION['staff_name']);
        unset($_SESSION['role']);
        unset($_SESSION['is_admin']);
        unset($_SESSION['last_activity']);
    }
}

/**
 * 現在のタブIDを付与したURLを生成（HTML出力用）
 *
 * @param string $path ベースパス（例: 'index.php', 'calendar.php?year=2026'）
 * @return string タブID付きURL（HTMLエスケープ済み）
 */
function url($path) {
    global $tab_id;
    $separator = (strpos($path, '?') !== false) ? '&' : '?';
    return htmlspecialchars($path . $separator . 'tab_id=' . $tab_id, ENT_QUOTES, 'UTF-8');
}

/**
 * リダイレクト用のURLを生成（htmlspecialchars なし）
 *
 * @param string $path ベースパス
 * @return string タブID付きURL（生の文字列）
 */
function redirect_url($path) {
    global $tab_id;
    $separator = (strpos($path, '?') !== false) ? '&' : '?';
    return $path . $separator . 'tab_id=' . $tab_id;
}

/**
 * 現在のタブのタイムアウトをチェック（タブ単位）
 * タイムアウトしていたらログアウト状態にする
 * 
 * @param int $timeout_seconds タイムアウト秒数（デフォルト: 1800秒 = 30分）
 * @return bool タイムアウトしていたら true
 */
function check_tab_timeout($timeout_seconds = 1800) {
    global $tab_id;
    
    if (!isset($_SESSION['instances'][$tab_id])) {
        return false;
    }
    
    $last_activity = $_SESSION['instances'][$tab_id]['last_activity'] ?? 0;
    if (time() - $last_activity > $timeout_seconds) {
        // タイムアウト → このタブをログアウト
        tab_logout();
        return true;
    }
    
    // アクティビティを更新
    $_SESSION['instances'][$tab_id]['last_activity'] = time();
    return false;
}

// ============================================================
// 4. JavaScript（sessionStorage によるタブID管理）
// 有効化版（リダイレクトループ対策付き）
// ============================================================
?>
<script>
(function() {
    'use strict';

    // sessionStorage にタブIDを保存（タブごとに独立）
    let localTabId = sessionStorage.getItem('yotei_tab_id');
    const urlParams = new URLSearchParams(window.location.search);
    const urlTabId = urlParams.get('tab_id');

    // sessionStorage にタブIDがなければ新規発行
    if (!localTabId) {
        localTabId = 'tab_' + Math.random().toString(36).substring(2, 10);
        sessionStorage.setItem('yotei_tab_id', localTabId);
    }

    // ★ デバッグログ（コンソールに表示）
    console.log('【タブID】sessionStorage: ' + localTabId + ', URL: ' + urlTabId);

    // ★ URLの tab_id と sessionStorage が一致しない場合のみリダイレクト
    // これにより「右クリック→新規タブ」でIDがコピーされる問題を解決
    if (urlTabId !== localTabId) {
        urlParams.set('tab_id', localTabId);
        // ★ リダイレクトループ防止フラグ
        if (!sessionStorage.getItem('yotei_redirecting')) {
            sessionStorage.setItem('yotei_redirecting', 'true');
            window.location.search = urlParams.toString();
        } else {
            sessionStorage.removeItem('yotei_redirecting');
            console.warn('【タブID】リダイレクトループ検出 → 停止');
        }
    } else {
        // 一致していたらリダイレクトフラグを解除
        sessionStorage.removeItem('yotei_redirecting');
    }

    // ★ フォーム送信時に tab_id を自動付与（POST用）
    document.addEventListener('submit', function(e) {
        const form = e.target;
        if (form.method.toUpperCase() === 'POST') {
            let existing = form.querySelector('input[name="tab_id"]');
            if (!existing) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'tab_id';
                input.value = localTabId;
                form.appendChild(input);
            } else {
                existing.value = localTabId;
            }
        }
    });

    // ★ リンククリック時に tab_id を自動付与（GET用・通常の<a>タグ）
    document.addEventListener('click', function(e) {
        const link = e.target.closest('a');
        if (link && link.href && link.href.indexOf('tab_id=') === -1) {
            // 同一サイト内のリンクのみ処理（外部リンクはスキップ）
            if (link.href.indexOf(window.location.origin) === 0 || link.href.startsWith('/') || link.href.startsWith('./') || link.href.startsWith('../')) {
                const separator = link.href.includes('?') ? '&' : '?';
                link.href = link.href + separator + 'tab_id=' + localTabId;
            }
        }
    });

})();
</script>
<?php
// ============================================================
// 5. デバッグ出力（必要に応じて有効化）
// ============================================================
/*
echo '<pre style="background:#f0f0f0;padding:10px;border:1px solid #ccc;font-size:12px;">';
echo '=== session_tab.php デバッグ ===' . "\n";
echo 'tab_id: ' . ($tab_id ?? '(なし)') . "\n";
echo '$_SESSION[\'instances\']: ' . "\n";
print_r($_SESSION['instances'] ?? '(なし)');
echo 'is_logged_in(): ' . (is_logged_in() ? 'true' : 'false') . "\n";
echo '</pre>';
*/