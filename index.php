<?php
/**
 * ============================================================
 * ファイル名: index.php
 * システム名: 医師予定表管理システム（yotei）
 * バージョン: v6.0（機能ポータル / メニュー画面）
 * 生成日時: 2026-10-08
 * ============================================================
 *
 * 【概要】
 * 医師予定表システムのメインポータル・機能ランチャー画面
 * 各機能（予定表一覧、カレンダー、予定追加、マスター管理等）へのジャンプメニュー
 * および直近の予定サマリー・小野会全体会情報を集約表示
 */

require_once __DIR__ . '/LIB/session_tab.php';
require_once __DIR__ . '/LIB/auth_helper.php';
require_once __DIR__ . '/LIB/db.php';

// 1. 認証チェック（未ログインなら login.php へ）
if (!is_logged_in()) {
    header('Location: ' . redirect_url('login.php'));
    exit;
}

// タイムアウトチェック（30分）
if (check_tab_timeout(1800)) {
    header('Location: ' . redirect_url('login.php?logout=force'));
    exit;
}

$currentUser = current_tab_user();
$staffName = $currentUser['staff_name'] ?? '職員';
$roleName = $currentUser['role'] ?? '';
$isAdmin = !empty($currentUser['is_admin']);

// 2. データベース接続
$pdo = getDbConnection();

$today = date('Y-m-d');
$currentYear = (int)date('Y');
$currentMonth = (int)date('n');

