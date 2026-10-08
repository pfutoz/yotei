<?php
// ============================================================
// ファイル名: master/doctor_delete.php
// システム名: 医師予定表管理システム（yotei）
// バージョン: v1.0
// 生成日時: 2026-08-07
// ============================================================
//
// 【概要】
// 医師を論理削除（is_active = false）する
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

// 削除処理（論理削除）
$stmt = $pdo->prepare("UPDATE doctors SET is_active = false WHERE id = :id");
$stmt->execute(['id' => $id]);

$_SESSION['message'] = '✅ 医師「' . htmlspecialchars($doctor['short_name']) . '」を無効にしました';
header('Location: index.php');
exit;
?>