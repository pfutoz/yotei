<?php
// ============================================================
// ファイル名: confirm.php
// システム名: 医師予定表管理システム（yotei）
// バージョン: v2.1（return_to 対応版）
// 生成日時: 2026-08-10
// ============================================================
//
// 【概要】
// 予定登録確認画面
// return_to を引き継いで insert.php に渡す
// ============================================================

require_once __DIR__ . '/LIB/db.php';

session_start();

// ============================================================
// POSTデータ取得
// ============================================================
$doctorId = $_POST['doctor_id'] ?? '';
$dateType = $_POST['date_type'] ?? 'single';
$startDate = $_POST['start_date'] ?? '';
$periodEnd = $_POST['period_end'] ?? '';
$endDate = $_POST['end_date'] ?? '';
$timeType = $_POST['time_type'] ?? 'allday';
$startTime = $_POST['start_time'] ?? '';
$endTime = $_POST['end_time'] ?? '';
$eventType = $_POST['event_type'] ?? 'absence';
$title = trim($_POST['title'] ?? '');
$note = trim($_POST['note'] ?? '');
$isPublic = isset($_POST['is_public']) ? 1 : 0;
$returnTo = $_POST['return_to'] ?? 'index';  // ★ 戻り先を取得

// ============================================================
// 日付・時間の確定
// ============================================================
if ($dateType === 'period') {
    $startDate = $startDate;
    $endDate = $periodEnd;
} else {
    $endDate = null;
}

if ($timeType === 'allday') {
    $startTime = null;
    $endTime = null;
} else {
    $startTime = !empty($startTime) ? $startTime : null;
    $endTime = !empty($endTime) ? $endTime : null;
    if ($dateType === 'period') {
        $endTime = null;
    }
}

// ============================================================
// バリデーション
// ============================================================
$errors = [];

if (empty($doctorId)) {
    $errors[] = '医師を選択してください';
}
if (empty($startDate)) {
    $errors[] = '日付を入力してください';
}
if (empty($title)) {
    $errors[] = 'タイトルを入力してください';
}
if ($dateType === 'period' && !empty($endDate) && $endDate < $startDate) {
    $errors[] = '終了日は開始日より後の日付を指定してください';
}

// 時刻バリデーション
if ($timeType === 'time') {
    if (empty($startTime)) {
        $errors[] = '開始時間を入力してください（例: 09:00）';
    } else {
        if (!preg_match('/^([0-1]?[0-9]|2[0-3]):[0-5][0-9]$/', $startTime)) {
            $errors[] = '開始時間の形式が正しくありません（例: 09:00）';
        } else {
            $parts = explode(':', $startTime);
            $startTime = sprintf('%02d:%02d', (int)$parts[0], (int)$parts[1]);
        }
    }
    if (!empty($endTime)) {
        if (!preg_match('/^([0-1]?[0-9]|2[0-3]):[0-5][0-9]$/', $endTime)) {
            $errors[] = '終了時間の形式が正しくありません（例: 17:00）';
        } else {
            $parts = explode(':', $endTime);
            $endTime = sprintf('%02d:%02d', (int)$parts[0], (int)$parts[1]);
        }
        if (!empty($startTime) && $startTime >= $endTime) {
            $errors[] = '終了時間は開始時間より後に設定してください';
        }
    }
}

// エラー処理
if (!empty($errors)) {
    $_SESSION['input_errors'] = $errors;
    $_SESSION['input_form_data'] = $_POST;
    $_SESSION['input_return_to'] = $returnTo;  // ★ 戻り先を保持
    header('Location: input.php?return_to=' . $returnTo);
    exit;
}

// ============================================================
// 医師情報取得
// ============================================================
$pdo = getDbConnection();

