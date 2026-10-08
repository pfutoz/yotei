<?php
/**
 * ============================================================
 * ファイル名: calendar.php
 * システム名: 医師予定表管理システム（yotei）
 * バージョン: v2.0（Googleカレンダー風マルチデイ帯表示 ＆ 1ヶ月/3ヶ月切替対応）
 * ============================================================
 *
 * 【新機能・改善点】
 * - 期間予定をGoogleカレンダー同様の横断カラー帯（マルチデイバー）で表示
 * - 土曜日から日曜日への週またぎ期間予定を自然に折り返して継続表示
 * - 医師予定の視認性向上（医師短縮名＋時間＋タイトル＋種別カラー）
 * - リッチなホバー詳細ツールチップ（PC）＆ タップ詳細表示（スマホ）
 * - 1ヶ月表示（標準・ワイド）と3ヶ月連続表示のワンクリック切替
 * - 予定多数時の「+他○件」バッジ ＆ 当日の全予定ポップアップ展開
 * - 空白日クリックで日付指定の新規予定登録（input.php）へシームレス遷移
 * - 全体会（🏛️紫帯）および院内かわら版（🔔青帯）の統合表示
 * - 小野会医院カレンダー（祝日・休診日判定）完全連動
 */

require_once __DIR__ . '/LIB/session_tab.php';
require_once __DIR__ . '/LIB/db.php';
require_once __DIR__ . '/LIB/calendar_helper_onokai.php';

// 認証チェック
if (!is_logged_in()) {
    header('Location: ' . redirect_url('login.php'));
    exit;
}

// データベース接続
$pdo = getDbConnection();

// ============================================================
// 1. 設定・リクエストパラメータ
// ============================================================
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');

if ($month < 1 || $month > 12) { $month = (int)date('m'); }
if ($year < 2000 || $year > 2100) { $year = (int)date('Y'); }

// ビューモード切替（1m: 1ヶ月表示[デフォルト] / 3m: 3ヶ月表示）
if (isset($_GET['view'])) {
    $viewMode = $_GET['view'] === '3m' ? '3m' : '1m';
    $_SESSION['calendar_view'] = $viewMode;
} else {
    $viewMode = $_SESSION['calendar_view'] ?? '1m';
}

// フィルター・表示オプション（セッション記憶）
if (isset($_POST['set_filter'])) {
    $_SESSION['department_filter'] = $_POST['filter_value'];
    header('Location: ' . redirect_url('calendar.php?year=' . $year . '&month=' . $month . '&view=' . $viewMode));
    exit;
}
if (isset($_POST['toggle_cancelled'])) {
    $_SESSION['show_cancelled'] = !($_SESSION['show_cancelled'] ?? true);
    header('Location: ' . redirect_url('calendar.php?year=' . $year . '&month=' . $month . '&view=' . $viewMode));
    exit;
}
if (isset($_POST['toggle_draft'])) {
    $_SESSION['show_draft'] = !($_SESSION['show_draft'] ?? false);
    header('Location: ' . redirect_url('calendar.php?year=' . $year . '&month=' . $month . '&view=' . $viewMode));
    exit;
}

$deptFilter = $_SESSION['department_filter'] ?? 'all';
$showCancelled = $_SESSION['show_cancelled'] ?? true;
$showDraft = $_SESSION['show_draft'] ?? false;

// ============================================================
// 2. 表示対象月の計算（1ヶ月または3ヶ月）
// ============================================================
$months = [];
$numMonths = ($viewMode === '3m') ? 3 : 1;

for ($i = 0; $i < $numMonths; $i++) {
    $m = $month + $i;
    $y = $year;
    if ($m > 12) { $m -= 12; $y++; }
    $months[] = ['year' => $y, 'month' => $m];
}

$firstMonthStart = sprintf("%04d-%02d-01", $months[0]['year'], $months[0]['month']);
$lastMonthEnd = date('Y-m-t', strtotime(sprintf("%04d-%02d-01", $months[$numMonths - 1]['year'], $months[$numMonths - 1]['month'])));

// ============================================================
// 3. データ取得（部門・メモ・予定・全体会・かわら版）
// ============================================================
// メモ取得
$memoText = getMemoText($pdo);
$memoLines = explode("\n", $memoText);

// 部門一覧取得
$stmt = $pdo->query("SELECT id, name, display_name, color_code FROM departments WHERE is_active = true ORDER BY sort_order");
$departments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 医師予定データ取得（期間予定がまたがる場合も含め完全にカバー）
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
    WHERE e.start_date <= :end_date
      AND COALESCE(e.end_date, e.start_date) >= :start_date
";
$params = ['start_date' => $firstMonthStart, 'end_date' => $lastMonthEnd];

if ($deptFilter !== 'all' && is_numeric($deptFilter)) {
    $sql .= " AND (COALESCE(e.department_id, d.department_id, s.department_id) = :dept_id OR dep.id = :dept_id)";
    $params['dept_id'] = (int)$deptFilter;
}
if (!$showCancelled) { $sql .= " AND e.is_cancelled = false"; }
if (!$showDraft) { $sql .= " AND e.is_public = true"; }

$sql .= " ORDER BY e.start_date ASC, e.id ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rawEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 全体会データ取得
$stmt = $pdo->prepare("
    SELECT meeting_date, title, start_time, location, note
    FROM all_meetings
    WHERE meeting_date >= :start_date
      AND meeting_date <= :end_date
      AND is_cancelled = false
    ORDER BY meeting_date ASC
");
$stmt->execute(['start_date' => $firstMonthStart, 'end_date' => $lastMonthEnd]);
$allMeetings = $stmt->fetchAll(PDO::FETCH_ASSOC);

// かわら版イベントデータ取得
function getKawaraDbConnectionLocal() {
    try {
        return new PDO(
            "pgsql:host=localhost;dbname=kawara",
            'postgres',
            'postgres',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]
        );
    } catch (PDOException $e) {
        return null;
    }
}

$kawaraEvents = [];
$kawaraDb = getKawaraDbConnectionLocal();
if ($kawaraDb) {
    try {
        $stmtKawara = $kawaraDb->prepare("
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
        ");
        $stmtKawara->execute(['start_date' => $firstMonthStart, 'end_date' => $lastMonthEnd]);
        $kawaraEvents = $stmtKawara->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('かわら版イベント取得エラー: ' . $e->getMessage());
    }
}

// ============================================================
// 4. ナビゲーション計算
// ============================================================
$step = ($viewMode === '3m') ? 3 : 1;

$prevMonth = $month - $step;
$prevYear = $year;
while ($prevMonth < 1) { $prevMonth += 12; $prevYear--; }

$nextMonth = $month + $step;
$nextYear = $year;
while ($nextMonth > 12) { $nextMonth -= 12; $nextYear++; }

$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);

$filterName = 'すべて';
if ($deptFilter !== 'all') {
    foreach ($departments as $d) {
        if ($d['id'] == $deptFilter) { $filterName = $d['display_name']; break; }
    }
}

