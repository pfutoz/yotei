<?php
/**
 * ============================================================
 * ファイル名: calendar.php
 * システム名: 医師予定表管理システム（yotei）
 * バージョン: v1.3
 * 生成日時: 2026-08-24 23:00
 * 最終更新: 2026-08-24 23:00
 * ============================================================
 *
 * 【概要】
 * 小野会カレンダー表示（3ヶ月分・日曜始まり・縦積み）
 * session_tab.php を使用してタブごとのセッションを維持
 *
 * 【変更履歴】
 * 2026-08-24 23:00 v1.3 session_tab.php 方式に対応（url() 関数使用）（事務長）
 * 2026-08-10 v1.2 かわら版統合版
 * ============================================================
 */

require_once __DIR__ . '/LIB/session_tab.php';
require_once __DIR__ . '/LIB/db.php';
require_once __DIR__ . '/LIB/calendar_helper_onokai.php';

// セッションは session_tab.php で開始済み

// 認証チェック
if (!is_logged_in()) {
    header('Location: ' . redirect_url('login.php'));
    exit;
}

// ============================================================
// 1. 設定・デフォルト値
// ============================================================
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$deptFilter = isset($_SESSION['department_filter']) ? $_SESSION['department_filter'] : 'all';
$showCancelled = isset($_SESSION['show_cancelled']) ? $_SESSION['show_cancelled'] : true;
$showDraft = isset($_SESSION['show_draft']) ? $_SESSION['show_draft'] : false;

if ($month < 1 || $month > 12) { $month = date('m'); }
if ($year < 2000 || $year > 2100) { $year = date('Y'); }

// ============================================================
// 2. 表示対象（3ヶ月）
// ============================================================
$months = [];
for ($i = 0; $i < 3; $i++) {
    $m = $month + $i;
    $y = $year;
    if ($m > 12) { $m -= 12; $y++; }
    $months[] = ['year' => $y, 'month' => $m];
}

// 3ヶ月分の開始日と終了日
$firstMonthStart = sprintf("%04d-%02d-01", $months[0]['year'], $months[0]['month']);
$lastMonthEnd = date('Y-m-t', strtotime(sprintf("%04d-%02d-01", $months[2]['year'], $months[2]['month'])));

// ============================================================
// 3. データベース接続
// ============================================================
$pdo = getDbConnection();

// ============================================================
// 4. メモ取得（一番上に表示）
// ============================================================
$memoText = getMemoText($pdo);
$memoLines = explode("\n", $memoText);

