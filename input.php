<?php
/**
 * ============================================================
 * ファイル名: input.php
 * システム名: 医師予定表管理システム（yotei）
 * バージョン: v5.0（縦圧縮レイアウト ＆ 期間時終日固定対応）
 * 生成日時: 2026-10-08
 * ============================================================
 *
 * 【概要】
 * 新規予定入力画面（タブセッション対応版）
 * - 縦方向の表示を大幅圧縮（スリム化・省スペース化）
 * - 日付パターン「期間」選択時は時間パターンを「終日」に自動固定（時間指定をdisabled化）
 * - 時間プリセット（ドロップダウン方式）
 *
 * 【変更履歴】
 * 2026-10-08 v5.0 縦方向表示圧縮／期間選択時の終日固定（disabled制御）対応
 * 2026-08-26 10:00 v4.0 時間プリセットをドロップダウン方式に変更
 * ============================================================
 */

require_once __DIR__ . '/LIB/session_tab.php';
require_once __DIR__ . '/LIB/db.php';

// 認証チェック
if (!is_logged_in()) {
    header('Location: ' . redirect_url('login.php'));
    exit;
}

// ============================================================
// 1. return_to と日付プリセット
// ============================================================
$returnTo = isset($_GET['return_to']) ? $_GET['return_to'] : 'index';
$presetDate = isset($_GET['start_date']) ? $_GET['start_date'] : null;

// 戻り先URLとラベルの決定
$backUrl = url('index.php');
$backLabel = 'メニューに戻る';
if ($returnTo === 'calendar') {
    $backUrl = url('calendar.php');
    $backLabel = 'カレンダーに戻る';
} elseif ($returnTo === 'list' || $returnTo === 'yotei_list') {
    $backUrl = url('yotei_list.php');
    $backLabel = '予定表一覧に戻る';
}

// フォームデータの初期化
if (empty($formData)) {
    $formData = [];
}

// プリセット日付があれば設定
if ($presetDate && empty($formData['start_date'])) {
    $formData['start_date'] = $presetDate;
}

// doctor_id が未設定の場合は空文字を設定
if (!isset($formData['doctor_id'])) {
    $formData['doctor_id'] = '';
}

// ============================================================
// 2. 基本データ取得
// ============================================================
$pdo = getDbConnection();

