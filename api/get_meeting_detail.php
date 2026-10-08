<?php
// ============================================================
// ファイル名: api/get_meeting_detail.php
// システム名: 医師予定表管理システム（yotei）
// バージョン: v1.0
// 生成日時: 2026-08-10
// ============================================================
//
// 【概要】
// 指定された日付の全体会詳細をJSONで返す
// calendar.php の予定詳細モーダルからAjaxで呼び出される
// ============================================================

require_once __DIR__ . '/../LIB/db.php';

// ヘッダー
header('Content-Type: application/json');

// パラメータ取得
$date = isset($_GET['date']) ? $_GET['date'] : '';
if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo json_encode(['error' => '日付が正しく指定されていません']);
    exit;
}

try {
    $pdo = getDbConnection();
    
    $stmt = $pdo->prepare("
        SELECT 
            meeting_date,
            title,
            start_time,
            location,
            note,
            is_cancelled
        FROM all_meetings
        WHERE meeting_date = :date
          AND is_cancelled = false
        LIMIT 1
    ");
    $stmt->execute(['date' => $date]);
    $meeting = $stmt->fetch();
    
    if (!$meeting) {
        echo json_encode(['error' => '指定された日付の全体会が見つかりません']);
        exit;
    }
    
    // 日付表示
    $dateDisplay = date('Y/m/d（D）', strtotime($meeting['meeting_date']));
    $timeDisplay = substr($meeting['start_time'], 0, 5);
    
    $response = [
        'title' => $meeting['title'],
        'date' => $dateDisplay,
        'time' => $timeDisplay,
        'location' => $meeting['location'],
        'note' => $meeting['note'],
        'is_cancelled' => (bool)$meeting['is_cancelled'],
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    error_log('【get_meeting_detail.php】' . $e->getMessage());
    echo json_encode(['error' => 'データの取得中にエラーが発生しました']);
}