<?php
/**
 * ============================================================
 * ファイル名: api/memo.php
 * システム名: 医師予定表管理システム（yotei）API
 * ============================================================
 *
 * 【概要】
 * 連絡・申し送りメモ情報取得API
 * 医師予定表上部に常時掲載される自由記述メモ（診察予定の補足、休診振替、全体会連絡等）を取得します。
 * 電子カルテ、院内サイネージ、電光掲示板、院内グループウェア等の連携向け。
 *
 * 【GETパラメータ】
 * - format : レスポンス形式 ('json' [デフォルト] または 'plain' / 'text')
 *            plain / text を指定すると、メモ本文のみを Content-Type: text/plain で直接返却します。
 * - trim   : 空行を除外して整形するか (1 [デフォルト] または 0)
 */

require_once __DIR__ . '/api_common.php';

try {
    $pdo = getDbConnection();

    $stmt = $pdo->prepare("
        SELECT setting_value, setting_type, description, updated_at 
        FROM settings 
        WHERE setting_key = 'memo_text'
    ");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    // 設定レコードが存在しない場合は初期デフォルト作成＆取得
    if (!$row) {
        $memoText = getMemoText($pdo);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        $memoText = $row['setting_value'] ?? '';
    }

    $format = strtolower(trim($_GET['format'] ?? 'json'));
    $shouldTrim = !isset($_GET['trim']) || $_GET['trim'] === '1' || $_GET['trim'] === 'true';

    // プレーンテキスト出力モード
    if ($format === 'plain' || $format === 'text') {
        header('Content-Type: text/plain; charset=utf-8');
        echo $memoText;
        exit;
    }

    // 行配列に分割
    $rawLines = preg_split('/\r\n|\r|\n/', (string)$memoText);
    $lines = [];
    foreach ($rawLines as $l) {
        if ($shouldTrim) {
            $t = trim($l);
            if ($t !== '') {
                $lines[] = $t;
            }
        } else {
            $lines[] = $l;
        }
    }

    $updatedAt = $row['updated_at'] ?? null;
    $updatedAtIso = $updatedAt ? date('c', strtotime($updatedAt)) : null;
    $updatedAtDisplay = $updatedAt ? date('Y年n月j日 H:i', strtotime($updatedAt)) : null;

    $data = [
        'has_memo' => !empty(trim((string)$memoText)),
        'text' => $memoText,
        'lines' => $lines,
        'line_count' => count($lines),
        'char_count' => mb_strlen((string)$memoText, 'UTF-8'),
        'description' => $row['description'] ?? 'メモ（自由記述）',
        'updated_at' => $updatedAtIso,
        'updated_at_display' => $updatedAtDisplay,
    ];

    apiSuccess($data);

} catch (Exception $e) {
    apiError('メモ情報の取得に失敗しました: ' . $e->getMessage(), 500);
}
