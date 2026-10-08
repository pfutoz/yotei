<?php
// ============================================================
// ファイル名: update.php
// システム名: 医師予定表管理システム（yotei）
// バージョン: v5.1（return_to 対応版）
// 生成日時: 2026-08-10
// ============================================================
//
// 【概要】
// 編集された予定をデータベースに更新する処理
// return_to に基づいてリダイレクト先を切り替える
// ============================================================

require_once __DIR__ . '/LIB/db.php';
session_start();

// ============================================================
// 1. POSTデータ確認
// ============================================================
$eventId = isset($_POST['event_id']) ? (int)$_POST['event_id'] : 0;
$returnTo = $_POST['return_to'] ?? 'index';

if (!$eventId) {
    error_log('【update.php】event_id が 0 です');
    $_SESSION['message'] = '❌ 更新対象が指定されていません';
    header('Location: ' . ($returnTo === 'calendar' ? 'calendar.php' : 'index.php'));
    exit;
}

// 認証チェック
if (!isset($_SESSION['edit_authorized']) || $_SESSION['edit_authorized'] !== true ||
    !isset($_SESSION['edit_event_id']) || $_SESSION['edit_event_id'] !== $eventId) {
    error_log('【update.php】認証エラー');
    $_SESSION['message'] = '❌ 編集認証が無効です。';
    header('Location: edit.php?event_id=' . $eventId . '&return_to=' . $returnTo);
    exit;
}

// ============================================================
// 2. POSTデータ取得
// ============================================================
$doctorId = $_POST['doctor_id'] ?? '';
$dateType = $_POST['date_type'] ?? 'single';
$startDate = $_POST['start_date'] ?? '';
$periodEnd = $_POST['period_end'] ?? '';
$timeType = $_POST['time_type'] ?? 'allday';
$startTime = $_POST['start_time'] ?? '';
$endTime = $_POST['end_time'] ?? '';
$eventType = $_POST['event_type'] ?? 'absence';
$title = trim($_POST['title'] ?? '');
$note = trim($_POST['note'] ?? '');
$isPublic = isset($_POST['is_public']) ? 1 : 0;

// ============================================================
// 3. データ整形
// ============================================================
if ($dateType === 'period') {
    $endDate = $periodEnd;
} else {
    $endDate = null;
}

if ($timeType === 'allday') {
    $startTime = null;
    $endTime = null;
} else {
    if ($dateType === 'period') {
        $endTime = null;
    }
}

$isStaff = ($doctorId === 'staff');

// ============================================================
// 4. バリデーション
// ============================================================
$errors = [];
if (empty($doctorId)) $errors[] = '医師を選択してください';
if (empty($startDate)) $errors[] = '日付を入力してください';
if (empty($title)) $errors[] = 'タイトルを入力してください';
if ($dateType === 'period' && !empty($endDate) && $endDate < $startDate) {
    $errors[] = '終了日は開始日より後の日付を指定してください';
}

if (!empty($errors)) {
    $_SESSION['edit_errors'] = $errors;
    $_SESSION['edit_form_data'] = $_POST;
    header('Location: edit.php?event_id=' . $eventId . '&return_to=' . $returnTo);
    exit;
}