// 医師一覧（略称順）
$stmt = $pdo->query("
    SELECT id, last_name, first_name, short_name, title, department_id
    FROM doctors
    WHERE is_active = true
    ORDER BY sort_order
");
$doctors = $stmt->fetchAll();

// 備考定型文（category = 'note'）
$stmt = $pdo->prepare("
    SELECT id, note_text
    FROM note_templates
    WHERE note_category = 'note'
      AND is_active = true
    ORDER BY sort_order
");
$stmt->execute();
$noteTemplates = $stmt->fetchAll();

// デフォルト値
$today = date('Y-m-d');
$defaultDate = $today;

// 今月の第2土曜日を計算（デフォルト用）
$firstDay = date('w', strtotime(date('Y-m-01')));
$secondSaturday = 1;
if ($firstDay <= 6) {
    $firstSaturday = (6 - $firstDay + 7) % 7 + 1;
    $secondSaturday = $firstSaturday + 7;
} else {
    $secondSaturday = 7;
}
$defaultDate = date('Y-m-' . str_pad($secondSaturday, 2, '0', STR_PAD_LEFT));

// フォームデータがなければデフォルト値を設定
if (empty($formData['start_date'])) {
    $formData['start_date'] = $defaultDate;
}
if (empty($formData['end_date'])) {
    $formData['end_date'] = '';
}
if (empty($formData['start_time'])) {
    $formData['start_time'] = '';
}
if (empty($formData['end_time'])) {
    $formData['end_time'] = '';
}
if (empty($formData['date_type'])) {
    $formData['date_type'] = 'single';
}
if (empty($formData['time_type'])) {
    $formData['time_type'] = 'allday';
}
if (empty($formData['event_type'])) {
    $formData['event_type'] = 'absence';
}
if (empty($formData['title'])) {
    $formData['title'] = '';
}
if (empty($formData['note'])) {
    $formData['note'] = '';
}
if (empty($formData['is_public'])) {
    $formData['is_public'] = '1';
}

// 曜日表示用
$weekDays = ['日', '月', '火', '水', '木', '金', '土'];
$startWeekday = !empty($formData['start_date']) ? $weekDays[(int)date('w', strtotime($formData['start_date']))] : '';
$endWeekday = !empty($formData['end_date']) ? $weekDays[(int)date('w', strtotime($formData['end_date']))] : '';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>予定を追加 - yotei</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', 'Hiragino Sans', 'Helvetica Neue', Arial, sans-serif;
            background: #f0f2f5;
            color: #2d3748;
            padding: 12px 14px;
        }
        .container {
            max-width: 760px;
            margin: 0 auto;
        }

        /* ヘッダー（スリム化） */
        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: white;
            padding: 10px 18px;
            border-radius: 8px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.05);
            margin-bottom: 10px;
        }
        .header h1 {
            font-size: 17px;
            font-weight: 700;
            color: #2C6E9C;
        }
        .header h1 span { color: #E8833A; }
        .header .back-link {
            color: #718096;
            text-decoration: none;
            font-size: 13px;
        }
        .header .back-link:hover { color: #2C6E9C; }

        /* カード（縦パディング＆マージンを圧縮） */
        .card {
            background: white;
            border-radius: 8px;
            padding: 12px 18px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.05);
            margin-bottom: 10px;
        }
        .card-title {
            font-size: 13px;
            font-weight: 700;
            color: #4a5568;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .card-title .badge {
            font-size: 11px;
            background: #e2e8f0;
            color: #718096;
            padding: 1px 8px;
            border-radius: 8px;
            font-weight: 400;
        }

        /* 医師ボタン（コンパクト化） */
        .doctor-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }
        .doctor-btn {
            padding: 5px 12px;
            border: 1.5px solid #e2e8f0;
            border-radius: 6px;
            background: white;
            cursor: pointer;
            font-size: 13px;
            transition: all 0.15s;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .doctor-btn:hover { background: #f7fafc; border-color: #cbd5e0; }
        .doctor-btn.active {
            border-color: #2C6E9C;
            background: #ebf8ff;
            box-shadow: 0 0 0 2px rgba(44, 110, 156, 0.2);
            font-weight: 600;
        }
        .doctor-btn .icon { font-size: 15px; }

        /* クイック入力ボタン（コンパクト化） */
        .quick-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }
        .quick-btn {
            padding: 4px 10px;
            border: 1.5px solid #e2e8f0;
            border-radius: 6px;
            background: #f7fafc;
            cursor: pointer;
            font-size: 12px;
            transition: all 0.15s;
            color: #334155;
        }
        .quick-btn:hover { background: #edf2f7; border-color: #cbd5e0; }

        /* ラジオグループ */
        .radio-group {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
            align-items: center;
        }
        .radio-group label {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
        }
        .radio-group input[type="radio"] {
            width: 15px;
            height: 15px;
            accent-color: #2C6E9C;
            cursor: pointer;
        }

        /* 日付入力行（コンパクト化） */
        .date-input-row {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
            margin-top: 6px;
        }
        .date-input-row label {
            font-size: 13px;
            font-weight: 600;
            color: #4a5568;
            min-width: 50px;
        }
        .date-input-row input[type="date"] {
            padding: 4px 8px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 13px;
            background: white;
            height: 32px;
        }
        .date-input-row .today-btn {
            padding: 4px 10px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            background: #f8fafc;
            cursor: pointer;
            font-size: 12px;
            height: 32px;
            transition: all 0.15s;
        }
        .date-input-row .today-btn:hover { background: #e2e8f0; }

        .weekday-badge {
            font-size: 13px;
            font-weight: 600;
            color: #4a5568;
            background: #edf2f7;
            padding: 3px 8px;
            border-radius: 6px;
            min-width: 42px;
            display: inline-block;
            text-align: center;
            height: 32px;
            line-height: 26px;
        }
        #endDateRow input:disabled {
            background: #f1f5f9;
            opacity: 0.6;
            cursor: not-allowed;
        }
        #endTimeWrapper.hidden { display: none; }

        /* 時間入力行（コンパクト化） */
        .time-input-row {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
            margin-top: 6px;
        }
        .time-input-row.hidden { display: none; }
        .time-input-row input[type="time"] {
            padding: 4px 8px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 13px;
            background: white;
            width: 110px;
            height: 32px;
        }

        /* 時間プリセット行（スリム化） */
        .time-preset-row {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
            margin-top: 6px;
        }
        .time-preset-row label {
            font-size: 13px;
            font-weight: 600;
            color: #4a5568;
        }
        .time-preset-row select {
            padding: 4px 8px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 13px;
            background: white;
            min-width: 180px;
            height: 32px;
            cursor: pointer;
        }
        .time-preset-row select:focus {
            outline: none;
            border-color: #2C6E9C;
            box-shadow: 0 0 0 2px rgba(44, 110, 156, 0.15);
        }
        .time-preset-row .clear-btn {
            padding: 4px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            background: #f8fafc;
            cursor: pointer;
            font-size: 12px;
            height: 32px;
            transition: all 0.15s;
            color: #4a5568;
        }
        .time-preset-row .clear-btn:hover { background: #edf2f7; }
        .time-hint {
            font-size: 11px;
            color: #94a3b8;
        }

        /* 期間選択時の固定通知バッジ */
        .lock-notice {
            display: none;
            font-size: 12px;
            color: #b45309;
            background: #fef3c7;
            border: 1px solid #fde68a;
            padding: 2px 8px;
            border-radius: 4px;
            font-weight: 600;
        }

        /* 予定種別ボタン（コンパクト化） */
        .type-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }
        .type-btn {
            padding: 6px 14px;
            border: 1.5px solid #e2e8f0;
            border-radius: 6px;
            background: white;
            cursor: pointer;
            font-size: 13px;
            transition: all 0.15s;
            font-weight: 600;
        }
        .type-btn:hover { transform: translateY(-1px); }
        .type-btn.active {
            border-color: #2C6E9C;
            box-shadow: 0 0 0 2px rgba(44, 110, 156, 0.15);
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

        /* タイトル行（コンパクト化） */
        .title-row {
            display: flex;
            gap: 6px;
            align-items: center;
            flex-wrap: wrap;
        }
        .title-row .text-input { flex: 1; min-width: 200px; }
        .title-row .auto-btn {
            padding: 5px 14px;
            background: #edf2f7;
            border: 1.5px solid #cbd5e1;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            color: #4a5568;
            height: 34px;
            transition: all 0.15s;
            white-space: nowrap;
        }
        .title-row .auto-btn:hover {
            background: #e2e8f0;
            border-color: #94a3b8;
        }

        .text-input {
            width: 100%;
            padding: 6px 10px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 13px;
            height: 34px;
            transition: border-color 0.15s;
        }
        .text-input:focus {
            outline: none;
            border-color: #2C6E9C;
            box-shadow: 0 0 0 2px rgba(44, 110, 156, 0.15);
        }
        .text-input.error { border-color: #e53e3e; background: #fff5f5; }

        .error-msg { color: #e53e3e; font-size: 12px; margin-top: 3px; }
        .help-text { font-size: 11px; color: #94a3b8; margin-top: 3px; }

        /* 備考行（コンパクト化） */
        .note-template-row {
            display: flex;
            gap: 6px;
            align-items: center;
            flex-wrap: wrap;
            margin-bottom: 6px;
        }
        .note-template-row label {
            font-size: 12px;
            font-weight: 600;
            color: #4a5568;
        }
        .note-template-row select {
            flex: 1;
            min-width: 160px;
            padding: 4px 8px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 13px;
            height: 32px;
            background: white;
        }
        .quick-note-btn {
            padding: 4px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            background: #f8fafc;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            height: 32px;
            transition: all 0.15s;
            white-space: nowrap;
        }
        .quick-note-btn:hover {
            background: #e2e8f0;
        }

        /* チェックボックス（公開設定） */
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
        }
        .checkbox-group input[type="checkbox"] {
            width: 15px;
            height: 15px;
            accent-color: #2C6E9C;
            cursor: pointer;
        }

        /* 登録・アクションボタン */
        .form-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
        }
        .btn {
            padding: 8px 22px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s;
        }
        .btn:hover { transform: translateY(-1px); }
        .btn-primary { background: #2C6E9C; color: white; }
        .btn-primary:hover { background: #1a4a6e; }
        .btn-secondary { background: #e2e8f0; color: #4a5568; }
        .btn-secondary:hover { background: #cbd5e0; }

        .toast {
            position: fixed;
            bottom: 24px;
            left: 50%;
            transform: translateX(-50%);
            background: #2d3748;
            color: white;
            padding: 10px 20px;
            border-radius: 6px;
            font-size: 13px;
            opacity: 0;
            transition: opacity 0.3s ease;
            z-index: 9999;
            pointer-events: none;
        }
        .toast.show { opacity: 1; }

        @media (max-width: 600px) {
            body { padding: 8px; }
            .header { flex-direction: column; align-items: stretch; gap: 6px; }
            .card { padding: 10px 14px; }
            .date-input-row { flex-direction: column; align-items: stretch; }
            .time-input-row { flex-direction: column; align-items: stretch; }
            .time-preset-row { flex-direction: column; align-items: stretch; }
            .time-preset-row select { width: 100%; }
            .doctor-buttons { justify-content: center; }
            .type-buttons { justify-content: center; }
            .form-actions { flex-direction: column; }
            .form-actions .btn { text-align: center; width: 100%; }
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
        <h1>✏️ <span>予定</span>を追加</h1>
        <a href="<?= $backUrl ?>" class="back-link">
            ← <?= htmlspecialchars($backLabel) ?>
        </a>
    </div>

    <form method="POST" action="<?= url('confirm.php') ?>" id="inputForm">
        <input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTo) ?>">

        <!-- ============================================================
        1. 医師選択
        ============================================================ -->
        <div class="card">
            <div class="card-title">👨‍⚕️ 医師を選ぶ</div>
            <div class="doctor-buttons" id="doctorButtons">
                <?php foreach ($doctors as $doc): ?>
                <button type="button"
                        class="doctor-btn <?= (($formData['doctor_id'] ?? '') == $doc['id']) ? 'active' : '' ?>"
                        data-doctor-id="<?= htmlspecialchars($doc['id'] ?? '') ?>"
                        data-doctor-short="<?= htmlspecialchars($doc['short_name'] ?? '') ?>"
                        data-doctor-full="<?= htmlspecialchars(($doc['last_name'] ?? '') . ($doc['first_name'] ?? '')) ?>"
                        data-doctor-title="<?= htmlspecialchars($doc['title'] ?? '') ?>"
                        onclick="selectDoctor(<?= htmlspecialchars($doc['id'] ?? '') ?>)">
                    <span class="icon">👤</span>
                    <?= htmlspecialchars($doc['short_name'] ?? '') ?>
                </button>
                <?php endforeach; ?>
                <button type="button"
                        class="doctor-btn <?= (($formData['doctor_id'] ?? '') === 'staff') ? 'active' : '' ?>"
                        data-doctor-id="staff"
                        data-doctor-short="事務長"
                        data-doctor-full="事務長"
                        data-doctor-title=""
                        onclick="selectDoctor('staff')">
                    <span class="icon">🏢</span>
                    事務長
                </button>
            </div>
            <input type="hidden" name="doctor_id" id="doctorId" value="<?= htmlspecialchars($formData['doctor_id'] ?? '') ?>">
        </div>

        <!-- ============================================================
        2. クイック入力
        ============================================================ -->
        <div class="card">
            <div class="card-title">⚡ クイック入力 <span class="badge">現在選択中の医師に適用</span></div>
            <div class="quick-buttons">
                <button type="button" class="quick-btn" onclick="setSecondSaturday();">
                    📅 第2土曜診察
                </button>
                <button type="button" class="quick-btn" onclick="setWednesdayHoliday();">
                    📅 水曜公休
                </button>
                <button type="button" class="quick-btn" onclick="setLastWednesdayMeeting();">
                    📅 最終水曜会議
                </button>
                <button type="button" class="quick-btn" onclick="setNextWeekSameDay();">
                    📅 来週同じ曜日
                </button>
            </div>
        </div>

        <!-- ============================================================
        3. 日付パターン
        ============================================================ -->
        <div class="card">
            <div class="card-title">📅 日付パターン</div>

            <div class="radio-group">
                <label>
                    <input type="radio" name="date_type" value="single"
                           <?= ($formData['date_type'] ?? 'single') === 'single' ? 'checked' : '' ?>
                           onchange="toggleDateType(); updateWeekday(); autoGenerateTitle();">
                    単日
                </label>
                <label>
                    <input type="radio" name="date_type" value="period"
                           <?= ($formData['date_type'] ?? 'single') === 'period' ? 'checked' : '' ?>
                           onchange="toggleDateType(); updateWeekday(); autoGenerateTitle();">
                    期間
                </label>
            </div>

            <div class="date-input-row">
                <label>開始日:</label>
                <input type="date" name="start_date" id="startDate"
                       value="<?= htmlspecialchars($formData['start_date'] ?? $defaultDate) ?>"
                       onchange="updateWeekday(); autoGenerateTitle(); syncEndDate();">
                <span id="startWeekday" class="weekday-badge">
                    <?= $startWeekday ? '(' . $startWeekday . ')' : '' ?>
                </span>
                <button type="button" class="today-btn" onclick="setToday(); updateWeekday(); autoGenerateTitle();">📌 今日</button>
            </div>

            <div class="date-input-row" id="endDateRow">
                <label>終了日:</label>
                <input type="date" name="period_end" id="periodEnd"
                       value="<?= htmlspecialchars($formData['end_date'] ?? '') ?>"
                       onchange="updateWeekday(); autoGenerateTitle(); endDateManuallyChanged = true;"
                       <?= ($formData['date_type'] ?? 'single') === 'single' ? 'disabled' : '' ?>>
                <span id="endWeekday" class="weekday-badge">
                    <?= $endWeekday ? '(' . $endWeekday . ')' : '' ?>
                </span>
            </div>

            <input type="hidden" name="end_date" id="endDateHidden" value="<?= htmlspecialchars($formData['end_date'] ?? '') ?>">
        </div>

        <!-- ============================================================
        4. 時間パターン（期間選択時は原則終日固定）
        ============================================================ -->
        <div class="card" id="timePatternCard">
            <div class="card-title">
                ⏰ 時間パターン
                <span id="periodTimeLockNotice" class="lock-notice">🔒 期間選択時は終日固定</span>
            </div>

            <div class="radio-group" id="timeRadioGroup">
                <label id="labelAllday">
                    <input type="radio" name="time_type" id="timeTypeAllday" value="allday"
                           <?= ($formData['time_type'] ?? 'allday') === 'allday' ? 'checked' : '' ?>
                           onchange="toggleTimeType(); updateDateTimeFields(); autoGenerateTitle();">
                    終日
                </label>
                <label id="labelTime">
                    <input type="radio" name="time_type" id="timeTypeTime" value="time"
                           <?= ($formData['time_type'] ?? 'allday') === 'time' ? 'checked' : '' ?>
                           onchange="toggleTimeType(); updateDateTimeFields(); autoGenerateTitle();">
                    時間指定
                </label>
            </div>

            <div id="timeInputRow" class="time-input-row <?= ($formData['time_type'] ?? 'allday') === 'allday' ? 'hidden' : '' ?>">
                <label>開始:</label>
                <input type="time" name="start_time" id="startTime"
                       value="<?= htmlspecialchars($formData['start_time'] ?? '') ?>"
                       onchange="autoGenerateTitle()" step="600">

                <span id="endTimeWrapper">
                    <label>〜 終了:</label>
                    <input type="time" name="end_time" id="endTime"
                           value="<?= htmlspecialchars($formData['end_time'] ?? '') ?>"
                           onchange="autoGenerateTitle()" step="600">
                </span>
            </div>

            <!-- 時間プリセット（ドロップダウン） -->
            <div class="time-preset-row" id="timePresetRow">
                <label>時間プリセット:</label>
                <select id="timePreset" onchange="applyTimePreset(this.value)">
                    <option value="">-- 選択してください --</option>
                    <option value="am1">🌅 午前 9:00-12:00</option>
                    <option value="am2">🌅 午前 9:00-12:30</option>
                    <option value="pm1">🌇 午後 12:00〜</option>
                    <option value="pm2">🌇 午後 12:30〜</option>
                    <option value="pm3">🌇 午後 13:00〜</option>
                    <option value="ev1">🌆 夕方 15:00〜</option>
                    <option value="ev2">🌆 夕方 16:00〜</option>
                    <option value="ev3">🌆 夕方 17:00〜</option>
                </select>
                <button type="button" class="clear-btn" id="timeClearBtn" onclick="clearTime(); showToast('✕ 時間をクリアしました');">
                    ✕ クリア
                </button>
            </div>
        </div>

        <!-- ============================================================
        5. 予定の種類
        ============================================================ -->
        <div class="card">
            <div class="card-title">📌 予定の種類</div>
            <div class="type-buttons" id="typeButtons">
                <button type="button"
                        class="type-btn type-absence <?= ($formData['event_type'] ?? 'absence') === 'absence' ? 'active' : '' ?>"
                        data-type="absence"
                        onclick="selectType('absence'); autoGenerateTitle();">
                    🔴 休診
                </button>
                <button type="button"
                        class="type-btn type-clinic <?= ($formData['event_type'] ?? 'absence') === 'clinic' ? 'active' : '' ?>"
                        data-type="clinic"
                        onclick="selectType('clinic'); autoGenerateTitle();">
                    🟢 診察
                </button>
                <button type="button"
                        class="type-btn type-meeting <?= ($formData['event_type'] ?? 'absence') === 'meeting' ? 'active' : '' ?>"
                        data-type="meeting"
                        onclick="selectType('meeting'); autoGenerateTitle();">
                    🟡 会議
                </button>
                <button type="button"
                        class="type-btn type-holiday <?= ($formData['event_type'] ?? 'absence') === 'holiday' ? 'active' : '' ?>"
                        data-type="holiday"
                        onclick="selectType('holiday'); autoGenerateTitle();">
                    ⚪ 休暇
                </button>
                <button type="button"
                        class="type-btn type-other <?= ($formData['event_type'] ?? 'absence') === 'other' ? 'active' : '' ?>"
                        data-type="other"
                        onclick="selectType('other'); autoGenerateTitle();">
                    🟣 その他
                </button>
            </div>
            <input type="hidden" name="event_type" id="eventType" value="<?= htmlspecialchars($formData['event_type'] ?? 'absence') ?>">
        </div>

        <!-- ============================================================
        6. タイトル（自動作成ボタン付き）
        ============================================================ -->
        <div class="card">
            <div class="card-title">📝 タイトル <span class="badge">必須</span></div>
            <div class="title-row">
                <input type="text" name="title" id="titleInput"
                       class="text-input <?= (!empty($errors) && in_array('タイトルを入力してください', $errors)) ? 'error' : '' ?>"
                       placeholder="例: 矢野哲也医師 休診（公休）"
                       value="<?= htmlspecialchars($formData['title'] ?? '') ?>">
                <button type="button" class="auto-btn" onclick="autoGenerateTitle(); showToast('✨ タイトルを自動生成しました');">
                    ✨ 自動作成
                </button>
            </div>
        </div>

        <!-- ============================================================
        7. 備考（任意）+ 定型文プルダウン
        ============================================================ -->
        <div class="card">
            <div class="card-title">📝 備考 <span class="badge">任意</span></div>

            <div class="note-template-row">
                <label>定型文:</label>
                <select id="noteTemplateSelect">
                    <option value="">-- 選択してください --</option>
                    <?php foreach ($noteTemplates as $template): ?>
                    <option value="<?= htmlspecialchars($template['note_text'] ?? '') ?>">
                        <?= htmlspecialchars($template['note_text'] ?? '') ?>
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
        </div>

        <!-- ============================================================
        8. 公開設定
        ============================================================ -->
        <div class="card">
            <div class="checkbox-group">
                <input type="checkbox" name="is_public" id="isPublicCheckbox" value="1"
                       <?= ($formData['is_public'] ?? '1') == '1' ? 'checked' : '' ?>>
                <label for="isPublicCheckbox">🔒 この予定を公開する（チェックを外すと非公開・下書き状態）</label>
            </div>
        </div>

        <!-- ============================================================
        9. アクションボタン
        ============================================================ -->
        <div class="card">
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">📋 確認画面へ</button>
                <button type="reset" class="btn btn-secondary" onclick="return confirm('入力内容をクリアしますか？')">🗑️ クリア</button>
                <a href="<?= $backUrl ?>" class="btn btn-secondary" style="text-decoration:none;text-align:center;">✕ 閉じる</a>
            </div>
        </div>

    </form>

</div>

<!-- ============================================================
JavaScript
============================================================ -->
<script>
// ============================================================
// 状態管理
// ============================================================
let endDateManuallyChanged = false;

// ============================================================
// 1. 医師選択
// ============================================================
function selectDoctor(id) {
    document.querySelectorAll('.doctor-btn').forEach(btn => btn.classList.remove('active'));
    const target = document.querySelector(`.doctor-btn[data-doctor-id="${id}"]`);
    if (target) target.classList.add('active');
    document.getElementById('doctorId').value = id;
    autoGenerateTitle();
}

// ============================================================
// 2. 予定タイプ選択
// ============================================================
function selectType(type) {
    document.querySelectorAll('.type-btn').forEach(btn => btn.classList.remove('active'));
    const target = document.querySelector(`.type-btn[data-type="${type}"]`);
    if (target) target.classList.add('active');
    document.getElementById('eventType').value = type;
    autoGenerateTitle();
}

// ============================================================
// 3. 日付パターン切替 ＆ 期間時終日固定連動
// ============================================================
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

        // ★ 単日：時間指定のロックを解除
        unlockTimeTypeForPeriod();
    } else {
        const startDate = document.getElementById('startDate').value;
        endDateInput.value = startDate;
        endDateHidden.value = startDate;
        endDateManuallyChanged = false;
        updateWeekday();

        // ★ 期間：時間パターンを「終日」に強制セットし、時間指定を無効化（固定）
        lockTimeTypeForPeriod();
    }
    updateDateTimeFields();
}

// ============================================================
// 3-1. 期間選択時の時間パターンロック処理
// ============================================================
function lockTimeTypeForPeriod() {
    // 1. 「終日」ラジオを自動選択
    const alldayRadio = document.getElementById('timeTypeAllday');
    const timeRadio = document.getElementById('timeTypeTime');
    if (alldayRadio) alldayRadio.checked = true;

    // 2. 「時間指定」ラジオを disabled にして選択不可にする
    if (timeRadio) {
        timeRadio.disabled = true;
        const labelTime = document.getElementById('labelTime');
        if (labelTime) {
            labelTime.style.opacity = '0.45';
            labelTime.style.cursor = 'not-allowed';
            labelTime.title = '期間選択時は終日固定となります';
        }
    }

    // 3. 時間入力欄・プリセット・クリアボタンをクリア＆無効化
    const startInput = document.getElementById('startTime');
    const endInput = document.getElementById('endTime');
    const presetSelect = document.getElementById('timePreset');
    const clearBtn = document.getElementById('timeClearBtn');
    if (startInput) startInput.value = '';
    if (endInput) endInput.value = '';
    if (presetSelect) {
        presetSelect.value = '';
        presetSelect.disabled = true;
    }
    if (clearBtn) clearBtn.disabled = true;

    // 4. 時間入力行を非表示
    const timeRow = document.getElementById('timeInputRow');
    if (timeRow) timeRow.classList.add('hidden');

    // 5. 終日固定のガイドバッジを表示
    const lockNotice = document.getElementById('periodTimeLockNotice');
    if (lockNotice) lockNotice.style.display = 'inline-block';
}

function unlockTimeTypeForPeriod() {
    // 「時間指定」ラジオのロックを解除
    const timeRadio = document.getElementById('timeTypeTime');
    if (timeRadio) {
        timeRadio.disabled = false;
        const labelTime = document.getElementById('labelTime');
        if (labelTime) {
            labelTime.style.opacity = '';
            labelTime.style.cursor = 'pointer';
            labelTime.title = '';
        }
    }
    const presetSelect = document.getElementById('timePreset');
    const clearBtn = document.getElementById('timeClearBtn');
    if (presetSelect) presetSelect.disabled = false;
    if (clearBtn) clearBtn.disabled = false;

    // 終日固定のガイドバッジを非表示
    const lockNotice = document.getElementById('periodTimeLockNotice');
    if (lockNotice) lockNotice.style.display = 'none';
}

// ============================================================
// 4. 時間パターン切替
// ============================================================
function toggleTimeType() {
    const isPeriod = document.querySelector('input[name="date_type"]:checked').value === 'period';
    if (isPeriod) {
        // 期間の場合は終日固定
        lockTimeTypeForPeriod();
        return;
    }

    const isTime = document.querySelector('input[name="time_type"]:checked').value === 'time';
    const timeRow = document.getElementById('timeInputRow');
    timeRow.classList.toggle('hidden', !isTime);
    updateDateTimeFields();
}

// ============================================================
// 5. 日付×時間の連動制御
// ============================================================
function updateDateTimeFields() {
    const isPeriod = document.querySelector('input[name="date_type"]:checked').value === 'period';
    const isTime = document.querySelector('input[name="time_type"]:checked').value === 'time';

    const endTimeWrapper = document.getElementById('endTimeWrapper');
    const endTimeInput = document.getElementById('endTime');

    if (isPeriod) {
        // 期間選択時は終日固定のため終了時刻ラッパー非表示
        endTimeWrapper.classList.add('hidden');
        endTimeInput.value = '';
    } else if (!isPeriod && isTime) {
        endTimeWrapper.classList.remove('hidden');
    } else {
        endTimeWrapper.classList.add('hidden');
        endTimeInput.value = '';
    }
}

// ============================================================
// 6. 曜日表示更新
// ============================================================
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

// ============================================================
// 7. 終了日同期（開始日変更時に終了日を追従）
// ============================================================
function syncEndDate() {
    const isPeriod = document.querySelector('input[name="date_type"]:checked').value === 'period';
    if (!isPeriod) return;
    if (endDateManuallyChanged) return;

    const startDate = document.getElementById('startDate').value;
    const endDateInput = document.getElementById('periodEnd');
    endDateInput.value = startDate;
    document.getElementById('endDateHidden').value = startDate;
    updateWeekday();
}

// ============================================================
// 8. 今日ボタン
// ============================================================
function setToday() {
    const today = new Date().toISOString().split('T')[0];
    document.getElementById('startDate').value = today;
    if (!endDateManuallyChanged) {
        document.getElementById('periodEnd').value = today;
    }
}

// ============================================================
// 9. 時間プリセット適用
// ============================================================
function applyTimePreset(value) {
    if (!value) return;

    const isPeriod = document.querySelector('input[name="date_type"]:checked').value === 'period';
    if (isPeriod) {
        showToast('⚠️ 期間選択時は時間指定できません（終日固定）');
        document.getElementById('timePreset').value = '';
        return;
    }

    const startInput = document.getElementById('startTime');
    const endInput = document.getElementById('endTime');

    // 時間指定モードに切り替え
    const timeRadio = document.querySelector('input[name="time_type"][value="time"]');
    if (!timeRadio.checked) {
        timeRadio.checked = true;
        toggleTimeType();
        updateDateTimeFields();
    }

    let startVal = '';
    let endVal = '';
    let message = '';

    switch(value) {
        case 'am1':
            startVal = '09:00';
            endVal = '12:00';
            message = '🌅 午前（9:00-12:00）をセットしました';
            break;
        case 'am2':
            startVal = '09:00';
            endVal = '12:30';
            message = '🌅 午前（9:00-12:30）をセットしました';
            break;
        case 'pm1':
            startVal = '12:00';
            endVal = '';
            message = '🌇 午後（12:00〜）をセットしました';
            break;
        case 'pm2':
            startVal = '12:30';
            endVal = '';
            message = '🌇 午後（12:30〜）をセットしました';
            break;
        case 'pm3':
            startVal = '13:00';
            endVal = '';
            message = '🌇 午後（13:00〜）をセットしました';
            break;
        case 'ev1':
            startVal = '15:00';
            endVal = '';
            message = '🌆 夕方（15:00〜）をセットしました';
            break;
        case 'ev2':
            startVal = '16:00';
            endVal = '';
            message = '🌆 夕方（16:00〜）をセットしました';
            break;
        case 'ev3':
            startVal = '17:00';
            endVal = '';
            message = '🌆 夕方（17:00〜）をセットしました';
            break;
        default:
            return;
    }

    startInput.value = startVal;
    endInput.value = endVal;
    document.getElementById('timePreset').value = '';
    showToast(message);
    autoGenerateTitle();
}

// ============================================================
// 10. 時間クリア
// ============================================================
function clearTime() {
    document.getElementById('startTime').value = '';
    document.getElementById('endTime').value = '';
    document.getElementById('timePreset').value = '';
    autoGenerateTitle();
}

// ============================================================
// 11. クイック入力（日付関連）
// ============================================================
function setSecondSaturday() {
    const today = new Date();
    const year = today.getFullYear();
    const month = today.getMonth() + 1;
    const firstDay = new Date(year, month - 1, 1).getDay();
    const firstSaturday = (6 - firstDay + 7) % 7 + 1;
    const secondSaturday = firstSaturday + 7;
    const pad = (n) => String(n).padStart(2, '0');
    const dateStr = `${year}-${pad(month)}-${pad(secondSaturday)}`;

    document.getElementById('startDate').value = dateStr;
    document.querySelector('input[name="date_type"][value="single"]').checked = true;
    toggleDateType();
    document.querySelector('input[name="time_type"][value="allday"]').checked = true;
    toggleTimeType();
    updateDateTimeFields();
    updateWeekday();
    selectType('clinic');
    endDateManuallyChanged = false;
    autoGenerateTitle();
    showToast('📅 第2土曜診察をセットしました');
}

function setWednesdayHoliday() {
    const today = new Date();
    const year = today.getFullYear();
    const month = today.getMonth() + 1;
    const firstDay = new Date(year, month - 1, 1).getDay();
    const firstSaturday = (6 - firstDay + 7) % 7 + 1;
    const secondSaturday = firstSaturday + 7;
    const wednesday = secondSaturday - 3;
    const pad = (n) => String(n).padStart(2, '0');
    const dateStr = `${year}-${pad(month)}-${pad(wednesday)}`;

    document.getElementById('startDate').value = dateStr;
    document.querySelector('input[name="date_type"][value="single"]').checked = true;
    toggleDateType();
    document.querySelector('input[name="time_type"][value="allday"]').checked = true;
    toggleTimeType();
    updateDateTimeFields();
    updateWeekday();
    selectType('absence');
    endDateManuallyChanged = false;
    autoGenerateTitle();
    showToast('📅 水曜公休をセットしました');
}

function setLastWednesdayMeeting() {
    const today = new Date();
    const year = today.getFullYear();
    const month = today.getMonth() + 1;
    const lastDay = new Date(year, month, 0).getDate();
    let lastWednesday = lastDay;
    for (let d = lastDay; d >= 1; d--) {
        if (new Date(year, month - 1, d).getDay() === 3) {
            lastWednesday = d;
            break;
        }
    }
    const pad = (n) => String(n).padStart(2, '0');
    const dateStr = `${year}-${pad(month)}-${pad(lastWednesday)}`;

    document.getElementById('startDate').value = dateStr;
    document.querySelector('input[name="date_type"][value="single"]').checked = true;
    toggleDateType();
    document.querySelector('input[name="time_type"][value="time"]').checked = true;
    toggleTimeType();
    document.getElementById('startTime').value = '13:00';
    document.getElementById('endTime').value = '';
    updateDateTimeFields();
    updateWeekday();
    selectType('meeting');
    endDateManuallyChanged = false;
    autoGenerateTitle();
    showToast('📅 最終水曜会議をセットしました');
}

function setNextWeekSameDay() {
    const startDate = document.getElementById('startDate').value;
    if (!startDate) {
        showToast('⚠️ 開始日を先に設定してください');
        return;
    }
    const d = new Date(startDate + 'T00:00:00');
    d.setDate(d.getDate() + 7);
    const dateStr = d.toISOString().split('T')[0];
    document.getElementById('startDate').value = dateStr;
    document.querySelector('input[name="date_type"][value="single"]').checked = true;
    toggleDateType();
    updateWeekday();
    if (!endDateManuallyChanged) {
        document.getElementById('periodEnd').value = dateStr;
    }
    autoGenerateTitle();
    showToast('📅 来週の同じ曜日をセットしました');
}

// ============================================================
// 12. タイトル自動作成
// ============================================================
function autoGenerateTitle() {
    const doctorId = document.getElementById('doctorId').value;
    const eventType = document.getElementById('eventType').value;
    const startDate = document.getElementById('startDate').value;
    const endDate = document.getElementById('periodEnd').value;
    const dateType = document.querySelector('input[name="date_type"]:checked').value;
    const timeType = document.querySelector('input[name="time_type"]:checked').value;

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

// ============================================================
// 13. 備考定型文
// ============================================================
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

// ============================================================
// 14. トースト通知
// ============================================================
function showToast(message) {
    const toast = document.getElementById('toast');
    toast.textContent = message;
    toast.classList.add('show');
    clearTimeout(toast._timer);
    toast._timer = setTimeout(() => {
        toast.classList.remove('show');
    }, 2000);
}

// ============================================================
// 15. フォーム送信前処理
// ============================================================
document.getElementById('inputForm').addEventListener('submit', function(e) {
    const isPeriod = document.querySelector('input[name="date_type"]:checked').value === 'period';

    if (isPeriod) {
        const endDate = document.getElementById('periodEnd').value;
        document.getElementById('endDateHidden').value = endDate;
        // 期間選択時は終日固定のため、開始・終了時刻をクリア
        document.getElementById('startTime').value = '';
        document.getElementById('endTime').value = '';
        document.getElementById('timeTypeAllday').checked = true;
    } else {
        document.getElementById('endDateHidden').value = '';
    }
});

// ============================================================
// 16. 初期化
// ============================================================
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