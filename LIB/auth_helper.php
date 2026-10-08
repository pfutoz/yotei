<?php
/**
 * ============================================================
 * ファイル名: auth_helper.php
 * システム名: 医師予定表管理システム（yotei）
 * バージョン: v6.0（かわら版統合認証 ＆ 端末自動固定対応）
 * ============================================================
 *
 * 【概要】
 * かわら版システムと共通化された認証およびスマホ端末自動固定（Remember Device）ヘルパー
 * session_tab.php とも連携し、タブセッションと通常セッションの双方を確立する
 */

require_once __DIR__ . '/session_tab.php';
require_once __DIR__ . '/db.php';

// 認証署名用秘密鍵（外部改ざん防止）
if (!defined('YOTEI_AUTH_SECRET')) {
    define('YOTEI_AUTH_SECRET', 'yotei_onokai_secure_device_secret_2026_xyz');
}

/**
 * かわら版データベース（kawara）へのPDO接続を取得
 * 
 * @return PDO
 */
function getKawaraDbConnection() {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', DB_HOST, DB_PORT, 'kawara');
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    }
    return $pdo;
}

/**
 * 端末記憶用Cookie（180日間有効）を発行する
 * 
 * @param int $staff_id
 * @param int $days 有効日数（デフォルト180日）
 * @return bool
 */
function issueDeviceRememberCookie($staff_id, $days = 180) {
    $staff_id = (int)$staff_id;
    if ($staff_id <= 0) return false;

    $expires_at = time() + ($days * 86400);
    $payload = "{$staff_id}:{$expires_at}";
    $signature = hash_hmac('sha256', $payload, YOTEI_AUTH_SECRET);
    $cookie_value = base64_encode("{$payload}:{$signature}");

    // 180日間保持するCookieを設定
    $cookie_options = [
        'expires'  => $expires_at,
        'path'     => '/',
        'domain'   => '',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax'
    ];

    if (!headers_sent()) {
        setcookie('yotei_device_token', $cookie_value, $cookie_options);
    }
    return true;
}

/**
 * 端末記憶用Cookieを削除する
 */
function clearDeviceRememberCookie() {
    $cookie_options = [
        'expires'  => time() - 86400,
        'path'     => '/',
        'domain'   => '',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax'
    ];
    if (!headers_sent()) {
        setcookie('yotei_device_token', '', $cookie_options);
    }
    unset($_COOKIE['yotei_device_token']);
}

/**
 * 端末記憶Cookieからスタッフ情報を検証して取得する
 * 
 * @param PDO $pdo
 * @return array|null 認証成功時はスタッフ情報連想配列、失敗時はnull
 */
function verifyDeviceRememberCookie($pdo) {
    if (empty($_COOKIE['yotei_device_token'])) {
        return null;
    }

    $raw = base64_decode($_COOKIE['yotei_device_token'], true);
    if (!$raw) return null;

    $parts = explode(':', $raw);
    if (count($parts) !== 3) return null;

    list($staff_id_str, $expires_at_str, $signature) = $parts;
    $staff_id = (int)$staff_id_str;
    $expires_at = (int)$expires_at_str;

    // 期限切れチェック
    if ($expires_at < time()) {
        clearDeviceRememberCookie();
        return null;
    }

    // 署名検証
    $payload = "{$staff_id}:{$expires_at}";
    $expected_sig = hash_hmac('sha256', $payload, YOTEI_AUTH_SECRET);
    if (!hash_equals($expected_sig, $signature)) {
        clearDeviceRememberCookie();
        return null;
    }

    // DB存在確認
    try {
        $stmt = $pdo->prepare("SELECT * FROM staff WHERE staff_id = :id AND (is_deleted IS NOT TRUE)");
        $stmt->execute([':id' => $staff_id]);
        $staff = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($staff) {
            return $staff;
        } else {
            clearDeviceRememberCookie();
            return null;
        }
    } catch (Exception $e) {
        return null;
    }
}

