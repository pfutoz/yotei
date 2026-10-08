<?php
// ============================================================
// ファイル名: master/all_meeting.php
// システム名: 医師予定表管理システム（yotei）
// バージョン: v2.1
// 生成日時: 2026-08-10
// ============================================================
//
// 【概要】
// 全体会（all_meetings）の一覧・追加・編集・削除・取消
// 過去データはデフォルト非表示（今日を含む未来のみ表示）
// 直近順（降順）に表示
// 全更新系処理にエラーログを実装
// ============================================================

require_once __DIR__ . '/../LIB/db.php';
require_once __DIR__ . '/../LIB/master_auth.php';

// 管理者パスワード認証チェック
requireMasterAuth('index.php');

$pdo = getDbConnection();
$message = '';
$errors = [];
$showPast = isset($_GET['show_past']) ? (int)$_GET['show_past'] : 0;

// ============================================================
// ログ出力関数
// ============================================================
function writeLog($msg) {
    error_log('【master/all_meeting.php】' . $msg);
}

// ============================================================
// 1. 一括生成（年指定）
// ============================================================
if (isset($_POST['generate']) && is_numeric($_POST['year'])) {
    $year = (int)$_POST['year'];
    $startDate = sprintf("%04d-01-01", $year);
    $endDate = sprintf("%04d-12-01", $year);
    
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM all_meetings WHERE EXTRACT(YEAR FROM meeting_date) = :year");
        $stmt->execute(['year' => $year]);
        if ($stmt->fetchColumn() > 0) {
            $errors[] = "{$year}年は既にデータが存在します";
        } else {
            $sql = "
                WITH RECURSIVE months AS (
                    SELECT generate_series(
                        DATE '$startDate',
                        DATE '$endDate',
                        INTERVAL '1 month'
                    ) AS month_start
                ),
                last_wednesday AS (
                    SELECT 
                        month_start,
                        CASE 
                            WHEN EXTRACT(DOW FROM (DATE_TRUNC('month', month_start) + INTERVAL '1 month' - INTERVAL '1 day')) = 3 
                            THEN (DATE_TRUNC('month', month_start) + INTERVAL '1 month' - INTERVAL '1 day')::DATE
                            ELSE (DATE_TRUNC('month', month_start) + INTERVAL '1 month' - INTERVAL '1 day' - 
                                  ((EXTRACT(DOW FROM (DATE_TRUNC('month', month_start) + INTERVAL '1 month' - INTERVAL '1 day')) - 3 + 7) % 7) * INTERVAL '1 day')::DATE
                        END AS meeting_date
                    FROM months
                ),
                adjusted AS (
                    SELECT 
                        meeting_date,
                        CASE 
                            WHEN EXTRACT(MONTH FROM meeting_date) = 12 AND EXTRACT(DAY FROM meeting_date) >= 25 THEN
                                meeting_date - INTERVAL '7 days'
                            ELSE
                                meeting_date
                        END AS adjusted_date
                    FROM last_wednesday
                )
                INSERT INTO all_meetings (meeting_date, title, start_time, location)
                SELECT 
                    adjusted_date,
                    '小野会全体会',
                    '13:00',
                    '胃腸科1F食堂'
                FROM adjusted
                ORDER BY adjusted_date
                ON CONFLICT (meeting_date) DO NOTHING
            ";
            $pdo->exec($sql);
            $message = "✅ {$year}年の全体会データを生成しました";
            writeLog("一括生成成功: {$year}年");
        }
    } catch (Exception $e) {
        writeLog('【一括生成エラー】' . $e->getMessage() . ' (line ' . $e->getLine() . ')');
        writeLog('  POSTデータ: ' . print_r($_POST, true));
        $errors[] = '一括生成に失敗しました: ' . $e->getMessage();
    }
}

