<?php
// ============================================================
// ファイル名: edit.php
// システム名: 医師予定表管理システム（yotei）
// バージョン: v1.1（return_to 対応版）
// 生成日時: 2026-08-10
// ============================================================
//
// 【概要】
// 既存の予定を編集する画面
// return_to パラメータで戻り先を切り替える
// ============================================================

require_once __DIR__ . '/LIB/db.php';

session_start();

// ============================================================
// 0. return_to パラメータ
// ============================================================
$returnTo = isset($_GET['return_to']) ? $_GET['return_to'] : 'index';
$eventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;

require_once __DIR__ . '/LIB/master_auth.php';

// ============================================================
// 1. パスワード認証（管理者パスワード・暗証番号で照合）
// ============================================================
$authError = '';

if (isset($_POST['auth_password'])) {
    if (verifyAdminPassword($_POST['auth_password'])) {
        $_SESSION['edit_authorized'] = true;
        $_SESSION['edit_event_id'] = $eventId;
        $_SESSION['edit_return_to'] = $returnTo;  // ★ 戻り先を保存
        header('Location: edit.php?event_id=' . $eventId . '&return_to=' . $returnTo);
        exit;
    } else {
        $authError = '⚠️ 管理者パスワード（暗証番号）が間違っています';
    }
}

// 認証チェック
$isAuthorized = isset($_SESSION['edit_authorized']) && $_SESSION['edit_authorized'] === true 
                && isset($_SESSION['edit_event_id']) && $_SESSION['edit_event_id'] === $eventId;

