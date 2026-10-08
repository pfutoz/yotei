<?php
// ============================================================
// ファイル名: index.php
// システム名: 医師予定表管理システム（yotei）
// バージョン: v5.3
// 生成日時: 2026-08-24 22:45
// 最終更新: 2026-08-24 22:45
// ============================================================
//
// 【変更履歴】
// 2026-08-24 22:45 v5.3 フローティングユーザーを苗字のみ表示／クリックでログアウト／緑系デザインに変更（事務長）
// 2026-08-24 22:30 v5.2 右上に丸囲みフローティングユーザー表示を追加（事務長）
// 2026-08-24 21:30 v5.1 デバッグ出力を追加（認証チェック前後の状態確認）（事務長）
// 2026-08-24 20:45 v5.0 session_tab.php 方式に全面リプレイス（事務長）
// ============================================================

// ============================================================
// 1. 共通ライブラリ読み込み
// ============================================================
require_once __DIR__ . '/LIB/session_tab.php';
require_once __DIR__ . '/LIB/db.php';

// ============================================================
// 2. セッション開始（session_tab.php で既に開始済み）
// ============================================================

// ============================================================
// 3. 認証チェック（このタブのログイン状態を確認）
// ============================================================
if (!is_logged_in()) {
    header('Location: ' . redirect_url('login.php'));
    exit;
}

// ★ タイムアウトチェック（このタブだけ）
if (check_tab_timeout(1800)) {
    header('Location: ' . redirect_url('login.php?reason=timeout'));
    exit;
}

// ★ 現在のタブのユーザー情報を取得
$currentUser = current_tab_user();

/**
 * 表示名から苗字だけを抽出
 * 例: "山本 太" → "山本"
 */
function extractLastName($fullName) {
    if (empty($fullName)) return '不明';
    // 全角スペースまたは半角スペースで分割
    $parts = preg_split('/[\s　]+/', $fullName);
    return $parts[0] ?? $fullName;
}

// ★ 苗字を抽出
$lastName = extractLastName($currentUser['staff_name'] ?? '不明');

// ============================================================
// 4. 設定・デフォルト値
// ============================================================
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$deptFilter = isset($_SESSION['department_filter']) ? $_SESSION['department_filter'] : 'all';
$displayMode = isset($_SESSION['display_mode']) ? $_SESSION['display_mode'] : 'short';
$showCancelled = isset($_SESSION['show_cancelled']) ? $_SESSION['show_cancelled'] : true;
$showDraft = isset($_SESSION['show_draft']) ? $_SESSION['show_draft'] : false;
$showHistory = isset($_SESSION['show_history']) ? $_SESSION['show_history'] : false;
$showPast = isset($_SESSION['show_past']) ? $_SESSION['show_past'] : false;

if ($month < 1 || $month > 12) { $month = date('m'); }
if ($year < 2000 || $year > 2100) { $year = date('Y'); }

// ============================================================
// 5. データベース接続
// ============================================================
$pdo = getDbConnection();

// ============================================================
// 6. メモ取得（自動保存付き）
// ============================================================
$memoText = getMemoText($pdo);
$memoLines = explode("\n", $memoText);

// ============================================================
// 7. データ取得関数（変更なし）
// ============================================================

/**
 * 部門一覧を取得
 */