// ============================================================
// 2. 個別追加
// ============================================================
if (isset($_POST['add'])) {
    $date = $_POST['meeting_date'] ?? '';
    $title = trim($_POST['title'] ?? '小野会全体会');
    $time = $_POST['start_time'] ?? '13:00';
    $location = trim($_POST['location'] ?? '胃腸科1F食堂');
    $note = trim($_POST['note'] ?? '');

    if (empty($date)) $errors[] = '開催日を入力してください';
    if (empty($title)) $errors[] = 'タイトルを入力してください';

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO all_meetings (meeting_date, title, start_time, location, note)
                VALUES (:date, :title, :time, :location, :note)
            ");
            $stmt->execute(['date' => $date, 'title' => $title, 'time' => $time, 'location' => $location, 'note' => $note]);
            $message = '✅ 全体会を追加しました';
            writeLog("個別追加成功: {$date} {$title}");
        } catch (Exception $e) {
            writeLog('【個別追加エラー】' . $e->getMessage() . ' (line ' . $e->getLine() . ')');
            writeLog('  POSTデータ: ' . print_r($_POST, true));
            $errors[] = '追加に失敗しました: ' . $e->getMessage();
        }
    }
}

// ============================================================
// 3. 編集
// ============================================================
if (isset($_POST['edit'])) {
    $id = (int)$_POST['id'];
    $title = trim($_POST['title'] ?? '小野会全体会');
    $time = $_POST['start_time'] ?? '13:00';
    $location = trim($_POST['location'] ?? '胃腸科1F食堂');
    $note = trim($_POST['note'] ?? '');

    if (empty($title)) $errors[] = 'タイトルを入力してください';

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("
                UPDATE all_meetings SET
                    title = :title,
                    start_time = :time,
                    location = :location,
                    note = :note,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ");
            $stmt->execute(['title' => $title, 'time' => $time, 'location' => $location, 'note' => $note, 'id' => $id]);
            $message = '✅ 全体会を更新しました';
            writeLog("編集成功: ID={$id} {$title}");
        } catch (Exception $e) {
            writeLog('【編集エラー】' . $e->getMessage() . ' (line ' . $e->getLine() . ')');
            writeLog('  POSTデータ: ' . print_r($_POST, true));
            $errors[] = '更新に失敗しました: ' . $e->getMessage();
        }
    }
}