if (!$isAuthorized || $eventId <= 0) {
    ?>
    <!DOCTYPE html>
    <html lang="ja">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>編集認証 - yotei</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body {
                font-family: 'Helvetica Neue', Arial, 'Hiragino Sans', 'Noto Sans JP', sans-serif;
                background: #f0f2f5;
                color: #2d3748;
                display: flex;
                align-items: center;
                justify-content: center;
                min-height: 100vh;
            }
            .auth-box {
                background: white;
                padding: 40px;
                border-radius: 12px;
                box-shadow: 0 4px 20px rgba(0,0,0,0.1);
                max-width: 400px;
                width: 90%;
            }
            .auth-box h1 { font-size: 20px; font-weight: 700; color: #2C6E9C; margin-bottom: 8px; }
            .auth-box p { font-size: 14px; color: #718096; margin-bottom: 20px; }
            .auth-box input[type="password"] {
                width: 100%;
                padding: 10px 14px;
                border: 1px solid #e2e8f0;
                border-radius: 6px;
                font-size: 16px;
                margin-bottom: 12px;
            }
            .auth-box input[type="password"]:focus {
                outline: none;
                border-color: #2C6E9C;
                box-shadow: 0 0 0 3px rgba(44,110,156,0.15);
            }
            .auth-box .error { color: #e53e3e; font-size: 14px; margin-bottom: 12px; }
            .auth-box .btn {
                width: 100%;
                padding: 10px;
                background: #2C6E9C;
                color: white;
                border: none;
                border-radius: 6px;
                font-size: 16px;
                font-weight: 600;
                cursor: pointer;
                transition: background 0.2s;
            }
            .auth-box .btn:hover { background: #1a4a6e; }
            .auth-box .back-link {
                display: block;
                text-align: center;
                margin-top: 12px;
                color: #a0aec0;
                text-decoration: none;
                font-size: 13px;
            }
            .auth-box .back-link:hover { color: #2C6E9C; }
        </style>
    </head>
    <body>
        <div class="auth-box">
            <h1>🔒 編集認証</h1>
            <p>予定を編集するにはパスワードを入力してください</p>
            <?php if ($authError): ?>
            <div class="error"><?= htmlspecialchars($authError) ?></div>
            <?php endif; ?>
            <form method="POST">
                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                <input type="password" name="auth_password" placeholder="パスワードを入力" autofocus>
                <button type="submit" class="btn">🔓 認証する</button>
            </form>
            <a href="<?= $returnTo === 'calendar' ? 'calendar.php' : 'index.php' ?>" class="back-link">
                ← <?= $returnTo === 'calendar' ? 'カレンダーに戻る' : '予定表に戻る' ?>
            </a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ============================================================
// 2. 編集データ取得
// ============================================================
$pdo = getDbConnection();

$stmt = $pdo->prepare("
    SELECT 
        e.*,
        d.short_name AS doctor_short_name,
        d.last_name || d.first_name AS doctor_full_name,
        d.title AS doctor_title,
        dep.name AS department_name,
        dep.display_name AS department_display_name,
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
    $_SESSION['message'] = '❌ 指定された予定が見つかりません';
    header('Location: ' . ($returnTo === 'calendar' ? 'calendar.php' : 'index.php'));
    exit;
}

// 医師一覧（編集用）
$stmt = $pdo->query("
    SELECT id, last_name, first_name, short_name, title
    FROM doctors WHERE is_active = true ORDER BY sort_order
");
$doctors = $stmt->fetchAll();

// 備考定型文
$stmt = $pdo->prepare("
    SELECT id, note_text
    FROM note_templates
    WHERE note_category = 'note' AND is_active = true
    ORDER BY sort_order
");
$stmt->execute();
$noteTemplates = $stmt->fetchAll();

// 現在の値をフォーム用にセット
$formData = [
    'doctor_id' => $event['doctor_id'] ?: ($event['staff_id'] ? 'staff' : ''),
    'start_date' => $event['start_date'],
    'end_date' => $event['end_date'],
    'start_time' => $event['start_time'],
    'end_time' => $event['end_time'],
    'date_type' => ($event['end_date'] && $event['end_date'] !== $event['start_date']) ? 'period' : 'single',
    'time_type' => ($event['start_time'] || $event['end_time']) ? 'time' : 'allday',
    'event_type' => $event['event_type'],
    'title' => $event['title'],
    'note' => $event['note'],
    'is_public' => $event['is_public'] ? '1' : '0',
];

// 曜日表示用
$weekDays = ['日', '月', '火', '水', '木', '金', '土'];
$startWeekday = !empty($formData['start_date']) ? $weekDays[(int)date('w', strtotime($formData['start_date']))] : '';
$endWeekday = !empty($formData['end_date']) ? $weekDays[(int)date('w', strtotime($formData['end_date']))] : '';

$defaultDate = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>予定を編集 - yotei</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Helvetica Neue', Arial, 'Hiragino Sans', 'Noto Sans JP', sans-serif;
            background: #f0f2f5;
            color: #2d3748;
            padding: 20px;
        }
        .container { max-width: 720px; margin: 0 auto; }

        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: white;
            padding: 16px 24px;
            border-radius: 10px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.06);
            margin-bottom: 20px;
        }
        .header h1 { font-size: 20px; font-weight: 700; color: #2C6E9C; }
        .header h1 span { color: #E8833A; }
        .header .back-link {
            color: #718096;
            text-decoration: none;
            font-size: 14px;
        }
        .header .back-link:hover { color: #2C6E9C; }

        .card {
            background: white;
            border-radius: 10px;
            padding: 24px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.06);
            margin-bottom: 16px;
        }
        .card-title {
            font-size: 14px;
            font-weight: 700;
            color: #4a5568;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .card-title .badge {
            font-size: 11px;
            background: #e2e8f0;
            color: #718096;
            padding: 1px 10px;
            border-radius: 10px;
            font-weight: 400;
        }

        .doctor-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .doctor-btn {
            padding: 8px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            background: white;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .doctor-btn:hover { background: #f7fafc; border-color: #cbd5e0; }
        .doctor-btn.active {
            border-color: #2C6E9C;
            background: #ebf8ff;
            box-shadow: 0 0 0 3px rgba(44, 110, 156, 0.2);
        }
        .doctor-btn .icon { font-size: 18px; }

        .radio-group {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        .radio-group label {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 14px;
            cursor: pointer;
        }
        .radio-group input[type="radio"] {
            width: 16px;
            height: 16px;
            accent-color: #2C6E9C;
            cursor: pointer;
        }

        .date-input-row {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 8px;
        }
        .date-input-row label { font-size: 14px; font-weight: 600; color: #4a5568; min-width: 56px; }
        .date-input-row input[type="date"] {
            padding: 6px 10px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 14px;
            background: white;
        }
        .weekday-badge {
            font-size: 14px;
            font-weight: 600;
            color: #4a5568;
            background: #edf2f7;
            padding: 4px 12px;
            border-radius: 6px;
            min-width: 48px;
            display: inline-block;
            text-align: center;
        }
        #endDateRow input:disabled {
            background: #f7fafc;
            opacity: 0.6;
            cursor: not-allowed;
        }
        #endTimeWrapper.hidden { display: none; }

        .time-input-row {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 8px;
        }
        .time-input-row input[type="time"] {
            padding: 6px 10px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 14px;
            background: white;
            width: 100px;
        }
        .time-shortcut {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }
        .time-shortcut button {
            padding: 4px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            background: white;
            cursor: pointer;
            font-size: 12px;
            transition: all 0.2s;
        }
        .time-shortcut button:hover { background: #edf2f7; }
        .time-hint { font-size: 11px; color: #a0aec0; margin-left: 4px; }

        .type-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .type-btn {
            padding: 8px 18px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            background: white;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.2s;
            font-weight: 600;
        }
        .type-btn:hover { transform: translateY(-1px); }
        .type-btn.active {
            border-color: #2C6E9C;
            box-shadow: 0 0 0 3px rgba(44, 110, 156, 0.15);
        }
        .type-btn.type-absence { color: #e53e3e; border-color: #fed7d7; }
        .type-btn.type-absence.active { border-color: #e53e3e; background: #fff5f5; }
        .type-btn.type-clinic { color: #38a169; border-color: #c6f6d5; }
        .type-btn.type-clinic.active { border-color: #38a169; background: #f0fff4; }
        .type-btn.type-meeting { color: #d69e2e; border-color: #fefcbf; }
        .type-btn.type-meeting.active { border-color: #d69e2e; background: #fffff0; }
        .type-btn.type-holiday { color: #718096; border-color: #e2e8f0; }
        .type-btn.type-holiday.active { border-color: #718096; background: #f7fafc; }
        .type-btn.type-other { color: #6b46c1; border-color: #e9d8fd; }
        .type-btn.type-other.active { border-color: #6b46c1; background: #faf5ff; }

        .title-row {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
        }
        .title-row .text-input { flex: 1; min-width: 200px; }
        .title-row .auto-btn {
            padding: 8px 16px;
            background: #edf2f7;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            color: #4a5568;
            transition: all 0.2s;
            white-space: nowrap;
        }
        .title-row .auto-btn:hover {
            background: #e2e8f0;
            border-color: #cbd5e0;
            transform: translateY(-1px);
        }

        .text-input {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 14px;
            transition: all 0.2s;
        }
        .text-input:focus {
            outline: none;
            border-color: #2C6E9C;
            box-shadow: 0 0 0 3px rgba(44, 110, 156, 0.15);
        }

        .note-template-row {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
            margin-bottom: 8px;
        }
        .note-template-row select {
            flex: 1;
            min-width: 150px;
            padding: 6px 10px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 14px;
            background: white;
        }
        .quick-note-btn {
            padding: 6px 16px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            background: #f7fafc;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            transition: all 0.2s;
            white-space: nowrap;
        }
        .quick-note-btn:hover {
            background: #edf2f7;
            border-color: #cbd5e0;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
        }
        .checkbox-group input[type="checkbox"] {
            width: 16px;
            height: 16px;
            accent-color: #2C6E9C;
            cursor: pointer;
        }

        .help-text { font-size: 12px; color: #a0aec0; margin-top: 4px; }

        .form-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 8px;
        }
        .btn {
            padding: 10px 28px;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn:hover { transform: translateY(-1px); }
        .btn-primary { background: #2C6E9C; color: white; }
        .btn-primary:hover { background: #1a4a6e; }
        .btn-secondary { background: #e2e8f0; color: #4a5568; }
        .btn-secondary:hover { background: #cbd5e0; }
        .btn-danger { background: #fc8181; color: white; }
        .btn-danger:hover { background: #f56565; }

        .toast {
            position: fixed;
            bottom: 30px;
            left: 50%;
            transform: translateX(-50%);
            background: #2d3748;
            color: white;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 14px;
            opacity: 0;
            transition: opacity 0.3s ease;
            z-index: 9999;
            pointer-events: none;
        }
        .toast.show { opacity: 1; }

        @media (max-width: 600px) {
            .header { flex-direction: column; align-items: stretch; gap: 8px; }
            .date-input-row { flex-direction: column; align-items: stretch; }
            .time-input-row { flex-direction: column; align-items: stretch; }
            .doctor-buttons { justify-content: center; }
            .type-buttons { justify-content: center; }
            .form-actions { justify-content: center; }
            .title-row { flex-direction: column; align-items: stretch; }
            .title-row .auto-btn { text-align: center; }
            .note-template-row { flex-direction: column; align-items: stretch; }
        }
    </style>
</head>
<body>
<div class="container">

    <div id="toast" class="toast"></div>

    <div class="header">
        <h1>✏️ <span>予定</span>を編集</h1>
        <a href="<?= $returnTo === 'calendar' ? 'calendar.php' : 'index.php' ?>" class="back-link">
            ← <?= $returnTo === 'calendar' ? 'カレンダーに戻る' : '予定表に戻る' ?>
        </a>
    </div>

    <div class="card" style="background:#fffff0;border:1px solid #fefcbf;">
        <span style="font-size:13px;color:#975A16;">🔒 編集モード（ID: <?= $eventId ?>）</span>
        <span style="font-size:12px;color:#a0aec0;margin-left:12px;">バージョン: <?= $event['version'] ?></span>
    </div>

    <form method="POST" action="update.php" id="editForm">
        <!-- ★ 戻り先を保持 -->
        <input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTo) ?>">

        <!-- 医師選択 -->
        <div class="card">
            <div class="card-title">👨‍⚕️ 医師を選ぶ</div>
            <div class="doctor-buttons" id="doctorButtons">
                <?php foreach ($doctors as $doc): ?>
                <button type="button"
                        class="doctor-btn <?= ($formData['doctor_id'] == $doc['id']) ? 'active' : '' ?>"
                        data-doctor-id="<?= $doc['id'] ?>"
                        data-doctor-short="<?= htmlspecialchars($doc['short_name']) ?>"
                        data-doctor-full="<?= htmlspecialchars($doc['last_name'] . $doc['first_name']) ?>"
                        data-doctor-title="<?= htmlspecialchars($doc['title'] ?? '') ?>"
                        onclick="selectDoctor(<?= $doc['id'] ?>)">
                    <span class="icon">👤</span>
                    <?= htmlspecialchars($doc['short_name']) ?>
                </button>
                <?php endforeach; ?>
                <button type="button"
                        class="doctor-btn <?= ($formData['doctor_id'] === 'staff') ? 'active' : '' ?>"
                        data-doctor-id="staff"
                        data-doctor-short="事務長"
                        data-doctor-full="事務長"
                        data-doctor-title=""
                        onclick="selectDoctor('staff')">
                    <span class="icon">🏢</span>
                    事務長
                </button>
            </div>
            <input type="hidden" name="doctor_id" id="doctorId" value="<?= htmlspecialchars($formData['doctor_id']) ?>">
            <input type="hidden" name="event_id" value="<?= $eventId ?>">
        </div>

        <!-- 日付パターン -->
        <div class="card">
            <div class="card-title">📅 日付パターン</div>
            <div class="radio-group">
                <label>
                    <input type="radio" name="date_type" value="single"
                           <?= ($formData['date_type'] ?? 'single') === 'single' ? 'checked' : '' ?>
                           onchange="toggleDateType(); updateWeekday();">
                    単日
                </label>
                <label>
                    <input type="radio" name="date_type" value="period"
                           <?= ($formData['date_type'] ?? 'single') === 'period' ? 'checked' : '' ?>
                           onchange="toggleDateType(); updateWeekday();">
                    期間
                </label>
            </div>

            <div class="date-input-row" style="margin-top:12px;">
                <label>開始日:</label>
                <input type="date" name="start_date" id="startDate"
                       value="<?= htmlspecialchars($formData['start_date'] ?? $defaultDate) ?>"
                       onchange="updateWeekday(); syncEndDate();">
                <span id="startWeekday" class="weekday-badge">
                    <?= $startWeekday ? '(' . $startWeekday . ')' : '' ?>
                </span>
            </div>

            <div class="date-input-row" id="endDateRow" style="margin-top:8px;">
                <label>終了日:</label>
                <input type="date" name="period_end" id="periodEnd"
                       value="<?= htmlspecialchars($formData['end_date'] ?? '') ?>"
                       onchange="updateWeekday(); endDateManuallyChanged = true;"
                       <?= ($formData['date_type'] ?? 'single') === 'single' ? 'disabled' : '' ?>>
                <span id="endWeekday" class="weekday-badge">
                    <?= $endWeekday ? '(' . $endWeekday . ')' : '' ?>
                </span>
            </div>

            <input type="hidden" name="end_date" id="endDateHidden" value="<?= htmlspecialchars($formData['end_date'] ?? '') ?>">
        </div>

        <!-- 時間パターン -->
        <div class="card">
            <div class="card-title">⏰ 時間パターン</div>
            <div class="radio-group">
                <label>
                    <input type="radio" name="time_type" value="allday"
                           <?= ($formData['time_type'] ?? 'allday') === 'allday' ? 'checked' : '' ?>
                           onchange="toggleTimeType(); updateDateTimeFields();">
                    終日
                </label>
                <label>
                    <input type="radio" name="time_type" value="time"
                           <?= ($formData['time_type'] ?? 'allday') === 'time' ? 'checked' : '' ?>
                           onchange="toggleTimeType(); updateDateTimeFields();">
                    時間指定
                </label>
            </div>

            <div id="timeInputRow" class="time-input-row <?= ($formData['time_type'] ?? 'allday') === 'allday' ? 'hidden' : '' ?>">
                <label>開始:</label>
                <input type="time" name="start_time" id="startTime"
                       value="<?= htmlspecialchars($formData['start_time'] ?? '') ?>"
                       step="600">

                <span id="endTimeWrapper">
                    <label>〜 終了:</label>
                    <input type="time" name="end_time" id="endTime"
                           value="<?= htmlspecialchars($formData['end_time'] ?? '') ?>"
                           step="600">
                </span>

                <span class="time-shortcut">
                    <button type="button" onclick="setTime('am');">🌅 午前</button>
                    <button type="button" onclick="setTime('pm');">🌇 午後</button>
                    <button type="button" onclick="setTime('evening');">🌆 夕方</button>
                    <button type="button" onclick="setTime('clear');">✕ クリア</button>
                </span>
                <span class="time-hint">（午前=9:00-12:00 / 午後=13:00-17:00 / 夕方=16:00〜）</span>
            </div>

            <div class="help-text" id="timeHelpText">
                💡 期間＋時間指定の場合は開始時刻のみ設定されます（終了時刻は不要）
            </div>
        </div>

        <!-- 予定の種類 -->
        <div class="card">
            <div class="card-title">📌 予定の種類</div>
            <div class="type-buttons" id="typeButtons">
                <button type="button"
                        class="type-btn type-absence <?= ($formData['event_type'] ?? 'absence') === 'absence' ? 'active' : '' ?>"
                        data-type="absence"
                        onclick="selectType('absence')">
                    🔴 休診
                </button>
                <button type="button"
                        class="type-btn type-clinic <?= ($formData['event_type'] ?? 'absence') === 'clinic' ? 'active' : '' ?>"
                        data-type="clinic"
                        onclick="selectType('clinic')">
                    🟢 診察
                </button>
                <button type="button"
                        class="type-btn type-meeting <?= ($formData['event_type'] ?? 'absence') === 'meeting' ? 'active' : '' ?>"
                        data-type="meeting"
                        onclick="selectType('meeting')">
                    🟡 会議
                </button>
                <button type="button"
                        class="type-btn type-holiday <?= ($formData['event_type'] ?? 'absence') === 'holiday' ? 'active' : '' ?>"
                        data-type="holiday"
                        onclick="selectType('holiday')">
                    ⚪ 休暇
                </button>
                <button type="button"
                        class="type-btn type-other <?= ($formData['event_type'] ?? 'absence') === 'other' ? 'active' : '' ?>"
                        data-type="other"
                        onclick="selectType('other')">
                    🟣 その他
                </button>
            </div>
            <input type="hidden" name="event_type" id="eventType" value="<?= htmlspecialchars($formData['event_type'] ?? 'absence') ?>">
        </div>

        <!-- タイトル -->
        <div class="card">
            <div class="card-title">📝 タイトル <span class="badge">必須</span></div>
            <div class="title-row">
                <input type="text" name="title" id="titleInput"
                       class="text-input"
                       placeholder="例: 矢野哲也医師 休診（公休）"
                       value="<?= htmlspecialchars($formData['title'] ?? '') ?>">
                <button type="button" class="auto-btn" onclick="autoGenerateTitle(); showToast('✨ タイトルを自動生成しました');">
                    ✨ 自動作成
                </button>
            </div>
            <div class="help-text">💡 「自動作成」ボタンで、選択中の医師・日付・種別からタイトルを生成します</div>
        </div>

        <!-- 備考 -->
        <div class="card">
            <div class="card-title">📝 備考 <span class="badge">任意</span></div>

            <div class="note-template-row">
                <label style="font-size:13px;font-weight:600;color:#4a5568;">定型文:</label>
                <select id="noteTemplateSelect">
                    <option value="">-- 選択してください --</option>
                    <?php foreach ($noteTemplates as $template): ?>
                    <option value="<?= htmlspecialchars($template['note_text']) ?>">
                        <?= htmlspecialchars($template['note_text']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="quick-note-btn" onclick="addNoteFromSelect();">
                    ➕ 追加
                </button>
            </div>

            <input type="text" name="note" id="noteInput" class="text-input"
                   placeholder="例: 第2土曜診察 / 公休 / 学会出席"
                   value="<?= htmlspecialchars($formData['note'] ?? '') ?>">

            <div class="help-text">
                💡 プルダウンで定型文を選んで「追加」→ 備考欄に追記されます（「代診：○○Dr」は選択中の医師名に置換）
            </div>
        </div>

        <!-- 公開設定 -->
        <div class="card">
            <div class="card-title">🔒 公開設定</div>
            <div class="checkbox-group">
                <input type="checkbox" name="is_public" value="1"
                       <?= ($formData['is_public'] ?? '1') == '1' ? 'checked' : '' ?>>
                <label>この予定を公開する（チェックを外すと非公開・下書き状態）</label>
            </div>
        </div>

        <!-- アクションボタン -->
        <div class="card">
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">💾 更新する</button>
                <a href="<?= $returnTo === 'calendar' ? 'calendar.php' : 'index.php' ?>" class="btn btn-secondary">✕ キャンセル</a>
            </div>
        </div>

    </form>

</div>

<!-- ============================================================
JavaScript
============================================================ -->
<script>
let endDateManuallyChanged = <?= ($formData['date_type'] ?? 'single') === 'period' && !empty($formData['end_date']) ? 'true' : 'false' ?>;

function selectDoctor(id) {
    document.querySelectorAll('.doctor-btn').forEach(btn => btn.classList.remove('active'));
    const target = document.querySelector(`.doctor-btn[data-doctor-id="${id}"]`);
    if (target) target.classList.add('active');
    document.getElementById('doctorId').value = id;
}

function selectType(type) {
    document.querySelectorAll('.type-btn').forEach(btn => btn.classList.remove('active'));
    const target = document.querySelector(`.type-btn[data-type="${type}"]`);
    if (target) target.classList.add('active');
    document.getElementById('eventType').value = type;
}

function toggleDateType() {
    const isPeriod = document.querySelector('input[name="date_type"]:checked').value === 'period';
    const endDateInput = document.getElementById('periodEnd');
    const endDateHidden = document.getElementById('endDateHidden');
    const endWeekdayEl = document.getElementById('endWeekday');

    endDateInput.disabled = !isPeriod;

    if (!isPeriod) {
        endDateInput.value = '';
        endDateHidden.value = '';
        endWeekdayEl.textContent = '';
        endDateManuallyChanged = false;
    } else {
        if (!endDateInput.value) {
            const startDate = document.getElementById('startDate').value;
            endDateInput.value = startDate;
            endDateHidden.value = startDate;
            endDateManuallyChanged = false;
            updateWeekday();
        }
    }
    updateDateTimeFields();
}

function toggleTimeType() {
    const isTime = document.querySelector('input[name="time_type"]:checked').value === 'time';
    const timeRow = document.getElementById('timeInputRow');
    timeRow.classList.toggle('hidden', !isTime);
    updateDateTimeFields();
}

function updateDateTimeFields() {
    const isPeriod = document.querySelector('input[name="date_type"]:checked').value === 'period';
    const isTime = document.querySelector('input[name="time_type"]:checked').value === 'time';

    const endTimeWrapper = document.getElementById('endTimeWrapper');
    const endTimeInput = document.getElementById('endTime');
    const helpText = document.getElementById('timeHelpText');

    if (isPeriod && isTime) {
        endTimeWrapper.classList.add('hidden');
        endTimeInput.value = '';
        helpText.style.display = 'block';
    } else if (!isPeriod && isTime) {
        endTimeWrapper.classList.remove('hidden');
        helpText.style.display = 'none';
    } else {
        endTimeWrapper.classList.add('hidden');
        endTimeInput.value = '';
        helpText.style.display = 'none';
    }
}

function updateWeekday() {
    const weekDays = ['日', '月', '火', '水', '木', '金', '土'];
    const startDate = document.getElementById('startDate').value;
    if (startDate) {
        const d = new Date(startDate + 'T00:00:00');
        document.getElementById('startWeekday').textContent = '(' + weekDays[d.getDay()] + ')';
    }
    const endDate = document.getElementById('periodEnd').value;
    const endWeekdayEl = document.getElementById('endWeekday');
    if (endDate) {
        const d = new Date(endDate + 'T00:00:00');
        endWeekdayEl.textContent = '(' + weekDays[d.getDay()] + ')';
    } else {
        endWeekdayEl.textContent = '';
    }
}

function syncEndDate() {
    const isPeriod = document.querySelector('input[name="date_type"]:checked').value === 'period';
    if (!isPeriod) return;
    if (endDateManuallyChanged) return;
    const startDate = document.getElementById('startDate').value;
    document.getElementById('periodEnd').value = startDate;
    document.getElementById('endDateHidden').value = startDate;
    updateWeekday();
}

function setTime(mode) {
    const startInput = document.getElementById('startTime');
    const endInput = document.getElementById('endTime');
    const timeRadio = document.querySelector('input[name="time_type"][value="time"]');
    if (!timeRadio.checked) {
        timeRadio.checked = true;
        toggleTimeType();
        updateDateTimeFields();
    }
    const isPeriod = document.querySelector('input[name="date_type"]:checked').value === 'period';
    switch(mode) {
        case 'am':
            startInput.value = '09:00';
            endInput.value = isPeriod ? '' : '12:00';
            showToast('🌅 午前（9:00-12:00）をセットしました');
            break;
        case 'pm':
            startInput.value = '13:00';
            endInput.value = isPeriod ? '' : '17:00';
            showToast('🌇 午後（13:00-17:00）をセットしました');
            break;
        case 'evening':
            startInput.value = '16:00';
            endInput.value = '';
            showToast('🌆 夕方（16:00〜）をセットしました');
            break;
        case 'clear':
            startInput.value = '';
            endInput.value = '';
            showToast('✕ 時間をクリアしました');
            break;
    }
}

function autoGenerateTitle() {
    const doctorId = document.getElementById('doctorId').value;
    const eventType = document.getElementById('eventType').value;
    const startDate = document.getElementById('startDate').value;
    const endDate = document.getElementById('periodEnd').value;
    const dateType = document.querySelector('input[name="date_type"]:checked').value;

    const btn = document.querySelector(`.doctor-btn[data-doctor-id="${doctorId}"]`);
    if (!btn || !startDate) return;

    const shortName = btn.dataset.doctorShort || '';
    const fullName = btn.dataset.doctorFull || '';
    const titleSuffix = btn.dataset.doctorTitle || '';

    const dateObj = new Date(startDate + 'T00:00:00');
    const dayOfWeek = dateObj.getDay();
    const day = dateObj.getDate();
    const isSecondSaturday = (dayOfWeek === 6 && day >= 8 && day <= 14);
    const isLastWednesday = (dayOfWeek === 3 && day > 21);

    const typeLabels = {
        'absence': '休診',
        'clinic': '診察あり',
        'meeting': '会議',
        'holiday': '休暇',
        'other': 'その他'
    };
    const typeLabel = typeLabels[eventType] || '';

    let generatedTitle = '';

    if (shortName === '哲也' && eventType === 'clinic' && isSecondSaturday) {
        generatedTitle = '矢野哲也医師 診察あり（第2土曜）';
    } else if (shortName === '哲也' && eventType === 'absence' && dayOfWeek === 3 && isSecondSaturday) {
        generatedTitle = '矢野哲也医師 休診（公休）';
    } else if (eventType === 'meeting' && isLastWednesday) {
        generatedTitle = '小野会全体会 13:00〜';
    } else if (dateType === 'period' && endDate && endDate !== startDate) {
        const periodDays = Math.ceil((new Date(endDate) - new Date(startDate)) / (1000 * 60 * 60 * 24)) + 1;
        const displayName = shortName || fullName;
        generatedTitle = displayName + (titleSuffix ? titleSuffix : '') + ' ' + typeLabel + '（' + periodDays + '日間）';
    } else {
        const displayName = shortName || fullName;
        generatedTitle = displayName + (titleSuffix ? titleSuffix : '') + ' ' + typeLabel;
    }

    document.getElementById('titleInput').value = generatedTitle;
}

function addNoteFromSelect() {
    const select = document.getElementById('noteTemplateSelect');
    let text = select.value;
    if (!text) {
        showToast('⚠️ 定型文を選択してください');
        return;
    }

    const noteInput = document.getElementById('noteInput');
    const current = noteInput.value;

    if (text.includes('○○Dr')) {
        const doctorId = document.getElementById('doctorId').value;
        const btn = document.querySelector(`.doctor-btn[data-doctor-id="${doctorId}"]`);
        let doctorName = '○○';
        if (btn) {
            doctorName = btn.dataset.doctorShort || '○○';
        }
        if (doctorId === 'staff') {
            doctorName = '事務長';
        }
        text = text.replace('○○Dr', doctorName + 'Dr');
    }

    if (current.includes(text)) {
        showToast('⚠️ 同じ内容が既に入力されています');
        select.value = '';
        return;
    }

    if (current && !current.endsWith(' ') && !current.endsWith('、')) {
        noteInput.value = current + '、' + text;
    } else {
        noteInput.value = current + text;
    }
    select.value = '';
    showToast('✅ 備考に追加しました: ' + text);
}

function showToast(message) {
    const toast = document.getElementById('toast');
    toast.textContent = message;
    toast.classList.add('show');
    clearTimeout(toast._timer);
    toast._timer = setTimeout(() => {
        toast.classList.remove('show');
    }, 2000);
}

document.getElementById('editForm').addEventListener('submit', function(e) {
    const isPeriod = document.querySelector('input[name="date_type"]:checked').value === 'period';
    const isTime = document.querySelector('input[name="time_type"]:checked').value === 'time';

    if (isPeriod) {
        document.getElementById('endDateHidden').value = document.getElementById('periodEnd').value;
    } else {
        document.getElementById('endDateHidden').value = '';
    }

    if (isPeriod && isTime) {
        document.getElementById('endTime').value = '';
    }
});

document.addEventListener('DOMContentLoaded', function() {
    toggleDateType();
    toggleTimeType();
    updateDateTimeFields();
    updateWeekday();

    const endVal = document.getElementById('periodEnd').value;
    if (endVal) {
        document.getElementById('endDateHidden').value = endVal;
        endDateManuallyChanged = true;
    }
});
</script>

</body>
</html>