// ============================================================
// 5. 更新処理
// ============================================================
try {
    $pdo = getDbConnection();
    $pdo->beginTransaction();

    // 更新前データ取得
    $stmt = $pdo->prepare("SELECT * FROM events WHERE id = :id");
    $stmt->execute(['id' => $eventId]);
    $old = $stmt->fetch();

    if (!$old) {
        throw new Exception('更新対象の予定が見つかりません');
    }

    // 事務長ID取得
    $staffId = null;
    if ($isStaff) {
        $stmt = $pdo->prepare("SELECT id FROM staff WHERE name = '事務長'");
        $stmt->execute();
        $staff = $stmt->fetch();
        $staffId = $staff ? $staff['id'] : null;
        if (!$staffId) {
            throw new Exception('事務長がスタッフマスタに登録されていません');
        }
    }

    // ============================================================
    // 6. events テーブル更新
    // ============================================================
    $stmt = $pdo->prepare("
        UPDATE events SET
            start_date = :start_date,
            end_date = :end_date,
            start_time = :start_time,
            end_time = :end_time,
            doctor_id = :doctor_id,
            staff_id = :staff_id,
            title = :title,
            note = :note,
            event_type = :event_type,
            is_public = :is_public,
            version = version + 1,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :id
    ");

    $stmt->execute([
        'start_date' => $startDate,
        'end_date' => $endDate,
        'start_time' => $startTime,
        'end_time' => $endTime,
        'doctor_id' => $isStaff ? null : (int)$doctorId,
        'staff_id' => $staffId,
        'title' => $title,
        'note' => $note,
        'event_type' => $eventType,
        'is_public' => $isPublic,
        'id' => $eventId,
    ]);

    // ============================================================
    // 7. 変更履歴保存
    // ============================================================
    $changes = [];
    if ($old['start_date'] != $startDate) $changes[] = "日付: {$old['start_date']} → {$startDate}";
    if ($old['end_date'] != $endDate) $changes[] = "終了日: {$old['end_date']} → {$endDate}";
    if ($old['start_time'] != $startTime) $changes[] = "開始時間: {$old['start_time']} → {$startTime}";
    if ($old['end_time'] != $endTime) $changes[] = "終了時間: {$old['end_time']} → {$endTime}";
    if ($old['title'] != $title) $changes[] = "タイトル: {$old['title']} → {$title}";
    if ($old['note'] != $note) $changes[] = "備考: {$old['note']} → {$note}";
    if ($old['event_type'] != $eventType) $changes[] = "種別: {$old['event_type']} → {$eventType}";
    if ((bool)$old['is_public'] != (bool)$isPublic) {
        $changes[] = "公開: " . ($old['is_public'] ? '公開' : '非公開') . " → " . ($isPublic ? '公開' : '非公開');
    }

    $summary = implode(' / ', $changes);
    if (empty($summary)) $summary = '変更なし（バージョンアップのみ）';

    $oldIsPublic = !empty($old['is_public']) ? 1 : 0;
    $oldIsCancelled = !empty($old['is_cancelled']) ? 1 : 0;

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
            :old_start_date, :old_end_date,
            :old_start_time, :old_end_time,
            :old_title, :old_note,
            :old_event_type,
            :old_is_public, :old_is_cancelled,
            :change_summary, :change_type,
            :changed_by,
            CURRENT_TIMESTAMP
        )
    ");

    $stmt->execute([
        ':event_id' => $eventId,
        ':old_start_date' => $old['start_date'],
        ':old_end_date' => $old['end_date'],
        ':old_start_time' => $old['start_time'],
        ':old_end_time' => $old['end_time'],
        ':old_title' => $old['title'],
        ':old_note' => $old['note'],
        ':old_event_type' => $old['event_type'],
        ':old_is_public' => $oldIsPublic,
        ':old_is_cancelled' => $oldIsCancelled,
        ':change_summary' => $summary,
        ':change_type' => 'update',
        ':changed_by' => 'web_user',
    ]);

    $pdo->commit();

    unset($_SESSION['edit_authorized']);
    unset($_SESSION['edit_event_id']);
    unset($_SESSION['edit_errors']);
    unset($_SESSION['edit_form_data']);

// 更新内容の要約を作成
$eventSummary = date('Y/m/d（D）', strtotime($startDate)) . ' ' . $title;
$_SESSION['message'] = '✅ 予定を更新しました！<br><span style="font-size:0.9rem;color:#4a5568;">📅 ' . htmlspecialchars($eventSummary) . '</span>';

    // ============================================================
    // 8. return_to に基づいてリダイレクト
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
    if (isset($pdo)) $pdo->rollBack();
    error_log('【update.php】エラー: ' . $e->getMessage() . ' (line ' . $e->getLine() . ')');
    $_SESSION['message'] = '❌ 更新に失敗しました。' . $e->getMessage();
    header('Location: edit.php?event_id=' . $eventId . '&return_to=' . $returnTo);
    exit;
}
?>