// ============================================================
// 5. 部門一覧
// ============================================================
$stmt = $pdo->query("
    SELECT id, name, display_name, color_code
    FROM departments WHERE is_active = true ORDER BY sort_order
");
$departments = $stmt->fetchAll();

// ============================================================
// 6. 予定データ取得（3ヶ月分）
// ============================================================
$sql = "
    SELECT 
        e.*,
        d.short_name AS doctor_short_name,
        d.last_name || d.first_name AS doctor_full_name,
        d.title AS doctor_title,
        dep.name AS department_name,
        dep.display_name AS department_display_name,
        dep.color_code AS department_color,
        s.name AS staff_name,
        s.short_name AS staff_short_name
    FROM events e
    LEFT JOIN doctors d ON e.doctor_id = d.id
    LEFT JOIN staff s ON e.staff_id = s.id
    LEFT JOIN departments dep ON COALESCE(e.department_id, d.department_id, s.department_id) = dep.id
    WHERE e.start_date >= :start_date
      AND e.start_date <= :end_date
      AND (e.end_date IS NULL OR e.end_date >= :start_date)
";
$params = ['start_date' => $firstMonthStart, 'end_date' => $lastMonthEnd];

if ($deptFilter !== 'all' && is_numeric($deptFilter)) {
    $sql .= " AND (COALESCE(e.department_id, d.department_id, s.department_id) = :dept_id OR dep.id = :dept_id)";
    $params['dept_id'] = (int)$deptFilter;
}
if (!$showCancelled) { $sql .= " AND e.is_cancelled = false"; }
if (!$showDraft) { $sql .= " AND e.is_public = true"; }

$sql .= " ORDER BY e.start_date ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rawEvents = $stmt->fetchAll();

// 日付をキーにした連想配列に変換
$eventMap = [];
foreach ($rawEvents as $e) {
    $date = $e['start_date'];
    if (!isset($eventMap[$date])) {
        $eventMap[$date] = [];
    }
    $eventMap[$date][] = $e;
}

// ============================================================
// 7. 全体会データ取得（3ヶ月分）
// ============================================================
$stmt = $pdo->prepare("
    SELECT 
        meeting_date,
        title,
        start_time,
        location,
        note
    FROM all_meetings
    WHERE meeting_date >= :start_date
      AND meeting_date <= :end_date
      AND is_cancelled = false
    ORDER BY meeting_date
");
$stmt->execute(['start_date' => $firstMonthStart, 'end_date' => $lastMonthEnd]);
$allMeetings = $stmt->fetchAll();

$meetingMap = [];
foreach ($allMeetings as $m) {
    $meetingMap[$m['meeting_date']] = $m;
}

// ============================================================
// 8. ★ かわら版イベントデータ取得（3ヶ月分）
// ============================================================
function getKawaraDbConnection() {
    try {
        $pdo = new PDO(
            "pgsql:host=localhost;dbname=kawara",
            'postgres',
            'postgres',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]
        );
        return $pdo;
    } catch (PDOException $e) {
        error_log('かわら版DB接続エラー: ' . $e->getMessage());
        return null;
    }
}

$kawaraMap = [];
$kawaraDb = getKawaraDbConnection();
if ($kawaraDb) {
    try {
        $sql = "
            SELECT 
                p.post_id AS id,
                p.title,
                p.target_datetime AS event_date,
                p.author_dept AS category_name,
                p.author_id
            FROM posts p
            WHERE p.target_datetime IS NOT NULL
              AND p.target_datetime::DATE >= :start_date
              AND p.target_datetime::DATE <= :end_date
            ORDER BY p.target_datetime ASC
        ";
        $stmt = $kawaraDb->prepare($sql);
        $stmt->execute(['start_date' => $firstMonthStart, 'end_date' => $lastMonthEnd]);
        $kawaraEvents = $stmt->fetchAll();

        foreach ($kawaraEvents as $ke) {
            $date = date('Y-m-d', strtotime($ke['event_date']));
            if (!isset($kawaraMap[$date])) {
                $kawaraMap[$date] = [];
            }
            $kawaraMap[$date][] = $ke;
        }
    } catch (Exception $e) {
        error_log('かわら版イベント取得エラー: ' . $e->getMessage());
    }
}

// ============================================================
// 9. ナビゲーション
// ============================================================
$prevYear = $year;
$prevMonth = $month - 3;
if ($prevMonth < 1) { $prevMonth += 12; $prevYear--; }
if ($prevMonth < 1) { $prevMonth += 12; $prevYear--; }

$nextYear = $year;
$nextMonth = $month + 3;
if ($nextMonth > 12) { $nextMonth -= 12; $nextYear++; }
if ($nextMonth > 12) { $nextMonth -= 12; $nextYear++; }

$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);

$filterName = 'すべて';
if ($deptFilter !== 'all') {
    foreach ($departments as $d) {
        if ($d['id'] == $deptFilter) { $filterName = $d['display_name']; break; }
    }
}

