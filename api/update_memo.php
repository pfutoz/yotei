<?php
// ============================================================
// ファイル名: api/update_memo.php
// システム名: 医師予定表管理システム（yotei）
// バージョン: v1.0
// 生成日時: 2026-08-09
// ============================================================
//
// 【概要】
// メモを更新するAPIエンドポイント
// index.php / calendar.php からAjaxで呼び出される
// ============================================================

require_once __DIR__ . '/../LIB/db.php';

session_start();

// ログ出力
function writeLog($msg) {
    error_log('【api/update_memo.php】' . $msg);
}

writeLog('=== メモ更新API 起動 ===');

try {
    $pdo = getDbConnection();
    $text = $_POST['memo_text'] ?? '';
    $password = $_POST['password'] ?? '';
    
    writeLog("テキスト長: " . strlen($text) . "文字, パスワード: " . ($password ? '入力あり' : '入力なし'));
    
    require_once __DIR__ . '/../LIB/master_auth.php';
    
    // 管理者パスワードチェック
    if (!verifyAdminPassword($password)) {
        writeLog('【エラー】パスワード不一致');
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => '管理者パスワード（暗証番号）が間違っています']);
        exit;
    }
    
    // メモを更新
    $stmt = $pdo->prepare("
        INSERT INTO settings (setting_key, setting_value, setting_type, description)
        VALUES ('memo_text', :value, 'text', 'メモ（自由記述）')
        ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value
    ");
    $stmt->execute(['value' => trim($text)]);
    
    writeLog('【成功】メモを更新しました');
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    writeLog('【エラー】' . $e->getMessage() . ' (line ' . $e->getLine() . ')');
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}