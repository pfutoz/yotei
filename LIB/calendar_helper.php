<?php
// ============================================================
// ファイル名: calendar_helper.php
// システム名: 医師予定表管理システム（yotei）
// バージョン: v1.0
// 生成日時: 2026-08-10
// ============================================================
//
// 【概要】
// 医師予定表（yotei）専用カレンダー表示ヘルパー
// 小野会カレンダーロジック（calendar_helper_onokai.php）を利用
// ============================================================

require_once __DIR__ . '/calendar_helper_onokai.php';

// ============================================================
// カレンダー表示関数
// ============================================================

/**
 * カレンダーセルを描画（yotei 用）
 * 
 * @param string $dateStr Y-m-d形式の日付
 * @param array $eventMap 日付をキーにしたイベント連想配列
 * @param array $meetingMap 日付をキーにした全体会連想配列
 * @param string $displayMode 'short' | 'full'
 * @return string HTML
 */
function renderYoteiDateCell($dateStr, $eventMap = [], $meetingMap = [], $displayMode = 'short') {
    $ts = strtotime($dateStr);
    $day = date('j', $ts);
    $w = (int)date('w', $ts);
    $weekDays = ['日', '月', '火', '水', '木', '金', '土'];
    $isToday = ($dateStr === date('Y-m-d'));
    $isPast = ($dateStr < date('Y-m-d'));
    
    // 小野会カレンダースタイルを取得
    $dayCheck = check_onokai_calendar($dateStr, null);
    $isClosed = $dayCheck['is_closed'];
    $style = get_onokai_cell_style($dateStr, null);
    $reason = $dayCheck['reason'] ?? '';
    
    $hasEvents = isset($eventMap[$dateStr]) && !empty($eventMap[$dateStr]);
    $hasMeeting = isset($meetingMap[$dateStr]);
    $isClickable = !$isPast && !$isClosed;
    
    // クリックURL
    if ($isClickable && !$hasEvents && !$hasMeeting) {
        // 空きセル → 入力画面へ
        $clickUrl = "input.php?start_date=" . $dateStr . "&return_to=calendar";
        $cellClass = 'clickable-empty';
    } elseif ($isClickable) {
        // 予定あり → index.php へ
        $clickUrl = "index.php?year=" . date('Y', $ts) . "&month=" . date('m', $ts) . "#day-" . $day;
        $cellClass = 'clickable-event';
    } else {
        $clickUrl = '#';
        $cellClass = 'no-click';
    }
    
    // クラス名
    $cellClass .= ' calendar-cell';
    if ($isToday) $cellClass .= ' today';
    if ($isPast) $cellClass .= ' past';
    if ($isClosed) $cellClass .= ' closed';
    if ($w == 0) $cellClass .= ' sun';
    if ($w == 6) $cellClass .= ' sat';
    
    $html = '<td class="' . $cellClass . '" style="' . $style['css'] . '" data-date="' . $dateStr . '">';
    
    // 日付
    if ($isClickable && !$hasEvents && !$hasMeeting) {
        $html .= '<a href="' . $clickUrl . '" class="day-link" title="予定を追加">';
    }
    $html .= '<div class="day-number">' . $day . '</div>';
    if ($isClickable && !$hasEvents && !$hasMeeting) {
        $html .= '<span class="add-hint">＋</span>';
        $html .= '</a>';
    }
    
    // 休診ラベル
    if ($isClosed && $reason) {
        $html .= '<div class="closed-label">' . htmlspecialchars($reason) . '</div>';
    }
    
    // 予定表示（最大2件）
    if ($hasEvents) {
        $events = $eventMap[$dateStr];
        $displayLimit = 2;
        $count = 0;
        foreach ($events as $e) {
            if ($count >= $displayLimit) break;
            $name = getDisplayName($e, $displayMode);
            $icon = ($e['event_type'] === 'clinic') ? '🟢' :
                    (($e['event_type'] === 'absence') ? '🔴' :
                    (($e['event_type'] === 'meeting') ? '🟡' : '⚪'));
            $html .= '<div class="event-chip">' . $icon . ' ' . htmlspecialchars($name) . '</div>';
            $count++;
        }
        if (count($events) > $displayLimit) {
            $html .= '<div class="event-chip more">+ ' . (count($events) - $displayLimit) . '件</div>';
        }
    }
    
    // 全体会表示（予定の下に表示）
    if ($hasMeeting) {
        $m = $meetingMap[$dateStr];
        $time = substr($m['start_time'], 0, 5);
        $html .= '<div class="event-chip meeting">🏛️ ' . htmlspecialchars($m['title']) . ' ' . $time . '〜</div>';
    }
    
    $html .= '</td>';
    return $html;
}

/**
 * 3ヶ月分のカレンダーを生成
 * 
 * @param PDO $pdo
 * @param int $year
 * @param int $month
 * @param array $eventMap 日付キーのイベント連想配列
 * @param array $meetingMap 日付キーの全体会連想配列
 * @param string $displayMode 'short' | 'full'
 * @return string HTML
 */
function renderCalendarMonthYotei($pdo, $year, $month, $eventMap, $meetingMap, $displayMode = 'short') {
    $weekDays = ['日', '月', '火', '水', '木', '金', '土'];
    $firstDayOfMonth = (int)date('w', strtotime("$year-$month-01"));
    $daysInMonth = (int)date('t', strtotime("$year-$month-01"));
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
    
    // 空白セル
    for ($i = 0; $i < $firstDayOfMonth; $i++) {
        $html .= '<td class="calendar-empty"></td>';
    }
    
    // 日付セル
    for ($d = 1; $d <= $daysInMonth; $d++) {
        $dateStr = sprintf("%04d-%02d-%02d", $year, $month, $d);
        $html .= renderYoteiDateCell($dateStr, $eventMap, $meetingMap, $displayMode);
        
        // 改行
        if (($d + $firstDayOfMonth) % 7 == 0 && $d < $daysInMonth) {
            $html .= '</tr><tr>';
        }
    }
    
    // 最終行の空白埋め
    $remaining = (7 - (($daysInMonth + $firstDayOfMonth) % 7)) % 7;
    for ($i = 0; $i < $remaining; $i++) {
        $html .= '<td class="calendar-empty"></td>';
    }
    
    $html .= '</tr></tbody></table></div>';
    return $html;
}