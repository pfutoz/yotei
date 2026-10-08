<?php
// ============================================================
// ファイル名: api/get_event_detail.php
// システム名: 医師予定表管理システム（yotei）
// バージョン: v1.0
// 生成日時: 2026-08-10
// ============================================================
//
// 【概要】
// 指定されたイベントIDの予定詳細をJSONで返す
// calendar.php の予定詳細モーダルからAjaxで呼び出される
// ============================================================

require_once __DIR__ . '/../LIB/db.php';

// ヘッダー
header('Content-Type: application/json');

// パラメータ取得
$eventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;
if (!$eventId) {
    echo json_encode(['error' => 'イベントIDが指定されていません']);
    exit;
}

try {
    $pdo = getDbConnection();
    
    $stmt = $pdo->prepare("
        SELECT 
            e.*,
            d.short_name AS doctor_short_name,
            d.last_name || d.first_name AS doctor_full_name,
            d.title AS doctor_title,
            dep.display_name AS department_name,
            s.name AS staff_name
        FROM events e
        LEFT JOIN doctors d ON e.doctor_id = d.id
        LEFT JOIN staff s ON e.staff_id = s.id
        LEFT JOIN departments dep ON COALESCE(e.department_id, d.department_id, s.department_id) = dep.id
        WHERE e.id = :id
    ");
    $stmt->execute(['id' => $eventId]);
    $event = $stmt->fetch();
    
    if (!$event) {
        echo json_encode(['error' => '指定された予定が見つかりません']);
        exit;
    }
    
    // 日付表示
    $dateDisplay = date('Y/m/d（D）', strtotime($event['start_date']));
    $endDateDisplay = $event['end_date'] ? date('Y/m/d（D）', strtotime($event['end_date'])) : null;
    
    // 時間表示
    $timeDisplay = formatEventTime($event);
    
    // 医師名
    if ($event['staff_name']) {
        $doctorName = $event['staff_name'];
        $doctorFull = $event['staff_name'];
    } else {
        $doctorName = $event['doctor_short_name'] ?? '不明';
        $doctorFull = ($event['doctor_full_name'] ?? '') . ($event['doctor_title'] ?? '');
    }
    
    // イベント種別
    $eventLabel = getEventLabel($event['event_type']);
    
    $response = [
        'id' => $event['id'],
        'doctor' => $doctorName,
        'doctor_full' => $doctorFull,
        'date' => $dateDisplay,
        'end_date' => $endDateDisplay,
        'time' => $timeDisplay,
        'event_type' => $event['event_type'],
        'event_label' => $eventLabel,
        'title' => $event['title'],
        'note' => $event['note'],
        'is_public' => (bool)$event['is_public'],
        'is_cancelled' => (bool)$event['is_cancelled'],
        'department' => $event['department_name'] ?? '',
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    error_log('【get_event_detail.php】' . $e->getMessage());
    echo json_encode(['error' => 'データの取得中にエラーが発生しました']);
}