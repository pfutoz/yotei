<?php
/**
 * ============================================================
 * ファイル名: api/today.php
 * システム名: 医師予定表管理システム（yotei）
 * ============================================================
 *
 * 【概要】
 * 本日（または指定日）の医師休診・診察状況・全体会サマリーAPI
 * サイネージ、院内かわら版ウィジェット、受付案内端末向け
 *
 * 【GETパラメータ】
 * - date : 指定日（YYYY-MM-DD、デフォルトは本日）
 */

require_once __DIR__ . '/api_common.php';
require_once __DIR__ . '/../LIB/calendar_helper_onokai.php';

try {
    $pdo = getDbConnection();

    // 日付指定（デフォルトは本日）
    $dateParam = $_GET['date'] ?? 'today';
    if ($dateParam === 'today') {
        $targetDate = date('Y-m-d');
    } elseif ($dateParam === 'tomorrow') {
        $targetDate = date('Y-m-d', strtotime('+1 day'));
    } else {
        $d = strtotime($dateParam);
        $targetDate = $d ? date('Y-m-d', $d) : date('Y-m-d');
    }

    $dowMap = ['日', '月', '火', '水', '木', '金', '土'];
    $dow = (int)date('w', strtotime($targetDate));
    $weekday = $dowMap[$dow];

    // 小野会カレンダー情報（祝日・休診日判定）
    $onokaiCheck = check_onokai_calendar($targetDate, null);
    $isClosed = !empty($onokaiCheck['is_closed']);
    $holidayName = $onokaiCheck['note'] ?? '';

    // 本日に重なる予定を取得（単日予定 ＋ 期間予定）
    $stmt = $pdo->prepare("
        SELECT 
            e.id,
            e.title,
            e.note,
            e.start_date,
            e.end_date,
            e.start_time,
            e.end_time,
            CASE WHEN e.start_time IS NULL THEN true ELSE false END AS is_all_day,
            e.event_type,
            e.is_public,
            d.id AS doctor_id,
            d.short_name AS doctor_short_name,
            d.last_name || d.first_name AS doctor_full_name,
            d.title AS doctor_title,
            dep.id AS department_id,
            dep.display_name AS department_name,
            dep.color_code AS department_color
        FROM events e
        LEFT JOIN doctors d ON e.doctor_id = d.id
        LEFT JOIN departments dep ON COALESCE(e.department_id, d.department_id) = dep.id
        WHERE e.start_date <= :date
          AND COALESCE(e.end_date, e.start_date) >= :date
          AND e.is_cancelled = false
          AND e.is_public = true
        ORDER BY 
            CASE 
                WHEN e.event_type = 'absence' THEN 1 
                WHEN e.event_type = 'clinic' THEN 2 
                ELSE 3 
            END,
            e.start_time ASC,
            e.id ASC
    ");
    $stmt->execute(['date' => $targetDate]);
    $rawEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $formattedEvents = [];
    $absentList = [];
    $clinicList = [];
    $otherList = [];

    foreach ($rawEvents as $ev) {
        $docShort = $ev['doctor_short_name'] ?? '担当';
        $docFull = $ev['doctor_full_name'] ?? $docShort;
        $isMulti = ($ev['start_date'] !== $ev['end_date'] && !empty($ev['end_date']));

        $timeStr = '終日';
        if (!empty($ev['start_time']) && empty($ev['is_all_day'])) {
            $sTime = substr($ev['start_time'], 0, 5);
            $eTime = !empty($ev['end_time']) ? substr($ev['end_time'], 0, 5) : '';
            $timeStr = $eTime ? "{$sTime}〜{$eTime}" : "{$sTime}〜";
        }

        $item = [
            'id' => (int)$ev['id'],
            'doctor_id' => $ev['doctor_id'] ? (int)$ev['doctor_id'] : null,
            'doctor_name' => $docFull,
            'doctor_short' => $docShort,
            'doctor_title' => $ev['doctor_title'] ?? '',
            'department_id' => $ev['department_id'] ? (int)$ev['department_id'] : null,
            'department_name' => $ev['department_name'] ?? '',
            'department_color' => $ev['department_color'] ?? '#64748b',
            'title' => $ev['title'],
            'note' => $ev['note'] ?? '',
            'event_type' => $ev['event_type'],
            'event_type_label' => getApiEventLabel($ev['event_type']),
            'event_icon' => getApiEventIcon($ev['event_type']),
            'start_date' => $ev['start_date'],
            'end_date' => $ev['end_date'] ?: $ev['start_date'],
            'is_multi_day' => $isMulti,
            'time' => $timeStr,
        ];

        $formattedEvents[] = $item;

        if ($ev['event_type'] === 'absence') {
            $absentList[] = $item;
        } elseif ($ev['event_type'] === 'clinic') {
            $clinicList[] = $item;
        } else {
            $otherList[] = $item;
        }
    }

    // 全体会データ取得
    $stmtMeet = $pdo->prepare("
        SELECT title, start_time, location, note
        FROM all_meetings
        WHERE meeting_date = :date
          AND is_cancelled = false
    ");
    $stmtMeet->execute(['date' => $targetDate]);
    $meeting = $stmtMeet->fetch(PDO::FETCH_ASSOC);

    $meetingData = null;
    if ($meeting) {
        $meetingData = [
            'title' => $meeting['title'],
            'time' => substr($meeting['start_time'], 0, 5) . '〜',
            'location' => $meeting['location'],
            'note' => $meeting['note'] ?? '',
        ];
    }

    $response = [
        'target_date' => $targetDate,
        'weekday' => $weekday,
        'date_display' => date('Y年n月j日', strtotime($targetDate)) . "（{$weekday}）",
        'is_hospital_closed' => $isClosed,
        'holiday_name' => $holidayName,
        'summary' => [
            'absent_count' => count($absentList),
            'clinic_count' => count($clinicList),
            'other_count' => count($otherList),
            'total_events' => count($formattedEvents),
            'has_all_meeting' => ($meetingData !== null),
        ],
        'absent_doctors' => $absentList,
        'clinic_doctors' => $clinicList,
        'all_meeting' => $meetingData,
        'all_events' => $formattedEvents,
    ];

    apiSuccess($response);

} catch (Exception $e) {
    apiError('本日のサマリー取得に失敗しました: ' . $e->getMessage(), 500);
}