function getDepartments($pdo) {
    $stmt = $pdo->query("
        SELECT id, name, display_name, color_code
        FROM departments WHERE is_active = true ORDER BY sort_order
    ");
    return $stmt->fetchAll();
}

/**
 * 医師一覧を取得
 */
function getDoctors($pdo) {
    $stmt = $pdo->query("
        SELECT id, last_name, first_name, short_name, title, department_id
        FROM doctors WHERE is_active = true ORDER BY sort_order
    ");
    return $stmt->fetchAll();
}

/**
 * 指定期間（3ヶ月分）の予定を取得（期間予定は統合）
 */
function getEventsGrouped($pdo, $year, $month, $deptFilter = 'all', $showCancelled = true, $showDraft = false, $showPast = false) {
    $startDate = sprintf("%04d-%02d-01", $year, $month);
    $endYear = $year; $endMonth = $month + 2;
    if ($endMonth > 12) { $endMonth -= 12; $endYear++; }
    $endDate = date('Y-m-t', strtotime(sprintf("%04d-%02d-01", $endYear, $endMonth)));
    
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
    
    $params = ['start_date' => $startDate, 'end_date' => $endDate];
    
    if (!$showPast) {
        $today = date('Y-m-d');
        $sql .= " AND e.start_date >= :today";
        $params['today'] = $today;
    }
    
    if ($deptFilter !== 'all' && is_numeric($deptFilter)) {
        $sql .= " AND (COALESCE(e.department_id, d.department_id, s.department_id) = :dept_id OR dep.id = :dept_id)";
        $params['dept_id'] = (int)$deptFilter;
    }
    if (!$showCancelled) { $sql .= " AND e.is_cancelled = false"; }
    if (!$showDraft) { $sql .= " AND e.is_public = true"; }
    
    $sql .= " ORDER BY e.start_date ASC, e.start_time ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rawEvents = $stmt->fetchAll();
    
    $grouped = [];
    $skipIds = [];
    $weekDays = ['日', '月', '火', '水', '木', '金', '土'];
    
    foreach ($rawEvents as $event) {
        if (in_array($event['id'], $skipIds)) continue;
        
        if (!empty($event['end_date']) && $event['end_date'] !== $event['start_date']) {
            $start = new DateTime($event['start_date']);
            $end = new DateTime($event['end_date']);
            $days = $start->diff($end)->days + 1;
            
            $event['display_date'] = date('n/j', strtotime($event['start_date'])) . '〜' . date('n/j', strtotime($event['end_date']));
            $startWeek = (int)date('w', strtotime($event['start_date']));
            $endWeek = (int)date('w', strtotime($event['end_date']));
            $event['display_week'] = $weekDays[$startWeek] . '〜' . $weekDays[$endWeek];
            $event['display_title'] = $event['title'] . '（' . $days . '日間）';
            $event['is_period'] = true;
            $event['period_days'] = $days;
            $event['period_start_display'] = date('n/j', strtotime($event['start_date'])) . '（' . $weekDays[$startWeek] . '）';
            $event['period_end_display'] = date('n/j', strtotime($event['end_date'])) . '（' . $weekDays[$endWeek] . '）';
            $event['period_start_time'] = $event['start_time'] ? substr($event['start_time'], 0, 5) : null;
            
            $grouped[] = $event;
            $skipIds[] = $event['id'];
        } else {
            $event['display_date'] = date('n/j', strtotime($event['start_date']));
            $event['display_week'] = $weekDays[(int)date('w', strtotime($event['start_date']))];
            $event['display_title'] = $event['title'];
            $event['is_period'] = false;
            $event['period_days'] = 1;
            $grouped[] = $event;
        }
    }
    
    usort($grouped, function($a, $b) {
        return strtotime($a['start_date']) - strtotime($b['start_date']);
    });
    
    return $grouped;
}

/**
 * イベントの履歴を取得
 */
function getEventHistory($pdo, $eventId) {
    $stmt = $pdo->prepare("
        SELECT * FROM event_history
        WHERE event_id = :event_id ORDER BY changed_at ASC
    ");
    $stmt->execute(['event_id' => $eventId]);
    return $stmt->fetchAll();
}

/**
 * パターン検索（過去データから） - 凍結中
 */
function searchPatterns($pdo, $keyword = '', $doctorId = null, $yearFrom = null, $yearTo = null) {
    return [];
}

// ============================================================
// 8. 処理（POST）
// ============================================================

// フィルタ切替
if (isset($_POST['set_filter'])) {
    $_SESSION['department_filter'] = $_POST['filter_value'];
    header('Location: ' . redirect_url('index.php?year=' . $year . '&month=' . $month));
    exit;
}

// 表示モード切替
if (isset($_POST['toggle_mode'])) {
    $_SESSION['display_mode'] = ($_SESSION['display_mode'] ?? 'short') === 'short' ? 'full' : 'short';
    header('Location: ' . redirect_url('index.php?year=' . $year . '&month=' . $month));
    exit;
}

// 表示オプション切替
if (isset($_POST['toggle_cancelled'])) {
    $_SESSION['show_cancelled'] = !($_SESSION['show_cancelled'] ?? true);
    header('Location: ' . redirect_url('index.php?year=' . $year . '&month=' . $month));
    exit;
}
if (isset($_POST['toggle_draft'])) {
    $_SESSION['show_draft'] = !($_SESSION['show_draft'] ?? false);
    header('Location: ' . redirect_url('index.php?year=' . $year . '&month=' . $month));
    exit;
}
if (isset($_POST['toggle_history'])) {
    $_SESSION['show_history'] = !($_SESSION['show_history'] ?? false);
    header('Location: ' . redirect_url('index.php?year=' . $year . '&month=' . $month));
    exit;
}
if (isset($_POST['toggle_past'])) {
    $_SESSION['show_past'] = !($_SESSION['show_past'] ?? false);
    header('Location: ' . redirect_url('index.php?year=' . $year . '&month=' . $month));
    exit;
}

// パターンDo（凍結中）
if (isset($_GET['do_pattern'])) {
    header('Location: ' . redirect_url('index.php?pattern_frozen=1'));
    exit;
}

// ============================================================
// 取消処理（try-catch + error_log 対応）
// ============================================================
if (isset($_POST['cancel_event'])) {
    $eventId = (int)$_POST['event_id'];
    $reason = $_POST['cancel_reason'] ?? '';
    $userId = 'web_user';
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM events WHERE id = :id");
        $stmt->execute(['id' => $eventId]);
        $old = $stmt->fetch();
        
        if ($old) {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("
                UPDATE events SET 
                    is_cancelled = true,
                    cancelled_at = CURRENT_TIMESTAMP,
                    cancelled_by = :user,
                    cancel_reason = :reason,
                    status = 'cancelled',
                    version = version + 1,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ");
            $stmt->execute(['id' => $eventId, 'user' => $userId, 'reason' => $reason]);
            
            $stmt = $pdo->prepare("
                INSERT INTO event_history (
                    event_id,
                    old_start_date, old_end_date,
                    old_start_time, old_end_time,
                    old_title, old_note,
                    old_event_type,
                    old_is_public, old_is_cancelled,
                    change_summary, change_type,
                    changed_by
                ) VALUES (
                    :event_id,
                    :old_start_date, :old_end_date,
                    :old_start_time, :old_end_time,
                    :old_title, :old_note,
                    :old_event_type,
                    :old_is_public, :old_is_cancelled,
                    :summary, :type,
                    :user
                )
            ");
            $stmt->execute([
                'event_id' => $eventId,
                'old_start_date' => $old['start_date'],
                'old_end_date' => $old['end_date'],
                'old_start_time' => $old['start_time'],
                'old_end_time' => $old['end_time'],
                'old_title' => $old['title'],
                'old_note' => $old['note'],
                'old_event_type' => $old['event_type'],
                'old_is_public' => empty($old['is_public']) ? 0 : 1,
                'old_is_cancelled' => empty($old['is_cancelled']) ? 0 : 1,
                'summary' => '予定を取消しました' . ($reason ? '（理由: ' . $reason . '）' : ''),
                'type' => 'cancel',
                'user' => $userId
            ]);
            
            $pdo->commit();
            $_SESSION['message'] = '✅ 予定を取消しました';
        } else {
            $_SESSION['message'] = '❌ 指定された予定が見つかりません';
        }
    } catch (Exception $e) {
        if (isset($pdo)) $pdo->rollBack();
        error_log('【index.php:cancel_event】' . $e->getMessage() . ' (line ' . $e->getLine() . ')');
        $_SESSION['message'] = '❌ 取消処理に失敗しました。';
    }
    header('Location: ' . redirect_url('index.php?year=' . $year . '&month=' . $month));
    exit;
}

// ============================================================
// 復活処理（try-catch + error_log 対応）
// ============================================================
if (isset($_POST['restore_event'])) {
    $eventId = (int)$_POST['event_id'];
    $userId = 'web_user';
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM events WHERE id = :id");
        $stmt->execute(['id' => $eventId]);
        $old = $stmt->fetch();
        
        if ($old) {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("
                UPDATE events SET 
                    is_cancelled = false,
                    cancelled_at = NULL,
                    cancelled_by = NULL,
                    cancel_reason = NULL,
                    status = 'active',
                    version = version + 1,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ");
            $stmt->execute(['id' => $eventId]);
            
            $stmt = $pdo->prepare("
                INSERT INTO event_history (
                    event_id,
                    old_start_date, old_end_date,
                    old_start_time, old_end_time,
                    old_title, old_note,
                    old_event_type,
                    old_is_public, old_is_cancelled,
                    change_summary, change_type,
                    changed_by
                ) VALUES (
                    :event_id,
                    :old_start_date, :old_end_date,
                    :old_start_time, :old_end_time,
                    :old_title, :old_note,
                    :old_event_type,
                    :old_is_public, :old_is_cancelled,
                    :summary, :type,
                    :user
                )
            ");
            $stmt->execute([
                'event_id' => $eventId,
                'old_start_date' => $old['start_date'],
                'old_end_date' => $old['end_date'],
                'old_start_time' => $old['start_time'],
                'old_end_time' => $old['end_time'],
                'old_title' => $old['title'],
                'old_note' => $old['note'],
                'old_event_type' => $old['event_type'],
                'old_is_public' => empty($old['is_public']) ? 0 : 1,
                'old_is_cancelled' => empty($old['is_cancelled']) ? 0 : 1,
                'summary' => '予定を復活させました（取消解除）',
                'type' => 'restore',
                'user' => $userId
            ]);
            
            $pdo->commit();
            $_SESSION['message'] = '✅ 予定を復活させました';
        } else {
            $_SESSION['message'] = '❌ 指定された予定が見つかりません';
        }
    } catch (Exception $e) {
        if (isset($pdo)) $pdo->rollBack();
        error_log('【index.php:restore_event】' . $e->getMessage() . ' (line ' . $e->getLine() . ')');
        $_SESSION['message'] = '❌ 復活処理に失敗しました。';
    }
    header('Location: ' . redirect_url('index.php?year=' . $year . '&month=' . $month));
    exit;
}

// ============================================================
// 公開切替処理（try-catch + error_log 対応）
// ============================================================
if (isset($_POST['toggle_public'])) {
    $eventId = (int)$_POST['event_id'];
    $isPublic = (bool)$_POST['is_public'];
    $userId = 'web_user';
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM events WHERE id = :id");
        $stmt->execute(['id' => $eventId]);
        $old = $stmt->fetch();
        
        if (!$old) {
            $_SESSION['message'] = '❌ 指定された予定が見つかりません';
            header('Location: ' . redirect_url('index.php?year=' . $year . '&month=' . $month));
            exit;
        }
        
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("
            UPDATE events SET 
                is_public = :public,
                status = CASE WHEN :public THEN 'active' ELSE 'draft' END,
                version = version + 1,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->execute(['id' => $eventId, 'public' => $isPublic]);
        
        $stmt = $pdo->prepare("
            INSERT INTO event_history (
                event_id,
                old_start_date, old_end_date,
                old_start_time, old_end_time,
                old_title, old_note,
                old_event_type,
                old_is_public, old_is_cancelled,
                change_summary, change_type,
                changed_by
            ) VALUES (
                :event_id,
                :old_start_date, :old_end_date,
                :old_start_time, :old_end_time,
                :old_title, :old_note,
                :old_event_type,
                :old_is_public, :old_is_cancelled,
                :summary, :type,
                :user
            )
        ");
        $stmt->execute([
            'event_id' => $eventId,
            'old_start_date' => $old['start_date'],
            'old_end_date' => $old['end_date'],
            'old_start_time' => $old['start_time'],
            'old_end_time' => $old['end_time'],
            'old_title' => $old['title'],
            'old_note' => $old['note'],
            'old_event_type' => $old['event_type'],
            'old_is_public' => empty($old['is_public']) ? 0 : 1,
            'old_is_cancelled' => empty($old['is_cancelled']) ? 0 : 1,
            'summary' => $isPublic ? '予定を公開しました' : '予定を非公開にしました',
            'type' => 'toggle_public',
            'user' => $userId
        ]);
        
        $pdo->commit();
        $_SESSION['message'] = $isPublic ? '✅ 予定を公開しました' : '✅ 予定を非公開にしました';
        
    } catch (Exception $e) {
        if (isset($pdo)) $pdo->rollBack();
        error_log('【index.php:toggle_public】' . $e->getMessage() . ' (line ' . $e->getLine() . ')');
        $_SESSION['message'] = '❌ 公開切替処理に失敗しました。';
    }
    header('Location: ' . redirect_url('index.php?year=' . $year . '&month=' . $month));
    exit;
}

// ============================================================
// 9. メイン表示
// ============================================================

$departments = getDepartments($pdo);
$doctors = getDoctors($pdo);
$events = getEventsGrouped($pdo, $year, $month, $deptFilter, $showCancelled, $showDraft, $showPast);

// 表示期間
$startDisplay = sprintf("%04d-%02d-01", $year, $month);
$endYear = $year; $endMonth = $month + 2;
if ($endMonth > 12) { $endMonth -= 12; $endYear++; }
$endDisplay = date('Y-m-t', strtotime(sprintf("%04d-%02d-01", $endYear, $endMonth)));

// ナビゲーション
$prev1Month = $month - 1; $prev1Year = $year;
if ($prev1Month < 1) { $prev1Month = 12; $prev1Year--; }
$prev3Month = $month - 3; $prev3Year = $year;
if ($prev3Month < 1) { $prev3Month += 12; $prev3Year--; }
if ($prev3Month < 1) { $prev3Month += 12; $prev3Year--; }
$next1Month = $month + 1; $next1Year = $year;
if ($next1Month > 12) { $next1Month = 1; $next1Year++; }
$next3Month = $month + 3; $next3Year = $year;
if ($next3Month > 12) { $next3Month -= 12; $next3Year++; }
if ($next3Month > 12) { $next3Month -= 12; $next3Year++; }

$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);

// 全大会情報（当月のみ・サーバ日付基準）
$todayYear = date('Y');
$todayMonth = date('m');
$allMeeting = getAllMeetingInfo($pdo, $todayYear, $todayMonth);

// 期間予定の件数
$periodCount = 0;
$totalDays = 0;
foreach ($events as $e) {
    if ($e['is_period']) {
        $periodCount++;
        $totalDays += $e['period_days'];
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>医師予定表 - yotei</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Noto+Sans+JP:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ============================================================
           スタイル（モダンデザイン版）
           ============================================================ */
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
        .container { max-width: 1280px; margin: 0 auto; }

        /* ★★★ フローティングユーザー表示（苗字のみ・緑系） ★★★ */
        #floatingUser {
            position: fixed;
            top: 16px;
            right: 20px;
            z-index: 9999;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        #floatingUser:hover {
            transform: scale(1.08);
            box-shadow: 0 4px 16px rgba(45, 106, 79, 0.3);
        }
        #floatingUser .user-circle {
            display: inline-block;
            padding: 4px 18px;
            border: 2.5px solid #2d6a4f;      /* 濃い緑 */
            border-radius: 30px;
            background: #d8f3dc;              /* 薄い緑 */
            font-size: 14px;
            font-weight: 700;
            color: #e53e3e;                   /* 赤 */
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            backdrop-filter: blur(4px);
            letter-spacing: 0.5px;
            font-family: 'Noto Sans JP', sans-serif;
        }
        #floatingUser .user-circle .logout-hint {
            font-size: 9px;
            font-weight: 400;
            color: #94a3b8;
            margin-left: 2px;
            opacity: 0.6;
        }

        /* ヘッダー内のユーザー情報は非表示（フローティングに統合） */
        .header-actions .user-info {
            display: none !important;
        }

        /* 以下、既存スタイル（変更なし） */
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

        .btn-logout {
            background: #fef2f2;
            color: var(--danger);
            border-color: #fca5a5;
            padding: 6px 14px;
            border-radius: 8px;
            border: 1px solid #fca5a5;
            font-weight: 500;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.85rem;
            text-decoration: none;
            transition: all 0.2s;
        }
        .btn-logout:hover {
            background: #fee2e2;
            border-color: #f87171;
        }

        .btn {
            padding: 8px 16px;
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

        .btn-frozen {
            background: #e2e8f0;
            border-color: #cbd5e0;
            color: #a0aec0;
            cursor: not-allowed;
            opacity: 0.6;
        }
        .btn-frozen:hover {
            background: #e2e8f0;
            transform: none;
        }

        .mode-toggle {
            display: inline-flex;
            background: #f1f5f9;
            border-radius: 18px;
            padding: 2px;
        }
        .mode-toggle button {
            padding: 4px 12px;
            border: none;
            border-radius: 16px;
            background: transparent;
            cursor: pointer;
            font-size: 12px;
            transition: all 0.2s;
            font-weight: 500;
        }
        .mode-toggle button.active {
            background: var(--primary);
            color: white;
        }

        .control-bar {
            background: var(--bg-card);
            padding: 14px 20px;
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
            padding: 0 8px;
            font-size: 0.9rem;
        }

        .message {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            background: #dcfce7;
            color: #166534;
            border: 1px solid #86efac;
        }

        .table-card {
            background: var(--bg-card);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            overflow: hidden;
            margin-bottom: 20px;
        }
        .table-card table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }
        .table-card th {
            background: #f8fafc;
            padding: 12px 16px;
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text-muted);
            border-bottom: 1px solid var(--border);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .table-card td {
            padding: 14px 16px;
            border-bottom: 1px solid var(--border);
            font-size: 0.9rem;
            vertical-align: middle;
        }
        .table-card tr:last-child td { border-bottom: none; }
        .table-card tr:hover td { background: #f8fafc; }

        .table-card td:first-child {
            white-space: nowrap;
        }
        .table-card th:first-child {
            width: 250px;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            padding: 2px 10px;
            border-radius: 6px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        .badge-new { background: #dbeafe; color: #1e40af; }
        .badge-dept { background: #f1f5f9; color: #475569; }
        .badge-cancelled { background: #fef2f2; color: #991b1b; }
        .badge-draft { background: #f1f5f9; color: #64748b; }

        .event-new {
            font-size: 10px;
            background: #185fed;
            color: white;
            padding: 1px 8px;
            border-radius: 10px;
            margin-left: 6px;
            font-weight: 700;
        }

        .event-doctor { font-weight: 700; display: inline-block; min-width: 48px; }
        .event-title { margin-left: 4px; }
        .event-note { font-size: 12px; color: var(--text-muted); margin-left: 6px; }

        .color-red { color: var(--danger); font-weight: 600; }
        .color-green { color: var(--success); font-weight: 600; }
        .color-yellow { color: var(--warning); font-weight: 600; }
        .color-gray { color: var(--text-muted); font-weight: 600; }
        .color-default { color: var(--text-main); }

        .past-event { opacity: 0.4; background-color: #fafafa; }
        .past-event td { color: #a0aec0; }
        .today-event td { background: #eff6ff; border-left: 4px solid var(--primary); }
        .cancelled-row td { text-decoration: line-through; color: #a0aec0; }
        .cancelled-row .event-doctor { text-decoration: line-through; }
        .cancelled-row .event-title { text-decoration: line-through; }

        .action-icons {
            display: flex;
            gap: 4px;
        }
        .icon-btn {
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
            font-size: 13px;
            text-decoration: none;
        }
        .icon-btn:hover {
            background: var(--primary-light);
            color: var(--primary);
            border-color: #bfdbfe;
        }
        .icon-btn-danger:hover { background: #fef2f2; color: var(--danger); border-color: #fca5a5; }
        .icon-btn-warning:hover { background: #fffbeb; color: var(--warning); border-color: #fcd34d; }

        .event-history {
            display: none;
            margin-top: 6px;
            padding: 8px 12px;
            background: #f8fafc;
            border-radius: 6px;
            font-size: 12px;
            color: var(--text-muted);
        }
        .event-history.show { display: block; }
        .event-history .history-item { padding: 2px 0; border-bottom: 1px dashed var(--border); }
        .event-history .history-item:last-child { border-bottom: none; }
        .event-history .history-version { font-weight: 600; color: var(--primary); }

        .all-meeting-box {
            margin-top: 20px;
            padding: 16px 20px;
            background: #eff6ff;
            border-radius: var(--radius);
            border: 2px solid var(--border);
        }
        .all-meeting-box .title {
            font-size: 14px;
            font-weight: 700;
            color: var(--primary);
        }
        .all-meeting-box .date-display {
            font-size: 15px;
            font-weight: 600;
            color: var(--text-main);
        }
        .all-meeting-box .location {
            font-size: 14px;
            color: var(--text-muted);
        }
        .all-meeting-box .badge-future {
            font-size: 12px;
            background: var(--success);
            color: white;
            padding: 2px 12px;
            border-radius: 12px;
        }
        .all-meeting-box .badge-past {
            font-size: 12px;
            background: #94a3b8;
            color: white;
            padding: 2px 12px;
            border-radius: 12px;
        }
        .all-meeting-box .note {
            font-size: 13px;
            color: var(--text-muted);
            margin-top: 6px;
        }

        .memo-card {
            background: var(--bg-card);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            padding: 20px 24px;
            margin-top: 20px;
        }
        .memo-card .memo-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
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
        }
        .info-list {
            list-style: none;
            font-size: 0.9rem;
            color: var(--text-muted);
            display: flex;
            flex-direction: column;
            gap: 4px;
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
            min-height: 120px;
        }
        .memo-edit-form textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
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
        .memo-edit-form .form-actions input[type="password"]:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }
        .memo-edit-form .form-actions .hint {
            font-size: 0.8rem;
            color: var(--text-muted);
        }
        .memo-message { margin-top: 6px; font-size: 0.9rem; }
        .memo-message.success { color: var(--success); }
        .memo-message.error { color: var(--danger); }

        .footer {
            margin-top: 24px;
            text-align: center;
            font-size: 12px;
            color: #a0aec0;
            padding: 16px;
        }

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
            max-width: 600px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        .modal-content h2 { font-size: 18px; margin-bottom: 16px; color: var(--text-main); }
        .modal-content .close-btn {
            float: right;
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #a0aec0;
        }
        .modal-content .close-btn:hover { color: var(--danger); }
        .modal-content .cancel-modal textarea {
            width: 100%;
            padding: 8px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 14px;
            min-height: 60px;
            resize: vertical;
        }

        @media (max-width: 768px) {
            body { padding: 12px; }
            .app-header { flex-direction: column; align-items: stretch; gap: 8px; }
            .header-actions { justify-content: center; }
            .control-bar { flex-direction: column; align-items: stretch; }
            .filter-group { justify-content: center; }
            .date-nav { justify-content: center; }
            .table-card { font-size: 13px; overflow-x: auto; }
            .table-card th, .table-card td { padding: 8px 10px; }
            .table-card th:first-child { width: 180px; }
            .all-meeting-box .date-display { font-size: 13px; }
            #floatingUser {
                top: 10px;
                right: 10px;
            }
            #floatingUser .user-circle {
                font-size: 12px;
                padding: 3px 12px;
            }
        }
        @media print {
            .no-print { display: none !important; }
            body { background: white; padding: 10px; }
            .table-card { box-shadow: none; border: 1px solid #ddd; }
            .past-event { opacity: 0.3; }
            .all-meeting-box { border: 1px solid #ddd; background: #f9f9f9; }
            #floatingUser { display: none !important; }
        }
    </style>
</head>
<body>
<div class="container">

    <!-- ★★★ フローティングユーザー表示（苗字のみ・緑系・クリックでログアウト） ★★★ -->
    <div id="floatingUser" onclick="if(confirm('ログアウトしますか？')){ location.href='<?= url('logout.php') ?>'; }" title="クリックでログアウト">
        <span class="user-circle">
            <?= htmlspecialchars($lastName) ?>
            <span class="logout-hint">⏻</span>
        </span>
    </div>

    <!-- ============================================================
    ヘッダー
    ============================================================ -->
    <header class="app-header">
        <div class="app-title">
            <i class="fa-solid fa-hospital-user"></i>
            医師予定表
            <a href="<?= url('input.php') ?>" class="input-link" title="予定を追加">＋</a>
        </div>
        <div class="header-actions">
            <!-- ユーザー情報はフローティングに統合したため非表示 -->

            <!-- ★ パスワード変更 -->
            <a href="<?= url('change_password.php') ?>" class="nav-link" title="パスワード変更">
                <i class="fa-solid fa-key"></i>
            </a>

            <!-- ★ ログアウト（フローティングクリックでも可能だが、念のため残す） -->
            <a href="<?= url('logout.php') ?>" class="btn-logout" onclick="return confirm('ログアウトしますか？');">
                <i class="fa-solid fa-sign-out-alt"></i> ログアウト
            </a>

            <a href="<?= url('calendar.php?year=' . $year . '&month=' . $month) ?>" class="btn btn-primary">
                <i class="fa-solid fa-calendar-days"></i> カレンダー
            </a>
            <a href="<?= url('master/index.php') ?>" class="nav-link" title="マスターメンテナンス">
                <i class="fa-solid fa-gear"></i>
            </a>
            <a href="<?= url('../index.php') ?>" class="nav-link" title="小野会ポータルに戻る">
                <i class="fa-solid fa-house"></i>
            </a>
            <div class="mode-toggle no-print">
                <button class="<?= $displayMode === 'short' ? 'active' : '' ?>" onclick="document.getElementById('mode_form_short').submit();">略称</button>
                <button class="<?= $displayMode === 'full' ? 'active' : '' ?>" onclick="document.getElementById('mode_form_full').submit();">フルネーム</button>
                <form id="mode_form_short" method="POST" style="display:none;"><input type="hidden" name="toggle_mode" value="1"></form>
                <form id="mode_form_full" method="POST" style="display:none;"><input type="hidden" name="toggle_mode" value="1"><input type="hidden" name="mode" value="full"></form>
            </div>
            <!-- ★ パターンDoボタン凍結 -->
            <button class="btn btn-frozen" disabled title="パターンDo機能は一時凍結中です">
                <i class="fa-solid fa-magnifying-glass"></i> パターンDo（凍結中）
            </button>
        </div>
    </header>

    <!-- ナビゲーション＋コントロールバー（変更なし） -->
    <div class="control-bar no-print">
        <div class="date-nav">
            <a href="<?= url('index.php?year=' . $prev3Year . '&month=' . $prev3Month) ?>" class="btn"><i class="fa-solid fa-angles-left"></i></a>
            <a href="<?= url('index.php?year=' . $prev1Year . '&month=' . $prev1Month) ?>" class="btn"><i class="fa-solid fa-angle-left"></i></a>
            <a href="<?= url('index.php?year=' . date('Y') . '&month=' . date('m')) ?>" class="btn btn-primary">今日</a>
            <a href="<?= url('index.php?year=' . $next1Year . '&month=' . $next1Month) ?>" class="btn"><i class="fa-solid fa-angle-right"></i></a>
            <a href="<?= url('index.php?year=' . $next3Year . '&month=' . $next3Month) ?>" class="btn"><i class="fa-solid fa-angles-right"></i></a>
            <span class="date-range"><?= date('Y/m/d', strtotime($startDisplay)) ?> 〜 <?= date('Y/m/d', strtotime($endDisplay)) ?></span>
        </div>
    </div>

    <!-- フィルタバー（変更なし） -->
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
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <span style="font-size:0.8rem;color:var(--text-muted);">
                <?= count($events) ?>件
                <?php if ($periodCount > 0): ?>
                （期間予定: <?= $periodCount ?>件 / 総日数: <?= $totalDays ?>日）
                <?php endif; ?>
            </span>
            <div class="option-group" style="display:flex;gap:10px;font-size:0.8rem;align-items:center;">
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="toggle_cancelled" value="1">
                    <label><input type="checkbox" <?= $showCancelled ? 'checked' : '' ?> onchange="this.form.submit();"> 取消表示</label>
                </form>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="toggle_draft" value="1">
                    <label><input type="checkbox" <?= $showDraft ? 'checked' : '' ?> onchange="this.form.submit();"> 非公開含める</label>
                </form>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="toggle_past" value="1">
                    <label><input type="checkbox" <?= $showPast ? 'checked' : '' ?> onchange="this.form.submit();"> 過去の予定を表示</label>
                </form>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="toggle_history" value="1">
                    <label><input type="checkbox" <?= $showHistory ? 'checked' : '' ?> onchange="this.form.submit();"> 📜 修正歴</label>
                </form>
            </div>
        </div>
    </div>

    <?php if ($message): ?>
    <div class="message"><?= $message ?></div>
    <?php endif; ?>

    <!-- ============================================================
    予定一覧（変更なし）
    ============================================================ -->
    <div class="table-card">
        <table>
            <thead>
                <tr>
                    <th>日付</th>
                    <th style="width:80px;">所属</th>
                    <th>予定</th>
                    <th style="width:120px;" class="no-print">操作</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($events)): ?>
                <tr>
                    <td colspan="4" style="text-align:center;padding:40px;color:#a0aec0;">
                        <i class="fa-solid fa-inbox" style="font-size:24px;display:block;margin-bottom:8px;"></i>
                        この期間の予定はありません
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($events as $event): 
                    $dateClass = getDateClass($event['start_date']);
                    $colorClass = getEventColorClass($event['event_type']);
                    $displayName = getDisplayName($event, $displayMode);
                    $isCancelled = $event['is_cancelled'];
                    $isPublic = $event['is_public'];
                    $isNew = isNew($event);
                    $history = $showHistory ? getEventHistory($pdo, $event['id']) : [];
                ?>
                <tr class="<?= $dateClass ?> <?= $isCancelled ? 'cancelled-row' : '' ?>">
                    <td>
                        <?php if ($event['is_period']): ?>
                            <?php
                            $line1 = $event['period_start_display'];
                            if ($event['period_start_time']) {
                                $line1 .= ' ' . $event['period_start_time'];
                            }
                            $line2 = '　〜 ' . $event['period_end_display'];
                            ?>
                            <div><?= htmlspecialchars($line1) ?></div>
                            <div style="font-size:13px;color:#94a3b8;padding-left:4px;"><?= htmlspecialchars($line2) ?></div>
                            <span style="font-size:10px;color:#94a3b8;display:block;margin-top:1px;">（<?= $event['period_days'] ?>日間）</span>
                        <?php else: ?>
                            <?= formatScheduleDisplay($event) ?>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($event['department_display_name'] ?? '') ?></td>
                    <td>
                        <!-- 予定内容（変更なし） -->
                        <?php if ($event['is_cancelled']): ?>
                        <span class="badge badge-cancelled"><i class="fa-solid fa-ban"></i> 取消</span>
                        <?php endif; ?>
                        <?php if (!$event['is_public']): ?>
                        <span class="badge badge-draft"><i class="fa-solid fa-lock"></i> 非公開</span>
                        <?php endif; ?>
                        <?php if ($isNew && !$isCancelled): ?>
                        <span class="event-new">NEW</span>
                        <?php endif; ?>
                        <span class="event-doctor <?= $colorClass ?>">
                            <?php if ($isCancelled): ?>
                                <del><?= htmlspecialchars($displayName) ?></del>
                            <?php else: ?>
                                <?= htmlspecialchars($displayName) ?>
                            <?php endif; ?>
                        </span>
                        <span class="event-title">
                            <?php if ($isCancelled): ?>
                                <del><?= htmlspecialchars($event['display_title']) ?></del>
                            <?php else: ?>
                                <?= htmlspecialchars($event['display_title']) ?>
                            <?php endif; ?>
                        </span>
                        <?php if (!empty($event['note'])): ?>
                        <span class="event-note">（<?= htmlspecialchars($event['note']) ?>）</span>
                        <?php endif; ?>
                        <?php if ($event['is_pattern'] && !$isCancelled): ?>
                        <span style="font-size:10px;background:#f1f5f9;padding:1px 8px;border-radius:10px;margin-left:6px;color:#64748b;">📌 パターン</span>
                        <?php endif; ?>
                        
                        <?php if ($showHistory && !empty($history)): ?>
                        <div class="event-history show">
                            <?php foreach ($history as $h): ?>
                            <div class="history-item">
                                <span class="history-version">v<?= $h['id'] ?></span>
                                <?= htmlspecialchars($h['change_summary']) ?>
                                <span style="color:#94a3b8;font-size:11px;">
                                    （<?= htmlspecialchars($h['changed_by']) ?> @ <?= date('Y/m/d H:i', strtotime($h['changed_at'])) ?>）
                                </span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td class="no-print">
                        <?php $isPast = $event['start_date'] < date('Y-m-d'); ?>
                        <?php if (!$isPast): ?>
                            <?php if (!$isCancelled): ?>
                            <div class="action-icons">
                                <button class="icon-btn icon-btn-danger" onclick="openCancelModal(<?= $event['id'] ?>, '<?= htmlspecialchars($displayName . ' ' . $event['display_title'], ENT_QUOTES) ?>')" title="取消"><i class="fa-solid fa-trash-can"></i></button>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="toggle_public" value="1">
                                    <input type="hidden" name="event_id" value="<?= $event['id'] ?>">
                                    <input type="hidden" name="is_public" value="<?= $isPublic ? '0' : '1' ?>">
                                    <button type="submit" class="icon-btn <?= $isPublic ? '' : 'icon-btn-warning' ?>" title="<?= $isPublic ? '非公開にする' : '公開する' ?>">
                                        <?= $isPublic ? '<i class="fa-solid fa-lock"></i>' : '<i class="fa-solid fa-lock-open"></i>' ?>
                                    </button>
                                </form>
                                <a href="<?= url('edit.php?event_id=' . $event['id']) ?>" class="icon-btn" title="編集"><i class="fa-solid fa-pen"></i></a>
                            </div>
                            <?php else: ?>
                            <div class="action-icons">
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="restore_event" value="1">
                                    <input type="hidden" name="event_id" value="<?= $event['id'] ?>">
                                    <button type="submit" class="icon-btn" onclick="return confirm('この予定を復活させますか？')" title="復活"><i class="fa-solid fa-rotate-left"></i></button>
                                </form>
                            </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="font-size:11px;color:#94a3b8;">（過去）</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- 当月の全体会（変更なし） -->
    <?php if ($allMeeting): ?>
    <div class="all-meeting-box">
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
            <span class="title"><i class="fa-solid fa-calendar-check"></i> 当月の全体会</span>
            <span class="date-display">
                <?= date('Y年n月j日', strtotime($allMeeting['date'])) ?>
                （<?= $allMeeting['weekday'] ?>）
                <?= $allMeeting['time'] ?>〜
            </span>
            <span class="location"><?= htmlspecialchars($allMeeting['location']) ?></span>
            <?php if ($allMeeting['is_future']): ?>
            <span class="badge-future">🟢 開催予定</span>
            <?php else: ?>
            <span class="badge-past">⚪ 開催済み</span>
            <?php endif; ?>
        </div>
        <div class="note">
            ※ 小野会全体会（毎月最終水曜日 / 12月25日以降は繰り上げ）
        </div>
    </div>
    <?php endif; ?>

    <!-- メモカード（変更なし） -->
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
                <textarea name="memo_text" rows="5"><?= htmlspecialchars($memoText) ?></textarea>
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

    <div class="footer no-print">
        yotei v5.3 &bull; PostgreSQL &bull; <?= date('Y年m月d日 H:i') ?>
    </div>

</div>

<!-- ============================================================
取消モーダル
============================================================ -->
<div id="cancelModal" class="modal-overlay">
    <div class="modal-content cancel-modal">
        <button class="close-btn" onclick="closeCancelModal()"><i class="fa-solid fa-xmark"></i></button>
        <h2><i class="fa-solid fa-trash-can" style="color:var(--danger);"></i> 予定の取消</h2>
        <p style="margin-bottom:12px;color:var(--text-muted);">以下の予定を取消します。よろしいですか？</p>
        <div style="padding:12px;background:#f8fafc;border-radius:6px;margin-bottom:16px;">
            <strong id="cancelEventTitle"></strong>
        </div>
        <form method="POST">
            <input type="hidden" name="cancel_event" value="1">
            <input type="hidden" name="event_id" id="cancelEventId" value="">
            <div style="margin-bottom:12px;">
                <label style="font-weight:600;font-size:14px;display:block;margin-bottom:4px;">取消理由（任意）</label>
                <textarea name="cancel_reason" placeholder="例: 学会出席のため、患者都合により..." id="cancelReason"></textarea>
            </div>
            <div style="display:flex;gap:8px;justify-content:flex-end;">
                <button type="button" class="btn" onclick="closeCancelModal()">キャンセル</button>
                <button type="submit" class="btn" style="background:var(--danger);color:white;border-color:var(--danger);">
                    <i class="fa-solid fa-check"></i> 取消する
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================
JavaScript
============================================================ -->
<script>
// 取消モーダル
function openCancelModal(eventId, title) {
    document.getElementById('cancelEventId').value = eventId;
    document.getElementById('cancelEventTitle').textContent = title;
    document.getElementById('cancelModal').classList.add('show');
}
function closeCancelModal() {
    document.getElementById('cancelModal').classList.remove('show');
    document.getElementById('cancelReason').value = '';
}

document.getElementById('cancelModal').addEventListener('click', function(e) {
    if (e.target === this) closeCancelModal();
});

// メモ編集トグル
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

// メモ保存（Ajax）
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
</script>

</body>
</html>