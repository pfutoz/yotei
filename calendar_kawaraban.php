<?php
// ============================================================
// ファイル名: calendar_kawaraban.php
// システム名: 医師予定表管理システム（yotei）
// バージョン: v1.4
// 生成日時: 2026-08-10
// ============================================================
//
// 【概要】
// かわら版システムと連携し、イベント日付のある投稿を
// 一覧表示するテストページ（別タブで詳細表示）
// ============================================================

require_once __DIR__ . '/LIB/db.php';

session_start();

// ============================================================
// 1. 設定
// ============================================================
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');

if ($month < 1 || $month > 12) { $month = date('m'); }
if ($year < 2000 || $year > 2100) { $year = date('Y'); }

// ============================================================
// 2. かわら版DB接続
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

$kawaraDb = getKawaraDbConnection();

// ============================================================
// 3. かわら版イベント一覧取得（過去データも全部表示）
// ============================================================
$startDate = sprintf("%04d-%02d-01", $year, $month);
$endDate = date('Y-m-t', strtotime($startDate));
$kawaraEvents = [];

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
        $stmt->execute(['start_date' => $startDate, 'end_date' => $endDate]);
        $kawaraEvents = $stmt->fetchAll();
    } catch (Exception $e) {
        error_log('かわら版イベント取得エラー: ' . $e->getMessage());
        $kawaraEvents = [];
    }
}

// 日付ごとにグループ化
$eventsByDate = [];
foreach ($kawaraEvents as $e) {
    $date = date('Y-m-d', strtotime($e['event_date']));
    if (!isset($eventsByDate[$date])) {
        $eventsByDate[$date] = [];
    }
    $eventsByDate[$date][] = $e;
}

// ============================================================
// 4. 月移動
// ============================================================
$prevMonth = $month - 1;
$prevYear = $year;
if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }

$nextMonth = $month + 1;
$nextYear = $year;
if ($nextMonth > 12) { $nextMonth = 1; $nextYear++; }