// ============================================================
// 4. 取消 / 復活 / 削除（GET処理）
// ============================================================
if (isset($_GET['cancel']) && is_numeric($_GET['cancel'])) {
    $id = (int)$_GET['cancel'];
    try {
        $stmt = $pdo->prepare("UPDATE all_meetings SET is_cancelled = true WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $message = '✅ 全体会を取消しました';
        writeLog("取消成功: ID={$id}");
    } catch (Exception $e) {
        writeLog('【取消エラー】' . $e->getMessage() . ' (line ' . $e->getLine() . ')');
        writeLog('  GETデータ: ' . print_r($_GET, true));
        $errors[] = '取消に失敗しました: ' . $e->getMessage();
    }
    header('Location: all_meeting.php?show_past=' . $showPast);
    exit;
}

if (isset($_GET['restore']) && is_numeric($_GET['restore'])) {
    $id = (int)$_GET['restore'];
    try {
        $stmt = $pdo->prepare("UPDATE all_meetings SET is_cancelled = false WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $message = '✅ 全体会を復活させました';
        writeLog("復活成功: ID={$id}");
    } catch (Exception $e) {
        writeLog('【復活エラー】' . $e->getMessage() . ' (line ' . $e->getLine() . ')');
        writeLog('  GETデータ: ' . print_r($_GET, true));
        $errors[] = '復活に失敗しました: ' . $e->getMessage();
    }
    header('Location: all_meeting.php?show_past=' . $showPast);
    exit;
}

if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    try {
        $stmt = $pdo->prepare("DELETE FROM all_meetings WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $message = '✅ 全体会を削除しました';
        writeLog("削除成功: ID={$id}");
    } catch (Exception $e) {
        writeLog('【削除エラー】' . $e->getMessage() . ' (line ' . $e->getLine() . ')');
        writeLog('  GETデータ: ' . print_r($_GET, true));
        $errors[] = '削除に失敗しました: ' . $e->getMessage();
    }
    header('Location: all_meeting.php?show_past=' . $showPast);
    exit;
}

// ============================================================
// 5. データ取得（直近順・過去フィルタ）
// ============================================================
$today = date('Y-m-d');
$sql = "SELECT * FROM all_meetings WHERE 1=1";
if (!$showPast) {
    $sql .= " AND meeting_date >= :today";
}
$sql .= " ORDER BY meeting_date DESC";

$stmt = $pdo->prepare($sql);
if (!$showPast) {
    $stmt->execute(['today' => $today]);
} else {
    $stmt->execute();
}
$meetings = $stmt->fetchAll();

$currentYear = date('Y');
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>全体会管理 - yotei</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Helvetica Neue', Arial, 'Hiragino Sans', 'Noto Sans JP', sans-serif;
            background: #f0f2f5;
            color: #2d3748;
            padding: 20px;
        }
        .container { max-width: 1200px; margin: 0 auto; }

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
        .header .back-link { color: #718096; text-decoration: none; font-size: 14px; }
        .header .back-link:hover { color: #2C6E9C; }

        .nav-tabs {
            display: flex;
            gap: 4px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }
        .nav-tabs a {
            padding: 8px 20px;
            background: white;
            border-radius: 8px 8px 0 0;
            text-decoration: none;
            color: #4a5568;
            border: 1px solid #e2e8f0;
            border-bottom: none;
            font-weight: 600;
        }
        .nav-tabs a.active { background: #2C6E9C; color: white; border-color: #2C6E9C; }
        .nav-tabs a:hover:not(.active) { background: #edf2f7; }

        .card {
            background: white;
            border-radius: 10px;
            padding: 24px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.06);
            margin-bottom: 16px;
        }
        .card-title {
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .message {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            background: #c6f6d5;
            color: #22543d;
            border: 1px solid #9ae6b4;
        }
        .error {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            background: #fed7d7;
            color: #c53030;
            border: 1px solid #feb2b2;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }
        th {
            text-align: left;
            font-size: 12px;
            font-weight: 600;
            color: #a0aec0;
            padding: 8px 12px;
            border-bottom: 2px solid #e2e8f0;
            background: #f7fafc;
        }
        td {
            padding: 8px 12px;
            border-bottom: 1px solid #edf2f7;
            font-size: 14px;
            vertical-align: middle;
        }
        tr:hover td { background: #f7fafc; }

        .form-group { margin-bottom: 12px; }
        .form-group label { display: block; font-weight: 600; font-size: 13px; color: #4a5568; margin-bottom: 2px; }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 6px 10px;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            font-size: 14px;
            font-family: inherit;
        }
        .form-group textarea { resize: vertical; min-height: 40px; }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            outline: none;
            border-color: #2C6E9C;
        }

        .btn {
            padding: 6px 14px;
            border: none;
            border-radius: 4px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
        }
        .btn:hover { transform: translateY(-1px); }
        .btn-primary { background: #2C6E9C; color: white; }
        .btn-primary:hover { background: #1a4a6e; }
        .btn-success { background: #48bb78; color: white; }
        .btn-success:hover { background: #38a169; }
        .btn-warning { background: #ecc94b; color: #2d3748; }
        .btn-warning:hover { background: #d69e2e; }
        .btn-danger { background: #fc8181; color: white; }
        .btn-danger:hover { background: #f56565; }
        .btn-secondary { background: #e2e8f0; color: #4a5568; }
        .btn-secondary:hover { background: #cbd5e0; }
        .btn-sm { padding: 2px 8px; font-size: 12px; }

        .row { display: flex; gap: 12px; }
        .row .form-group { flex: 1; }

        .badge-future {
            font-size: 11px;
            background: #c6f6d5;
            color: #22543d;
            padding: 2px 10px;
            border-radius: 10px;
        }
        .badge-today {
            font-size: 11px;
            background: #fef3c7;
            color: #92400e;
            padding: 2px 10px;
            border-radius: 10px;
            font-weight: 700;
        }
        .badge-past {
            font-size: 11px;
            background: #e2e8f0;
            color: #718096;
            padding: 2px 10px;
            border-radius: 10px;
        }
        .badge-cancelled {
            font-size: 11px;
            background: #fed7d7;
            color: #c53030;
            padding: 2px 10px;
            border-radius: 10px;
        }

        .filter-option {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
        }
        .filter-option input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: #2C6E9C;
            cursor: pointer;
        }

        .generate-box {
            background: #f7fafc;
            padding: 16px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            display: flex;
            gap: 12px;
            align-items: flex-end;
            flex-wrap: wrap;
        }
        .generate-box .form-group { margin-bottom: 0; }

        @media (max-width: 768px) {
            .row { flex-direction: column; }
            table { font-size: 13px; }
            th, td { padding: 6px 8px; }
            .generate-box { flex-direction: column; align-items: stretch; }
        }
    </style>
</head>
<body>
<div class="container">

    <div class="header">
        <h1>📅 全体会管理</h1>
        <a href="index.php" class="back-link">← マスターメンテに戻る</a>
    </div>

    <div class="nav-tabs">
        <a href="index.php">👨‍⚕️ 医師</a>
        <a href="department.php">🏥 部門</a>
        <a href="note.php">📝 備考定型文</a>
        <a href="all_meeting.php" class="active">📅 全体会</a>
    </div>

    <?php if ($message): ?>
    <div class="message"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    <?php if (!empty($errors)): ?>
    <div class="error"><?= implode('<br>', array_map('htmlspecialchars', $errors)) ?></div>
    <?php endif; ?>

    <!-- ============================================================
    表示オプション
    ============================================================ -->
    <div class="card">
        <div class="card-title" style="margin-bottom:0;">
            <div class="filter-option">
                <input type="checkbox" id="showPast" <?= $showPast ? 'checked' : '' ?>
                       onchange="location.href='?show_past=' + (this.checked ? 1 : 0)">
                <label for="showPast">過去の開催も表示する</label>
                <span style="font-size:12px;color:#a0aec0;margin-left:8px;">（デフォルトは今日以降のみ表示）</span>
            </div>
            <span style="font-size:13px;color:#a0aec0;">
                <?= count($meetings) ?>件表示中
            </span>
        </div>
    </div>

    <!-- ============================================================
    一括生成
    ============================================================ -->
    <div class="card">
        <div class="card-title">📦 一括生成</div>
        <div class="generate-box">
            <div class="form-group">
                <label>年</label>
                <select name="year" id="generateYear" style="padding:6px 10px;border:1px solid #e2e8f0;border-radius:4px;font-size:14px;">
                    <?php for ($y = $currentYear - 1; $y <= $currentYear + 3; $y++): ?>
                    <option value="<?= $y ?>" <?= $y == $currentYear ? 'selected' : '' ?>><?= $y ?>年</option>
                    <?php endfor; ?>
                </select>
            </div>
            <button type="button" class="btn btn-success" onclick="generateYear()">📦 一括生成</button>
            <span style="font-size:12px;color:#a0aec0;">※ 既にデータが存在する年は生成されません</span>
        </div>
    </div>

    <!-- ============================================================
    個別追加
    ============================================================ -->
    <div class="card">
        <div class="card-title">➕ 個別追加</div>
        <form method="POST">
            <div class="row">
                <div class="form-group">
                    <label>開催日（必須）</label>
                    <input type="date" name="meeting_date" value="<?= htmlspecialchars($_POST['meeting_date'] ?? date('Y-m-d')) ?>">
                </div>
                <div class="form-group">
                    <label>タイトル</label>
                    <input type="text" name="title" value="<?= htmlspecialchars($_POST['title'] ?? '小野会全体会') ?>">
                </div>
            </div>
            <div class="row">
                <div class="form-group">
                    <label>開始時刻</label>
                    <input type="time" name="start_time" value="<?= htmlspecialchars($_POST['start_time'] ?? '13:00') ?>">
                </div>
                <div class="form-group">
                    <label>開催場所</label>
                    <input type="text" name="location" value="<?= htmlspecialchars($_POST['location'] ?? '胃腸科1F食堂') ?>">
                </div>
            </div>
            <div class="form-group">
                <label>備考</label>
                <textarea name="note"><?= htmlspecialchars($_POST['note'] ?? '') ?></textarea>
            </div>
            <button type="submit" name="add" class="btn btn-success">➕ 追加</button>
        </form>
    </div>

    <!-- ============================================================
    一覧（直近順・未来のみデフォルト）
    ============================================================ -->
    <div class="card">
        <div class="card-title">📋 全体会一覧</div>
        <?php if (empty($meetings)): ?>
        <p style="color:#a0aec0;text-align:center;padding:20px;">
            <?= $showPast ? 'データがありません' : '予定されている全体会はありません（過去の開催は非表示）' ?>
        </p>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th style="width:120px;">開催日</th>
                    <th>タイトル</th>
                    <th style="width:80px;">時刻</th>
                    <th>場所</th>
                    <th>備考</th>
                    <th style="width:110px;">状態</th>
                    <th style="width:180px;">操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($meetings as $m):
                    $meetingDate = $m['meeting_date'];
                    $isToday = ($meetingDate === $today);
                    $isFuture = ($meetingDate > $today);
                    $isPast = ($meetingDate < $today);
                    $isCancelled = $m['is_cancelled'];
                ?>
                <tr>
                    <td><?= htmlspecialchars($meetingDate ?? '') ?></td>
                    <td>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="edit" value="1">
                            <input type="hidden" name="id" value="<?= $m['id'] ?>">
                            <input type="text" name="title" value="<?= htmlspecialchars($m['title'] ?? '') ?>" style="padding:2px 4px;border:1px solid #e2e8f0;border-radius:4px;font-size:13px;width:120px;">
                            <input type="time" name="start_time" value="<?= htmlspecialchars($m['start_time'] ?? '') ?>" style="padding:2px 4px;border:1px solid #e2e8f0;border-radius:4px;font-size:13px;width:80px;">
                            <input type="text" name="location" value="<?= htmlspecialchars($m['location'] ?? '') ?>" style="padding:2px 4px;border:1px solid #e2e8f0;border-radius:4px;font-size:13px;width:100px;">
                            <input type="text" name="note" value="<?= htmlspecialchars($m['note'] ?? '') ?>" style="padding:2px 4px;border:1px solid #e2e8f0;border-radius:4px;font-size:13px;width:100px;">
                            <button type="submit" class="btn btn-sm btn-warning">✏️</button>
                        </form>
                    </td>
                    <td>
                        <?php if ($isCancelled): ?>
                        <span class="badge-cancelled">🗑️ 取消</span>
                        <?php elseif ($isToday): ?>
                        <span class="badge-today">🟡 本日開催</span>
                        <?php elseif ($isFuture): ?>
                        <span class="badge-future">🟢 開催予定</span>
                        <?php else: ?>
                        <span class="badge-past">⚪ 開催済み</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="display:flex;gap:4px;flex-wrap:wrap;">
                            <?php if ($isCancelled): ?>
                            <a href="?restore=<?= $m['id'] ?>&show_past=<?= $showPast ?>" class="btn btn-sm btn-success" onclick="return confirm('この全体会を復活させますか？')">↩️ 復活</a>
                            <?php else: ?>
                            <a href="?cancel=<?= $m['id'] ?>&show_past=<?= $showPast ?>" class="btn btn-sm btn-danger" onclick="return confirm('この全体会を取消しますか？')">🗑️ 取消</a>
                            <?php endif; ?>
                            <a href="?delete=<?= $m['id'] ?>&show_past=<?= $showPast ?>" class="btn btn-sm btn-secondary" onclick="return confirm('この全体会を完全に削除しますか？')">✕ 削除</a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

</div>

<script>
function generateYear() {
    const year = document.getElementById('generateYear').value;
    if (!year) return;
    if (!confirm(year + '年の全体会データを生成しますか？（既存データはスキップされます）')) return;
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '';
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'generate';
    input.value = '1';
    const input2 = document.createElement('input');
    input2.type = 'hidden';
    input2.name = 'year';
    input2.value = year;
    form.appendChild(input);
    form.appendChild(input2);
    document.body.appendChild(form);
    form.submit();
}
</script>

</body>
</html>