/**
 * スタッフのログインセッションを確立する（かわら版セッション ＋ yoteiタブセッション両対応）
 * 
 * @param array $staff
 */
function setupStaffSession($staff) {
    $staffId = (int)$staff['staff_id'];
    $staffName = $staff['staff_name'];
    $role = $staff['role'] ?? '';
    $isAdmin = (bool)($staff['is_admin'] ?? false);

    // 1. かわら版互換セッション変数
    $_SESSION['staff_id'] = $staffId;
    $_SESSION['staff_name'] = $staffName;
    $_SESSION['role'] = $role;
    $_SESSION['is_admin'] = $isAdmin;
    $_SESSION['can_toggle_disaster'] = (bool)($staff['can_toggle_disaster'] ?? false);
    $_SESSION['last_activity'] = time();

    // 2. yoteiタブセッションへの保存
    tab_login([
        'staff_id'         => $staffId,
        'staff_name'       => $staffName,
        'role'             => $role,
        'is_admin'         => $isAdmin,
        'permission_level' => $isAdmin ? 9 : 1,
        'login_at'         => date('Y-m-d H:i:s'),
        'last_activity'    => time(),
    ]);

    // 3. 最近使ったスタッフ記録（セッション & クッキー）
    if (!isset($_SESSION['recent_staff_ids']) || !is_array($_SESSION['recent_staff_ids'])) {
        $_SESSION['recent_staff_ids'] = [];
    }
    $_SESSION['recent_staff_ids'] = array_diff($_SESSION['recent_staff_ids'], [$staffId]);
    array_unshift($_SESSION['recent_staff_ids'], $staffId);
    $_SESSION['recent_staff_ids'] = array_slice($_SESSION['recent_staff_ids'], 0, 10);

    // クッキーにも保存（ブラウザ再起動時の最近使ったスタッフ用）
    $cookieRecent = isset($_COOKIE['yotei_recent_staff']) ? explode(',', $_COOKIE['yotei_recent_staff']) : [];
    $cookieRecent = array_diff($cookieRecent, [(string)$staffId]);
    array_unshift($cookieRecent, (string)$staffId);
    $cookieRecent = array_slice($cookieRecent, 0, 5);
    if (!headers_sent()) {
        setcookie('yotei_recent_staff', implode(',', $cookieRecent), time() + 86400 * 30, '/');
    }
}

/**
 * ログイン状態を検証し、未ログインならCookieからの自動復元を試行する
 * 
 * @param PDO|null $pdo
 * @param string $redirect_target リダイレクト後の戻り先URL
 * @return array ログイン中のスタッフ情報
 */
function checkAuthOrAutoLogin($pdo = null, $redirect_target = '') {
    if ($pdo === null) {
        $pdo = getKawaraDbConnection();
    }

    // 1. セッションが存在する場合
    if (is_logged_in() || !empty($_SESSION['staff_id'])) {
        $sid = (int)($_SESSION['staff_id'] ?? current_tab_user()['staff_id'] ?? 0);
        if ($sid > 0) {
            $stmt = $pdo->prepare("SELECT * FROM staff WHERE staff_id = :id AND (is_deleted IS NOT TRUE)");
            $stmt->execute([':id' => $sid]);
            $staff = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($staff) {
                $_SESSION['last_activity'] = time();
                return $staff;
            } else {
                tab_logout();
            }
        }
    }

    // 2. セッションが切れていても、端末記憶Cookieがあれば自動復元
    $remembered_staff = verifyDeviceRememberCookie($pdo);
    if ($remembered_staff) {
        setupStaffSession($remembered_staff);
        // Cookieの有効期限をさらに180日延長（ローリング延長）
        issueDeviceRememberCookie($remembered_staff['staff_id'], 180);
        return $remembered_staff;
    }

    // 3. 認証不可なら login.php へリダイレクト
    if (empty($redirect_target)) {
        $redirect_target = $_SERVER['REQUEST_URI'] ?? 'index.php';
    }
    $login_url = 'login.php?redirect=' . urlencode($redirect_target);
    header("Location: {$login_url}");
    exit;
}