$isStaff = ($doctorId === 'staff');
if ($isStaff) {
    $doctorName = '事務長';
    $doctorShortName = '事務長';
    $doctorIdForDb = null;
} else {
    $stmt = $pdo->prepare("
        SELECT id, last_name, first_name, short_name, title, department_id
        FROM doctors WHERE id = :id
    ");
    $stmt->execute(['id' => $doctorId]);
    $doctor = $stmt->fetch();
    if ($doctor) {
        $doctorShortName = $doctor['short_name'];
        $doctorName = $doctor['last_name'] . $doctor['first_name'] . ($doctor['title'] ?? '');
    } else {
        $doctorName = '不明';
        $doctorShortName = '不明';
    }
    $doctorIdForDb = $doctorId;
}

// ============================================================
// 表示用データ
// ============================================================
$weekDays = ['日', '月', '火', '水', '木', '金', '土'];

// 日付表示
if ($dateType === 'period' && !empty($endDate)) {
    $dateDisplay = date('Y/m/d (D)', strtotime($startDate)) . ' 〜 ' . date('Y/m/d (D)', strtotime($endDate));
} else {
    $dateDisplay = date('Y/m/d (D)', strtotime($startDate));
}

// 時間表示
if ($timeType === 'allday') {
    $timeDisplay = '終日';
} else {
    $timeDisplay = '';
    if (!empty($startTime)) {
        $timeDisplay .= $startTime;
        if ($dateType === 'period') {
            $timeDisplay .= ' 〜（期間中毎日）';
        } elseif (!empty($endTime)) {
            $timeDisplay .= ' 〜 ' . $endTime;
        } else {
            $timeDisplay .= ' 〜';
        }
    } else {
        $timeDisplay = '時間指定（未設定）';
    }
}

// 予定種別表示
$typeLabels = [
    'absence' => '🔴 休診',
    'clinic' => '🟢 診察',
    'meeting' => '🟡 会議',
    'holiday' => '⚪ 休暇',
    'other' => '🟣 その他'
];
$typeDisplay = $typeLabels[$eventType] ?? $eventType;

$publicDisplay = $isPublic ? '✅ 公開' : '🔒 非公開（下書き）';

// ============================================================
// セッション保存（insert.php用）
// ============================================================
$_SESSION['confirm_data'] = [
    'doctor_id' => $doctorId,
    'is_staff' => $isStaff,
    'start_date' => $startDate,
    'end_date' => $endDate,
    'start_time' => $startTime,
    'end_time' => $endTime,
    'event_type' => $eventType,
    'title' => $title,
    'note' => $note,
    'is_public' => $isPublic,
    'doctor_name' => $doctorName,
    'doctor_short_name' => $doctorShortName,
    'date_type' => $dateType,
    'time_type' => $timeType,
];
$_SESSION['return_to'] = $returnTo;  // ★ 戻り先をセッションに保存
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>確認 - yotei</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Helvetica Neue', Arial, 'Hiragino Sans', 'Noto Sans JP', sans-serif;
            background: #f0f2f5;
            color: #2d3748;
            padding: 20px;
        }
        .container { max-width: 640px; margin: 0 auto; }

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

        .confirm-item {
            display: flex;
            padding: 10px 0;
            border-bottom: 1px solid #edf2f7;
        }
        .confirm-item:last-child { border-bottom: none; }
        .confirm-item .label {
            width: 100px;
            flex-shrink: 0;
            font-weight: 600;
            color: #4a5568;
            font-size: 14px;
        }
        .confirm-item .value {
            font-size: 15px;
            color: #2d3748;
        }
        .confirm-item .value .highlight {
            background: #ebf8ff;
            padding: 2px 8px;
            border-radius: 4px;
        }

        .warning-box {
            background: #fffff0;
            border: 1px solid #fefcbf;
            border-radius: 8px;
            padding: 16px;
            margin-top: 12px;
        }
        .warning-box .ok { color: #38a169; }
        .warning-box .ng { color: #e53e3e; }

        .form-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        .btn {
            padding: 10px 28px;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }
        .btn:hover { transform: translateY(-1px); }
        .btn-primary { background: #2C6E9C; color: white; }
        .btn-primary:hover { background: #1a4a6e; }
        .btn-secondary { background: #e2e8f0; color: #4a5568; }
        .btn-secondary:hover { background: #cbd5e0; }
        .btn-danger { background: #fc8181; color: white; }
        .btn-danger:hover { background: #f56565; }

        @media (max-width: 600px) {
            .confirm-item { flex-direction: column; gap: 4px; }
            .confirm-item .label { width: auto; }
            .form-actions { flex-direction: column; }
            .form-actions .btn { text-align: center; }
        }
    </style>
</head>
<body>
<div class="container">

    <div class="header">
        <h1>⚠️ 登録内容の確認</h1>
        <a href="input.php?return_to=<?= htmlspecialchars($returnTo) ?>" class="back-link">← 入力画面に戻る</a>
    </div>

    <div class="card">
        <p style="color:#4a5568;margin-bottom:16px;">以下の内容で予定を登録します。よろしいですか？</p>

        <div class="confirm-item">
            <span class="label">👨‍⚕️ 医師</span>
            <span class="value"><span class="highlight"><?= htmlspecialchars($doctorName) ?></span></span>
        </div>

        <div class="confirm-item">
            <span class="label">📅 日付</span>
            <span class="value"><?= htmlspecialchars($dateDisplay) ?></span>
        </div>

        <div class="confirm-item">
            <span class="label">⏰ 時間</span>
            <span class="value"><?= htmlspecialchars($timeDisplay) ?></span>
        </div>

        <div class="confirm-item">
            <span class="label">📌 種類</span>
            <span class="value"><?= $typeDisplay ?></span>
        </div>

        <div class="confirm-item">
            <span class="label">📝 タイトル</span>
            <span class="value"><?= htmlspecialchars($title) ?></span>
        </div>

        <?php if (!empty($note)): ?>
        <div class="confirm-item">
            <span class="label">📝 備考</span>
            <span class="value"><?= htmlspecialchars($note) ?></span>
        </div>
        <?php endif; ?>

        <div class="confirm-item">
            <span class="label">🔒 公開</span>
            <span class="value"><?= $publicDisplay ?></span>
        </div>

        <!-- 重複チェック -->
        <div class="warning-box">
            <?php
            if ($isStaff) {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as cnt FROM events
                    WHERE staff_id = (SELECT id FROM staff WHERE name = '事務長')
                      AND start_date = :start_date
                      AND is_cancelled = false
                ");
                $stmt->execute(['start_date' => $startDate]);
                $dup = $stmt->fetch();
                if ($dup['cnt'] > 0) {
                    echo '<div class="ng">⚠️ 事務長の同日に既存の予定があります（' . $dup['cnt'] . '件）</div>';
                } else {
                    echo '<div class="ok">✅ 事務長の同日に予定はありません</div>';
                }
            } else {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as cnt FROM events
                    WHERE doctor_id = :doctor_id
                      AND start_date = :start_date
                      AND is_cancelled = false
                ");
                $stmt->execute(['doctor_id' => $doctorId, 'start_date' => $startDate]);
                $dup = $stmt->fetch();
                if ($dup['cnt'] > 0) {
                    echo '<div class="ng">⚠️ 同じ医師の同日に既存の予定があります（' . $dup['cnt'] . '件）</div>';
                } else {
                    echo '<div class="ok">✅ 同じ医師の同日に予定はありません</div>';
                }
            }
            ?>
        </div>

    </div>

    <div class="card">
        <form method="POST" action="insert.php">
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">✅ 登録する</button>
                <a href="input.php?return_to=<?= htmlspecialchars($returnTo) ?>" class="btn btn-secondary">🔙 戻る</a>
                <a href="<?= $returnTo === 'calendar' ? 'calendar.php' : 'index.php' ?>" class="btn btn-danger">✕ キャンセル</a>
            </div>
        </form>
    </div>

</div>
</body>
</html>