// 3. 直近の予定（直近14日間、最大5件）を取得
$stmtUpcoming = $pdo->prepare("
    SELECT 
        e.*,
        d.short_name as doctor_short_name,
        d.last_name as doctor_last_name,
        d.title as doctor_title,
        s.name as staff_name,
        dept.name as department_name
    FROM events e
    LEFT JOIN doctors d ON e.doctor_id = d.id
    LEFT JOIN staff s ON e.staff_id = s.id
    LEFT JOIN departments dept ON e.department_id = dept.id
    WHERE e.is_cancelled = false
      AND (
        (e.end_date IS NULL AND e.start_date >= :today AND e.start_date <= :max_date)
        OR (e.end_date IS NOT NULL AND e.end_date >= :today AND e.start_date <= :max_date)
      )
    ORDER BY e.start_date, e.start_time NULLS FIRST
    LIMIT 6
");
$maxDate = date('Y-m-d', strtotime('+14 days'));
$stmtUpcoming->execute(['today' => $today, 'max_date' => $maxDate]);
$upcomingEvents = $stmtUpcoming->fetchAll();

// 4. 当月の小野会全体会情報を取得
$allMeeting = getAllMeetingInfo($pdo, $currentYear, $currentMonth);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>医師予定表 - メニュー</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Noto+Sans+JP:wght@400;500;700;900&family=Outfit:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #005a9c;
            --primary-dark: #004085;
            --primary-light: #eff6ff;
            --accent: #0ea5e9;
            --bg-body: #f1f5f9;
            --bg-card: #ffffff;
            --text-main: #1e293b;
            --text-muted: #64748b;
            --border: #e2e8f0;
            --radius: 12px;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            --shadow-hover: 0 10px 25px rgba(0, 90, 156, 0.12);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', 'Noto Sans JP', sans-serif;
            background: var(--bg-body);
            color: var(--text-main);
            padding: 20px 16px;
            min-height: 100vh;
            line-height: 1.5;
        }

        .container {
            max-width: 1080px;
            margin: 0 auto;
        }

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
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 12px;
        }
        .header-title-wrap {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .header-icon {
            width: 44px;
            height: 44px;
            background: linear-gradient(135deg, #005a9c, #0284c7);
            color: white;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            box-shadow: 0 4px 10px rgba(0, 90, 156, 0.25);
        }
        .header-title {
            font-size: 1.25rem;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.3px;
        }
        .header-subtitle {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-top: 1px;
        }

        .user-nav-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .user-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #eff6ff;
            border: 1.5px solid #bfdbfe;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.88rem;
            font-weight: 700;
            color: #0369a1;
        }
        .user-badge .role-tag {
            font-size: 0.72rem;
            font-weight: normal;
            background: #dbeafe;
            color: #1e40af;
            padding: 1px 7px;
            border-radius: 10px;
        }
        .btn-header {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            border-radius: 8px;
            font-size: 0.84rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s;
            cursor: pointer;
            border: 1px solid var(--border);
            background: white;
            color: var(--text-main);
        }
        .btn-header:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
        }
        .btn-header.btn-logout {
            color: #dc2626;
            border-color: #fecaca;
            background: #fef2f2;
        }
        .btn-header.btn-logout:hover {
            background: #fee2e2;
            border-color: #fca5a5;
        }

        /* ウェルカムセクション */
        .welcome-card {
            background: linear-gradient(135deg, #005a9c 0%, #0369a1 100%);
            color: white;
            border-radius: var(--radius);
            padding: 24px 28px;
            margin-bottom: 24px;
            box-shadow: 0 6px 20px rgba(0, 90, 156, 0.2);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }
        .welcome-text h2 {
            font-size: 1.35rem;
            font-weight: 800;
            margin-bottom: 6px;
        }
        .welcome-text p {
            font-size: 0.9rem;
            opacity: 0.92;
        }
        .today-info-badge {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 12px;
            padding: 10px 18px;
            text-align: right;
        }
        .today-date-text {
            font-size: 1.15rem;
            font-weight: 800;
            font-family: 'Outfit', sans-serif;
            letter-spacing: 0.5px;
        }
        .today-weekday-text {
            font-size: 0.85rem;
            opacity: 0.9;
        }

        /* セクション見出し */
        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
        }
        .section-header h3 {
            font-size: 1.05rem;
            font-weight: 800;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* メインメニューグリッド */
        .menu-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(310px, 1fr));
            gap: 18px;
            margin-bottom: 28px;
        }
        .menu-card {
            background: var(--bg-card);
            border: 1.5px solid var(--border);
            border-radius: var(--radius);
            padding: 22px 20px;
            text-decoration: none;
            color: inherit;
            display: flex;
            align-items: flex-start;
            gap: 16px;
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
            overflow: hidden;
            box-shadow: var(--shadow);
        }
        .menu-card:hover {
            transform: translateY(-4px);
            border-color: var(--primary);
            box-shadow: var(--shadow-hover);
        }
        .menu-icon-box {
            width: 52px;
            height: 52px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            flex-shrink: 0;
            transition: transform 0.2s;
        }
        .menu-card:hover .menu-icon-box {
            transform: scale(1.08);
        }

        .icon-list   { background: #eff6ff; color: #2563eb; }
        .icon-cal    { background: #ecfdf5; color: #059669; }
        .icon-add    { background: #fff7ed; color: #ea580c; }
        .icon-gear   { background: #f5f3ff; color: #7c3aed; }
        .icon-api    { background: #f0fdfa; color: #0d9488; }
        .icon-kawara { background: #fdf2f8; color: #db2777; }

        .menu-info {
            flex: 1;
        }
        .menu-title {
            font-size: 1.05rem;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .menu-desc {
            font-size: 0.82rem;
            color: var(--text-muted);
            line-height: 1.4;
            margin-bottom: 10px;
        }
        .menu-action-label {
            font-size: 0.8rem;
            font-weight: 700;
            color: var(--primary);
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .menu-card:hover .menu-action-label {
            color: var(--primary-dark);
        }

        /* ダッシュボード下部（直近予定 & 全体会） */
        .dashboard-row {
            display: grid;
            grid-template-columns: 1.6fr 1fr;
            gap: 20px;
            margin-bottom: 24px;
        }

        .dash-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 20px;
            box-shadow: var(--shadow);
        }
        .dash-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--border);
        }
        .dash-card-title {
            font-size: 0.95rem;
            font-weight: 700;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        /* 予定一覧アイテム */
        .event-list-wrap {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .event-mini-item {
            padding: 10px 14px;
            border-radius: 8px;
            background: #f8fafc;
            border: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            transition: all 0.15s;
        }
        .event-mini-item:hover {
            background: #f1f5f9;
            border-color: #cbd5e1;
        }
        .event-mini-date {
            font-size: 0.85rem;
            font-weight: 700;
            color: #0369a1;
            white-space: nowrap;
        }
        .event-mini-title {
            font-size: 0.88rem;
            font-weight: 600;
            color: #1e293b;
            flex: 1;
        }
        .event-mini-badge {
            font-size: 0.72rem;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 6px;
            white-space: nowrap;
        }
        .badge-absence { background: #fee2e2; color: #b91c1c; }
        .badge-clinic  { background: #dcfce7; color: #15803d; }
        .badge-meeting { background: #fef3c7; color: #b45309; }
        .badge-holiday { background: #f1f5f9; color: #475569; }

        /* 全体会カード内装飾 */
        .all-meeting-card-content {
            background: #eff6ff;
            border: 1.5px solid #bfdbfe;
            border-radius: 10px;
            padding: 16px;
            margin-top: 6px;
        }
        .all-meeting-date-large {
            font-size: 1.1rem;
            font-weight: 800;
            color: #0369a1;
            margin-bottom: 4px;
        }
        .all-meeting-detail-row {
            font-size: 0.85rem;
            color: #334155;
            margin-top: 4px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        /* フッター */
        .app-footer {
            text-align: center;
            padding: 16px 0;
            font-size: 0.8rem;
            color: var(--text-muted);
            border-top: 1px dashed var(--border);
            margin-top: 20px;
        }

        @media (max-width: 768px) {
            .app-header { flex-direction: column; align-items: stretch; }
            .user-nav-actions { justify-content: space-between; }
            .dashboard-row { grid-template-columns: 1fr; }
            .menu-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="container">

    <!-- 1. ヘッダー -->
    <header class="app-header">
        <div class="header-title-wrap">
            <div class="header-icon">
                <i class="fa-solid fa-hospital-user"></i>
            </div>
            <div>
                <h1 class="header-title">医師予定表管理システム</h1>
                <div class="header-subtitle">医療法人 小野会 院内スケジュールポータル</div>
            </div>
        </div>

        <div class="user-nav-actions">
            <div class="user-badge">
                <i class="fa-solid fa-user"></i>
                <span><?= htmlspecialchars($staffName) ?></span>
                <?php if ($roleName): ?>
                <span class="role-tag"><?= htmlspecialchars($roleName) ?></span>
                <?php endif; ?>
            </div>

            <a href="<?= url('logout.php') ?>" class="btn-header btn-logout" onclick="return confirm('ログアウトしますか？');" title="ログアウト">
                <i class="fa-solid fa-sign-out-alt"></i> ログアウト
            </a>
        </div>
    </header>

    <!-- 2. ウェルカムバナー -->
    <div class="welcome-card">
        <div class="welcome-text">
            <h2>お疲れ様です、<?= htmlspecialchars($staffName) ?> さん</h2>
            <p>本日の医師診察・休診・不在状況の確認や、今後の予定登録はこちらから行えます。</p>
        </div>
        <div class="today-info-badge">
            <div class="today-date-text">
                <?= date('Y年n月j日') ?>
            </div>
            <div class="today-weekday-text">
                （<?= WEEKDAYS[(int)date('w')] ?>曜日）本日
            </div>
        </div>
    </div>

    <!-- 3. メイン機能メニュー -->
    <div class="section-header">
        <h3><i class="fa-solid fa-layer-group" style="color:var(--primary);"></i> 機能メニュー</h3>
    </div>

    <div class="menu-grid">
        <!-- 医師予定表（一覧表示） -->
        <a href="<?= url('yotei_list.php') ?>" class="menu-card">
            <div class="menu-icon-box icon-list">
                <i class="fa-solid fa-table-list"></i>
            </div>
            <div class="menu-info">
                <div class="menu-title">
                    医師予定表（一覧）
                    <i class="fa-solid fa-chevron-right" style="font-size:0.8rem;color:#cbd5e1;"></i>
                </div>
                <div class="menu-desc">月別・部署別の医師予定一覧。不在・休診・診察の詳細確認や取消・編集を行えます。</div>
                <span class="menu-action-label">一覧を開く →</span>
            </div>
        </a>

        <!-- 小野会カレンダー -->
        <a href="<?= url('calendar.php') ?>" class="menu-card">
            <div class="menu-icon-box icon-cal">
                <i class="fa-solid fa-calendar-days"></i>
            </div>
            <div class="menu-info">
                <div class="menu-title">
                    小野会カレンダー
                    <i class="fa-solid fa-chevron-right" style="font-size:0.8rem;color:#cbd5e1;"></i>
                </div>
                <div class="menu-desc">3ヶ月分の予定を縦積みカレンダー形式で一覧表示。院内掲示や印刷にも対応しています。</div>
                <span class="menu-action-label">カレンダーを開く →</span>
            </div>
        </a>

        <!-- 予定を追加 -->
        <a href="<?= url('input.php') ?>" class="menu-card">
            <div class="menu-icon-box icon-add">
                <i class="fa-solid fa-calendar-plus"></i>
            </div>
            <div class="menu-info">
                <div class="menu-title">
                    予定を追加
                    <i class="fa-solid fa-chevron-right" style="font-size:0.8rem;color:#cbd5e1;"></i>
                </div>
                <div class="menu-desc">休診・診察・会議などの新規予定を入力・登録。単日・期間指定や時間プリセットに対応。</div>
                <span class="menu-action-label">予定を登録する →</span>
            </div>
        </a>

        <!-- マスター管理 -->
        <a href="<?= url('master/index.php') ?>" class="menu-card">
            <div class="menu-icon-box icon-gear">
                <i class="fa-solid fa-sliders"></i>
            </div>
            <div class="menu-info">
                <div class="menu-title">
                    マスター管理
                    <i class="fa-solid fa-chevron-right" style="font-size:0.8rem;color:#cbd5e1;"></i>
                </div>
                <div class="menu-desc">小野会全体会の日程登録・変更、医師マスタや定型メモテンプレートのメンテナンス。</div>
                <span class="menu-action-label">管理画面へ →</span>
            </div>
        </a>


        <!-- 外部連携 API -->
        <a href="<?= url('api/index.php') ?>" class="menu-card">
            <div class="menu-icon-box icon-api">
                <i class="fa-solid fa-code"></i>
            </div>
            <div class="menu-info">
                <div class="menu-title">
                    外部連携 API
                    <i class="fa-solid fa-chevron-right" style="font-size:0.8rem;color:#cbd5e1;"></i>
                </div>
                <div class="menu-desc">他システム（電子カルテ・サイネージ・かわら版等）連携用REST API・iCalフィード。</div>
                <span class="menu-action-label">API仕様・テスト →</span>
            </div>
        </a>

        <!-- 院内かわら版へジャンプ -->
        <a href="/kawara/index.php" class="menu-card">
            <div class="menu-icon-box icon-kawara">
                <i class="fa-solid fa-newspaper"></i>
            </div>
            <div class="menu-info">
                <div class="menu-title">
                    院内かわら版
                    <i class="fa-solid fa-arrow-up-right-from-square" style="font-size:0.8rem;color:#cbd5e1;"></i>
                </div>
                <div class="menu-desc">院内周知事項・業務連絡・災害時安否確認などの院内ポータルへ移動します。</div>
                <span class="menu-action-label">かわら版を開く ↗</span>
            </div>
        </a>
    </div>

    <!-- 4. ダッシュボードインフォメーション -->
    <div class="dashboard-row">
        <!-- 直近の予定プレビュー -->
        <div class="dash-card">
            <div class="dash-card-header">
                <div class="dash-card-title">
                    <i class="fa-solid fa-bell" style="color:#0284c7;"></i>
                    直近の医師予定（今後14日間）
                </div>
                <a href="<?= url('yotei_list.php') ?>" style="font-size:0.8rem;color:var(--primary);text-decoration:none;font-weight:700;">
                    すべて見る →
                </a>
            </div>

            <?php if (!empty($upcomingEvents)): ?>
            <div class="event-list-wrap">
                <?php foreach ($upcomingEvents as $ev): 
                    $evDate = date('n/j', strtotime($ev['start_date'])) . '（' . getDayOfWeek($ev['start_date']) . '）';
                    $badgeClass = 'badge-' . ($ev['event_type'] ?? 'absence');
                    $badgeLabel = getEventLabel($ev['event_type']);
                ?>
                <div class="event-mini-item">
                    <span class="event-mini-date"><?= $evDate ?></span>
                    <span class="event-mini-title"><?= htmlspecialchars($ev['title']) ?></span>
                    <span class="event-mini-badge <?= $badgeClass ?>"><?= $badgeLabel ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div style="padding:24px;text-align:center;color:var(--text-muted);font-size:0.88rem;">
                <i class="fa-solid fa-check-circle" style="font-size:24px;color:#10b981;display:block;margin-bottom:6px;"></i>
                向こう14日間に休診・不在等の登録予定はありません
            </div>
            <?php endif; ?>
        </div>

        <!-- 当月の小野会全体会 -->
        <div class="dash-card">
            <div class="dash-card-header">
                <div class="dash-card-title">
                    <i class="fa-solid fa-calendar-check" style="color:#10b981;"></i>
                    今月の小野会全体会
                </div>
                <a href="<?= url('master/all_meeting.php') ?>" style="font-size:0.8rem;color:var(--primary);text-decoration:none;font-weight:700;">
                    設定 →
                </a>
            </div>

            <?php if ($allMeeting): ?>
            <div class="all-meeting-card-content">
                <div class="all-meeting-date-large">
                    <?= date('n月j日', strtotime($allMeeting['date'])) ?>（<?= $allMeeting['weekday'] ?>）
                </div>
                <div class="all-meeting-detail-row">
                    <i class="fa-solid fa-clock" style="color:#0284c7;"></i>
                    <b><?= $allMeeting['time'] ?>〜</b>
                </div>
                <div class="all-meeting-detail-row">
                    <i class="fa-solid fa-location-dot" style="color:#0284c7;"></i>
                    <span><?= htmlspecialchars($allMeeting['location']) ?></span>
                </div>
                <?php if (!empty($allMeeting['title'])): ?>
                <div class="all-meeting-detail-row" style="margin-top:8px;font-weight:bold;color:#0f172a;">
                    <?= htmlspecialchars($allMeeting['title']) ?>
                </div>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div style="padding:24px;text-align:center;color:var(--text-muted);font-size:0.88rem;">
                今月の全体会日程は未設定です
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- 5. フッター -->
    <footer class="app-footer">
        医師予定表管理システム（yotei） &bull; 医療法人 小野会 &bull; <?= date('Y') ?> &bull; 
        <a href="仕様書/index.html" style="color:#0284c7;text-decoration:none;font-weight:600;"><i class="fa-solid fa-book"></i> 仕様書ポータル</a>
    </footer>

</div>
</body>
</html>