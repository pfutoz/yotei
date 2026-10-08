<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>医師予定表 REST API ドキュメント - yotei</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;600&family=Noto+Sans+JP:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #2563eb;
            --primary-light: #eff6ff;
            --success: #059669;
            --success-light: #ecfdf5;
            --warning: #d97706;
            --danger: #dc2626;
            --bg-body: #f8fafc;
            --bg-card: #ffffff;
            --text-main: #0f172a;
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
            line-height: 1.6;
        }
        .container { max-width: 1080px; margin: 0 auto; }

        header {
            background: var(--bg-card);
            padding: 24px 30px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }
        .header-title h1 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .header-title p {
            color: var(--text-muted);
            font-size: 0.9rem;
            margin-top: 4px;
        }
        .header-badge {
            background: var(--primary-light);
            color: var(--primary);
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.82rem;
            font-weight: 600;
            border: 1px solid #bfdbfe;
        }

        .api-card {
            background: var(--bg-card);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            margin-bottom: 20px;
            overflow: hidden;
            transition: border-color 0.2s;
        }
        .api-header {
            padding: 18px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            user-select: none;
            background: #ffffff;
            border-bottom: 1px solid transparent;
        }
        .api-card.open .api-header {
            border-bottom-color: var(--border);
            background: #fafafa;
        }
        .api-method-endpoint {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .method-badge {
            padding: 4px 10px;
            border-radius: 6px;
            font-family: 'JetBrains Mono', monospace;
            font-weight: 700;
            font-size: 0.8rem;
            letter-spacing: 0.5px;
        }
        .method-get { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
        .endpoint-url {
            font-family: 'JetBrains Mono', monospace;
            font-size: 1rem;
            font-weight: 600;
            color: #1e293b;
        }
        .endpoint-desc {
            font-size: 0.88rem;
            color: var(--text-muted);
            margin-left: 8px;
        }

        .api-body {
            padding: 20px 24px;
            display: none;
        }
        .api-card.open .api-body { display: block; }

        .param-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
            margin-bottom: 16px;
        }
        .param-table th, .param-table td {
            padding: 10px 12px;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }
        .param-table th {
            background: #f8fafc;
            color: var(--text-muted);
            font-weight: 600;
        }
        .param-name {
            font-family: 'JetBrains Mono', monospace;
            font-weight: 600;
            color: var(--primary);
        }

        .try-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 14px 18px;
            margin-top: 16px;
        }
        .try-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .try-btn {
            background: var(--primary);
            color: white;
            border: none;
            padding: 8px 18px;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            font-size: 0.85rem;
            transition: background 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .try-btn:hover { background: #1d4ed8; }
        .open-raw-link {
            color: var(--primary);
            font-size: 0.85rem;
            text-decoration: none;
            font-weight: 500;
        }
        .open-raw-link:hover { text-decoration: underline; }

        .response-preview {
            margin-top: 12px;
            background: #0f172a;
            color: #f8fafc;
            padding: 14px;
            border-radius: 8px;
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.8rem;
            max-height: 280px;
            overflow-y: auto;
            white-space: pre-wrap;
            word-break: break-all;
            display: none;
        }

        .footer {
            text-align: center;
            color: var(--text-muted);
            font-size: 0.82rem;
            margin-top: 30px;
            padding: 10px;
        }
    </style>
</head>
<body>
<div class="container">

    <header>
        <div class="header-title">
            <h1><i class="fa-solid fa-code"></i> 医師予定表 REST API</h1>
            <p>電子カルテ、受付サイネージ、院内かわら版、外部グループウェア等で医師予定を利用するためのWeb APIです。</p>
        </div>
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
            <a href="../仕様書/api_spec.html" target="_blank" style="display:inline-flex;align-items:center;gap:6px;background:#059669;color:white;padding:7px 14px;border-radius:20px;font-size:0.82rem;font-weight:600;text-decoration:none;">
                <i class="fa-solid fa-book-open"></i> API詳細仕様書 (HTML)
            </a>
            <div class="header-badge">
                <i class="fa-solid fa-bolt"></i> CORS対応 &bull; JSON / iCal
            </div>
        </div>
    </header>

    <!-- 1. 本日のサマリー API -->
    <div class="api-card open" id="card-today">
        <div class="api-header" onclick="toggleCard('card-today')">
            <div class="api-method-endpoint">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-url">/api/today.php</span>
                <span class="endpoint-desc">本日の医師休診・診察サマリー（サイネージ・受付向け）</span>
            </div>
            <i class="fa-solid fa-chevron-down"></i>
        </div>
        <div class="api-body">
            <p style="font-size:0.9rem;margin-bottom:12px;color:#475569;">
                指定日（デフォルト: 本日）の休診・不在医師、診察医師、全体会情報、休診日判定をワンコールで一括取得します。
            </p>
            <table class="param-table">
                <thead><tr><th>パラメータ</th><th>型</th><th>必須</th><th>説明</th></tr></thead>
                <tbody>
                    <tr><td class="param-name">date</td><td>string</td><td>任意</td><td>指定日 (<code>YYYY-MM-DD</code>, <code>today</code>, <code>tomorrow</code>)。省略時は本日。</td></tr>
                </tbody>
            </table>
            <div class="try-box">
                <div class="try-actions">
                    <button class="try-btn" onclick="executeApi('today.php', 'res-today')"><i class="fa-solid fa-play"></i> 実行して試す</button>
                    <a href="today.php" target="_blank" class="open-raw-link"><i class="fa-solid fa-arrow-up-right-from-square"></i> 新規タブで開く</a>
                </div>
                <div class="response-preview" id="res-today"></div>
            </div>
        </div>
    </div>

    <!-- 2. 予定一覧検索 API -->
    <div class="api-card open" id="card-events">
        <div class="api-header" onclick="toggleCard('card-events')">
            <div class="api-method-endpoint">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-url">/api/events.php</span>
                <span class="endpoint-desc">医師予定の検索・期間取得（カレンダー・詳細連携）</span>
            </div>
            <i class="fa-solid fa-chevron-down"></i>
        </div>
        <div class="api-body">
            <p style="font-size:0.9rem;margin-bottom:12px;color:#475569;">
                期間や医師・部門で絞り込んだ予定データを取得します。複数日にまたがる期間予定の日別展開（<code>expand_period=1</code>）にも対応。
            </p>
            <table class="param-table">
                <thead><tr><th>パラメータ</th><th>型</th><th>必須</th><th>説明</th></tr></thead>
                <tbody>
                    <tr><td class="param-name">start_date</td><td>string</td><td>任意</td><td>検索開始日 (<code>YYYY-MM-DD</code>、デフォルト: 当月初日)</td></tr>
                    <tr><td class="param-name">end_date</td><td>string</td><td>任意</td><td>検索終了日 (<code>YYYY-MM-DD</code>、デフォルト: 翌月末日)</td></tr>
                    <tr><td class="param-name">date</td><td>string</td><td>任意</td><td>単一日指定 (<code>YYYY-MM-DD</code> または <code>today</code>)</td></tr>
                    <tr><td class="param-name">doctor_id</td><td>integer</td><td>任意</td><td>医師ID</td></tr>
                    <tr><td class="param-name">department_id</td><td>integer</td><td>任意</td><td>部門ID</td></tr>
                    <tr><td class="param-name">event_type</td><td>string</td><td>任意</td><td>種別 (<code>absence</code>=休診, <code>clinic</code>=診察, <code>meeting</code>=会議, <code>business_trip</code>=出張)</td></tr>
                    <tr><td class="param-name">expand_period</td><td>integer</td><td>任意</td><td><code>1</code> を指定すると、複数日の期間予定を日別に分割展開して返却</td></tr>
                </tbody>
            </table>
            <div class="try-box">
                <div class="try-actions">
                    <button class="try-btn" onclick="executeApi('events.php?start_date=<?= date('Y-m-01') ?>&end_date=<?= date('Y-m-t') ?>', 'res-events')"><i class="fa-solid fa-play"></i> 今月分を実行して試す</button>
                    <a href="events.php" target="_blank" class="open-raw-link"><i class="fa-solid fa-arrow-up-right-from-square"></i> 新規タブで開く</a>
                </div>
                <div class="response-preview" id="res-events"></div>
            </div>
        </div>
    </div>

    <!-- 3. 医師マスタ API -->
    <div class="api-card" id="card-doctors">
        <div class="api-header" onclick="toggleCard('card-doctors')">
            <div class="api-method-endpoint">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-url">/api/doctors.php</span>
                <span class="endpoint-desc">アクティブな医師マスタ一覧</span>
            </div>
            <i class="fa-solid fa-chevron-down"></i>
        </div>
        <div class="api-body">
            <p style="font-size:0.9rem;margin-bottom:12px;color:#475569;">
                現在有効な医師一覧（ID、氏名、略称、役職、所属部門、カラーコード等）を取得します。
            </p>
            <div class="try-box">
                <div class="try-actions">
                    <button class="try-btn" onclick="executeApi('doctors.php', 'res-doctors')"><i class="fa-solid fa-play"></i> 実行して試す</button>
                    <a href="doctors.php" target="_blank" class="open-raw-link"><i class="fa-solid fa-arrow-up-right-from-square"></i> 新規タブで開く</a>
                </div>
                <div class="response-preview" id="res-doctors"></div>
            </div>
        </div>
    </div>

    <!-- 4. 部門マスタ API -->
    <div class="api-card" id="card-departments">
        <div class="api-header" onclick="toggleCard('card-departments')">
            <div class="api-method-endpoint">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-url">/api/departments.php</span>
                <span class="endpoint-desc">部門マスタ一覧</span>
            </div>
            <i class="fa-solid fa-chevron-down"></i>
        </div>
        <div class="api-body">
            <p style="font-size:0.9rem;margin-bottom:12px;color:#475569;">
                胃腸科、肛門科などの部門マスタ情報とカラーコードを取得します。
            </p>
            <div class="try-box">
                <div class="try-actions">
                    <button class="try-btn" onclick="executeApi('departments.php', 'res-departments')"><i class="fa-solid fa-play"></i> 実行して試す</button>
                    <a href="departments.php" target="_blank" class="open-raw-link"><i class="fa-solid fa-arrow-up-right-from-square"></i> 新規タブで開く</a>
                </div>
                <div class="response-preview" id="res-departments"></div>
            </div>
        </div>
    </div>

    <!-- 5. 全体会日程 API -->
    <div class="api-card" id="card-meetings">
        <div class="api-header" onclick="toggleCard('card-meetings')">
            <div class="api-method-endpoint">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-url">/api/meetings.php</span>
                <span class="endpoint-desc">小野会全体会日程一覧</span>
            </div>
            <i class="fa-solid fa-chevron-down"></i>
        </div>
        <div class="api-body">
            <p style="font-size:0.9rem;margin-bottom:12px;color:#475569;">
                指定年・月（省略時は当年全体）の全体会開催日程、時間、場所、備考を取得します。
            </p>
            <table class="param-table">
                <thead><tr><th>パラメータ</th><th>型</th><th>必須</th><th>説明</th></tr></thead>
                <tbody>
                    <tr><td class="param-name">year</td><td>integer</td><td>任意</td><td>指定年 (4桁数値、デフォルトは当年)</td></tr>
                    <tr><td class="param-name">month</td><td>integer</td><td>任意</td><td>指定月 (1〜12、省略時は年間)</td></tr>
                </tbody>
            </table>
            <div class="try-box">
                <div class="try-actions">
                    <button class="try-btn" onclick="executeApi('meetings.php?year=<?= date('Y') ?>', 'res-meetings')"><i class="fa-solid fa-play"></i> 実行して試す</button>
                    <a href="meetings.php" target="_blank" class="open-raw-link"><i class="fa-solid fa-arrow-up-right-from-square"></i> 新規タブで開く</a>
                </div>
                <div class="response-preview" id="res-meetings"></div>
            </div>
        </div>
    </div>

    <!-- 6. 申し送り・連絡メモ取得 API -->
    <div class="api-card open" id="card-memo">
        <div class="api-header" onclick="toggleCard('card-memo')">
            <div class="api-method-endpoint">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-url">/api/memo.php</span>
                <span class="endpoint-desc">連絡・申し送りメモ情報（院内告知・補足案内）</span>
            </div>
            <i class="fa-solid fa-chevron-down"></i>
        </div>
        <div class="api-body">
            <p style="font-size:0.9rem;margin-bottom:12px;color:#475569;">
                医師予定表の上部に常時掲出されている連絡・申し送りメモ（診察曜日の変更連絡や全体会案内等の自由記述）を取得します。
                <code>format=plain</code> を指定することで、マイコン端末や電光掲示板、シェル等からそのまま出力できるテキスト形式での取得も可能です。
            </p>
            <table class="param-table">
                <thead><tr><th>パラメータ</th><th>型</th><th>必須</th><th>説明</th></tr></thead>
                <tbody>
                    <tr><td class="param-name">format</td><td>string</td><td>任意</td><td>レスポンス形式 (<code>json</code> [デフォルト] または <code>plain</code> / <code>text</code>)</td></tr>
                    <tr><td class="param-name">trim</td><td>integer</td><td>任意</td><td>空行の除外と前後余白トリム (<code>1</code> [デフォルト] または <code>0</code>)</td></tr>
                </tbody>
            </table>
            <div class="try-box">
                <div class="try-actions">
                    <button class="try-btn" onclick="executeApi('memo.php', 'res-memo')"><i class="fa-solid fa-play"></i> 実行して試す (JSON)</button>
                    <a href="memo.php" target="_blank" class="open-raw-link"><i class="fa-solid fa-arrow-up-right-from-square"></i> 新規タブで開く (JSON)</a>
                    <a href="memo.php?format=plain" target="_blank" class="open-raw-link" style="margin-left:10px;"><i class="fa-solid fa-file-lines"></i> テキスト形式 (plain)</a>
                </div>
                <div class="response-preview" id="res-memo"></div>
            </div>
        </div>
    </div>

    <!-- 7. iCalendar (.ics) 自動購読フィード -->
    <div class="api-card" id="card-feed">
        <div class="api-header" onclick="toggleCard('card-feed')">
            <div class="api-method-endpoint">
                <span class="method-badge method-get" style="background:#e0f2fe;color:#0369a1;border-color:#7dd3fc;">iCal</span>
                <span class="endpoint-url">/api/feed.php</span>
                <span class="endpoint-desc">Googleカレンダー・Outlook・スマホ同期用フィード (.ics)</span>
            </div>
            <i class="fa-solid fa-chevron-down"></i>
        </div>
        <div class="api-body">
            <p style="font-size:0.9rem;margin-bottom:12px;color:#475569;">
                GoogleカレンダーやOutlook、iPhone、Androidなどのカレンダーアプリで「URLでカレンダーを追加（購読）」に登録すると、医師予定が自動的に端末のカレンダーと同期されます。
            </p>
            <table class="param-table">
                <thead><tr><th>パラメータ</th><th>型</th><th>必須</th><th>説明</th></tr></thead>
                <tbody>
                    <tr><td class="param-name">doctor_id</td><td>integer</td><td>任意</td><td>指定医師のみのカレンダーに絞り込み</td></tr>
                </tbody>
            </table>
            <div class="try-box">
                <div class="try-actions">
                    <a href="feed.php" target="_blank" class="try-btn" style="text-decoration:none;"><i class="fa-solid fa-calendar-plus"></i> .ics ファイルをダウンロード・確認</a>
                </div>
            </div>
        </div>
    </div>

    <div class="footer">
        医師予定表管理システム (yotei) API v1.0 &bull; <a href="../index.php" style="color:var(--primary);text-decoration:none;">ポータルへ戻る</a>
    </div>

</div>

<script>
function toggleCard(id) {
    const card = document.getElementById(id);
    card.classList.toggle('open');
}

function executeApi(endpoint, targetId) {
    const el = document.getElementById(targetId);
    el.style.display = 'block';
    el.textContent = '実行中...';

    fetch(endpoint)
        .then(res => res.json())
        .then(data => {
            el.textContent = JSON.stringify(data, null, 2);
        })
        .catch(err => {
            el.textContent = 'エラーが発生しました: ' + err;
        });
}
</script>
</body>
</html>
