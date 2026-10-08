<?php
// ============================================================
// ファイル名: db.php
// システム名: 医師予定表管理システム（yotei）
// バージョン: v2.0
// 生成日時: 2026-08-07
// ============================================================
//
// 【概要】
// データベース接続と共通ヘルパー関数を提供するライブラリ
// 全画面から呼び出されて使用される
//
// 【機能一覧】
// - PostgreSQL接続（PDO）
// - 曜日・日付関連ヘルパー
// - 日付範囲生成
// - イベント表示用ヘルパー
//
// 【変更履歴】
// 2026-08-07 v2.0 再生成（全大会・NEWマーク対応のヘルパーを追加）
// 2026-08-05 v1.0 初版作成
//
// 【既知の課題】
// - なし
// ============================================================

// ============================================================
// 1. 設定・定数
// ============================================================

/** データベース接続情報 */
define('DB_HOST', 'localhost');
define('DB_PORT', '5432');
define('DB_NAME', 'yotei');
define('DB_USER', 'postgres');
define('DB_PASS', 'postgres');

/** タイムゾーン */
date_default_timezone_set('Asia/Tokyo');

/** 曜日配列（日本語） */
define('WEEKDAYS', ['日', '月', '火', '水', '木', '金', '土']);

/** イベント種別のカラー定義 */
define('EVENT_COLORS', [
    'absence' => 'color-red',
    'clinic'  => 'color-green',
    'meeting' => 'color-yellow',
    'holiday' => 'color-gray',
]);

/** イベント種別のラベル */
define('EVENT_LABELS', [
    'absence' => '🔴 休診',
    'clinic'  => '🟢 診察',
    'meeting' => '🟡 会議',
    'holiday' => '⚪ 休暇',
    'other'   => '🟣 その他',
]);

// ============================================================
// 2. データベース接続
// ============================================================

/**
 * PostgreSQLにPDO接続する
 * 
 * @return PDO
 * @throws PDOException
 */
function getDbConnection() {
    try {
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s;',
            DB_HOST,
            DB_PORT,
            DB_NAME
        );
        $pdo = new PDO($dsn, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    } catch (PDOException $e) {
        error_log('DB接続エラー: ' . $e->getMessage());
        die('データベース接続エラーが発生しました。管理者に連絡してください。');
    }
}

// ============================================================
// 3. 日付・曜日ヘルパー
// ============================================================

/**
 * 日付から曜日を日本語で取得
 * 
 * @param string $date Y-m-d形式
 * @return string 曜日（例: 「月」）
 */
function getDayOfWeek($date) {
    return WEEKDAYS[(int)date('w', strtotime($date))];
}

/**
 * 日付が「今日」か判定
 * 
 * @param string $date Y-m-d形式
 * @return bool
 */
function isToday($date) {
    return $date === date('Y-m-d');
}

/**
 * 日付が「過去」か判定
 * 
 * @param string $date Y-m-d形式
 * @return bool
 */
function isPast($date) {
    return $date < date('Y-m-d');
}

/**
 * 日付が「未来」か判定
 * 
 * @param string $date Y-m-d形式
 * @return bool
 */
function isFuture($date) {
    return $date > date('Y-m-d');
}

/**
 * 日付に対応するCSSクラスを取得
 * 
 * @param string $date Y-m-d形式
 * @return string 'past-event' | 'today-event' | 'future-event'
 */
function getDateClass($date) {
    if ($date < date('Y-m-d')) {
        return 'past-event';
    } elseif ($date === date('Y-m-d')) {
        return 'today-event';
    } else {
        return 'future-event';
    }
}

/**
 * 日付範囲を配列で取得
 * 
 * @param string $startDate Y-m-d形式
 * @param string $endDate Y-m-d形式
 * @return string[] Y-m-d形式の日付配列
 */
function getDateRange($startDate, $endDate) {
    $dates = [];
    $start = new DateTime($startDate);
    $end = new DateTime($endDate);
    $end->modify('+1 day');
    $interval = new DateInterval('P1D');
    $period = new DatePeriod($start, $interval, $end);
    foreach ($period as $date) {
        $dates[] = $date->format('Y-m-d');
    }
    return $dates;
}

/**
 * 2つの日付の差（日数）を計算
 * 
 * @param string $startDate Y-m-d形式
 * @param string $endDate Y-m-d形式
 * @return int 日数
 */
function getDateDiff($startDate, $endDate) {
    $start = new DateTime($startDate);
    $end = new DateTime($endDate);
    return $start->diff($end)->days + 1;
}

// ============================================================
// 4. イベント表示ヘルパー
// ============================================================

/**
 * イベント種別に対応するCSSクラスを取得
 * 
 * @param string $eventType
 * @return string
 */
function getEventColorClass($eventType) {
    return EVENT_COLORS[$eventType] ?? 'color-default';
}

/**
 * イベント種別に対応するラベルを取得
 * 
 * @param string $eventType
 * @return string
 */
function getEventLabel($eventType) {
    return EVENT_LABELS[$eventType] ?? $eventType;
}

/**
 * イベントの表示名を生成（医師・スタッフ）
 * 
 * @param array $event イベントデータ
 * @param string $mode 'short' | 'full'
 * @return string
 */
function getDisplayName($event, $mode = 'short') {
    // スタッフ（事務長など）
    if (!empty($event['staff_name'])) {
        return $event['staff_name'];
    }
    
    // 医師
    if (!empty($event['doctor_short_name'])) {
        if ($mode === 'short') {
            return $event['doctor_short_name'];
        } else {
            $name = $event['doctor_full_name'] ?? ($event['doctor_short_name']);
            if (!empty($event['doctor_title'])) {
                $name .= $event['doctor_title'];
            }
            return $name;
        }
    }
    
    return '不明';
}