// ============================================================
// 10. カレンダー描画関数（かわら版対応）
// ============================================================
function renderCalendarMonth($pdo, $year, $month, $eventMap, $meetingMap, $kawaraMap, $deptFilter) {
    $weekDays = ['日', '月', '火', '水', '木', '金', '土'];
    $firstDayOfMonth = date('w', strtotime("$year-$month-01"));
    $daysInMonth = date('t', strtotime("$year-$month-01"));
    $today = date('Y-m-d');
    $monthName = date('Y年n月', strtotime("$year-$month-01"));

    $html = '<div class="calendar-month">';
    $html .= '<div class="calendar-month-title">' . $monthName . '</div>';
    $html .= '<table class="calendar-table">';
    $html .= '<thead><tr>';
    foreach ($weekDays as $wd) {
        $class = ($wd === '日') ? 'sun' : (($wd === '土') ? 'sat' : '');
        $html .= '<th class="' . $class . '">' . $wd . '</th>';
    }
    $html .= '</tr></thead><tbody><tr>';

    for ($i = 0; $i < $firstDayOfMonth; $i++) {
        $html .= '<td class="calendar-empty"></td>';
    }

    for ($d = 1; $d <= $daysInMonth; $d++) {
        $dateStr = sprintf("%04d-%02d-%02d", $year, $month, $d);
        $isToday = ($dateStr === $today);
        $isPast = ($dateStr < $today);
        $dayOfWeek = date('w', strtotime($dateStr));

        $style = get_onokai_cell_style($dateStr, null);
        $isHoliday = check_onokai_calendar($dateStr, null)['is_closed'];

        $cellClass = 'calendar-cell';
        if ($isToday) $cellClass .= ' today';
        if ($isPast) $cellClass .= ' past';
        if ($isHoliday) $cellClass .= ' holiday';
        if ($dayOfWeek == 0) $cellClass .= ' sun';
        if ($dayOfWeek == 6) $cellClass .= ' sat';

        $hasEvents = isset($eventMap[$dateStr]) && !empty($eventMap[$dateStr]);
        $hasMeeting = isset($meetingMap[$dateStr]);
        $hasKawara = isset($kawaraMap[$dateStr]) && !empty($kawaraMap[$dateStr]);

        // クリック動作
        if ($hasEvents) {
            $cellClass .= ' clickable-event';
            $onclick = "openEventDetail(" . $eventMap[$dateStr][0]['id'] . ")";
        } elseif ($hasMeeting) {
            $cellClass .= ' clickable-meeting';
            $meeting = $meetingMap[$dateStr];
            $onclick = "openMeetingDetail('" . $dateStr . "')";
        } elseif (!$isPast && !$isHoliday) {
            $cellClass .= ' clickable-empty';
            $onclick = "location.href='" . url('input.php?start_date=' . $dateStr . '&return_to=calendar') . "'";
        } else {
            $cellClass .= ' no-click';
            $onclick = '';
        }

        $html .= '<td class="' . $cellClass . '" style="' . $style['css'] . '" data-date="' . $dateStr . '" onclick="' . $onclick . '">';
        $html .= '<div class="day-number">' . $d . '</div>';

        // 予定表示（最大2件）
        if ($hasEvents) {
            $cnt = count($eventMap[$dateStr]);
            $displayLimit = 2;
            for ($i = 0; $i < min($cnt, $displayLimit); $i++) {
                $e = $eventMap[$dateStr][$i];
                $displayName = getDisplayName($e, 'short');
                $icon = ($e['event_type'] === 'clinic') ? '🟢' :
                        (($e['event_type'] === 'absence') ? '🔴' :
                        (($e['event_type'] === 'meeting') ? '🟡' : '⚪'));
                $html .= '<div class="event-chip">' . $icon . ' ' . htmlspecialchars($displayName) . '</div>';
            }
            if ($cnt > $displayLimit) {
                $html .= '<div class="event-chip more">+ ' . ($cnt - $displayLimit) . '件</div>';
            }
        }

        // 全体会表示
        if ($hasMeeting) {
            $m = $meetingMap[$dateStr];
            $time = substr($m['start_time'], 0, 5);
            $html .= '<div class="event-chip meeting">🏛️ ' . htmlspecialchars($m['title']) . ' ' . $time . '〜</div>';
        }

        // ★ かわら版イベント表示
        if ($hasKawara) {
            foreach ($kawaraMap[$dateStr] as $ke) {
                $html .= '<div class="event-chip kawara" onclick="event.stopPropagation(); location.href=\'/kawara/view_post.php?id=' . $ke['id'] . '\';" style="cursor:pointer;">';
                $html .= '🔔 ' . htmlspecialchars($ke['title']);
                if (!empty($ke['category_name'])) {
                    $html .= ' <span style="font-size:0.55rem;opacity:0.7;">[' . htmlspecialchars($ke['category_name']) . ']</span>';
                }
                $html .= '</div>';
            }
        }

        $html .= '</td>';
        if (($d + $firstDayOfMonth) % 7 == 0 && $d < $daysInMonth) {
            $html .= '</tr><tr>';
        }
    }

    $remaining = (7 - (($daysInMonth + $firstDayOfMonth) % 7)) % 7;
    for ($i = 0; $i < $remaining; $i++) {
        $html .= '<td class="calendar-empty"></td>';
    }

    $html .= '</tr></tbody></table></div>';
    return $html;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>医師予定カレンダー - yotei</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Noto+Sans+JP:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #2563eb;
            --primary-light: #eff6ff;
            --secondary: #64748b;
            --success: #059669;
            --warning: #d97706;
            --danger: #dc2626;
            --bg-body: #f8fafc;
            --bg-card: #ffffff;
            --text-main: #1e293b;
            --text-muted: #64748b;
            --border: #e2e8f0;
            --radius: 12px;
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -2px rgba(0, 0, 0, 0.05);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', 'Noto Sans JP', sans-serif;
            background: var(--bg-body);
            color: var(--text-main);
            padding: 24px;
            line-height: 1.5;
        }
        .container { max-width: 900px; margin: 0 auto; }

        /* ヘッダー */
        .app-header {
            background: var(--bg-card);
            padding: 16px 24px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 12px;
        }
        .app-title {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--primary);
        }
        .app-title i {
            background: var(--primary-light);
            padding: 10px;
            border-radius: 10px;
            color: var(--primary);
        }
        .app-title .input-link {
            font-size: 14px;
            font-weight: 700;
            color: #a0aec0;
            text-decoration: none;
            transition: all 0.2s;
            padding: 2px 6px;
            border-radius: 4px;
        }
        .app-title .input-link:hover {
            color: var(--primary);
            background: var(--primary-light);
            transform: scale(1.1);
        }
        .header-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .header-actions .nav-link {
            color: var(--text-muted);
            text-decoration: none;
            transition: color 0.2s;
            font-size: 16px;
            padding: 6px 8px;
            border-radius: 6px;
        }
        .header-actions .nav-link:hover {
            color: var(--primary);
            background: var(--primary-light);
        }

        .btn {
            padding: 6px 14px;
            border-radius: 6px;
            border: 1px solid var(--border);
            background: var(--bg-card);
            font-weight: 500;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: var(--text-main);
            transition: all 0.2s;
            font-size: 0.9rem;
            text-decoration: none;
        }
        .btn:hover { background: #f1f5f9; }
        .btn-primary {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        .btn-primary:hover { opacity: 0.9; background: var(--primary); }

        .control-bar {
            background: var(--bg-card);
            padding: 12px 20px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            flex-wrap: wrap;
            gap: 12px;
        }
        .filter-group, .date-nav {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }
        .filter-chip {
            padding: 4px 14px;
            border-radius: 20px;
            border: 1px solid var(--border);
            background: var(--bg-body);
            font-size: 0.8rem;
            font-weight: 500;
            cursor: pointer;
            color: var(--text-muted);
            transition: all 0.2s;
        }
        .filter-chip:hover { background: #e2e8f0; }
        .filter-chip.active {
            background: var(--text-main);
            color: white;
            border-color: var(--text-main);
        }
        .date-range {
            font-weight: 600;
            color: var(--text-main);
            font-size: 0.95rem;
            margin-left: 8px;
        }
        .option-group {
            display: flex;
            gap: 10px;
            font-size: 0.8rem;
            align-items: center;
        }
        .option-group label {
            display: flex;
            align-items: center;
            gap: 4px;
            cursor: pointer;
        }

        .message {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            background: #dcfce7;
            color: #166534;
            border: 1px solid #86efac;
        }

        /* メモカード */
        .memo-card {
            background: var(--bg-card);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            padding: 16px 20px;
            margin-bottom: 16px;
        }
        .memo-card .memo-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .memo-card .memo-header h3 {
            font-size: 0.95rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--primary);
            margin: 0;
        }
        .memo-card .memo-header .icon-btn {
            width: 30px;
            height: 30px;
            border-radius: 6px;
            border: 1px solid var(--border);
            background: white;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: var(--text-muted);
            transition: all 0.2s;
        }
        .memo-card .memo-header .icon-btn:hover {
            background: var(--primary-light);
            color: var(--primary);
            border-color: #bfdbfe;
        }
        .info-list {
            list-style: none;
            font-size: 0.9rem;
            color: var(--text-muted);
            display: flex;
            flex-direction: column;
            gap: 4px;
            margin-top: 6px;
        }
        .info-list li {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .info-list li::before {
            content: "•";
            color: var(--primary);
            font-weight: bold;
        }

        .memo-edit-form textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 0.9rem;
            font-family: inherit;
            resize: vertical;
            min-height: 100px;
            margin-top: 8px;
        }
        .memo-edit-form .form-actions {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
            margin-top: 8px;
        }
        .memo-edit-form .form-actions input[type="password"] {
            padding: 6px 12px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 0.9rem;
        }
        .memo-edit-form .form-actions .hint {
            font-size: 0.8rem;
            color: var(--text-muted);
        }
        .memo-message { margin-top: 6px; font-size: 0.9rem; }
        .memo-message.success { color: var(--success); }
        .memo-message.error { color: var(--danger); }

        /* カレンダー（縦積み） */
        .calendar-grid {
            display: flex;
            flex-direction: column;
            gap: 24px;
            max-width: 100%;
        }

        .calendar-month {
            background: var(--bg-card);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            overflow: hidden;
            box-shadow: var(--shadow);
            width: 100%;
        }
        .calendar-month-title {
            background: #f8fafc;
            padding: 10px 16px;
            font-weight: 700;
            font-size: 1rem;
            text-align: center;
            border-bottom: 1px solid var(--border);
            color: var(--text-main);
        }
        .calendar-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        .calendar-table th {
            padding: 6px 0;
            font-size: 0.7rem;
            font-weight: 600;
            color: var(--text-muted);
            text-align: center;
            background: #fafafa;
            border-bottom: 1px solid var(--border);
        }
        .calendar-table th.sun { color: var(--danger); }
        .calendar-table th.sat { color: var(--primary); }

        .calendar-table td {
            height: 80px;
            padding: 4px 6px;
            border: 1px solid var(--border);
            vertical-align: top;
            cursor: default;
            font-size: 0.75rem;
            transition: background 0.15s;
        }
        .calendar-table td.calendar-empty {
            background: #fafafa;
        }

        .day-number {
            font-weight: 600;
            font-size: 0.85rem;
            margin-bottom: 2px;
            display: inline-block;
        }
        .calendar-cell.today .day-number {
            background: var(--primary);
            color: white;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            text-align: center;
            line-height: 24px;
        }
        .calendar-cell.sun .day-number { color: var(--danger); }
        .calendar-cell.sat .day-number { color: var(--primary); }
        .calendar-cell.past { opacity: 0.7; }
        .calendar-cell.holiday { background: #fef2f2 !important; }

        .calendar-cell.clickable-empty {
            cursor: pointer;
        }
        .calendar-cell.clickable-empty:hover {
            background: #f0fdf4 !important;
            border-color: var(--success);
        }
        .calendar-cell.clickable-event {
            cursor: pointer;
        }
        .calendar-cell.clickable-event:hover {
            background: #eff6ff !important;
            border-color: var(--primary);
        }
        .calendar-cell.clickable-meeting {
            cursor: pointer;
        }
        .calendar-cell.clickable-meeting:hover {
            background: #fffbeb !important;
            border-color: var(--warning);
        }
        .calendar-cell.no-click {
            cursor: default;
        }

        .event-chip {
            font-size: 0.6rem;
            padding: 1px 4px;
            border-radius: 3px;
            margin-bottom: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            background: #f1f5f9;
            color: var(--text-muted);
        }
        .event-chip.more {
            font-size: 0.55rem;
            color: var(--text-muted);
            background: transparent;
        }
        .event-chip.meeting {
            background: #fef3c7;
            color: #92400e;
        }
        .event-chip.kawara {
            background: #dbeafe;
            color: #1e40af;
            border-left: 3px solid #3b82f6;
            cursor: pointer;
        }
        .event-chip.kawara:hover {
            background: #bfdbfe;
        }

        /* 凡例 */
        .legend {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
            padding: 8px 0 16px 0;
            font-size: 0.75rem;
            color: var(--text-muted);
        }
        .legend-item {
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .legend-item .dot {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 3px;
        }
        .legend-item .dot-red { background: #fecaca; border: 1px solid #f87171; }
        .legend-item .dot-green { background: #bbf7d0; border: 1px solid #4ade80; }
        .legend-item .dot-yellow { background: #fde68a; border: 1px solid #fbbf24; }
        .legend-item .dot-meeting { background: #fef3c7; border: 1px solid #f59e0b; }
        .legend-item .dot-kawara { background: #dbeafe; border: 1px solid #3b82f6; }

        .footer {
            margin-top: 24px;
            text-align: center;
            font-size: 12px;
            color: #a0aec0;
            padding: 16px;
        }

        /* モーダル */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 9999;
            align-items: center;
            justify-content: center;
        }
        .modal-overlay.show { display: flex; }
        .modal-content {
            background: white;
            padding: 32px;
            border-radius: 16px;
            max-width: 500px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            position: relative;
        }
        .modal-content .close-btn {
            position: absolute;
            top: 16px;
            right: 16px;
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #a0aec0;
            transition: color 0.2s;
        }
        .modal-content .close-btn:hover { color: var(--danger); }
        .modal-content h2 {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--text-main);
            margin-bottom: 16px;
            padding-right: 30px;
        }
        .detail-item {
            display: flex;
            padding: 8px 0;
            border-bottom: 1px solid var(--border);
        }
        .detail-item:last-child { border-bottom: none; }
        .detail-item .label {
            width: 80px;
            flex-shrink: 0;
            font-weight: 600;
            color: var(--text-muted);
            font-size: 0.85rem;
        }
        .detail-item .value {
            font-size: 0.95rem;
            color: var(--text-main);
        }
        .detail-item .value .badge-status {
            font-size: 0.75rem;
            padding: 2px 10px;
            border-radius: 12px;
            font-weight: 600;
        }
        .badge-public { background: #dcfce7; color: #166534; }
        .badge-draft { background: #f1f5f9; color: #64748b; }
        .badge-cancelled { background: #fef2f2; color: #991b1b; }

        .modal-actions {
            display: flex;
            gap: 10px;
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid var(--border);
            justify-content: flex-end;
        }

        @media (max-width: 600px) {
            body { padding: 12px; }
            .app-header { flex-direction: column; align-items: stretch; gap: 8px; }
            .header-actions { justify-content: center; }
            .control-bar { flex-direction: column; align-items: stretch; }
            .filter-group { justify-content: center; }
            .date-nav { justify-content: center; flex-wrap: wrap; }
            .calendar-table td { height: 60px; padding: 2px 4px; font-size: 0.65rem; }
            .day-number { font-size: 0.75rem; }
            .event-chip { font-size: 0.5rem; }
            .modal-content { padding: 20px; }
            .detail-item { flex-direction: column; gap: 2px; }
            .detail-item .label { width: auto; }
        }
        @media print {
            .no-print { display: none !important; }
            body { background: white; padding: 10px; }
            .calendar-month { box-shadow: none; border: 1px solid #ddd; }
        }
    </style>
</head>
<body>
<div class="container">

    <!-- ============================================================
    ヘッダー
    ============================================================ -->
    <header class="app-header">
        <div class="app-title">
            <i class="fa-solid fa-calendar-days"></i>
            医師予定カレンダー
            <a href="<?= url('input.php?return_to=calendar') ?>" class="input-link" title="予定を追加">＋</a>
        </div>
        <div class="header-actions">
            <a href="<?= url('yotei_list.php?year=' . $year . '&month=' . $month) ?>" class="btn" title="予定表一覧（リスト）">
                <i class="fa-solid fa-list"></i> リスト表示
            </a>
            <a href="<?= url('index.php') ?>" class="btn" style="background:#f1f5f9;" title="機能メニューへ">
                <i class="fa-solid fa-table-cells-large"></i> メニュー
            </a>
            <a href="<?= url('calendar_kawaraban.php?year=' . $year . '&month=' . $month) ?>" class="btn" style="background:#ebf8ff;border-color:#90cdf4;">
                <i class="fa-regular fa-newspaper"></i> かわら版
            </a>
            <a href="<?= url('master/index.php') ?>" class="nav-link" title="マスターメンテナンス">
                <i class="fa-solid fa-gear"></i>
            </a>
            <a href="<?= url('../index.php') ?>" class="nav-link" title="小野会ポータルに戻る">
                <i class="fa-solid fa-house"></i>
            </a>
        </div>
    </header>

    <!-- ============================================================
    コントロールバー（日付ナビ）
    ============================================================ -->
    <div class="control-bar no-print">
        <div class="date-nav">
            <a href="<?= url('calendar.php?year=' . $prevYear . '&month=' . $prevMonth) ?>" class="btn">
                <i class="fa-solid fa-chevron-left"></i>
            </a>
            <a href="<?= url('calendar.php?year=' . date('Y') . '&month=' . date('m')) ?>" class="btn btn-primary" style="font-weight:600;">
                今日
            </a>
            <a href="<?= url('calendar.php?year=' . $nextYear . '&month=' . $nextMonth) ?>" class="btn">
                <i class="fa-solid fa-chevron-right"></i>
            </a>
            <span class="date-range">
                <?= date('Y/m/d', strtotime($firstMonthStart)) ?> 〜 <?= date('Y/m/d', strtotime($lastMonthEnd)) ?>
            </span>
        </div>
    </div>

    <!-- ============================================================
    フィルタバー
    ============================================================ -->
    <div class="control-bar no-print">
        <div class="filter-group">
            <span style="font-size:0.8rem;font-weight:600;color:var(--text-muted);">部門:</span>
            <form method="POST" style="display:inline;">
                <input type="hidden" name="set_filter" value="1">
                <input type="hidden" name="filter_value" value="all">
                <button type="submit" class="filter-chip <?= $deptFilter === 'all' ? 'active' : '' ?>">すべて</button>
            </form>
            <?php foreach ($departments as $dept): ?>
            <form method="POST" style="display:inline;">
                <input type="hidden" name="set_filter" value="1">
                <input type="hidden" name="filter_value" value="<?= $dept['id'] ?>">
                <button type="submit" class="filter-chip <?= $deptFilter == $dept['id'] ? 'active' : '' ?>" data-dept="<?= $dept['id'] ?>">
                    <?= htmlspecialchars($dept['display_name']) ?>
                </button>
            </form>
            <?php endforeach; ?>
        </div>
        <div class="option-group">
            <form method="POST" style="display:inline;">
                <input type="hidden" name="toggle_cancelled" value="1">
                <label><input type="checkbox" <?= $showCancelled ? 'checked' : '' ?> onchange="this.form.submit();"> 取消表示</label>
            </form>
            <form method="POST" style="display:inline;">
                <input type="hidden" name="toggle_draft" value="1">
                <label><input type="checkbox" <?= $showDraft ? 'checked' : '' ?> onchange="this.form.submit();"> 非公開含める</label>
            </form>
        </div>
    </div>

    <!-- ============================================================
    メッセージ
    ============================================================ -->
    <?php if ($message): ?>
    <div class="message"><?= $message ?></div>
    <?php endif; ?>

    <!-- ============================================================
    メモ（一番上）
    ============================================================ -->
    <div class="memo-card">
        <div class="memo-header">
            <h3><i class="fa-solid fa-pen-to-square"></i> メモ</h3>
            <button class="icon-btn" onclick="toggleMemoEdit()" id="memoEditBtn" title="メモを編集">
                <i class="fa-solid fa-pen"></i>
            </button>
        </div>

        <div id="memoDisplay">
            <ul class="info-list">
                <?php
                $hasMemo = false;
                foreach ($memoLines as $line):
                    $line = trim($line);
                    if (empty($line)) continue;
                    $hasMemo = true;
                ?>
                <li><?= htmlspecialchars($line) ?></li>
                <?php endforeach; ?>
                <?php if (!$hasMemo): ?>
                <li style="color:#94a3b8;font-style:italic;">メモはありません</li>
                <?php endif; ?>
            </ul>
        </div>

        <div id="memoEdit" style="display:none;" class="memo-edit-form">
            <form method="POST" onsubmit="return submitMemo(event)">
                <textarea name="memo_text" rows="4"><?= htmlspecialchars($memoText) ?></textarea>
                <div class="form-actions">
                    <input type="password" name="password" placeholder="パスワード" autocomplete="off">
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> 保存</button>
                    <button type="button" class="btn" onclick="toggleMemoEdit()">キャンセル</button>
                    <span class="hint">※ マスターメンテ用パスワード</span>
                </div>
                <div id="memoMessage" class="memo-message"></div>
            </form>
        </div>
    </div>

    <!-- ============================================================
    凡例
    ============================================================ -->
    <div class="legend">
        <span style="font-weight:600;font-size:0.8rem;color:var(--text-main);">📌 凡例</span>
        <span class="legend-item"><span class="dot dot-red"></span> 休診</span>
        <span class="legend-item"><span class="dot dot-green"></span> 診察</span>
        <span class="legend-item"><span class="dot dot-yellow"></span> 会議</span>
        <span class="legend-item"><span class="dot dot-meeting"></span> 全体会</span>
        <span class="legend-item"><span class="dot dot-kawara"></span> かわら版</span>
        <span class="legend-item" style="color:var(--text-muted);">🔵 クリックで予定追加</span>
    </div>

    <!-- ============================================================
    カレンダー（縦積み・3ヶ月）
    ============================================================ -->
    <div class="calendar-grid">
        <?php foreach ($months as $m): ?>
        <?= renderCalendarMonth($pdo, $m['year'], $m['month'], $eventMap, $meetingMap, $kawaraMap, $deptFilter) ?>
        <?php endforeach; ?>
    </div>

    <!-- ============================================================
    フッター
    ============================================================ -->
    <div class="footer no-print">
        yotei v1.3 &bull; PostgreSQL &bull; <?= date('Y年m月d日 H:i') ?>
    </div>

</div>

<!-- ============================================================
予定詳細モーダル
============================================================ -->
<div id="detailModal" class="modal-overlay">
    <div class="modal-content">
        <button class="close-btn" onclick="closeDetailModal()"><i class="fa-solid fa-xmark"></i></button>
        <h2 id="detailTitle">📋 予定詳細</h2>
        <div id="detailBody">
            <div class="loading"><i class="fa-solid fa-spinner fa-spin"></i> 読み込み中...</div>
        </div>
    </div>
</div>

<!-- ============================================================
JavaScript
============================================================ -->
<script>
// ============================================================
// 1. メモ編集
// ============================================================
function toggleMemoEdit() {
    const display = document.getElementById('memoDisplay');
    const edit = document.getElementById('memoEdit');
    const btn = document.getElementById('memoEditBtn');
    
    if (edit.style.display === 'none') {
        edit.style.display = 'block';
        display.style.display = 'none';
        btn.innerHTML = '<i class="fa-solid fa-times"></i>';
        btn.title = '編集を閉じる';
    } else {
        edit.style.display = 'none';
        display.style.display = 'block';
        btn.innerHTML = '<i class="fa-solid fa-pen"></i>';
        btn.title = 'メモを編集';
        document.getElementById('memoMessage').innerHTML = '';
        document.getElementById('memoMessage').className = 'memo-message';
    }
}

function submitMemo(event) {
    event.preventDefault();
    const form = event.target;
    const formData = new FormData(form);
    const msgEl = document.getElementById('memoMessage');
    
    msgEl.innerHTML = '<span style="color:#94a3b8;">保存中...</span>';
    msgEl.className = 'memo-message';
    
    fetch('<?= url('api/update_memo.php') ?>', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            msgEl.innerHTML = '<i class="fa-solid fa-check-circle"></i> 保存しました！';
            msgEl.className = 'memo-message success';
            setTimeout(() => location.reload(), 1000);
        } else {
            msgEl.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> ' + data.error;
            msgEl.className = 'memo-message error';
        }
    })
    .catch(error => {
        msgEl.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> 通信エラーが発生しました';
        msgEl.className = 'memo-message error';
    });
}

// ============================================================
// 2. 予定詳細モーダル
// ============================================================
function openEventDetail(eventId) {
    const modal = document.getElementById('detailModal');
    const body = document.getElementById('detailBody');
    const title = document.getElementById('detailTitle');
    
    modal.classList.add('show');
    body.innerHTML = '<div class="loading"><i class="fa-solid fa-spinner fa-spin"></i> 読み込み中...</div>';
    title.textContent = '📋 予定詳細';
    
    fetch('api/get_event_detail.php?event_id=' + eventId)
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                body.innerHTML = '<p style="color:var(--danger);">' + data.error + '</p>';
                return;
            }
            
            const statusBadge = data.is_cancelled ? 'badge-cancelled 🗑️ 取消' :
                                data.is_public ? 'badge-public ✅ 公開' : 'badge-draft 🔒 非公開';
            
            body.innerHTML = `
                <div class="detail-item">
                    <span class="label">👨‍⚕️ 医師</span>
                    <span class="value">${data.doctor}（${data.doctor_full || ''}）</span>
                </div>
                <div class="detail-item">
                    <span class="label">📅 日付</span>
                    <span class="value">${data.date}</span>
                </div>
                ${data.end_date ? `
                <div class="detail-item">
                    <span class="label">📅 終了日</span>
                    <span class="value">${data.end_date}</span>
                </div>` : ''}
                <div class="detail-item">
                    <span class="label">⏰ 時間</span>
                    <span class="value">${data.time}</span>
                </div>
                <div class="detail-item">
                    <span class="label">📌 種類</span>
                    <span class="value">${data.event_label}</span>
                </div>
                <div class="detail-item">
                    <span class="label">📝 タイトル</span>
                    <span class="value">${data.title}</span>
                </div>
                ${data.note ? `
                <div class="detail-item">
                    <span class="label">📝 備考</span>
                    <span class="value">${data.note}</span>
                </div>` : ''}
                <div class="detail-item">
                    <span class="label">🔒 状態</span>
                    <span class="value"><span class="badge-status ${statusBadge.split(' ')[0]}">${statusBadge.replace(data.is_cancelled ? 'badge-cancelled ' : data.is_public ? 'badge-public ' : 'badge-draft ', '')}</span></span>
                </div>
                <div class="modal-actions">
                    <a href="<?= url('edit.php?event_id=') ?>${data.id}&return_to=calendar" class="btn btn-primary">
                        <i class="fa-solid fa-pen"></i> 編集
                    </a>
                    <button class="btn" onclick="closeDetailModal()">閉じる</button>
                </div>
            `;
        })
        .catch(error => {
            body.innerHTML = '<p style="color:var(--danger);">エラーが発生しました</p>';
        });
}

function openMeetingDetail(dateStr) {
    const modal = document.getElementById('detailModal');
    const body = document.getElementById('detailBody');
    const title = document.getElementById('detailTitle');
    
    modal.classList.add('show');
    body.innerHTML = '<div class="loading"><i class="fa-solid fa-spinner fa-spin"></i> 読み込み中...</div>';
    title.textContent = '🏛️ 全体会詳細';
    
    fetch('api/get_meeting_detail.php?date=' + dateStr)
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                body.innerHTML = '<p style="color:var(--danger);">' + data.error + '</p>';
                return;
            }
            
            body.innerHTML = `
                <div class="detail-item">
                    <span class="label">🏛️ タイトル</span>
                    <span class="value">${data.title}</span>
                </div>
                <div class="detail-item">
                    <span class="label">📅 日付</span>
                    <span class="value">${data.date}</span>
                </div>
                <div class="detail-item">
                    <span class="label">⏰ 時間</span>
                    <span class="value">${data.time}</span>
                </div>
                <div class="detail-item">
                    <span class="label">📍 場所</span>
                    <span class="value">${data.location}</span>
                </div>
                ${data.note ? `
                <div class="detail-item">
                    <span class="label">📝 備考</span>
                    <span class="value">${data.note}</span>
                </div>` : ''}
                <div class="modal-actions">
                    <a href="<?= url('master/all_meeting.php') ?>" class="btn btn-primary">
                        <i class="fa-solid fa-gear"></i> 管理画面
                    </a>
                    <button class="btn" onclick="closeDetailModal()">閉じる</button>
                </div>
            `;
        })
        .catch(error => {
            body.innerHTML = '<p style="color:var(--danger);">エラーが発生しました</p>';
        });
}

function closeDetailModal() {
    document.getElementById('detailModal').classList.remove('show');
}

document.getElementById('detailModal').addEventListener('click', function(e) {
    if (e.target === this) closeDetailModal();
});
</script>

</body>
</html>