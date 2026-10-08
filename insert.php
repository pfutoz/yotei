<?php
// ============================================================
// ファイル名: insert.php
// システム名: 医師予定表管理システム（yotei）
// バージョン: v2.2（return_to 対応版）
// 生成日時: 2026-08-10
// ============================================================
//
// 【概要】
// 新規予定登録処理
// return_to に基づいてリダイレクト先を切り替える
// ============================================================

require_once __DIR__ . '/LIB/db.php';

session_start();

// ============================================================
// 1. セッションデータ取得
// ============================================================
$data = $_SESSION['confirm_data'] ?? null;

if (!$data) {
    error_log('【insert.php】セッションデータがありません');
    header('Location: input.php');
    exit;
}

// 戻り先を取得
$returnTo = $_SESSION['return_to'] ?? 'index';

// データ取得
$doctorId = $data['doctor_id'];
$isStaff = $data['is_staff'] ?? false;
$startDate = $data['start_date'];
$endDate = $data['end_date'];
$startTime = $data['start_time'];
$endTime = $data['end_time'];
$eventType = $data['event_type'];
$title = $data['title'];
$note = $data['note'];
$isPublic = $data['is_public'];
$doctorName = $data['doctor_name'];
$dateType = $data['date_type'] ?? 'single';
$timeType = $data['time_type'] ?? 'allday';

try {
    $pdo = getDbConnection();
    $pdo->beginTransaction();

    // スタッフID取得（事務長）
    $staffId = null;
    if ($isStaff) {
        $stmt = $pdo->prepare("SELECT id FROM staff WHERE name = '事務長'");
        $stmt->execute();
        $staff = $stmt->fetch();
        $staffId = $staff ? $staff['id'] : null;
        if (!$staffId) {
            throw new Exception('事務長がスタッフマスタに登録されていません');
        }
        $doctorIdForDb = null;
    } else {
        $doctorIdForDb = (int)$doctorId;
    }

    // ============================================================
    // 2. 予定をINSERT
    // ============================================================
    $stmt = $pdo->prepare("
        INSERT INTO events (
            start_date, end_date, start_time, end_time,
            doctor_id, staff_id,
            title, note, event_type,
            is_public, is_cancelled, status,
            is_pattern, version,
            created_by, created_at, updated_at
        ) VALUES (
            :start_date, :end_date, :start_time, :end_time,
            :doctor_id, :staff_id,
            :title, :note, :event_type,
            :is_public, false, 'active',
            false, 1,
            :created_by, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
        )
    ");

    $stmt->execute([
        'start_date' => $startDate,
        'end_date' => $endDate,
        'start_time' => $startTime,
        'end_time' => $endTime,
        'doctor_id' => $doctorIdForDb,
        'staff_id' => $staffId,
        'title' => $title,
        'note' => $note,
        'event_type' => $eventType,
        'is_public' => $isPublic,
        'created_by' => 'web_input',
    ]);

    $eventId = $pdo->lastInsertId();

    // ============================================================
    // 3. 履歴をINSERT
    // ============================================================
    $stmt = $pdo->prepare("
        INSERT INTO event_history (
            event_id,
            old_start_date, old_end_date,
            old_start_time, old_end_time,
            old_title, old_note,
            old_event_type,
            old_is_public, old_is_cancelled,
            change_summary, change_type,
            changed_by,
            changed_at
        ) VALUES (
            :event_id,
            :start_date, :end_date,
            :start_time, :end_time,
            :title, :note,
            :event_type,
            :is_public, :is_cancelled,
            :summary, 'create',
            :user,
            CURRENT_TIMESTAMP
        )
    ");

    $stmt->execute([
        'event_id' => $eventId,
        'start_date' => $startDate,
        'end_date' => $endDate,
        'start_time' => $startTime,
        'end_time' => $endTime,
        'title' => $title,
        'note' => $note,
        'event_type' => $eventType,
        'is_public' => (int)$isPublic,
        'is_cancelled' => 0,
        'summary' => '予定を登録しました（' . $doctorName . '）',
        'user' => 'web_input'
    ]);

    $pdo->commit();

    // セッションクリア
    unset($_SESSION['confirm_data']);
    unset($_SESSION['return_to']);

// 登録内容の要約を作成
$eventSummary = date('Y/m/d（D）', strtotime($startDate)) . ' ' . $title;
$_SESSION['message'] = '✅ 予定を登録しました！<br><span style="font-size:0.9rem;color:#4a5568;">📅 ' . htmlspecialchars($eventSummary) . '</span>';

    // ============================================================
    // 4. return_to に基づいてリダイレクト
    // ============================================================
    $redirectYear = date('Y', strtotime($startDate));
    $redirectMonth = date('m', strtotime($startDate));

    if ($returnTo === 'calendar') {
        $redirectUrl = 'calendar.php?year=' . $redirectYear . '&month=' . $redirectMonth;
    } else {
        $redirectUrl = 'index.php?year=' . $redirectYear . '&month=' . $redirectMonth;
    }

    header('Location: ' . $redirectUrl);
    exit;

} catch (Exception $e) {
    // ============================================================
    // 5. エラー処理
    // ============================================================
    if (isset($pdo)) {
        $pdo->rollBack();
    }
    
    error_log('【insert.php】エラー発生');
    error_log('  メッセージ: ' . $e->getMessage());
    error_log('  ファイル: ' . $e->getFile() . ' ライン: ' . $e->getLine());
    error_log('  セッションデータ: ' . print_r($_SESSION, true));
    
    $_SESSION['message'] = '❌ 登録に失敗しました。' . $e->getMessage();
    header('Location: input.php');
    exit;
}
?>