// ============================================================
// 5. Googleカレンダー風 月間グリッド描画関数
// ============================================================
function renderCalendarMonthGrid($targetYear, $targetMonth, $rawEvents, $allMeetings, $kawaraEvents, $viewMode) {
    $firstDayTs = strtotime(sprintf("%04d-%02d-01", $targetYear, $targetMonth));
    $daysInMonth = (int)date('t', $firstDayTs);
    $firstDayOfWeek = (int)date('w', $firstDayTs); // 0:日〜6:土
    $today = date('Y-m-d');
    $monthName = date('Y年n月', $firstDayTs);

    // カレンダーの開始日（第1週の日曜日）
    $calStartTs = strtotime("-{$firstDayOfWeek} days", $firstDayTs);
    // カレンダーの総日数（7の倍数）
    $totalCells = ceil(($firstDayOfWeek + $daysInMonth) / 7) * 7;
    $numWeeks = (int)($totalCells / 7);

    // 最大表示スロット数（1ヶ月表示なら4スロット、3ヶ月表示なら2スロット）
    $maxSlots = ($viewMode === '3m') ? 2 : 4;

    $html = '<div class="calendar-month-card" data-year="' . $targetYear . '" data-month="' . $targetMonth . '">';
    $html .= '<div class="calendar-month-header">';
    $html .= '<h2 class="month-title">' . $monthName . '</h2>';
    $html .= '<div class="month-subinfo">' . $daysInMonth . '日間</div>';
    $html .= '</div>';

    // 曜日ヘッダー
    $weekLabels = ['日', '月', '火', '水', '木', '金', '土'];
    $html .= '<div class="cal-weekday-grid">';
    foreach ($weekLabels as $idx => $lbl) {
        $cls = ($idx === 0) ? 'sun' : (($idx === 6) ? 'sat' : '');
        $html .= '<div class="cal-weekday-cell ' . $cls . '">' . $lbl . '</div>';
    }
    $html .= '</div>'; // .cal-weekday-grid

    // 週ごとのループ
    $currentDayTs = $calStartTs;
    for ($w = 0; $w < $numWeeks; $w++) {
        $weekStartTs = $currentDayTs;
        $weekEndTs = strtotime('+6 days', $weekStartTs);
        $weekStartDate = date('Y-m-d', $weekStartTs);
        $weekEndDate = date('Y-m-d', $weekEndTs);

        // 当週の7日分の日付情報を構築
        $daysInThisWeek = [];
        for ($d = 0; $d < 7; $d++) {
            $dayTs = strtotime("+{$d} days", $weekStartTs);
            $dayDate = date('Y-m-d', $dayTs);
            $isCurMonth = (date('Y-m', $dayTs) === sprintf("%04d-%02d", $targetYear, $targetMonth));
            $isToday = ($dayDate === $today);
            $isPast = ($dayDate < $today);
            $dow = (int)date('w', $dayTs);

            // 小野会カレンダー判定（祝日・休診日等）
            $onokaiCheck = check_onokai_calendar($dayDate, null);
            $isHoliday = !empty($onokaiCheck['is_closed']);
            $holidayName = $onokaiCheck['note'] ?? '';

            $daysInThisWeek[$d] = [
                'date' => $dayDate,
                'day_num' => (int)date('j', $dayTs),
                'is_cur_month' => $isCurMonth,
                'is_today' => $isToday,
                'is_past' => $isPast,
                'dow' => $dow,
                'is_holiday' => $isHoliday,
                'holiday_name' => $holidayName,
            ];
        }

        // 当週に重なるイベントを収集
        $weekEvents = [];

        // 1. 医師予定
        foreach ($rawEvents as $ev) {
            $eStart = $ev['start_date'];
            $eEnd = !empty($ev['end_date']) ? $ev['end_date'] : $ev['start_date'];
            if ($eStart <= $weekEndDate && $eEnd >= $weekStartDate) {
                $ev['_type'] = 'yotei';
                $weekEvents[] = $ev;
            }
        }

        // 2. 全体会
        foreach ($allMeetings as $am) {
            if ($am['meeting_date'] >= $weekStartDate && $am['meeting_date'] <= $weekEndDate) {
                $weekEvents[] = [
                    '_type' => 'all_meeting',
                    'id' => 'am_' . $am['meeting_date'],
                    'start_date' => $am['meeting_date'],
                    'end_date' => $am['meeting_date'],
                    'title' => $am['title'],
                    'start_time' => $am['start_time'],
                    'location' => $am['location'],
                    'note' => $am['note'] ?? '',
                ];
            }
        }

        // 3. かわら版
        foreach ($kawaraEvents as $ke) {
            $kDate = date('Y-m-d', strtotime($ke['event_date']));
            if ($kDate >= $weekStartDate && $kDate <= $weekEndDate) {
                $weekEvents[] = [
                    '_type' => 'kawara',
                    'id' => $ke['id'],
                    'start_date' => $kDate,
                    'end_date' => $kDate,
                    'title' => $ke['title'],
                    'category_name' => $ke['category_name'] ?? '',
                ];
            }
        }

        // イベントのソート（帯を上部に優先、期間が長い順、開始日順）
        usort($weekEvents, function($a, $b) {
            $aStart = $a['start_date'];
            $aEnd = !empty($a['end_date']) ? $a['end_date'] : $a['start_date'];
            $bStart = $b['start_date'];
            $bEnd = !empty($b['end_date']) ? $b['end_date'] : $b['start_date'];

            $aSpan = (strtotime($aEnd) - strtotime($aStart)) / 86400;
            $bSpan = (strtotime($bEnd) - strtotime($bStart)) / 86400;

            $aMulti = $aSpan > 0 ? 1 : 0;
            $bMulti = $bSpan > 0 ? 1 : 0;

            if ($aMulti !== $bMulti) return $bMulti - $aMulti;
            if ($aSpan !== $bSpan) return $bSpan - $aSpan;
            if ($aStart !== $bStart) return strcmp($aStart, $bStart);
            return strcmp((string)($a['id'] ?? ''), (string)($b['id'] ?? ''));
        });

        // スロット割り当て（Greedy Interval Scheduling）
        $slots = [];
        $placedEvents = [];
        $overflowCounts = array_fill(0, 7, 0);

        foreach ($weekEvents as $ev) {
            $eStart = $ev['start_date'];
            $eEnd = !empty($ev['end_date']) ? $ev['end_date'] : $ev['start_date'];

            $startCol = max(0, (int)round((strtotime(max($eStart, $weekStartDate)) - strtotime($weekStartDate)) / 86400));
            $endCol = min(6, (int)round((strtotime(min($eEnd, $weekEndDate)) - strtotime($weekStartDate)) / 86400));

            $slotIdx = 0;
            while (true) {
                $conflict = false;
                if (isset($slots[$slotIdx])) {
                    for ($c = $startCol; $c <= $endCol; $c++) {
                        if (!empty($slots[$slotIdx][$c])) {
                            $conflict = true;
                            break;
                        }
                    }
                }
                if (!$conflict) break;
                $slotIdx++;
            }

            // スロットを占有
            for ($c = $startCol; $c <= $endCol; $c++) {
                $slots[$slotIdx][$c] = true;
            }

            $isMultiDay = ($eStart !== $eEnd);
            $continuesPrev = ($eStart < $weekStartDate);
            $continuesNext = ($eEnd > $weekEndDate);

            if ($slotIdx < $maxSlots) {
                $placedEvents[] = [
                    'data' => $ev,
                    'slot' => $slotIdx,
                    'start_col' => $startCol + 1, // CSS Grid 1-indexed
                    'end_col' => $endCol + 2,     // CSS Grid end line
                    'is_multi_day' => $isMultiDay,
                    'continues_prev' => $continuesPrev,
                    'continues_next' => $continuesNext,
                ];
            } else {
                for ($c = $startCol; $c <= $endCol; $c++) {
                    $overflowCounts[$c]++;
                }
            }
        }

        // 週グリッドのHTML出力
        $html .= '<div class="cal-week-row">';

        // レイヤー1: 背景グリッド（各日の枠線・日付ヘッダー・背景色）
        $html .= '<div class="cal-bg-layer">';
        for ($c = 0; $c < 7; $c++) {
            $dayInfo = $daysInThisWeek[$c];
            $cellClass = 'cal-day-cell';
            if (!$dayInfo['is_cur_month']) $cellClass .= ' other-month';
            if ($dayInfo['is_today']) $cellClass .= ' today';
            if ($dayInfo['is_past']) $cellClass .= ' past';
            if ($dayInfo['is_holiday']) $cellClass .= ' holiday';
            if ($dayInfo['dow'] === 0) $cellClass .= ' sun';
            if ($dayInfo['dow'] === 6) $cellClass .= ' sat';

            $newUrl = url('input.php?start_date=' . $dayInfo['date'] . '&return_to=calendar');
            $html .= '<div class="' . $cellClass . '" data-date="' . $dayInfo['date'] . '">';
            $html .= '<div class="day-cell-top">';
            $html .= '<span class="day-number">' . $dayInfo['day_num'] . '</span>';
            if (!empty($dayInfo['holiday_name'])) {
                $html .= '<span class="holiday-name" title="' . htmlspecialchars($dayInfo['holiday_name']) . '">' . htmlspecialchars($dayInfo['holiday_name']) . '</span>';
            }
            if ($dayInfo['is_cur_month'] && !$dayInfo['is_past']) {
                $html .= '<a href="' . $newUrl . '" class="day-add-link" title="この日に予定を追加"><i class="fa-solid fa-plus"></i></a>';
            }
            $html .= '</div>'; // .day-cell-top

            // オーバーフロー（+他○件）表示
            if ($overflowCounts[$c] > 0) {
                $html .= '<div class="day-overflow-btn" onclick="openDayDetailModal(\'' . $dayInfo['date'] . '\')">';
                $html .= '+ 他' . $overflowCounts[$c] . '件';
                $html .= '</div>';
            }

            $html .= '</div>'; // .cal-day-cell
        }
        $html .= '</div>'; // .cal-bg-layer

        // レイヤー2: イベント帯（マルチデイバー & 単日チップ）グリッド
        $html .= '<div class="cal-events-layer">';
        foreach ($placedEvents as $item) {
            $ev = $item['data'];
            $slot = $item['slot'];
            $startCol = $item['start_col'];
            $endCol = $item['end_col'];
            $isMulti = $item['is_multi_day'];
            $contPrev = $item['continues_prev'];
            $contNext = $item['continues_next'];

            $gridStyle = "grid-column: {$startCol} / {$endCol}; grid-row: " . ($slot + 1) . ";";

            if ($ev['_type'] === 'yotei') {
                // 医師予定
                $docName = $ev['doctor_short_name'] ?? $ev['staff_short_name'] ?? '担当';
                $evType = $ev['event_type'] ?? 'other';
                $isCancelled = !empty($ev['is_cancelled']);

                // 種別カラークラス
                $typeClass = 'type-' . $evType;
                if ($isCancelled) $typeClass = 'type-cancelled';

                // アイコン選定
                $icon = '📌';
                if ($evType === 'absence') $icon = '🔴';
                elseif ($evType === 'clinic') $icon = '🟢';
                elseif ($evType === 'meeting') $icon = '🟡';
                elseif ($evType === 'business_trip') $icon = '✈️';
                if ($isCancelled) $icon = '❌';

                // 表示テキスト
                $timeText = '';
                if (!empty($ev['start_time']) && empty($ev['is_all_day'])) {
                    $timeText = substr($ev['start_time'], 0, 5) . ' ';
                }

                $barClass = 'event-bar ' . $typeClass;
                if ($isMulti) $barClass .= ' is-multi-day';
                if ($contPrev) $barClass .= ' cont-prev';
                if ($contNext) $barClass .= ' cont-next';

                // JSON詳細データ属性
                $jsonDetail = htmlspecialchars(json_encode([
                    'id' => $ev['id'],
                    'title' => $ev['title'],
                    'doctor' => $docName,
                    'doctor_full' => $ev['doctor_full_name'] ?? $docName,
                    'dept' => $ev['department_display_name'] ?? '',
                    'start_date' => $ev['start_date'],
                    'end_date' => $ev['end_date'] ?? null,
                    'time' => $timeText ? trim($timeText) : '終日',
                    'event_type' => $evType,
                    'note' => $ev['note'] ?? '',
                    'is_cancelled' => $isCancelled,
                    'is_public' => !empty($ev['is_public']),
                    'created_by' => $ev['created_by'] ?? '',
                ], JSON_UNESCAPED_UNICODE), ENT_QUOTES);

                $html .= '<div class="' . $barClass . '" style="' . $gridStyle . '" onclick="openEventDetail(' . $ev['id'] . ')" data-event-json="' . $jsonDetail . '" onmouseenter="showTooltip(event, this)" onmouseleave="hideTooltip()">';
                if ($contPrev) $html .= '<span class="arrow-indicator">◀</span>';
                $html .= '<span class="event-icon">' . $icon . '</span>';
                $html .= '<span class="event-doctor-tag">[' . htmlspecialchars($docName) . ']</span> ';
                if ($timeText) $html .= '<span class="event-time">' . htmlspecialchars($timeText) . '</span>';
                $html .= '<span class="event-title-text">' . htmlspecialchars($ev['title']) . '</span>';
                if ($contNext) $html .= '<span class="arrow-indicator">▶</span>';
                $html .= '</div>';

            } elseif ($ev['_type'] === 'all_meeting') {
                // 全体会
                $barClass = 'event-bar type-all-meeting';
                $timeText = substr($ev['start_time'], 0, 5);
                $jsonDetail = htmlspecialchars(json_encode([
                    'type' => 'all_meeting',
                    'title' => $ev['title'],
                    'date' => $ev['start_date'],
                    'time' => $timeText,
                    'location' => $ev['location'],
                    'note' => $ev['note'],
                ], JSON_UNESCAPED_UNICODE), ENT_QUOTES);

                $html .= '<div class="' . $barClass . '" style="' . $gridStyle . '" onclick="openMeetingDetail(\'' . $ev['start_date'] . '\')" data-event-json="' . $jsonDetail . '" onmouseenter="showTooltip(event, this)" onmouseleave="hideTooltip()">';
                $html .= '<span class="event-icon">🏛️</span>';
                $html .= '<span class="event-title-text">全体会 ' . htmlspecialchars($ev['title']) . ' ' . $timeText . '〜</span>';
                $html .= '</div>';

            } elseif ($ev['_type'] === 'kawara') {
                // かわら版
                $barClass = 'event-bar type-kawara';
                $cat = !empty($ev['category_name']) ? '[' . htmlspecialchars($ev['category_name']) . '] ' : '';
                $jsonDetail = htmlspecialchars(json_encode([
                    'type' => 'kawara',
                    'id' => $ev['id'],
                    'title' => $ev['title'],
                    'date' => $ev['start_date'],
                    'category' => $ev['category_name'],
                ], JSON_UNESCAPED_UNICODE), ENT_QUOTES);

                $html .= '<div class="' . $barClass . '" style="' . $gridStyle . '" onclick="window.location.href=\'/kawara/view_post.php?id=' . $ev['id'] . '\'" data-event-json="' . $jsonDetail . '" onmouseenter="showTooltip(event, this)" onmouseleave="hideTooltip()">';
                $html .= '<span class="event-icon">🔔</span>';
                $html .= '<span class="event-title-text">' . $cat . htmlspecialchars($ev['title']) . '</span>';
                $html .= '</div>';
            }
        }
        $html .= '</div>'; // .cal-events-layer

        $html .= '</div>'; // .cal-week-row

        $currentDayTs = strtotime('+7 days', $weekStartTs);
    }

    $html .= '</div>'; // .calendar-month-card
    return $html;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>医師予定カレンダー - yotei</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Noto+Sans+JP:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #2563eb;
            --primary-light: #eff6ff;
            --primary-dark: #1d4ed8;
            --secondary: #64748b;
            --success: #059669;
            --warning: #d97706;
            --danger: #dc2626;
            --bg-body: #f8fafc;
            --bg-card: #ffffff;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --border: #e2e8f0;
            --border-cell: #f1f5f9;
            --radius: 12px;
            --shadow-sm: 0 1px 3px 0 rgba(0,0,0,0.06), 0 1px 2px 0 rgba(0,0,0,0.04);
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -2px rgba(0, 0, 0, 0.05);
            --shadow-lg: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', 'Noto Sans JP', sans-serif;
            background: var(--bg-body);
            color: var(--text-main);
            padding: 20px 24px;
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
        }
        .container {
            max-width: <?= ($viewMode === '3m') ? '1050px' : '1280px' ?>;
            margin: 0 auto;
            transition: max-width 0.3s ease;
        }

        /* ヘッダー */
        .app-header {
            background: var(--bg-card);
            padding: 14px 20px;
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
        .app-title {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--primary);
        }
        .app-title i.main-icon {
            background: var(--primary-light);
            padding: 10px;
            border-radius: 10px;
            color: var(--primary);
        }
        .app-title .input-link {
            font-size: 13px;
            font-weight: 700;
            color: #64748b;
            text-decoration: none;
            background: #f1f5f9;
            padding: 4px 10px;
            border-radius: 20px;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .app-title .input-link:hover {
            color: white;
            background: var(--primary);
            transform: translateY(-1px);
        }
        .header-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .header-actions .btn {
            padding: 6px 12px;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: var(--bg-card);
            font-weight: 500;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: var(--text-main);
            transition: all 0.2s;
            font-size: 0.85rem;
            text-decoration: none;
        }
        .header-actions .btn:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
        }

        /* コントロールバー（ビュー切替 ＆ 日付ナビ） */
        .control-bar {
            background: var(--bg-card);
            padding: 12px 18px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 14px;
            flex-wrap: wrap;
            gap: 12px;
        }
        .view-switcher {
            display: flex;
            background: #f1f5f9;
            padding: 3px;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
        }
        .view-btn {
            padding: 6px 14px;
            border-radius: 8px;
            border: none;
            background: transparent;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-muted);
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }
        .view-btn.active {
            background: white;
            color: var(--primary);
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .date-nav {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }
        .date-nav .btn {
            padding: 6px 12px;
            border-radius: 6px;
            border: 1px solid var(--border);
            background: white;
            color: var(--text-main);
            font-size: 0.85rem;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all 0.15s;
        }
        .date-nav .btn:hover { background: #f8fafc; border-color: #cbd5e1; }
        .date-nav .btn-primary {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        .date-nav .btn-primary:hover { background: var(--primary-dark); }
        .date-range-badge {
            font-weight: 700;
            color: var(--text-main);
            font-size: 0.95rem;
            margin-left: 6px;
            padding: 4px 10px;
            background: #f8fafc;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
        }

        /* フィルターバー */
        .filter-group {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }
        .filter-chip {
            padding: 4px 12px;
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
        .option-group {
            display: flex;
            gap: 12px;
            font-size: 0.8rem;
            align-items: center;
        }
        .option-group label {
            display: flex;
            align-items: center;
            gap: 4px;
            cursor: pointer;
            color: var(--text-muted);
            font-weight: 500;
        }

        /* メモカード */
        .memo-card {
            background: var(--bg-card);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            padding: 12px 18px;
            margin-bottom: 14px;
        }
        .memo-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .memo-header h3 {
            font-size: 0.9rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--primary);
            margin: 0;
        }
        .memo-header .icon-btn {
            width: 28px;
            height: 28px;
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
        .memo-header .icon-btn:hover {
            background: var(--primary-light);
            color: var(--primary);
        }
        .info-list {
            list-style: none;
            font-size: 0.85rem;
            color: var(--text-muted);
            display: flex;
            flex-direction: column;
            gap: 3px;
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
            padding: 8px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 0.85rem;
            font-family: inherit;
            resize: vertical;
            min-height: 80px;
            margin-top: 6px;
        }
        .memo-edit-form .form-actions {
            display: flex;
            gap: 8px;
            align-items: center;
            margin-top: 6px;
        }
        .memo-edit-form .form-actions input[type="password"] {
            padding: 4px 8px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 0.85rem;
        }

        /* 凡例バー */
        .legend-bar {
            display: flex;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
            padding: 4px 6px 12px 6px;
            font-size: 0.75rem;
            color: var(--text-muted);
        }
        .legend-title {
            font-weight: 700;
            color: var(--text-main);
        }
        .legend-item {
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .legend-badge {
            display: inline-block;
            width: 14px;
            height: 10px;
            border-radius: 3px;
        }
        .legend-badge.badge-absence { background: #fee2e2; border: 1px solid #f87171; }
        .legend-badge.badge-clinic { background: #dcfce7; border: 1px solid #4ade80; }
        .legend-badge.badge-meeting { background: #fef3c7; border: 1px solid #fbbf24; }
        .legend-badge.badge-trip { background: #dbeafe; border: 1px solid #60a5fa; }
        .legend-badge.badge-all-meeting { background: #f3e8ff; border: 1px solid #c084fc; }
        .legend-badge.badge-kawara { background: #e0f2fe; border: 1px solid #38bdf8; }

        /* ============================================================
           Googleカレンダー風 CSS Grid カレンダー
           ============================================================ */
        .calendar-container {
            display: flex;
            flex-direction: column;
            gap: 24px;
        }

        .calendar-month-card {
            background: var(--bg-card);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            overflow: hidden;
        }
        .calendar-month-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 20px;
            background: #ffffff;
            border-bottom: 1px solid var(--border);
        }
        .month-title {
            font-size: 1.15rem;
            font-weight: 700;
            color: var(--text-main);
        }
        .month-subinfo {
            font-size: 0.8rem;
            color: var(--text-muted);
            font-weight: 500;
        }

        /* 曜日グリッド */
        .cal-weekday-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            background: #f8fafc;
            border-bottom: 1px solid var(--border);
        }
        .cal-weekday-cell {
            padding: 8px 4px;
            text-align: center;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-muted);
        }
        .cal-weekday-cell.sun { color: var(--danger); }
        .cal-weekday-cell.sat { color: var(--primary); }

        /* 週行コンテナ */
        .cal-week-row {
            position: relative;
            min-height: <?= ($viewMode === '3m') ? '90px' : '120px' ?>;
            border-bottom: 1px solid var(--border);
        }
        .cal-week-row:last-child {
            border-bottom: none;
        }

        /* レイヤー1: 背景グリッド（7日分の日付セル） */
        .cal-bg-layer {
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            display: grid;
            grid-template-columns: repeat(7, 1fr);
        }
        .cal-day-cell {
            border-right: 1px solid var(--border-cell);
            padding: 4px 6px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            background: #ffffff;
            transition: background 0.15s;
        }
        .cal-day-cell:last-child { border-right: none; }
        .cal-day-cell.other-month {
            background: #fafafa;
            color: #94a3b8;
        }
        .cal-day-cell.today {
            background: #eff6ff !important;
        }
        .cal-day-cell.holiday {
            background: #fff5f5 !important;
        }
        .cal-day-cell.sun .day-number { color: var(--danger); }
        .cal-day-cell.sat .day-number { color: var(--primary); }

        .day-cell-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 4px;
        }
        .day-number {
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 22px;
            height: 22px;
            border-radius: 50%;
        }
        .cal-day-cell.today .day-number {
            background: var(--primary);
            color: white !important;
        }
        .holiday-name {
            font-size: 0.65rem;
            color: var(--danger);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 65px;
        }
        .day-add-link {
            font-size: 0.7rem;
            color: #cbd5e1;
            text-decoration: none;
            padding: 2px 4px;
            border-radius: 4px;
            opacity: 0;
            transition: all 0.15s;
        }
        .cal-day-cell:hover .day-add-link {
            opacity: 1;
            color: var(--primary);
            background: #e0f2fe;
        }

        .day-overflow-btn {
            font-size: 0.7rem;
            color: var(--primary);
            font-weight: 600;
            cursor: pointer;
            padding: 1px 4px;
            border-radius: 4px;
            background: #eff6ff;
            text-align: center;
            border: 1px solid #bfdbfe;
            margin-top: auto;
            pointer-events: auto;
            transition: all 0.15s;
        }
        .day-overflow-btn:hover {
            background: #dbeafe;
            color: var(--primary-dark);
        }

        /* レイヤー2: イベント帯（マルチデイバー & 単日チップ）グリッド */
        .cal-events-layer {
            position: relative;
            z-index: 2;
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            grid-auto-rows: 24px;
            gap: 2px 0;
            padding-top: 26px; /* 日付ヘッダー分の余白 */
            padding-bottom: 4px;
            pointer-events: none;
        }

        /* 帯（バー）共通スタイル */
        .event-bar {
            pointer-events: auto;
            margin: 1px 3px;
            padding: 0 6px;
            font-size: 0.72rem;
            line-height: 22px;
            height: 22px;
            border-radius: 4px;
            cursor: pointer;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            display: flex;
            align-items: center;
            gap: 4px;
            font-weight: 500;
            border: 1px solid transparent;
            box-shadow: 0 1px 2px rgba(0,0,0,0.04);
            transition: all 0.15s ease;
            position: relative;
        }
        .event-bar:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.12);
            z-index: 10;
        }

        /* 複数日帯の週またぎ境界表現（Googleカレンダー風） */
        .event-bar.is-multi-day {
            font-weight: 600;
        }
        .event-bar.cont-prev {
            border-top-left-radius: 0;
            border-bottom-left-radius: 0;
            margin-left: 0;
            border-left: 3px solid currentColor;
        }
        .event-bar.cont-next {
            border-top-right-radius: 0;
            border-bottom-right-radius: 0;
            margin-right: 0;
            border-right: 3px solid currentColor;
        }
        .arrow-indicator {
            font-size: 0.55rem;
            opacity: 0.7;
        }

        .event-doctor-tag {
            font-weight: 700;
        }
        .event-time {
            font-size: 0.68rem;
            opacity: 0.85;
        }
        .event-title-text {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* 種別ごとのカラーリング */
        /* 休診・不在（赤系） */
        .event-bar.type-absence {
            background: #fee2e2;
            color: #991b1b;
            border-color: #fca5a5;
        }
        .event-bar.type-absence:hover { background: #fecaca; }

        /* 診察・外来（緑系） */
        .event-bar.type-clinic {
            background: #dcfce7;
            color: #166534;
            border-color: #86efac;
        }
        .event-bar.type-clinic:hover { background: #bbf7d0; }

        /* 会議・委員会（黄・アンバー系） */
        .event-bar.type-meeting {
            background: #fef3c7;
            color: #92400e;
            border-color: #fcd34d;
        }
        .event-bar.type-meeting:hover { background: #fde68a; }

        /* 出張・学会（青系） */
        .event-bar.type-business_trip {
            background: #dbeafe;
            color: #1e40af;
            border-color: #93c5fd;
        }
        .event-bar.type-business_trip:hover { background: #bfdbfe; }

        /* その他（スレート系） */
        .event-bar.type-other {
            background: #f1f5f9;
            color: #334155;
            border-color: #cbd5e1;
        }
        .event-bar.type-other:hover { background: #e2e8f0; }

        /* 取消（グレー打消し） */
        .event-bar.type-cancelled {
            background: #f1f5f9;
            color: #94a3b8;
            border-color: #e2e8f0;
            text-decoration: line-through;
        }

        /* 全体会（パープル系） */
        .event-bar.type-all-meeting {
            background: #f3e8ff;
            color: #6b21a8;
            border-color: #d8b4fe;
            font-weight: 600;
        }
        .event-bar.type-all-meeting:hover { background: #e9d5ff; }

        /* かわら版（スカイブルー系） */
        .event-bar.type-kawara {
            background: #e0f2fe;
            color: #0369a1;
            border-color: #7dd3fc;
        }
        .event-bar.type-kawara:hover { background: #bae6fd; }

        /* フローティングツールチップ（ポップオーバー） */
        #hoverTooltip {
            position: fixed;
            z-index: 99999;
            pointer-events: none;
            background: #1e293b;
            color: #ffffff;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 0.8rem;
            line-height: 1.4;
            max-width: 300px;
            box-shadow: var(--shadow-lg);
            opacity: 0;
            transform: translateY(6px);
            transition: opacity 0.15s ease, transform 0.15s ease;
        }
        #hoverTooltip.visible {
            opacity: 1;
            transform: translateY(0);
        }
        #hoverTooltip .tooltip-title {
            font-weight: 700;
            font-size: 0.88rem;
            margin-bottom: 4px;
            color: #f8fafc;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        #hoverTooltip .tooltip-row {
            display: flex;
            gap: 6px;
            margin-bottom: 2px;
            color: #cbd5e1;
        }
        #hoverTooltip .tooltip-row .label {
            color: #94a3b8;
            width: 45px;
            flex-shrink: 0;
        }

        /* モーダル */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(2px);
            z-index: 9999;
            align-items: center;
            justify-content: center;
        }
        .modal-overlay.show { display: flex; }
        .modal-content {
            background: white;
            padding: 24px;
            border-radius: 16px;
            max-width: 520px;
            width: 92%;
            max-height: 85vh;
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
            font-size: 20px;
            cursor: pointer;
            color: #94a3b8;
            transition: color 0.2s;
        }
        .modal-content .close-btn:hover { color: var(--danger); }
        .modal-header h2 {
            font-size: 1.15rem;
            font-weight: 700;
            color: var(--text-main);
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .day-event-card {
            background: #f8fafc;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 10px 12px;
            margin-bottom: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
        }
        .day-event-card.absence { border-left: 4px solid #ef4444; }
        .day-event-card.clinic { border-left: 4px solid #10b981; }
        .day-event-card.meeting { border-left: 4px solid #f59e0b; }
        .day-event-card.business_trip { border-left: 4px solid #3b82f6; }
        .day-event-card.all-meeting { border-left: 4px solid #a855f7; }
        .day-event-card.kawara { border-left: 4px solid #0284c7; }

        .modal-actions {
            display: flex;
            gap: 8px;
            margin-top: 16px;
            padding-top: 14px;
            border-top: 1px solid var(--border);
            justify-content: flex-end;
        }
        .modal-actions .btn {
            padding: 6px 14px;
            border-radius: 6px;
            border: 1px solid var(--border);
            background: white;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            color: var(--text-main);
        }
        .modal-actions .btn-primary {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .detail-item {
            display: flex;
            padding: 7px 0;
            border-bottom: 1px solid var(--border);
            font-size: 0.88rem;
        }
        .detail-item:last-child { border-bottom: none; }
        .detail-item .label {
            width: 75px;
            flex-shrink: 0;
            font-weight: 600;
            color: var(--text-muted);
        }
        .detail-item .value { color: var(--text-main); }

        .badge-status {
            font-size: 0.75rem;
            padding: 2px 8px;
            border-radius: 12px;
            font-weight: 600;
        }
        .badge-public { background: #dcfce7; color: #166534; }
        .badge-draft { background: #f1f5f9; color: #64748b; }
        .badge-cancelled { background: #fef2f2; color: #991b1b; }

        .footer {
            margin-top: 24px;
            text-align: center;
            font-size: 12px;
            color: #94a3b8;
            padding: 12px;
        }

        @media (max-width: 768px) {
            body { padding: 12px; }
            .app-header { flex-direction: column; align-items: stretch; gap: 8px; }
            .control-bar { flex-direction: column; align-items: stretch; }
            .date-nav { justify-content: center; }
            .view-switcher { justify-content: center; }
            .cal-week-row { min-height: 80px; }
            .event-bar { font-size: 0.65rem; height: 20px; line-height: 20px; }
            .event-doctor-tag { display: inline; }
            .event-time { display: none; }
        }

        @media print {
            .no-print { display: none !important; }
            body { background: white; padding: 0; }
            .container { max-width: 100% !important; }
            .calendar-month-card { box-shadow: none; border: 1px solid #ccc; break-inside: avoid; }
            .cal-week-row { min-height: 75px; }
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
            <i class="fa-solid fa-calendar-days main-icon"></i>
            <span>医師予定カレンダー</span>
            <a href="<?= url('input.php?return_to=calendar') ?>" class="input-link" title="予定を追加">
                <i class="fa-solid fa-plus"></i> 予定を追加
            </a>
        </div>
        <div class="header-actions">
            <a href="<?= url('yotei_list.php?year=' . $year . '&month=' . $month) ?>" class="btn" title="予定表一覧（リスト）">
                <i class="fa-solid fa-list-ul"></i> リスト表示
            </a>
            <a href="<?= url('index.php') ?>" class="btn" style="background:#f1f5f9;" title="機能メニューへ">
                <i class="fa-solid fa-table-cells-large"></i> メニュー
            </a>
            <a href="<?= url('calendar_kawaraban.php?year=' . $year . '&month=' . $month) ?>" class="btn" style="background:#ebf8ff;border-color:#90cdf4;">
                <i class="fa-regular fa-newspaper"></i> かわら版
            </a>
            <a href="<?= url('master/index.php') ?>" class="btn" title="マスターメンテナンス">
                <i class="fa-solid fa-gear"></i> 設定
            </a>
            <a href="<?= url('../index.php') ?>" class="btn" title="小野会ポータルに戻る">
                <i class="fa-solid fa-house"></i>
            </a>
        </div>
    </header>

    <!-- ============================================================
         コントロールバー（ビュー切替 ＆ 日付ナビ）
         ============================================================ -->
    <div class="control-bar no-print">
        <!-- 1ヶ月 / 3ヶ月 表示切替 -->
        <div class="view-switcher">
            <a href="<?= url('calendar.php?year=' . $year . '&month=' . $month . '&view=1m') ?>" class="view-btn <?= $viewMode === '1m' ? 'active' : '' ?>">
                <i class="fa-solid fa-calendar-day"></i> 1ヶ月表示
            </a>
            <a href="<?= url('calendar.php?year=' . $year . '&month=' . $month . '&view=3m') ?>" class="view-btn <?= $viewMode === '3m' ? 'active' : '' ?>">
                <i class="fa-solid fa-calendar-week"></i> 3ヶ月連続
            </a>
        </div>

        <!-- 前後月・今日ナビ -->
        <div class="date-nav">
            <a href="<?= url('calendar.php?year=' . $prevYear . '&month=' . $prevMonth . '&view=' . $viewMode) ?>" class="btn" title="前へ">
                <i class="fa-solid fa-chevron-left"></i>
            </a>
            <a href="<?= url('calendar.php?year=' . date('Y') . '&month=' . date('m') . '&view=' . $viewMode) ?>" class="btn btn-primary" title="今月に移動">
                今日
            </a>
            <a href="<?= url('calendar.php?year=' . $nextYear . '&month=' . $nextMonth . '&view=' . $viewMode) ?>" class="btn" title="次へ">
                <i class="fa-solid fa-chevron-right"></i>
            </a>
            <span class="date-range-badge">
                <?= date('Y年n月j日', strtotime($firstMonthStart)) ?> 〜 <?= date('Y年n月j日', strtotime($lastMonthEnd)) ?>
            </span>
        </div>
    </div>

    <!-- ============================================================
         フィルターバー
         ============================================================ -->
    <div class="control-bar no-print">
        <div class="filter-group">
            <span style="font-size:0.8rem;font-weight:600;color:var(--text-muted);"><i class="fa-solid fa-filter"></i> 部門:</span>
            <form method="POST" style="display:inline;">
                <input type="hidden" name="set_filter" value="1">
                <input type="hidden" name="filter_value" value="all">
                <button type="submit" class="filter-chip <?= $deptFilter === 'all' ? 'active' : '' ?>">すべて</button>
            </form>
            <?php foreach ($departments as $dept): ?>
            <form method="POST" style="display:inline;">
                <input type="hidden" name="set_filter" value="1">
                <input type="hidden" name="filter_value" value="<?= $dept['id'] ?>">
                <button type="submit" class="filter-chip <?= $deptFilter == $dept['id'] ? 'active' : '' ?>">
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
                <label><input type="checkbox" <?= $showDraft ? 'checked' : '' ?> onchange="this.form.submit();"> 非公開含む</label>
            </form>
        </div>
    </div>

    <!-- ============================================================
         メッセージ
         ============================================================ -->
    <?php if ($message): ?>
    <div class="message" style="padding:10px 14px;background:#dcfce7;color:#166534;border:1px solid #86efac;border-radius:8px;margin-bottom:12px;">
        <?= $message ?>
    </div>
    <?php endif; ?>

    <!-- ============================================================
         メモカード
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
                <textarea name="memo_text" rows="3"><?= htmlspecialchars($memoText) ?></textarea>
                <div class="form-actions">
                    <input type="password" name="password" placeholder="マスターパスワード" autocomplete="off">
                    <button type="submit" class="btn btn-primary" style="padding:4px 10px;background:var(--primary);color:white;border:none;border-radius:4px;cursor:pointer;"><i class="fa-solid fa-floppy-disk"></i> 保存</button>
                    <button type="button" class="btn" style="padding:4px 10px;background:#f1f5f9;border:1px solid #cbd5e1;border-radius:4px;cursor:pointer;" onclick="toggleMemoEdit()">キャンセル</button>
                </div>
                <div id="memoMessage" style="margin-top:4px;font-size:0.85rem;"></div>
            </form>
        </div>
    </div>

    <!-- ============================================================
         凡例バー
         ============================================================ -->
    <div class="legend-bar no-print">
        <span class="legend-title">📌 凡例:</span>
        <span class="legend-item"><span class="legend-badge badge-absence"></span> 休診・不在</span>
        <span class="legend-item"><span class="legend-badge badge-clinic"></span> 診察・外来</span>
        <span class="legend-item"><span class="legend-badge badge-meeting"></span> 会議</span>
        <span class="legend-item"><span class="legend-badge badge-trip"></span> 出張・学会</span>
        <span class="legend-item"><span class="legend-badge badge-all-meeting"></span> 全体会</span>
        <span class="legend-item"><span class="legend-badge badge-kawara"></span> かわら版</span>
        <span class="legend-item" style="color:#0284c7;margin-left:auto;"><i class="fa-regular fa-hand-pointer"></i> 帯にホバーで詳細表示 / 空き日クリックで追加</span>
    </div>

    <!-- ============================================================
         カレンダーグリッド本体
         ============================================================ -->
    <div class="calendar-container">
        <?php foreach ($months as $m): ?>
        <?= renderCalendarMonthGrid($m['year'], $m['month'], $rawEvents, $allMeetings, $kawaraEvents, $viewMode) ?>
        <?php endforeach; ?>
    </div>

    <!-- ============================================================
         フッター
         ============================================================ -->
    <div class="footer no-print">
        yotei v2.0 &bull; PostgreSQL &bull; <?= date('Y年m月d日 H:i') ?>
    </div>

</div>

<!-- ============================================================
     フローティングツールチップ（ホバー詳細）
     ============================================================ -->
<div id="hoverTooltip">
    <div id="tooltipContent"></div>
</div>

<!-- ============================================================
     予定詳細モーダル
     ============================================================ -->
<div id="detailModal" class="modal-overlay">
    <div class="modal-content">
        <button class="close-btn" onclick="closeDetailModal()"><i class="fa-solid fa-xmark"></i></button>
        <div class="modal-header">
            <h2 id="detailTitle"><i class="fa-solid fa-clipboard-list"></i> 予定詳細</h2>
        </div>
        <div id="detailBody">
            <div style="text-align:center;padding:20px;color:#94a3b8;"><i class="fa-solid fa-spinner fa-spin"></i> 読み込み中...</div>
        </div>
    </div>
</div>

<!-- ============================================================
     当日全予定ポップオーバーモーダル（+他○件クリック時）
     ============================================================ -->
<div id="dayDetailModal" class="modal-overlay">
    <div class="modal-content" style="max-width: 580px;">
        <button class="close-btn" onclick="closeDayDetailModal()"><i class="fa-solid fa-xmark"></i></button>
        <div class="modal-header">
            <h2 id="dayModalTitle"><i class="fa-regular fa-calendar-check"></i> 当日の予定一覧</h2>
        </div>
        <div id="dayModalBody">
            <!-- 動的挿入 -->
        </div>
        <div class="modal-actions">
            <a href="#" id="dayModalAddBtn" class="btn btn-primary"><i class="fa-solid fa-plus"></i> この日に予定を追加</a>
            <button class="btn" onclick="closeDayDetailModal()">閉じる</button>
        </div>
    </div>
</div>

<!-- ============================================================
     JavaScript
     ============================================================ -->
<script>
// ============================================================
// 1. ホバー詳細ツールチップ
// ============================================================
const tooltip = document.getElementById('hoverTooltip');
const tooltipContent = document.getElementById('tooltipContent');

function showTooltip(e, el) {
    const rawJson = el.getAttribute('data-event-json');
    if (!rawJson) return;

    try {
        const ev = JSON.parse(rawJson);
        let html = '';

        if (ev.type === 'all_meeting') {
            html = `
                <div class="tooltip-title">🏛️ 全体会: ${escapeHtml(ev.title)}</div>
                <div class="tooltip-row"><span class="label">📅 日程:</span> ${ev.date} ${ev.time}〜</div>
                ${ev.location ? `<div class="tooltip-row"><span class="label">📍 場所:</span> ${escapeHtml(ev.location)}</div>` : ''}
                ${ev.note ? `<div class="tooltip-row"><span class="label">📝 備考:</span> ${escapeHtml(ev.note)}</div>` : ''}
            `;
        } else if (ev.type === 'kawara') {
            html = `
                <div class="tooltip-title">🔔 かわら版: ${escapeHtml(ev.title)}</div>
                <div class="tooltip-row"><span class="label">📅 日程:</span> ${ev.date}</div>
                ${ev.category ? `<div class="tooltip-row"><span class="label">🏷️ 部門:</span> ${escapeHtml(ev.category)}</div>` : ''}
            `;
        } else {
            const dateRange = (ev.end_date && ev.end_date !== ev.start_date)
                ? `${ev.start_date} 〜 ${ev.end_date}`
                : ev.start_date;
            const statusText = ev.is_cancelled ? '🗑️ 取消済み' : (!ev.is_public ? '🔒 非公開' : '✅ 公開');

            html = `
                <div class="tooltip-title">👨‍⚕️ ${escapeHtml(ev.doctor_full || ev.doctor)}</div>
                <div class="tooltip-row"><span class="label">📝 用件:</span> <strong>${escapeHtml(ev.title)}</strong></div>
                <div class="tooltip-row"><span class="label">📅 日程:</span> ${dateRange}</div>
                <div class="tooltip-row"><span class="label">⏰ 時間:</span> ${ev.time}</div>
                ${ev.dept ? `<div class="tooltip-row"><span class="label">🏢 部門:</span> ${escapeHtml(ev.dept)}</div>` : ''}
                ${ev.note ? `<div class="tooltip-row"><span class="label">🗒️ 備考:</span> ${escapeHtml(ev.note)}</div>` : ''}
                <div class="tooltip-row"><span class="label">🔒 状態:</span> ${statusText}</div>
            `;
        }

        tooltipContent.innerHTML = html;
        tooltip.classList.add('visible');
        updateTooltipPosition(e);
    } catch(err) {
        console.error(err);
    }
}

function hideTooltip() {
    tooltip.classList.remove('visible');
}

function updateTooltipPosition(e) {
    const x = e.clientX + 14;
    const y = e.clientY + 14;
    const tooltipWidth = tooltip.offsetWidth || 280;
    const tooltipHeight = tooltip.offsetHeight || 120;
    const winWidth = window.innerWidth;
    const winHeight = window.innerHeight;

    let posX = x;
    let posY = y;

    if (x + tooltipWidth > winWidth - 10) {
        posX = e.clientX - tooltipWidth - 14;
    }
    if (y + tooltipHeight > winHeight - 10) {
        posY = e.clientY - tooltipHeight - 14;
    }

    tooltip.style.left = posX + 'px';
    tooltip.style.top = posY + 'px';
}

document.addEventListener('mousemove', function(e) {
    if (tooltip.classList.contains('visible')) {
        updateTooltipPosition(e);
    }
});

// ============================================================
// 2. 予定詳細モーダル
// ============================================================
function openEventDetail(eventId) {
    hideTooltip();
    const modal = document.getElementById('detailModal');
    const body = document.getElementById('detailBody');
    const title = document.getElementById('detailTitle');

    modal.classList.add('show');
    body.innerHTML = '<div style="text-align:center;padding:24px;color:#94a3b8;"><i class="fa-solid fa-spinner fa-spin"></i> 読み込み中...</div>';
    title.innerHTML = '<i class="fa-solid fa-clipboard-list"></i> 📋 予定詳細';

    fetch('api/get_event_detail.php?event_id=' + eventId)
        .then(res => res.json())
        .then(data => {
            if (data.error) {
                body.innerHTML = '<p style="color:var(--danger);padding:10px;">' + data.error + '</p>';
                return;
            }

            const statusBadge = data.is_cancelled ? '<span class="badge-status badge-cancelled">🗑️ 取消</span>' :
                                (!data.is_public ? '<span class="badge-status badge-draft">🔒 非公開</span>' :
                                '<span class="badge-status badge-public">✅ 公開</span>');

            const isMulti = data.end_date && data.end_date !== data.date;
            const dateStr = isMulti ? `${data.date} 〜 ${data.end_date}` : data.date;

            body.innerHTML = `
                <div class="detail-item">
                    <span class="label">👨‍⚕️ 医師</span>
                    <span class="value"><strong>${escapeHtml(data.doctor)}</strong>（${escapeHtml(data.doctor_full || '')}）</span>
                </div>
                <div class="detail-item">
                    <span class="label">📅 日程</span>
                    <span class="value">${dateStr}</span>
                </div>
                <div class="detail-item">
                    <span class="label">⏰ 時間</span>
                    <span class="value">${escapeHtml(data.time)}</span>
                </div>
                <div class="detail-item">
                    <span class="label">📌 種類</span>
                    <span class="value">${escapeHtml(data.event_label)}</span>
                </div>
                <div class="detail-item">
                    <span class="label">📝 タイトル</span>
                    <span class="value"><strong>${escapeHtml(data.title)}</strong></span>
                </div>
                ${data.note ? `
                <div class="detail-item">
                    <span class="label">🗒️ 備考</span>
                    <span class="value">${escapeHtml(data.note)}</span>
                </div>` : ''}
                <div class="detail-item">
                    <span class="label">🔒 状態</span>
                    <span class="value">${statusBadge}</span>
                </div>
                <div class="modal-actions">
                    <a href="<?= url('edit.php?event_id=') ?>${data.id}&return_to=calendar" class="btn btn-primary">
                        <i class="fa-solid fa-pen"></i> 予定を編集
                    </a>
                    <button class="btn" onclick="closeDetailModal()">閉じる</button>
                </div>
            `;
        })
        .catch(() => {
            body.innerHTML = '<p style="color:var(--danger);padding:10px;">詳細の取得に失敗しました</p>';
        });
}

function openMeetingDetail(dateStr) {
    hideTooltip();
    const modal = document.getElementById('detailModal');
    const body = document.getElementById('detailBody');
    const title = document.getElementById('detailTitle');

    modal.classList.add('show');
    body.innerHTML = '<div style="text-align:center;padding:24px;color:#94a3b8;"><i class="fa-solid fa-spinner fa-spin"></i> 読み込み中...</div>';
    title.innerHTML = '<i class="fa-solid fa-landmark"></i> 🏛️ 全体会詳細';

    fetch('api/get_meeting_detail.php?date=' + dateStr)
        .then(res => res.json())
        .then(data => {
            if (data.error) {
                body.innerHTML = '<p style="color:var(--danger);padding:10px;">' + data.error + '</p>';
                return;
            }

            body.innerHTML = `
                <div class="detail-item">
                    <span class="label">🏛️ タイトル</span>
                    <span class="value"><strong>${escapeHtml(data.title)}</strong></span>
                </div>
                <div class="detail-item">
                    <span class="label">📅 日付</span>
                    <span class="value">${escapeHtml(data.date)}</span>
                </div>
                <div class="detail-item">
                    <span class="label">⏰ 時間</span>
                    <span class="value">${escapeHtml(data.time)}〜</span>
                </div>
                <div class="detail-item">
                    <span class="label">📍 場所</span>
                    <span class="value">${escapeHtml(data.location)}</span>
                </div>
                ${data.note ? `
                <div class="detail-item">
                    <span class="label">🗒️ 備考</span>
                    <span class="value">${escapeHtml(data.note)}</span>
                </div>` : ''}
                <div class="modal-actions">
                    <a href="<?= url('master/all_meeting.php') ?>" class="btn btn-primary">
                        <i class="fa-solid fa-gear"></i> 全体会管理画面
                    </a>
                    <button class="btn" onclick="closeDetailModal()">閉じる</button>
                </div>
            `;
        })
        .catch(() => {
            body.innerHTML = '<p style="color:var(--danger);padding:10px;">詳細の取得に失敗しました</p>';
        });
}

function closeDetailModal() {
    document.getElementById('detailModal').classList.remove('show');
}

// ============================================================
// 3. 当日の全予定ポップオーバーモーダル（+他○件クリック）
// ============================================================
function openDayDetailModal(dateStr) {
    hideTooltip();
    const modal = document.getElementById('dayDetailModal');
    const title = document.getElementById('dayModalTitle');
    const body = document.getElementById('dayModalBody');
    const addBtn = document.getElementById('dayModalAddBtn');

    title.innerHTML = `<i class="fa-regular fa-calendar-check"></i> ${dateStr} の予定一覧`;
    addBtn.href = `<?= url('input.php?start_date=') ?>${dateStr}&return_to=calendar`;

    // 画面内に存在するその日のイベント要素を全収集
    const allBars = document.querySelectorAll('.event-bar[data-event-json]');
    const dayEvents = [];

    allBars.forEach(bar => {
        try {
            const ev = JSON.parse(bar.getAttribute('data-event-json'));
            const eStart = ev.start_date;
            const eEnd = ev.end_date || ev.start_date || ev.date;
            if (dateStr >= eStart && dateStr <= eEnd) {
                // 重複排除
                if (!dayEvents.some(x => x.id === ev.id && x.type === ev.type)) {
                    dayEvents.push(ev);
                }
            }
        } catch(e) {}
    });

    if (dayEvents.length === 0) {
        body.innerHTML = '<p style="color:#94a3b8;padding:16px;text-align:center;">この日の登録予定はありません</p>';
    } else {
        let html = '';
        dayEvents.forEach(ev => {
            if (ev.type === 'all_meeting') {
                html += `
                    <div class="day-event-card all-meeting">
                        <div>
                            <div style="font-weight:700;color:#6b21a8;">🏛️ 全体会: ${escapeHtml(ev.title)}</div>
                            <div style="font-size:0.8rem;color:#64748b;">${ev.time}〜 @ ${escapeHtml(ev.location || '')}</div>
                        </div>
                        <button class="btn" onclick="closeDayDetailModal(); openMeetingDetail('${dateStr}');" style="padding:4px 8px;font-size:0.75rem;">詳細</button>
                    </div>
                `;
            } else if (ev.type === 'kawara') {
                html += `
                    <div class="day-event-card kawara">
                        <div>
                            <div style="font-weight:700;color:#0369a1;">🔔 かわら版: ${escapeHtml(ev.title)}</div>
                            <div style="font-size:0.8rem;color:#64748b;">${escapeHtml(ev.category || '')}</div>
                        </div>
                        <a href="/kawara/view_post.php?id=${ev.id}" class="btn" style="padding:4px 8px;font-size:0.75rem;">記事を見る</a>
                    </div>
                `;
            } else {
                const cardClass = ev.event_type || 'other';
                const isMulti = ev.end_date && ev.end_date !== ev.start_date;
                const range = isMulti ? `期間: ${ev.start_date} 〜 ${ev.end_date}` : ev.time;

                html += `
                    <div class="day-event-card ${cardClass}">
                        <div>
                            <div style="font-weight:700;color:#0f172a;">
                                [${escapeHtml(ev.doctor)}] ${escapeHtml(ev.title)}
                            </div>
                            <div style="font-size:0.8rem;color:#64748b;">
                                ⏰ ${range} ${ev.dept ? '• ' + escapeHtml(ev.dept) : ''}
                            </div>
                        </div>
                        <button class="btn" onclick="closeDayDetailModal(); openEventDetail(${ev.id});" style="padding:4px 8px;font-size:0.75rem;">
                            <i class="fa-solid fa-pen"></i> 詳細・編集
                        </button>
                    </div>
                `;
            }
        });
        body.innerHTML = html;
    }

    modal.classList.add('show');
}

function closeDayDetailModal() {
    document.getElementById('dayDetailModal').classList.remove('show');
}

// モーダル外クリックで閉じる
window.addEventListener('click', function(e) {
    const detailModal = document.getElementById('detailModal');
    const dayModal = document.getElementById('dayDetailModal');
    if (e.target === detailModal) closeDetailModal();
    if (e.target === dayModal) closeDayDetailModal();
});

// ============================================================
// 4. メモ編集
// ============================================================
function toggleMemoEdit() {
    const display = document.getElementById('memoDisplay');
    const edit = document.getElementById('memoEdit');
    const btn = document.getElementById('memoEditBtn');

    if (edit.style.display === 'none') {
        edit.style.display = 'block';
        display.style.display = 'none';
        btn.innerHTML = '<i class="fa-solid fa-xmark"></i>';
        btn.title = '閉じる';
    } else {
        edit.style.display = 'none';
        display.style.display = 'block';
        btn.innerHTML = '<i class="fa-solid fa-pen"></i>';
        btn.title = 'メモを編集';
        document.getElementById('memoMessage').innerHTML = '';
    }
}

function submitMemo(event) {
    event.preventDefault();
    const form = event.target;
    const formData = new FormData(form);
    const msgEl = document.getElementById('memoMessage');

    msgEl.innerHTML = '<span style="color:#94a3b8;">保存中...</span>';

    fetch('<?= url('api/update_memo.php') ?>', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            msgEl.innerHTML = '<span style="color:var(--success);"><i class="fa-solid fa-check"></i> 保存しました</span>';
            setTimeout(() => location.reload(), 800);
        } else {
            msgEl.innerHTML = '<span style="color:var(--danger);"><i class="fa-solid fa-circle-exclamation"></i> ' + data.error + '</span>';
        }
    })
    .catch(() => {
        msgEl.innerHTML = '<span style="color:var(--danger);">通信エラーが発生しました</span>';
    });
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}
</script>

</body>
</html>