$monthName = date('Y年n月', strtotime("$year-$month-01"));
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>かわら版連携テスト - yotei</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Noto+Sans+JP:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', 'Noto Sans JP', sans-serif;
            background: #f5f7fa;
            color: #2d3748;
            padding: 16px;
            line-height: 1.6;
        }
        .container { max-width: 900px; margin: 0 auto; }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: white;
            padding: 12px 20px;
            border-radius: 8px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06);
            margin-bottom: 16px;
        }
        .header h1 {
            font-size: 16px;
            font-weight: 700;
            color: #2C6E9C;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .header h1 i { color: #2C6E9C; }
        .header .back-link {
            font-size: 13px;
            color: #a0aec0;
            text-decoration: none;
        }
        .header .back-link:hover { color: #2C6E9C; }

        .nav-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: white;
            padding: 8px 16px;
            border-radius: 8px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06);
            margin-bottom: 16px;
        }
        .nav-bar .month { font-weight: 600; font-size: 15px; }
        .nav-bar .btn {
            padding: 4px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            background: white;
            cursor: pointer;
            font-size: 13px;
            text-decoration: none;
            color: #2d3748;
            transition: all 0.2s;
        }
        .nav-bar .btn:hover { background: #edf2f7; }
        .nav-bar .btn-today {
            background: #e2e8f0;
            font-weight: 600;
        }

        .card {
            background: white;
            border-radius: 8px;
            padding: 16px 20px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06);
            margin-bottom: 16px;
        }
        .card-title {
            font-size: 13px;
            font-weight: 600;
            color: #a0aec0;
            margin-bottom: 8px;
            border-bottom: 1px solid #edf2f7;
            padding-bottom: 6px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .event-item {
            display: flex;
            align-items: center;
            padding: 6px 0;
            border-bottom: 1px solid #f7fafc;
            font-size: 14px;
            gap: 8px;
        }
        .event-item:last-child { border-bottom: none; }
        .event-item .date {
            width: 80px;
            flex-shrink: 0;
            font-weight: 600;
            color: #2d3748;
            font-size: 13px;
        }
        .event-item .title {
            flex: 1;
            color: #2d3748;
        }
        .event-item .category {
            font-size: 11px;
            color: #a0aec0;
            margin-left: 6px;
        }
        .event-item .badge-kawara {
            font-size: 10px;
            background: #ebf8ff;
            color: #2C6E9C;
            padding: 1px 8px;
            border-radius: 10px;
            margin-left: 6px;
        }
        .event-item .link {
            color: #2C6E9C;
            text-decoration: none;
            font-size: 13px;
            padding: 2px 10px;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            transition: all 0.2s;
            white-space: nowrap;
        }
        .event-item .link:hover {
            background: #ebf8ff;
            border-color: #2C6E9C;
        }

        .no-data {
            color: #a0aec0;
            text-align: center;
            padding: 20px;
            font-size: 14px;
        }

        .count-badge {
            font-size: 11px;
            color: #a0aec0;
        }

        .footer {
            text-align: center;
            font-size: 11px;
            color: #a0aec0;
            padding: 12px;
        }

        @media (max-width: 600px) {
            .header { flex-direction: column; gap: 6px; align-items: stretch; text-align: center; }
            .event-item { flex-wrap: wrap; gap: 4px; }
            .event-item .date { width: auto; }
            .nav-bar { flex-wrap: wrap; gap: 6px; justify-content: center; }
        }
    </style>
</head>
<body>
<div class="container">

    <!-- ============================================================
    ヘッダー
    ============================================================ -->
    <div class="header">
        <h1>
            <i class="fa-regular fa-newspaper"></i>
            かわら版連携テスト
        </h1>
        <a href="calendar.php" class="back-link">
            <i class="fa-regular fa-calendar"></i> カレンダーに戻る
        </a>
    </div>

    <!-- ============================================================
    ナビゲーション
    ============================================================ -->
    <div class="nav-bar">
        <a href="?year=<?= $prevYear ?>&month=<?= $prevMonth ?>" class="btn">
            <i class="fa-solid fa-chevron-left"></i>
        </a>
        <span class="month"><?= $monthName ?></span>
        <a href="?year=<?= $nextYear ?>&month=<?= $nextMonth ?>" class="btn">
            <i class="fa-solid fa-chevron-right"></i>
        </a>
        <a href="?year=<?= date('Y') ?>&month=<?= date('m') ?>" class="btn btn-today">
            <i class="fa-regular fa-clock"></i> 今日
        </a>
        <span style="font-size:12px;color:#a0aec0;">
            <i class="fa-regular fa-file-lines"></i> <?= count($kawaraEvents) ?>件
        </span>
    </div>

    <!-- ============================================================
    一覧
    ============================================================ -->
    <div class="card">
        <div class="card-title">
            <span><i class="fa-regular fa-calendar-plus"></i> イベント日付のある投稿</span>
            <span class="count-badge"><?= count($kawaraEvents) ?>件</span>
        </div>

        <?php if (empty($kawaraEvents)): ?>
        <div class="no-data">
            <i class="fa-regular fa-face-frown" style="font-size:20px;display:block;margin-bottom:6px;"></i>
            イベント日付のある投稿はありません
        </div>
        <?php else: ?>
        <?php foreach ($eventsByDate as $date => $events): ?>
            <div style="margin-bottom:6px;">
                <div style="font-size:12px;font-weight:600;color:#4a5568;padding:4px 0 2px 0;border-bottom:1px dashed #edf2f7;">
                    <?= date('Y/m/d（D）', strtotime($date)) ?>
                </div>
                <?php foreach ($events as $e): ?>
                <div class="event-item">
                    <span class="date"><?= date('H:i', strtotime($e['event_date'])) ?></span>
                    <span class="title">
                        <i class="fa-regular fa-bell" style="color:#f59e0b;font-size:12px;"></i>
                        <?= htmlspecialchars($e['title']) ?>
                        <span class="badge-kawara">かわら版</span>
                        <?php if (!empty($e['category_name'])): ?>
                        <span class="category">[<?= htmlspecialchars($e['category_name']) ?>]</span>
                        <?php endif; ?>
                    </span>
                    <a href="/kawara/view_post.php?id=<?= $e['id'] ?>" class="link" target="_blank">
                        <i class="fa-regular fa-eye"></i> 詳細
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- ============================================================
    フッター
    ============================================================ -->
    <div class="footer">
        yotei / kawara 連携テスト &bull; <?= date('Y/m/d H:i') ?>
        <?php if ($kawaraDb): ?>
        &bull; <span style="color:#48bb78;">✅ かわら版DB接続OK</span>
        <?php else: ?>
        &bull; <span style="color:#fc8181;">❌ かわら版DB接続NG</span>
        <?php endif; ?>
    </div>

</div>
</body>
</html>