/**
 * 時間情報をフォーマット
 * 
 * @param array $event イベントデータ
 * @return string '終日' | '09:00〜12:00' | '13:00〜'
 */
function formatEventTime($event) {
    $startTime = $event['start_time'] ? substr($event['start_time'], 0, 5) : null;
    $endTime = $event['end_time'] ? substr($event['end_time'], 0, 5) : null;
    
    if (!$startTime && !$endTime) {
        return '終日';
    }
    if ($startTime && $endTime) {
        return $startTime . '〜' . $endTime;
    }
    if ($startTime) {
        return $startTime . '〜';
    }
    return '';
}

/**
 * 日付＋曜日＋時刻の表示を生成（一覧表示用）
 * 
 * @param array $event イベントデータ
 * @return string 表示文字列
 */
function formatScheduleDisplay($event) {
    $startDate = $event['start_date'];
    $endDate = $event['end_date'] ?? null;
    $startTime = $event['start_time'] ? substr($event['start_time'], 0, 5) : null;
    $endTime = $event['end_time'] ? substr($event['end_time'], 0, 5) : null;
    
    $startDisplay = date('n/j', strtotime($startDate)) . '（' . getDayOfWeek($startDate) . '）';
    $isPeriod = ($endDate && $endDate !== $startDate);
    
    if ($isPeriod) {
        $endDisplay = date('n/j', strtotime($endDate)) . '（' . getDayOfWeek($endDate) . '）';
        if ($startTime) {
            return $startDisplay . ' ' . $startTime . ' 〜 ' . $endDisplay;
        } else {
            return $startDisplay . ' 〜 ' . $endDisplay;
        }
    } else {
        if ($startTime && $endTime) {
            return $startDisplay . ' ' . $startTime . '〜' . $endTime;
        } elseif ($startTime) {
            return $startDisplay . ' ' . $startTime . '〜';
        } else {
            return $startDisplay . ' 終日';
        }
    }
}

/**
 * イベントがNEW（3日以内作成）か判定
 * 
 * @param array $event イベントデータ
 * @return bool
 */
function isNew($event) {
    $createdAt = strtotime($event['created_at']);
    $now = time();
    return ($now - $createdAt) < (3 * 24 * 60 * 60);
}

/**
 * イベントのステータスバッジ（HTML）を生成
 * 
 * @param array $event イベントデータ
 * @return string HTML
 */
function getStatusBadge($event) {
    $badges = [];
    if ($event['is_cancelled']) {
        $badges[] = '<span class="badge-cancelled">🗑️ 取消</span>';
    }
    if (!$event['is_public']) {
        $badges[] = '<span class="badge-draft">🔒 非公開</span>';
    }
    return implode(' ', $badges);
}

// ============================================================
// 5. 全大会（settings）関連ヘルパー
// ============================================================

/**
 * 指定月の全大会情報を取得
 * 
 * @param PDO $pdo
 * @param int $year
 * @param int $month
 * @return array|null ['date', 'time', 'location', 'title', 'weekday', 'is_future']
 */
// ============================================================
// 5. 全大会（all_meetings テーブル）関連ヘルパー
// ============================================================

/**
 * 指定月の全大会情報を取得（all_meetings テーブルから）
 * 
 * @param PDO $pdo
 * @param int $year
 * @param int $month
 * @return array|null ['date', 'time', 'location', 'title', 'weekday', 'is_future']
 */
function getAllMeetingInfo($pdo, $year, $month) {
    $startDate = sprintf("%04d-%02d-01", $year, $month);
    $endDate = date('Y-m-t', strtotime($startDate));
    
    $stmt = $pdo->prepare("
        SELECT 
            meeting_date,
            title,
            start_time,
            location,
            note,
            is_cancelled
        FROM all_meetings
        WHERE meeting_date >= :start_date
          AND meeting_date <= :end_date
          AND is_cancelled = false
        ORDER BY meeting_date
        LIMIT 1
    ");
    $stmt->execute(['start_date' => $startDate, 'end_date' => $endDate]);
    $meeting = $stmt->fetch();
    
    if (!$meeting) {
        return null;
    }
    
    return [
        'date' => $meeting['meeting_date'],
        'time' => $meeting['start_time'],
        'location' => $meeting['location'],
        'title' => $meeting['title'],
        'note' => $meeting['note'],
        'weekday' => getDayOfWeek($meeting['meeting_date']),
        'is_future' => ($meeting['meeting_date'] >= date('Y-m-d')),
    ];
}
// db.php の末尾に追加
/**
 * メモを取得（なければデフォルトを自動保存）
 */
function getMemoText($pdo) {
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'memo_text'");
    $stmt->execute();
    $row = $stmt->fetch();
    
    if ($row && !empty(trim($row['setting_value']))) {
        return $row['setting_value'];
    }
    
    // デフォルトを自動保存
    $defaultMemo = "矢野哲也医師：第2土曜診察あり（その週の水曜は公休）\n小野会全体会：毎月最終水曜日 13:00〜（胃腸科1F食堂）";
    
    $stmt = $pdo->prepare("
        INSERT INTO settings (setting_key, setting_value, setting_type, description)
        VALUES ('memo_text', :value, 'text', 'メモ（自由記述）')
        ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value
    ");
    $stmt->execute(['value' => $defaultMemo]);
    
    return $defaultMemo;
}



// ============================================================
// 6. デバッグ用（本番ではコメントアウト）
// ============================================================
// if (php_sapi_name() === 'cli' && basename($_SERVER['PHP_SELF']) === 'db.php') {
//     $pdo = getDbConnection();
//     echo "✅ DB接続成功！\n";
// }