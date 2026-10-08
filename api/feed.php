<?php
/**
 * ============================================================
 * ファイル名: api/feed.php (calendar.ics)
 * システム名: 医師予定表管理システム（yotei）
 * ============================================================
 *
 * 【概要】
 * iCalendar (RFC 5545) フィード出力 API
 * Googleカレンダー、Outlook、iPhone、Android、Mac等の
 * カレンダーアプリに「URLで購読」可能な標準カレンダーフィード
 *
 * 【GETパラメータ】
 * - doctor_id : 指定医師のみに絞り込み（省略時は全体）
 */

require_once __DIR__ . '/../LIB/db.php';

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="doctor_yotei.ics"');

try {
    $pdo = getDbConnection();

    $doctorId = !empty($_GET['doctor_id']) ? (int)$_GET['doctor_id'] : null;

    // 直近3ヶ月〜今後6ヶ月の予定を出力
    $startDate = date('Y-m-d', strtotime('-3 months'));
    $endDate = date('Y-m-d', strtotime('+6 months'));

    $sql = "
        SELECT 
            e.*,
            d.short_name AS doctor_short_name,
            d.last_name || d.first_name AS doctor_full_name,
            dep.display_name AS department_name
        FROM events e
        LEFT JOIN doctors d ON e.doctor_id = d.id
        LEFT JOIN departments dep ON COALESCE(e.department_id, d.department_id) = dep.id
        WHERE e.start_date <= :end_date
          AND COALESCE(e.end_date, e.start_date) >= :start_date
          AND e.is_cancelled = false
          AND e.is_public = true
    ";
    $params = ['start_date' => $startDate, 'end_date' => $endDate];

    if ($doctorId) {
        $sql .= " AND e.doctor_id = :doctor_id";
        $params['doctor_id'] = $doctorId;
    }

    $sql .= " ORDER BY e.start_date ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 全体会も取得
    $stmtMeet = $pdo->prepare("
        SELECT meeting_date, title, start_time, location, note
        FROM all_meetings
        WHERE meeting_date >= :start_date AND meeting_date <= :end_date
          AND is_cancelled = false
    ");
    $stmtMeet->execute(['start_date' => $startDate, 'end_date' => $endDate]);
    $meetings = $stmtMeet->fetchAll(PDO::FETCH_ASSOC);

    echo "BEGIN:VCALENDAR\r\n";
    echo "VERSION:2.0\r\n";
    echo "PRODID:-//Ono Hospital//Doctor Yotei System//JA\r\n";
    echo "CALSCALE:GREGORIAN\r\n";
    echo "METHOD:PUBLISH\r\n";
    echo "X-WR-CALNAME:医師予定表 (yotei)\r\n";
    echo "X-WR-TIMEZONE:Asia/Tokyo\r\n";

    // 医師予定イベント
    foreach ($events as $ev) {
        $uid = "yotei-event-{$ev['id']}@" . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $doc = $ev['doctor_short_name'] ?? $ev['doctor_full_name'] ?? '担当医師';
        $summary = "[{$doc}] " . $ev['title'];
        $desc = "医師: {$ev['doctor_full_name']}\n部門: {$ev['department_name']}\n用件: {$ev['title']}";
        if (!empty($ev['note'])) {
            $desc .= "\n備考: {$ev['note']}";
        }

        echo "BEGIN:VEVENT\r\n";
        echo "UID:{$uid}\r\n";
        echo "DTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n";

        if (!empty($ev['is_all_day']) || empty($ev['start_time'])) {
            // 終日イベント（または複数日終日）
            $dtStart = date('Ymd', strtotime($ev['start_date']));
            $endRaw = !empty($ev['end_date']) ? $ev['end_date'] : $ev['start_date'];
            // iCalendarの終日DTENDは翌日0時（排他的終了日）
            $dtEnd = date('Ymd', strtotime('+1 day', strtotime($endRaw)));
            echo "DTSTART;VALUE=DATE:{$dtStart}\r\n";
            echo "DTEND;VALUE=DATE:{$dtEnd}\r\n";
        } else {
            // 時間指定イベント
            $sTime = str_replace(':', '', substr($ev['start_time'], 0, 5)) . '00';
            $eTime = !empty($ev['end_time']) ? str_replace(':', '', substr($ev['end_time'], 0, 5)) . '00' : date('His', strtotime('+1 hour', strtotime($ev['start_time'])));
            $dtStart = date('Ymd', strtotime($ev['start_date'])) . 'T' . $sTime;
            $dtEnd = date('Ymd', strtotime($ev['end_date'] ?: $ev['start_date'])) . 'T' . $eTime;
            echo "DTSTART;TZID=Asia/Tokyo:{$dtStart}\r\n";
            echo "DTEND;TZID=Asia/Tokyo:{$dtEnd}\r\n";
        }

        echo "SUMMARY:" . escapeIcs($summary) . "\r\n";
        echo "DESCRIPTION:" . escapeIcs($desc) . "\r\n";
        echo "END:VEVENT\r\n";
    }

    // 全体会イベント
    foreach ($meetings as $m) {
        $uid = "yotei-meeting-{$m['meeting_date']}@" . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $summary = "🏛️ 全体会: {$m['title']}";
        $sTime = str_replace(':', '', substr($m['start_time'], 0, 5)) . '00';
        $dtStart = date('Ymd', strtotime($m['meeting_date'])) . 'T' . $sTime;
        $dtEnd = date('Ymd', strtotime($m['meeting_date'])) . 'T' . date('His', strtotime('+1 hour', strtotime($m['start_time'])));

        echo "BEGIN:VEVENT\r\n";
        echo "UID:{$uid}\r\n";
        echo "DTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n";
        echo "DTSTART;TZID=Asia/Tokyo:{$dtStart}\r\n";
        echo "DTEND;TZID=Asia/Tokyo:{$dtEnd}\r\n";
        echo "SUMMARY:" . escapeIcs($summary) . "\r\n";
        if (!empty($m['location'])) {
            echo "LOCATION:" . escapeIcs($m['location']) . "\r\n";
        }
        echo "DESCRIPTION:" . escapeIcs($m['note'] ?? '小野会全体会') . "\r\n";
        echo "END:VEVENT\r\n";
    }

    echo "END:VCALENDAR\r\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}

function escapeIcs($str) {
    $str = str_replace('\\', '\\\\', $str);
    $str = str_replace(';', '\;', $str);
    $str = str_replace(',', '\,', $str);
    $str = str_replace("\r\n", '\n', $str);
    $str = str_replace("\n", '\n', $str);
    return $str;
}
